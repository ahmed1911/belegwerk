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

The keys follow the [template context](templates.md#context), but the result is not a complete context. Everything the
invoice does not contain is `null`, lists are empty and totals `0`.

| Key | Content |
| --- | --- |
| `syntax` | `CII` or `UBL` (only in the result of `read()`) |
| `profile` | Specification identifier (BT-24), e.g. `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0` (only in the result of `read()`) |
| `number`, `type_code`, `currency` | Invoice number (BT-1), type code (BT-3), currency code (BT-5) as stated |
| `date`, `due_date`, `service_date` | `YYYY-MM-DD`. CII dates in format 102 (`YYYYMMDD`) are converted, other formats are returned as stated |
| `period` | `{start, end}` or `null` |
| `buyer_reference`, `order_reference`, `despatch_reference` | Strings |
| `preceding_invoice` | `{number, date}` or `null` |
| `payment_terms`, `note` | Strings. Several notes are joined with a blank line |
| `cash_discount` | `{days, percent}` from the first `#SKONTO#TAGE=…#PROZENT=…#` in `payment_terms`, or `null`. `percent` is a string, there is no calculated date or amount |
| `seller`, `buyer` | `name`, `address` (`street`, `additional`, `zip`, `city`, `country`), `vat_id`, `tax_number`, `email`, `phone`, `contact_name`, `electronic_address` (`{scheme, id}` or `null`). `contact_name` is not filled in from the name |
| `delivery` | `{name, address, location_id}` (`location_id` is `{scheme, id}`) or `null` |
| `bank` | `{holder, iban, bic, name}` for payment means 30 and 58 with an IBAN, otherwise `null`. `holder` is the account name or, if missing, the seller's name. `name` is always `null` |
| `direct_debit` | `{mandate_reference, creditor_id, iban}` for payment means 49 and 59, otherwise `null` |
| `lines[]` | See below |
| `allowances[]`, `charges[]` | Document level: `reason`, `reason_code`, `percent`, `base_cents`, `amount_cents`, `tax_category`, `tax_rate` |
| `tax_groups[]` | `category`, `rate`, `base_cents`, `amount_cents`, `exemption_reason` |
| `attachments[]` | `id`, `description`, `filename`, `mime`, `size`, `url`, `content`; see [Attachments](#attachments) |
| `totals` | `line_total_cents`, `allowance_total_cents`, `charge_total_cents`, `net_cents`, `tax_cents`, `gross_cents`, `paid_cents`, `due_cents` |

Each line has `id`, `name`, `description` (the name if there is no description), `quantity`, `unit_code`,
`unit_price_cents`, `allowances[]`, `charges[]` (each with `reason`, `reason_code`, `percent`, `base_cents`,
`amount_cents`), `net_cents`, `tax_category` and `tax_rate`.

Value types:

- Amounts ending in `_cents` are integers. Negative amounts (credit notes, negative lines) stay negative.
- `quantity`, `percent` and `rate` are strings without trailing zeros: `"6.5"`, not `6.5000`.
- `unit_code` is the UN/ECE code from the XML (`HUR`), not a label.
- `unit_price_cents` is an integer for prices with up to two decimals and a float for more (a price of `12.345` gives
  about `1234.5`). If the price refers to a base quantity (BT-149, e.g. 25.00 € per 100 pieces), it is converted to
  the price per unit: `25` cents for the example.
- The tax amount (`tax_cents`, `tax_groups[]`) is the one in the invoice currency. A second amount in an accounting
  currency (BT-111) is ignored.
- Currencies other than EUR are read as they are, and the `_cents` fields hold the smallest unit of that currency. The
  `money` filter always appends `€`, so do not use it for them.

Unlike the template context, the result has no `unit` labels, `subtotal_cents`, `custom`, `payment_qr` or
`tax_exemption_reason` (the reason is in `tax_groups[].exemption_reason`).

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
