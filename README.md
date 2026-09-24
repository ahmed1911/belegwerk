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

// Invoice: always ZUGFeRD, a normal-looking PDF that also carries the e-invoice XML
$pdf = $belegwerk->invoice([
    'number' => 'RE-2026-0042',
    'date' => '2026-09-24',
    'due_date' => '2026-10-08',
    'buyer' => ['name' => 'Beispiel AG', 'address' => ['street' => 'Ring 1', 'zip' => '10115', 'city' => 'Berlin']],
    'items' => [['description' => 'Beratung', 'quantity' => 2, 'unit' => 'Std', 'unit_price_cents' => 9000]],
]);

// XRechnung XML, e.g. for German public authorities
$xml = $belegwerk->xrechnung($invoice);

// Your own template, e.g. loaded from your database
$pdf = $belegwerk->invoice($invoice, new Template($html, footer: $footerHtml));

// Any other document with free-form data (defaults are merged in as well)
$pdf = $belegwerk->document(Template::fromDirectory('/path/to/quote'), ['number' => 'A-7', 'items' => $items]);
```

All methods return the file content as a string.

## Invoice data

`$invoice` is an array (for example decoded JSON). Amounts are **integer cents**, dates are `YYYY-MM-DD` strings.
belegwerk calculates line amounts (`round(quantity × unit_price_cents)`), the tax per category and rate, and the totals.
See [`examples/invoice.json`](examples/invoice.json).

| Field | Required | Description |
| --- | --- | --- |
| `number`, `date`, `due_date` | yes | Invoice number, issue date, due date |
| `type_code` | | `380` invoice (default), `381` credit note, `384` corrected invoice, `326` partial invoice, … |
| `currency` | | Only `EUR` for now |
| `service_date` or `period.start` + `period.end` | | Delivery date or billing period |
| `buyer_reference` | XRechnung | Buyer reference; the Leitweg-ID for German public authorities |
| `order_reference`, `payment_terms`, `note` | | Purchase order number, payment terms text, invoice note |
| `tax_exemption_reason` | for category `E` | E.g. "Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG." |
| `seller.name`, `seller.address` | yes | `address`: `street`, `zip`, `city`, `country` (ISO code, default `DE`), optional `additional` |
| `seller.vat_id` or `seller.tax_number` | one of them | Without a VAT ID, the tax number also serves as seller identifier in the XML |
| `seller.email`, `seller.phone` | XRechnung | Contact; `email` is also the electronic address |
| `seller.contact_name`, `seller.electronic_address` | | Contact person (default: seller name), electronic address (default: `email`) |
| `buyer.name`, `buyer.address.city` | yes | Street and zip are optional (zip is required for XRechnung) |
| `buyer.email` or `buyer.electronic_address` | XRechnung | Email, or `{"scheme": "0204", "id": "<Leitweg-ID>"}` |
| `buyer.vat_id` | for `AE`/`K` | Buyer VAT ID |
| `bank.holder`, `bank.iban` | yes | SEPA credit transfer; optional `bic`, `name` |
| `items[]` | yes | `description`, `unit_price_cents`, optional `quantity` (default 1, max. 3 decimals), `name`, `unit`, `unit_code`, `tax_rate` (default 19), `tax_category`, `custom` |
| `custom` | | Free-form data for templates, passed through unchanged |

**Units:** `unit` accepts common German names (`Stk`, `Std`, `Tag`, `Monat`, `pauschal`, `km`, `kg`, …) or a UN/ECE Rec. 20
code (`H87`, `HUR`, `DAY`, `LS`, …). Other units need a `unit_code`.

**VAT categories:** `S` (standard, rate > 0), `Z` (zero rated), `E` (exempt), `AE` (reverse charge),
`K` (intra-community supply), `G` (export). All but `S` require `tax_rate: 0`.

## Templates

A `Template` has up to three Twig sources, each a complete HTML document:

- `html` (required): the page content. Set page size and margins with CSS `@page`.
- `header`, `footer` (optional): repeated on every page by Chromium. Elements with the classes `pageNumber` and
  `totalPages` are filled in. Header and footer need their own inline styles and must fit into the page margins.

Create one from strings (`new Template($html, $header, $footer)`) or from a directory with `html.twig`, `header.twig`
and `footer.twig` (`Template::fromDirectory()`). [`templates/standard`](templates/standard) holds the built-in layout
and is a good starting point.

### Context

For `invoice()` the template receives the validated and calculated invoice:

| Variable | Content |
| --- | --- |
| `number`, `type_code`, `currency`, `date`, `due_date`, `service_date`, `period.start`, `period.end` | As passed in |
| `buyer_reference`, `order_reference`, `payment_terms`, `note`, `tax_exemption_reason` | As passed in, or `null` |
| `seller`, `buyer`, `bank` | As passed in; `bank.iban` without spaces |
| `lines[]` | `id`, `name`, `description`, `quantity` (e.g. `"6.5"`), `unit`, `unit_code`, `unit_price_cents`, `net_cents`, `tax_category`, `tax_rate`, `custom` |
| `tax_groups[]` | `category`, `rate`, `base_cents`, `amount_cents`, `exemption_reason` |
| `totals` | `net_cents`, `tax_cents`, `gross_cents` |
| `custom` | Your free-form data |

For `document()` the context is the array you pass, merged into the defaults. It may only contain arrays and scalars.

The standard template reads these optional `custom` fields: `logo` (data URI), `legal` (list of lines for the footer,
e.g. commercial register and managing directors), `occasion`, `project`, `orderer`, `section_title`.

### Formatting

```twig
{{ totals.gross_cents|money }}                  {# 1.062,37 € #}
{{ date|date('d.m.Y') }}                        {# 24.09.2026 #}
{{ line.quantity|replace({'.': ','}) }}         {# 6,5 #}
{{ bank.iban|split('', 4)|join(' ') }}          {# DE89 3704 0044 0532 0130 00 #}
```

### What templates may use

Templates run in Twig's sandbox with a strict policy:

- **Tags:** `if`, `for`, `set`
- **Filters:** `escape`/`e`, `raw`, `upper`, `lower`, `capitalize`, `title`, `trim`, `nl2br`, `striptags`, `replace`,
  `format`, `number_format`, `round`, `abs`, `date`, `default`, `length`, `first`, `last`, `join`, `split`, `slice`,
  `keys`, `merge`, `batch`, `column`, `money`
- **Tests** such as `is empty`, `is defined`, `is odd`
- Operators, including `~`, `in`, `??` and arithmetic

Everything else is rejected: all functions, `include`, `extends`, macros, and method or property calls.
Output is HTML-escaped automatically.

### Fonts and images

Embed them as `data:` URIs, since Gotenberg does not load anything from the network:

```html
<style>
  @font-face { font-family: Brand; src: url(data:font/woff2;base64,...) format('woff2'); }
  body { font-family: Brand, Arial, sans-serif; }
</style>
<img src="data:image/svg+xml;base64,...">
```

## Errors

| Exception | When |
| --- | --- |
| `Belegwerk\Invoice\ValidationException` | Invalid invoice data. `$e->errors` maps field paths such as `items.0.unit_price_cents` to messages. |
| `Twig\Error\Error` | Template syntax error or sandbox violation (`SecurityError`). `$e->getTemplateLine()` gives the line. |
| `Belegwerk\Gotenberg\GotenbergException` | Gotenberg is unreachable or cannot render the document. |

Tip: render a preview when a user saves a template, so errors show up there and not during invoicing.

## Security notes

- Templates cannot run PHP code, call methods or read files; see the allowed features above.
- Twig's sandbox does not limit CPU time or memory. Render with PHP's `max_execution_time` and `memory_limit` in place;
  Gotenberg limits a conversion with `--api-timeout` (default 30s).

## Not supported (yet)

Allowances and charges, prepayments, currencies other than EUR, VAT category `O`, UBL syntax, reading incoming
e-invoices.

## Tested with

- XRechnung XML for a standard invoice, a small business (§ 19 UStG), reverse charge, a credit note and a negative
  line: 0 errors with the official Schematron rules EN 16931 CII 1.3.16 and XRechnung 3.0.2
- ZUGFeRD PDFs from `invoice()`: PDF/A-3b valid according to veraPDF, embedded XML without Schematron errors

## Development

```sh
composer install
docker compose up -d
composer test          # tests that need Gotenberg are skipped if it is not reachable (GOTENBERG_URL)
```

## License

MIT, see [LICENSE](LICENSE).
