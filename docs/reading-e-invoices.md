# Reading incoming e-invoices

```php
$invoice = $belegwerk->read(file_get_contents('incoming.pdf'));   // or the XML of an XRechnung
echo $invoice['seller']['name'], ': ', $invoice['totals']['due_cents'] / 100, ' € due ', $invoice['due_date'];
```

`read()` takes the raw content as a string and needs neither Gotenberg nor the constructor's `defaults`.

## Supported formats

| Content | Detected by |
| --- | --- |
| PDF with embedded XML (ZUGFeRD, Factur-X, XRechnung) | Starts with `%PDF` |
| XML in CII syntax (`CrossIndustryInvoice`) | Root element namespace |
| XML in UBL syntax, `Invoice` and `CreditNote` | Root element namespace |

For a PDF, belegwerk uses the embedded file named `ZUGFeRD-invoice.xml`, `zugferd-invoice.xml`, `factur-x.xml` or
`xrechnung.xml` (the first one found). Other embedded files are ignored. The XML is then read like any other XML.

`read()` returns the amounts the invoice states and recalculates nothing. It does not validate either: an invoice that
breaks business rules is read as long as it has a number (BT-1). To check incoming invoices, use the KoSIT validator
([Validating e-invoices](validation.md)). Treat incoming files as untrusted input; see
[Security](security.md#reading-untrusted-invoices).

## Result

The keys and structure are those of the [template context](templates.md#context) of `invoice()`. Everything the invoice
does not contain is `null`, lists are empty and totals `0`. Differences:

| Key | Difference |
| --- | --- |
| `syntax`, `profile` | Only here: `CII` or `UBL`, and the specification identifier (BT-24), e.g. `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0` |
| `currency` | As stated, not only `EUR` |
| dates | CII dates in format 102 (`YYYYMMDD`) become `YYYY-MM-DD`, other formats are returned as stated |
| `note` | Several notes are joined with a blank line |
| `cash_discount` | `{days, percent}` from the first `#SKONTO#TAGE=…#PROZENT=…#` in `payment_terms`, no date or amounts |
| `seller`, `buyer` | Both have `tax_number` and `phone`. `contact_name` is not filled in from the name |
| `bank` | Only for payment means 30 and 58 with an IBAN. `holder` falls back to the seller's name, `name` is always `null` |
| `direct_debit` | Only for payment means 49 and 59 |
| `lines[]` | `description` falls back to the name. No `unit` label, `subtotal_cents` or `custom` |
| `attachments[]` | Also `content`, see [Attachments](#attachments) |
| missing | `payment_qr`, `custom`, `tax_exemption_reason` (the reason is in `tax_groups[].exemption_reason`) |

Value types:

- Amounts ending in `_cents` are integers. Negative amounts (credit notes, negative lines) stay negative.
- `quantity`, `percent` and `rate` are strings without trailing zeros: `"6.5"`, not `6.5000`.
- `unit_code` is the UN/ECE code from the XML (`HUR`), not a label.
- `unit_price_cents` is an integer for prices with up to two decimals and a float for more (a price of `12.345` gives
  about `1234.5`). If the price refers to a base quantity (BT-149, e.g. 25.00 € per 100 pieces), it is converted to
  the price per unit: `25` cents for the example.
- The tax amount (`tax_cents`, `tax_groups[]`) is the one in the invoice currency. A second amount in an accounting
  currency (BT-111) is ignored.
- With another currency, the `_cents` fields hold its smallest unit. The `money` filter always appends `€`, so do not
  use it for them.

## Attachments

`attachments[]` holds the supporting documents of the XML (BG-24). A document with an embedded file has its decoded
bytes in `content` and its size in `size`. A document with only a link has `url` and `content` is `null`. `filename`
and `mime` come from the XML.

The file name comes from the sender. Never use it as a path unchecked:

```php
foreach ($invoice['attachments'] as $attachment) {
    if ($attachment['content'] !== null) {
        $name = bin2hex(random_bytes(8)) . '-' . basename((string) $attachment['filename']);
        file_put_contents('/var/invoices/attachments/' . $name, $attachment['content']);
    }
}
```

`content` is also `null` if the embedded data is not valid Base64 or empty. `mime` is only what the sender states;
belegwerk does not check it against the content.

## Errors

`read()` throws `Belegwerk\Invoice\ReadException` (a `RuntimeException`, see [Errors](api.md#errors)) if:

- the content starts with `%PDF` but contains none of the embedded XML files above,
- the content is neither a PDF nor well-formed XML (this includes empty content),
- the XML is neither a CII nor a UBL invoice (`Unknown XML: expected a CII or UBL invoice.`),
- the invoice has no number.

Anything else that is missing in a readable invoice is `null`; `read()` does not report it.
