# Invoice data

`$invoice` is the input of `invoice()` and `xrechnung()`: an array, for example decoded JSON. See
[`examples/invoice.json`](../examples/invoice.json) for a complete one.

- Amounts are **integer cents** (`unit_price_cents`, `amount_cents`, `paid_cents`). Floats and strings are rejected.
- Dates are `YYYY-MM-DD` strings and must exist (`2026-02-30` is rejected).
- Strings are trimmed. An empty string counts as missing. Integers are accepted where a string is expected.
- Data from the [defaults](api.md#defaults) is merged in before validation, so `seller` and `bank` may live there.
- Errors are collected and thrown together, each with its field path, see
  [ValidationException](api.md#validationexception).

belegwerk calculates the line amounts, the allowance and charge amounts, the tax per category and rate, and the totals.
All calculations are exact in decimals, without float errors, and round half away from zero to whole cents:

| Value | Calculation |
| --- | --- |
| Line amount | `round(quantity × unit_price_cents) − allowances + charges` |
| Percentage allowance or charge | `round(base × percent / 100)` |
| Tax per category and rate | `round(tax base × rate / 100)`; the tax base is the sum of the line amounts, minus allowances, plus charges |
| Net, tax, gross | net = lines − allowances + charges; tax = sum of the rounded tax amounts; gross = net + tax |
| Amount due | `gross − paid_cents` |
| Cash discount | `round(amount due × percent / 100)` |

The template context with all calculated values is described in [Templates: Context](templates.md#context).

The array shape is declared as `InvoiceData` on `Belegwerk`, so PHPStan and IDEs that read array shapes check and
complete it. Use it for your own mapping code with `/** @phpstan-import-type InvoiceData from Belegwerk */` on the
class and `@return InvoiceData` on the method. PHPStan reports missing required fields and wrong types, but not
misspelled optional keys. `seller` and `bank` are optional in the type because they usually come from the defaults.

## Fields

"XRechnung" in the Required column marks what `xrechnung()` additionally requires, see
[XRechnung requirements](#xrechnung-requirements). BT and BG numbers refer to EN 16931.

### Invoice

| Field | Required | Description |
| --- | --- | --- |
| `number` | yes | Invoice number (BT-1) |
| `date` | yes | Issue date (BT-2) |
| `due_date` | yes | Payment due date (BT-9) |
| `type_code` | | Document type (BT-3), default `380`. Allowed: `380` invoice, `381` credit note, `384` corrected invoice, `326` partial invoice, `389` self-billed invoice, `875`/`876`/`877` partial, partial final and final construction invoice |
| `currency` | | Only `EUR` (default) |
| `service_date` | | Delivery or service date (BT-72) |
| `period` | | Billing period (BG-14): `start` and `end`, both required if `period` is given; `end` must not be before `start` |
| `buyer_reference` | XRechnung | Buyer reference (BT-10); the Leitweg-ID for German public authorities |
| `order_reference` | | Purchase order number (BT-13) |
| `despatch_reference` | | Delivery note number (Lieferscheinnummer, BT-16) |
| `preceding_invoice` | for type `384` | Invoice that a credit note (`381`) or corrected invoice (`384`) refers to (BG-3): `number` (BT-25, required if the object is given) and optional `date` (BT-26) |
| `payment_terms` | | Payment terms text (BT-20). Default (German): "Zahlbar bis zum dd.mm.yyyy ohne Abzug.", or a text for direct debit or for an invoice that is already paid |
| `note` | | Invoice note (BT-22) |
| `tax_exemption_reason` | for category `E` | Reason for the exemption (BT-120), e.g. "Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG." Also printed for `AE`, `K` and `G`, replacing their default text, see [VAT categories](#vat-categories) |
| `paid_cents` | | Amount already paid (BT-113), default 0; the amount due becomes `gross − paid`. Must not exceed the gross amount |
| `cash_discount` | | Cash discount (Skonto): `days` (positive integer) and `percent` (above 0, below 100, max. 2 decimals) |
| `bank` | unless `direct_debit` | Seller's bank account for SEPA credit transfer (BG-17): `holder`, `iban`, optional `bic`, `name` |
| `direct_debit` | | SEPA direct debit (payment means 59) instead of a transfer: `mandate_reference` (BT-89), `creditor_id` (BT-90), `iban` (BT-91, the buyer's account), all required |
| `seller` | yes | See [Parties](#parties) |
| `buyer` | yes | See [Parties](#parties) |
| `delivery` | | Deliver-to party if it differs from the buyer (BG-13), see [Parties](#parties) |
| `items` | yes | Non-empty list of lines, see [Items](#items) |
| `allowances`, `charges` | | Allowances (discounts) and charges (e.g. shipping) on the whole invoice, see [Allowances and charges](#allowances-and-charges) |
| `attachments` | | Supporting documents such as timesheets, see [Attachments](#attachments) |
| `custom` | | Free-form object for templates, passed through unchanged |

Payment:

- The `iban` of `bank` and `direct_debit` is checked (checksum and length for the country). Spaces and case are
  ignored, the result is upper case without spaces.
- With `direct_debit`, `bank` is optional. If you pass both, the XML contains the direct debit only. Templates can still
  print the bank account.
- The invoice number is the remittance information of a credit transfer.
- The XML carries `cash_discount` as `#SKONTO#TAGE=14#PROZENT=2.00#` at the start of the payment terms (BT-20), where
  XRechnung expects it.

### Parties

| Field | Required | Description |
| --- | --- | --- |
| `seller.name` | yes | Seller name |
| `seller.address` | yes | `street`, `zip` and `city` are required; `country` is an ISO 3166-1 alpha-2 code (default `DE`); optional `additional` (address line 2) |
| `seller.vat_id` or `seller.tax_number` | one of them | VAT ID (BT-31) or tax number (BT-32). Without a VAT ID, the tax number also serves as seller identifier in the XML. Categories `AE`, `K` and `G` need the VAT ID |
| `seller.email` | XRechnung | Contact email (BT-43), checked for a valid format. Also the default `electronic_address` |
| `seller.phone` | XRechnung | Contact phone (BT-42) |
| `seller.contact_name` | | Contact person (BT-41), default: the seller name |
| `seller.electronic_address` | XRechnung | Electronic address (BT-34): an email address, or `{"scheme": "EM", "id": "…"}` with a scheme from the EAS code list. Default: `email` with scheme `EM` |
| `buyer.name` | yes | Buyer name |
| `buyer.address` | yes | `city` is required; `street`, `zip`, `additional` are optional (`zip` is required for XRechnung); `country` as for the seller |
| `buyer.vat_id` | for `AE`, `K` | Buyer VAT ID (BT-48) |
| `buyer.email`, `buyer.contact_name` | | Like the seller's. `email` is checked for a valid format |
| `buyer.electronic_address` | XRechnung | Electronic address (BT-49), like the seller's: the `buyer.email`, or `{"scheme": "0204", "id": "<Leitweg-ID>"}` |
| `delivery.name` | | Name of the deliver-to party (BT-70) |
| `delivery.address` | | Deliver-to address (BG-15), like `buyer.address`: `city` required, `zip` required for XRechnung |
| `delivery.location_id` | | Location ID (BT-71): a string agreed with the buyer, or `{"scheme": "0088", "id": "<GLN>"}` with a four-digit ISO 6523 scheme |

`seller.tax_number` and `seller.phone` exist for the seller only. `delivery` needs at least one of `name`, `address`
and `location_id`.

### Items

Each entry of `items` is an object:

| Field | Required | Description |
| --- | --- | --- |
| `description` | yes | Description (BT-154). May contain line breaks |
| `unit_price_cents` | yes | Net price per unit in cents. May be negative, e.g. for a correction line; the XML moves the sign to the quantity, because EN 16931 forbids negative prices |
| `quantity` | | Number, default 1. Max. 6 decimals, may be negative |
| `name` | | Item name (BT-153). Default: the first line of `description`, cut off after 200 characters |
| `unit`, `unit_code` | | Unit of measure, default `H87` (piece), see [Units](#units) |
| `tax_rate` | | VAT rate in percent, rounded to 2 decimals. Default: 19 for category `S` (or none), 0 for the other categories |
| `tax_category` | | VAT category, see [VAT categories](#vat-categories). Default: `Z` for rate 0, otherwise `S` |
| `allowances`, `charges` | | Allowances and charges on this line, see [Allowances and charges](#allowances-and-charges) |
| `custom` | | Free-form object for templates, passed through unchanged |

The lines are numbered 1, 2, … in the order of `items`.

## Units

`unit` accepts the labels belegwerk prints and common German names, in any case: `Stk`, `Stück`, `Std`, `Stunde`,
`Tag`, `Woche`, `Monat`, `Jahr`, `Min`, `pauschal`, `m²`, `qm`, `km`, `kg`, `l`, `kWh`, … It also accepts a UN/ECE Rec.
20/21 code (`H87`, `HUR`, `DAY`, `LS`, …). Anything else needs a `unit_code`, which is checked against the code list.

| Code | Label | Code | Label | Code | Label |
| --- | --- | --- | --- | --- | --- |
| `H87`, `C62` | Stk. | `HUR` | Std. | `MIN` | Min. |
| `DAY` | Tag(e) | `WEE` | Woche(n) | `MON` | Monat(e) |
| `ANN` | Jahr(e) | `LS` | pauschal | `KGM` | kg |
| `GRM` | g | `TNE` | t | `MTR` | m |
| `KMT` | km | `MTK` | m² | `MTQ` | m³ |
| `LTR` | l | `KWH` | kWh | `SET` | Set |
| `PR` | Paar | `XPK` | Paket | | |

The templates receive `unit` as printed (your text, or the label of the code if you gave a code or no unit) and
`unit_code`. If you pass both, `unit_code` goes into the XML and `unit` stays as you wrote it.

## Allowances and charges

On items and on the whole invoice, `allowances` (discounts) and `charges` (e.g. shipping) are lists of objects:

| Field | Description |
| --- | --- |
| `reason` | Required text |
| `amount_cents` | Positive integer cents. Exactly one of `amount_cents` and `percent` |
| `percent` | Above 0, up to 100, max. 2 decimals |
| `reason_code` | Optional. Allowances: UNTDID 5189, e.g. `95` discount. Charges: UNTDID 7161, e.g. `FC` freight |
| `tax_rate`, `tax_category` | Whole invoice only; defaults as for items |

**On an item**, they change the line amount. A percentage applies to `round(quantity × unit_price_cents)`, not to the
amount after other allowances. The item's tax category and rate stay.

**On the whole invoice**, they change the tax base. Each entry belongs to one VAT category and rate (BG-20, BG-21):

- With `tax_rate` or `tax_category`, the entry belongs to that category and rate. A percentage then needs at least one
  line with exactly this category and rate and applies to the sum of these lines.
- Without them, a percentage applies to the line amounts of every VAT category and rate. The result has one entry per
  category and rate, each rounded on its own.
- Without them, an amount takes the category and rate of the lines if they all have the same. If the lines differ, the
  entry needs `tax_rate`.
- A percentage always refers to the line amounts after item allowances and charges, never to other invoice-level
  entries.

In the template context, an entry from a percentage carries `percent` and `base_cents`, see
[Templates: Context](templates.md#context). Example: [Discounts and shipping](#discounts-and-shipping).

## Attachments

`attachments` is a list. Each entry is a file (`filename` and `content`, the raw bytes) or a link (`url`):

| Field | Description |
| --- | --- |
| `filename`, `content` | A file, embedded base64-encoded in the XML (BT-125). `content` must not be empty; `filename` is required with it |
| `url` | A link to the document (BT-124) |
| `id` | Document reference (BT-122). Default: the file name without extension. Required for a link |
| `description` | Text for the document (BT-123), shown in templates |

What belegwerk does with files:

- The type is detected from the content, not from the name. Allowed: PDF, PNG, JPEG, CSV, XLSX and ODS. A text file
  named `*.csv` counts as CSV.
- Directories are cut off the file name (`/` and `\`) and the extension follows the detected type: `foto.jpg` becomes
  `foto.jpeg`, `Lieferschein` becomes `Lieferschein.png`.
- File names must be unique, ignoring case (BR-DE-22). The check runs after the extension was adjusted.
- Files are embedded in the XML of XRechnung and ZUGFeRD alike. Templates get the list without the content, but with
  `mime` and `size`.
- Receiving portals limit the size of an e-invoice, so keep attachments small or link them.

The result of [reading e-invoices](reading-e-invoices.md#attachments) has attachments, too.

## VAT categories

| Category | Meaning | Rate | Needs |
| --- | --- | --- | --- |
| `S` | Standard rate | above 0 | |
| `Z` | Zero rated | 0 | |
| `E` | Exempt | 0 | `tax_exemption_reason` |
| `AE` | Reverse charge | 0 | `seller.vat_id`, `buyer.vat_id` |
| `K` | Intra-community supply | 0 | `seller.vat_id`, `buyer.vat_id` |
| `G` | Export outside the EU | 0 | `seller.vat_id` |

The category is not case-sensitive. Categories other than `S` need rate 0, which is also their default. Without
`tax_category`, rate 0 gives `Z` and every other rate `S`.

belegwerk groups the lines (and the invoice-level allowances and charges) by category and rate. Each group becomes one
VAT breakdown (BG-23) in the XML and one entry of `tax_groups` in the template context. The groups `E`, `AE`, `K` and
`G` carry an exemption reason (BT-120): your `tax_exemption_reason`, or for `AE`, `K` and `G` a German default
("Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge)", "Steuerfreie innergemeinschaftliche Lieferung",
"Steuerfreie Ausfuhrlieferung"). Because one `tax_exemption_reason` applies to the whole invoice, mix `E` with `AE`,
`K` or `G` only if the same text fits.

## XRechnung requirements

`xrechnung()` creates an XRechnung 3.0 (CII) and needs these fields on top of the valid invoice data. Without them it
throws a `ValidationException` that lists every missing field. `invoice()` does not check them.

| Field | Note |
| --- | --- |
| `buyer_reference` | The Leitweg-ID for German public authorities |
| `seller.phone` | |
| `seller.email` | Also fills `seller.electronic_address` unless you set that |
| `seller.electronic_address` | Taken from `seller.email` if not set |
| `buyer.electronic_address` | Taken from `buyer.email` if not set; for public authorities `{"scheme": "0204", "id": "<Leitweg-ID>"}` |
| `buyer.address.zip` | |
| `delivery.address.zip` | Only if `delivery.address` is given |

## Examples

Each snippet shows only the fields that differ from [`examples/invoice.json`](../examples/invoice.json). The test suite
validates all of them as XRechnung with the KoSIT validator (`tests/SchematronTest.php`), see
[Validation](validation.md#tested-with).

### Small business (§ 19 UStG)

Every item gets the category `E` with rate 0, and the invoice a `tax_exemption_reason`. The seller has no VAT ID, only
the tax number (remove `seller.vat_id` from the example).

```json
{
  "tax_exemption_reason": "Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.",
  "items": [
    {"description": "Konzeption und Workshop", "quantity": 6.5, "unit": "Std", "unit_price_cents": 12000, "tax_category": "E"},
    {"description": "Hosting-Paket Business", "unit": "Monat", "unit_price_cents": 4990, "tax_category": "E"}
  ]
}
```

### Reverse charge

Category `AE` on the items, the buyer's VAT ID, and a buyer in another EU country. The seller needs a VAT ID. The
exemption reason has a German default.

```json
{
  "buyer": {
    "name": "Stadtverwaltung Beispielstadt",
    "vat_id": "ATU12345678",
    "address": {"street": "Ring 1", "zip": "1010", "city": "Wien", "country": "AT"},
    "email": "eingangsrechnung@beispielstadt.de"
  },
  "items": [
    {"description": "Konzeption und Workshop", "quantity": 6.5, "unit": "Std", "unit_price_cents": 12000, "tax_category": "AE"}
  ]
}
```

### Credit note and corrected invoice

Amounts stay positive. `preceding_invoice` names the invoice. The date is optional, the number required. A corrected
invoice (`384`) needs `preceding_invoice`, a credit note (`381`) does not.

```json
{
  "type_code": "381",
  "preceding_invoice": {"number": "RE-2026-0041", "date": "2026-03-01"}
}
```

```json
{
  "type_code": "384",
  "preceding_invoice": {"number": "RE-2026-0041"}
}
```

### Partial payment

`paid_cents` reduces the amount due. If it equals the gross amount, the invoice is paid (due 0) and the default payment
terms say so. It must not exceed the gross amount.

```json
{"paid_cents": 50000}
```

### Cash discount (Skonto)

2 % within 14 days.

```json
{"cash_discount": {"days": 14, "percent": 2}}
```

### SEPA direct debit

Replaces `bank` (remove it from the example, or keep it for your template). `iban` is the buyer's account.

```json
{
  "direct_debit": {
    "mandate_reference": "M-7",
    "creditor_id": "DE98ZZZ09999999999",
    "iban": "DE02120300000000202051"
  }
}
```

### Discounts and shipping

A 10 % allowance on the first line, a surcharge on the second, 5 % off the whole invoice (applied to both VAT rates of
the example), and shipping at 19 %. Result: net 790.35 €, tax 142.20 €, gross 932.55 €.

```json
{
  "items": [
    {"description": "Konzeption und Workshop", "quantity": 6.5, "unit": "Std", "unit_price_cents": 12000, "tax_rate": 19,
     "allowances": [{"reason": "Treuerabatt", "percent": 10}]},
    {"description": "Hosting-Paket Business", "unit": "Monat", "unit_price_cents": 4990, "tax_rate": 19,
     "charges": [{"reason": "Expresszuschlag", "amount_cents": 500}]},
    {"description": "Fachbuch \"Barrierefreies Webdesign\"", "quantity": 2, "unit": "Stk", "unit_price_cents": 3495, "tax_rate": 7}
  ],
  "allowances": [{"reason": "Rabatt", "percent": 5, "reason_code": "95"}],
  "charges": [{"reason": "Versand", "amount_cents": 490, "tax_rate": 19, "reason_code": "FC"}]
}
```

### Deliver-to address with attachments

A deliver-to party with a GLN, a delivery note number, two files and a link. The first file gets the id
`stundenzettel-2026-09`, the link needs an `id`.

```php
$invoice['despatch_reference'] = 'LS-1234';
$invoice['delivery'] = [
    'name' => 'Stadtverwaltung Beispielstadt, Bauhof',
    'address' => ['street' => 'Industriestraße 9', 'zip' => '10117', 'city' => 'Berlin'],
    'location_id' => ['scheme' => '0088', 'id' => '4012345000009'],
];
$invoice['attachments'] = [
    ['filename' => 'stundenzettel-2026-09.pdf', 'content' => file_get_contents($pdfPath), 'description' => 'Stundenzettel September'],
    ['filename' => 'lieferschein.png', 'content' => file_get_contents($pngPath), 'description' => 'Lieferschein', 'id' => 'LS-1234'],
    ['url' => 'https://kunde.example/abnahme/77', 'id' => 'ABN-77', 'description' => 'Abnahmeprotokoll'],
];
```
