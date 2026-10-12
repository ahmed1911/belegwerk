# belegwerk

PHP library that renders PDF documents from Twig templates via [Gotenberg](https://gotenberg.dev) and creates
e-invoices for Germany and the EU:

- **XRechnung 3.0** (UN/CEFACT CII syntax)
- **ZUGFeRD / Factur-X**: a PDF/A-3b with the invoice XML embedded (profile EN 16931)
- **invoice PDFs** and **any other document** (quotes, delivery notes, reminders) from your own templates

It is built for developers of invoicing software whose users design their own templates: templates run in Twig's
sandbox, and Gotenberg runs without JavaScript and without network access. belegwerk stores nothing. Your application
keeps templates and data and passes them in.

The e-invoice XML is written by [horstoeko/zugferd](https://github.com/horstoeko/zugferd).

## Installation

```sh
composer require ahmed1911/belegwerk
```

Requirements: PHP 8.2+ with the extensions curl, dom, fileinfo, simplexml and zip, and a running Gotenberg 8. This repository's `compose.yaml` starts one on `127.0.0.1:3000`:

```sh
docker compose up -d
```

### Gotenberg settings

Templates may come from untrusted users, so start Gotenberg with these flags (as in `compose.yaml`):

| Flag | Why |
| --- | --- |
| `--chromium-disable-javascript=true` | Scripts in templates do not run. |
| `--chromium-allow-list=^file:///tmp/.*` | Chromium only loads the files belegwerk sends. Requests to other URLs (internet or internal network) are blocked and those resources stay empty. |
| `--api-disable-download-from=true` | Gotenberg does not download files from URLs given in requests. |

Keep Gotenberg reachable only from your application, never publicly.

## Usage

```php
use Belegwerk\Belegwerk;
use Belegwerk\Template;

// Master data once: merged into every call, which overrides it field by field (optional: timeout in seconds, default 60)
$belegwerk = new Belegwerk('http://127.0.0.1:3000', defaults: [
    'seller' => ['name' => 'Musterfirma GmbH', 'address' => [...], 'vat_id' => 'DE123456789', 'email' => '...', 'phone' => '...'],
    'bank' => ['holder' => 'Musterfirma GmbH', 'iban' => 'DE89 3704 0044 0532 0130 00'],
    'custom' => ['logo' => 'data:image/png;base64,...'],
]);

// Templates live in your project: a directory with html.twig (optional header.twig, footer.twig, partials/,
// assets/ and defaults.json) ...
$template = Template::fromDirectory(__DIR__ . '/templates/invoice');

$invoice = [
    'number' => 'RE-2026-0042',
    'date' => '2026-09-24',
    'due_date' => '2026-10-08',
    'buyer' => ['name' => 'Beispiel AG', 'address' => ['street' => 'Ring 1', 'zip' => '10115', 'city' => 'Berlin']],
    'items' => [['description' => 'Beratung', 'quantity' => 2, 'unit' => 'Std', 'unit_price_cents' => 9000]],
];

// Invoice: always ZUGFeRD, a normal-looking PDF that also carries the e-invoice XML
$pdf = $belegwerk->invoice($invoice, $template);

// XRechnung XML, e.g. for German public authorities (also needs buyer_reference and the buyer's email)
$xml = $belegwerk->xrechnung($invoice);

// ... or strings, e.g. loaded from your database
$pdf = $belegwerk->invoice($invoice, new Template($html, footer: $footerHtml));

// Any other document with free-form data (defaults are merged in as well)
$pdf = $belegwerk->document(Template::fromDirectory('/path/to/quote'), ['number' => 'A-7', 'items' => $items]);
```

All methods return the file content as a string.

## Invoice data

`$invoice` is an array (for example decoded JSON). Amounts are **integer cents**, dates are `YYYY-MM-DD` strings.
belegwerk calculates line amounts (`round(quantity × unit_price_cents)`), the tax per category and rate,
and the totals. It calculates exactly in decimals, without float errors, and rounds half away from zero.
See [`examples/invoice.json`](examples/invoice.json).

The array shape is declared as `InvoiceData` on `Belegwerk`, so PHPStan and IDEs that read array shapes check and
complete it. Use it for your own mapping code with `/** @phpstan-import-type InvoiceData from Belegwerk */` on the
class and `@return InvoiceData` on the method. PHPStan reports missing required fields and wrong types, but not
misspelled optional keys.

| Field | Required | Description |
| --- | --- | --- |
| `number`, `date`, `due_date` | yes | Invoice number, issue date, due date |
| `type_code` | | `380` invoice (default), `381` credit note, `384` corrected invoice, `326` partial invoice, … |
| `currency` | | Only `EUR` for now |
| `service_date` or `period.start` + `period.end` | | Delivery date or billing period |
| `buyer_reference` | XRechnung | Buyer reference; the Leitweg-ID for German public authorities |
| `order_reference`, `payment_terms`, `note` | | Purchase order number, payment terms text, invoice note |
| `preceding_invoice.number`, `preceding_invoice.date` | for type `384` | Invoice that a credit note (`381`) or corrected invoice (`384`) refers to (BT-25/BT-26) |
| `tax_exemption_reason` | for category `E` | E.g. "Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG." |
| `seller.name`, `seller.address` | yes | `address`: `street`, `zip`, `city`, `country` (ISO code, default `DE`), optional `additional` |
| `seller.vat_id` or `seller.tax_number` | one of them | Without a VAT ID, the tax number also serves as seller identifier in the XML |
| `seller.email`, `seller.phone` | XRechnung | Contact; `email` is also the electronic address |
| `seller.contact_name`, `seller.electronic_address` | | Contact person (default: seller name), electronic address (default: `email`) |
| `buyer.name`, `buyer.address.city` | yes | Street and zip are optional (zip is required for XRechnung) |
| `buyer.email` or `buyer.electronic_address` | XRechnung | Email, or `{"scheme": "0204", "id": "<Leitweg-ID>"}` |
| `buyer.vat_id` | for `AE`/`K` | Buyer VAT ID |
| `bank.holder`, `bank.iban` | unless `direct_debit` | SEPA credit transfer; optional `bic`, `name` |
| `direct_debit.mandate_reference`, `.creditor_id`, `.iban` | | SEPA direct debit (payment means 59) from the buyer's account instead of a transfer |
| `cash_discount.days`, `cash_discount.percent` | | Cash discount (Skonto) on the amount due, written as `#SKONTO#TAGE=…#PROZENT=…#` (XRechnung) |
| `paid_cents` | | Amount already paid (BT-113); the amount due becomes `gross − paid` |
| `items[]` | yes | `description`, `unit_price_cents`, optional `quantity` (default 1, max. 6 decimals), `name`, `unit`, `unit_code`, `tax_rate` (default 19), `tax_category`, `custom` |
| `custom` | | Free-form data for templates, passed through unchanged |

**Units:** `unit` accepts common German names (`Stk`, `Std`, `Tag`, `Monat`, `pauschal`, `km`, `kg`, …) or a UN/ECE Rec. 20/21
code (`H87`, `HUR`, `DAY`, `LS`, …). Other names need a `unit_code`.

**VAT categories:** `S` (standard, rate > 0), `Z` (zero rated), `E` (exempt), `AE` (reverse charge),
`K` (intra-community supply), `G` (export). All but `S` require `tax_rate: 0`.

## Templates

A `Template` has up to three Twig sources, each a complete HTML document:

- `html` (required): the page content. Set page size and margins with CSS `@page`.
- `header`, `footer` (optional): repeated on every page by Chromium. Elements with the classes `pageNumber` and
  `totalPages` are filled in. Header and footer need their own inline styles and must fit into the page margins.

Create one from strings (`new Template($html, $header, $footer)`) or from a directory (`Template::fromDirectory()`):

```
templates/invoice/
  html.twig            required
  header.twig          optional
  footer.twig          optional
  partials/*.twig      includes, e.g. {% include 'partials/styles.twig' %}
  assets/*             images and fonts, e.g. {{ 'logo.png'|asset }}
  defaults.json        values for optional fields, e.g. {"custom": {"project": null}}
```

Includes can only load partials supplied with the template. For templates stored in a database, pass the same parts
as constructor arguments: `partials: [...]`, `assets: ['logo.png' => $bytes]`, `defaults: [...]`.

belegwerk ships no templates; they live in your project. [`examples/invoice-template`](examples/invoice-template)
is a German invoice layout to copy as a starting point.

### Context

For `invoice()` the template receives the validated and calculated invoice:

| Variable | Content |
| --- | --- |
| `number`, `type_code`, `currency`, `date`, `due_date`, `service_date`, `period.start`, `period.end` | As passed in |
| `buyer_reference`, `order_reference`, `preceding_invoice`, `payment_terms`, `note`, `tax_exemption_reason` | As passed in, or `null` |
| `seller`, `buyer`, `bank`, `direct_debit` | As passed in; IBANs without spaces; `bank` is `null` if omitted with a direct debit |
| `cash_discount` | `days`, `percent`, calculated `date`, `amount_cents`, `payable_cents`; or `null` |
| `lines[]` | `id`, `name`, `description`, `quantity` (up to 6 decimals), `unit`, `unit_code`, `unit_price_cents`, `net_cents`, `tax_category`, `tax_rate`, `custom` |
| `tax_groups[]` | `category`, `rate`, `base_cents`, `amount_cents`, `exemption_reason` |
| `totals` | `net_cents`, `tax_cents`, `gross_cents`, `paid_cents`, `due_cents` |
| `custom` | Your free-form data |

For `document()` the context is the array you pass, merged into the defaults. It may only contain arrays and scalars.

Undefined variables and keys throw a `Twig\Error\RuntimeError` instead of printing nothing, so a typo like
`{{ seler.name }}` cannot slip onto an invoice. All invoice fields above are always set (`null` if not given).

Declare the optional `custom` fields a template reads in its `defaults.json`. They are merged below the data you pass
(template defaults < `defaults` of `Belegwerk` < call data), so the template works whether or not a field is set:

```json
{ "custom": { "time_range": null, "section_title": "Leistungen" } }
```

Defaults under `line` apply to each entry of `lines`, for optional per-line fields:

```json
{ "custom": { "time_range": null }, "line": { "custom": { "time_range": null } } }
```

Template defaults only reach the template. They never change the e-invoice XML, so a user-supplied template cannot
alter invoice data. Alternatively, read optional data with `default` or `is defined`:

```twig
{% if custom.project|default %}Projekt: {{ custom.project }}{% endif %}
{{ custom.section_title|default('Leistungen') }}
{% if not direct_debit %}IBAN {{ bank.iban }}{% endif %}   {# bank may be null with a direct debit #}
```

The example template reads these optional `custom` fields, declared in its `defaults.json`: `logo` (data URI),
`legal` (list of lines for the footer, e.g. commercial register and managing directors), `occasion`, `project`,
`orderer`, `section_title`.

### Formatting

```twig
{{ totals.gross_cents|money }}                  {# 1.062,37 € #}
{{ date|date('d.m.Y') }}                        {# 24.09.2026 #}
{{ line.quantity|replace({'.': ','}) }}         {# 6,5 #}
{{ bank.iban|split('', 4)|join(' ') }}          {# DE89 3704 0044 0532 0130 00 #}
```

### What templates may use

Templates run in Twig's sandbox with a strict policy:

- **Tags:** `if`, `for`, `set`, `include`
- **Filters:** `escape`/`e`, `raw`, `upper`, `lower`, `capitalize`, `title`, `trim`, `nl2br`, `striptags`, `replace`,
  `format`, `number_format`, `round`, `abs`, `date`, `default`, `length`, `first`, `last`, `join`, `split`, `slice`,
  `keys`, `merge`, `batch`, `column`, `money`, `asset`
- **Tests** such as `is empty`, `is defined`, `is odd`
- Operators, including `~`, `in`, `??` and arithmetic

Everything else is rejected: all functions, `extends`, macros, and method or property calls.
Output is HTML-escaped automatically.

### Fonts and images

Put them into the template's `assets/` folder (or pass `assets:` to the constructor) and use the `asset` filter. It
embeds the file as a `data:` URI, since Gotenberg does not load anything from the network. This works in `html`,
`header` and `footer`, in `<img>` as well as in CSS:

```html
<style>
  @font-face { font-family: Brand; src: url({{ 'Brand.woff2'|asset }}) format('woff2'); }
  body { font-family: Brand, Arial, sans-serif; }
</style>
<img src="{{ 'logo.png'|asset }}">
```

Supported types: png, jpg, jpeg, gif, webp, svg, woff2, woff, ttf, otf. Asset names are plain file names without a path;
an unknown name throws a `Twig\Error\RuntimeError`. Images that change per customer, such as a tenant's logo, can still
be passed in the data as a data URI, e.g. `custom.logo`:

```html
{% if custom.logo %}<img src="{{ custom.logo }}">{% endif %}
```

## Errors

| Exception | When |
| --- | --- |
| `Belegwerk\Invoice\ValidationException` | Invalid invoice data. `$e->errors` maps field paths such as `items.0.unit_price_cents` to messages. |
| `Twig\Error\Error` | Template syntax error, undefined variable (`RuntimeError`) or sandbox violation (`SecurityError`). `$e->getTemplateLine()` gives the line. |
| `Belegwerk\Gotenberg\GotenbergException` | Gotenberg is unreachable or cannot render the document. |

Tip: render a preview when a user saves a template, so errors show up there and not during invoicing.

## Security notes

- Templates cannot run PHP code, call methods or read files; see the allowed features above.
- Twig's sandbox does not limit CPU time or memory. Render with PHP's `max_execution_time` and `memory_limit` in place;
  Gotenberg limits a conversion with `--api-timeout` (default 30s).

## Validating e-invoices

belegwerk checks the input (required fields, tax rules, units, country codes and electronic address schemes against
the official code lists) and the generated XML against its XSD schema. The complete business rules (EN 16931, BR-DE)
are Schematron rules that need Java. The test suite runs them for typical invoices. To check every invoice in
production, run the official [KoSIT validator](https://github.com/itplr-kosit/validator) and connect it through
horstoeko's `ZugferdKositValidator`, which also supports a remote KoSIT daemon.

## Not supported (yet)

Allowances and charges, payment by card or cash, currencies other than EUR, VAT category `O`, UBL syntax, reading incoming
e-invoices.

## Tested with

- XRechnung XML for a standard invoice, a small business (§ 19 UStG), reverse charge, a credit note and a negative
  line: accepted by the official KoSIT validator with the XRechnung 3.0.2 configuration (EN 16931 CII rules 1.3.16,
  part of the test suite)
- ZUGFeRD PDFs from `invoice()`: PDF/A-3b valid according to veraPDF (part of the test suite), embedded XML checked
  against the XSD schema on every call

## Development

```sh
composer install
docker compose up -d
composer test          # tests that need Gotenberg or veraPDF are skipped if unreachable (GOTENBERG_URL, VERAPDF_URL)
```

The `schematron` test group validates XRechnung output with the KoSIT validator via horstoeko's `ZugferdKositValidator`.
It needs Java and downloads the validator and its XRechnung configuration from GitHub once (cached in the system temp
directory). Skip it with
`vendor/bin/phpunit --exclude-group schematron`.

## License

MIT, see [LICENSE](LICENSE).
