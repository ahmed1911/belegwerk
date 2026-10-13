# Templates

A `Template` holds the Twig sources of a document. Each source is a complete HTML document that Gotenberg turns into a
PDF:

- `html` (required): the page content.
- `header`, `footer` (optional): repeated on every page.

belegwerk ships no templates; they live in your project, see [Starting point](#starting-point). For the PHP
constructor and `fromDirectory()` see [api.md](api.md#template). Templates are treated as untrusted input; see
[security.md](security.md#templates).

## Directory layout

`Template::fromDirectory($path)` reads these files:

```
templates/invoice/
  html.twig            required
  header.twig          optional
  footer.twig          optional
  partials/*.twig      includes, e.g. {% include 'partials/styles.twig' %}
  assets/*             images and fonts, e.g. {{ 'logo.png'|asset }}
  defaults.json        values for optional fields, e.g. {"custom": {"project": null}}
```

- Without `html.twig` it throws an `InvalidArgumentException`. Other files in the directory are ignored.
- Partials: only `partials/<name>.twig` directly in that folder; the name may contain letters, digits, `_` and `-`.
  A template can include only the partials supplied with it; an unknown one throws a `Twig\Error\LoaderError`.
  `include` needs a fixed name such as `'partials/styles.twig'`. Partials may include other partials, but not
  themselves, directly or indirectly. Both are checked before rendering, also in branches that are not taken, and
  throw a `Twig\Sandbox\SecurityError`.
- Assets: every file directly in `assets/`; subfolders and dotfiles are skipped. A file with an unsupported type
  throws an `InvalidArgumentException`, so keep other files out of the folder. See [Fonts and images](#fonts-and-images).
- `defaults.json` must contain a JSON object. Invalid JSON or a list throws an `InvalidArgumentException`.
  See [Template defaults](#template-defaults).

For templates stored in a database, pass the same parts to the constructor. The keys are the file paths as above:

```php
new Template(
    html: $html,
    header: $header,
    footer: $footer,
    partials: ['partials/styles.twig' => $styles],
    assets: ['logo.png' => $bytes],
    defaults: ['custom' => ['project' => null]],
);
```

## Header and footer

- Set page size and margins with CSS `@page` in `html`, for example `@page { size: A4; margin: 5mm 18.5mm 50mm; }`.
- Chromium prints header and footer inside the page margins. They must fit there, or they are cut off or overlap the
  content.
- Header and footer do not share the styles of `html`. Give each its own inline `<style>` (font family, size, color)
  and its own horizontal padding, since the `@page` margins do not apply to them.
- Elements with the classes `pageNumber` and `totalPages` are filled in by Chromium:
  `Seite <span class="pageNumber"></span> von <span class="totalPages"></span>`.
- Images and fonts in header and footer need the `asset` filter or data URIs as well.
- Backgrounds are printed. External URLs are blocked, see [Security](security.md#gotenberg-settings).

## Context

Templates run with strict variables: an undefined variable or key throws a `Twig\Error\RuntimeError` instead of
printing nothing, so a typo like `{{ seler.name }}` cannot slip onto an invoice. A tag, filter or function outside the
[allowed list](#what-templates-may-use) throws a `Twig\Sandbox\SecurityError` before anything is rendered.

### invoice()

The template receives the validated and calculated invoice. The input fields are described in
[invoice-data.md](invoice-data.md#fields); here is what the template sees. Every key listed is always set (`null` if
not given), except where noted.

Conventions: amounts are integer cents (`*_cents`), dates are `YYYY-MM-DD` strings, and decimals (`quantity`,
`tax_rate`, `percent`) are strings with `.` as separator and without trailing zeros, such as `"6.5"`, `"19"` or `"7"`.
Strings are trimmed; empty strings become `null`.

| Variable | Content |
| --- | --- |
| `number`, `date`, `due_date` | As passed in |
| `type_code` | Invoice type code, `"380"` by default |
| `currency` | `"EUR"` |
| `service_date`, `buyer_reference`, `order_reference`, `despatch_reference`, `payment_terms`, `note`, `tax_exemption_reason` | As passed in, or `null` |
| `period` | `start`, `end`; or `null` |
| `preceding_invoice` | `number`, `date` (may be `null`); or `null` |
| `seller`, `buyer` | [Party](#party) |
| `delivery` | `name`, `address` ([address](#party), or `null`), `location_id` (`scheme`, which may be `null`, and `id`; or `null`); or `null` |
| `bank` | `holder`, `iban` (uppercase, no spaces), `bic`, `name`; `null` if omitted with a direct debit |
| `direct_debit` | `mandate_reference`, `creditor_id`, `iban`; or `null` |
| `cash_discount` | `days`, `percent`, `date` (invoice date plus days), `amount_cents` (percentage of `totals.due_cents`), `payable_cents`; or `null` |
| `lines` | List of [lines](#lines) |
| `allowances`, `charges` | Lists of [invoice-level adjustments](#allowances-and-charges); empty if none |
| `tax_groups` | One entry per VAT category and rate: `category`, `rate`, `base_cents`, `amount_cents`, `exemption_reason` |
| `totals` | See below |
| `attachments` | List of `id`, `description`, `filename`, `mime`, `size` (bytes), `url`; without the content |
| `payment_qr` | GiroCode (EPC QR code) as an SVG data URI for `<img src>`; `null` if there is nothing to transfer |
| `custom` | Your free-form data (`{}` if none) |

`totals`:

| Key | Content |
| --- | --- |
| `line_total_cents` | Sum of `lines[].net_cents` |
| `allowance_total_cents`, `charge_total_cents` | Sum of the invoice-level allowances and charges |
| `net_cents` | Tax base: line total minus allowances plus charges |
| `tax_cents` | Sum of `tax_groups[].amount_cents` |
| `gross_cents` | `net_cents` plus `tax_cents` |
| `paid_cents` | Already paid, `0` if not given |
| `due_cents` | `gross_cents` minus `paid_cents` |

`tax_groups[].exemption_reason` is `null` for categories `S` and `Z`. For the others it is `tax_exemption_reason` or,
for `AE`, `K` and `G`, a German default text. `attachments[].filename` carries the extension of the detected file type;
`filename`, `mime` and `size` are `null` for a link without file. `payment_qr` is `null` with a direct debit and when
`totals.due_cents` is zero or negative. The QR code encodes the amount due, so it ignores a cash discount.

#### Party

`seller` and `buyer` have the same keys, except that `tax_number` and `phone` exist only on `seller`:
`name`, `address`, `vat_id`, `tax_number`, `email`, `phone`, `contact_name`, `electronic_address`.

- `address`: `street`, `additional`, `zip`, `city`, `country`. `country` is an uppercase ISO code, `"DE"` by default.
  `street` and `zip` are always set for the seller and may be `null` for the buyer.
- `contact_name` of the seller falls back to `name`.
- `electronic_address` is `scheme` and `id`, or `null`. An `email` without an explicit address becomes
  `{scheme: "EM", id: <email>}`.

Reading `buyer.phone` throws, because the buyer has no such key.

#### Lines

Each entry of `lines` has `id` (`"1"`, `"2"`, ...), `name`, `description`, `quantity` (up to 6 decimals), `unit` (display
label such as `Std.`), `unit_code`, `unit_price_cents`, `subtotal_cents` (quantity times price), `allowances`,
`charges`, `net_cents` (subtotal minus allowances plus charges), `tax_category`, `tax_rate` and `custom`. Without a
`name`, it is the first line of the description, cut at 200 characters.

Line-level `allowances` and `charges` have `reason`, `reason_code`, `percent`, `base_cents` and `amount_cents`.
`percent` and `base_cents` (the line's `subtotal_cents`) are `null` for a fixed amount. `amount_cents` is always
positive; the list tells whether it is subtracted or added.

#### Allowances and charges

Invoice-level `allowances` and `charges` have the same keys plus `tax_category` and `tax_rate`. A percentage without
`tax_rate` applies to every tax group of the lines and is split into one entry per group, with the group's
`base_cents`. `totals.allowance_total_cents` and `charge_total_cents` sum all entries. How to pass them:
[invoice-data.md](invoice-data.md#allowances-and-charges).

### document()

The context is the `$data` of `document()`, merged over the defaults, see [api.md](api.md#document). There are no
invoice fields and no `payment_qr`.

## Template defaults

Template defaults fill in optional data, so the template works whether or not a field is set. They come from
`defaults.json` or the `defaults:` constructor argument and have the lowest priority: the `defaults` of `Belegwerk`
and the data of the call override them. Merge order and rules (`null`, empty arrays, lists) are in
[api.md](api.md#defaults).

```json
{ "custom": { "time_range": null, "section_title": "Leistungen" } }
```

- `line` defaults apply to each entry of the context's `lines`, for optional per-line fields. They are merged below
  the line, so `lines[].custom` can declare keys, too:

  ```json
  { "custom": { "time_range": null }, "line": { "custom": { "time_range": null } } }
  ```

- Template defaults only reach the template. They never change the e-invoice XML, so a user-supplied template cannot
  alter invoice data. Every invoice field is always set in the context (`null` if empty), and that `null` overrides a
  template default, so a default for e.g. `order_reference` never takes effect. Defaults work for `custom`,
  `line.custom` and keys the context does not contain.

Without defaults, read optional data with `default` or `is defined`:

```twig
{% if custom.project|default %}Projekt: {{ custom.project }}{% endif %}
{{ custom.section_title|default('Leistungen') }}
{% if not direct_debit %}IBAN {{ bank.iban }}{% endif %}   {# bank may be null with a direct debit #}
```

## Formatting

Output is HTML-escaped automatically. Use `nl2br` for multi-line text; it escapes first.

```twig
{{ totals.gross_cents|money }}                  {# 1.062,37 € #}
{{ line.net_cents|money }}                      {# -1.062,37 € for a negative amount #}
{{ date|date('d.m.Y') }}                        {# 24.09.2026 #}
{{ line.quantity|replace({'.': ','}) }}         {# 6,5 #}
{{ bank.iban|split('', 4)|join(' ') }}          {# DE89 3704 0044 0532 0130 00 #}
```

`money` does not depend on the server locale and has no currency parameter, since only EUR is supported.

## What templates may use

Templates run in Twig's sandbox with a strict policy:

- **Tags:** `if` (with `elseif`, `else`), `for`, `set`, `include` (partials only, see
  [Directory layout](#directory-layout))
- **Filters:** `escape`/`e`, `raw`, `upper`, `lower`, `capitalize`, `title`, `trim`, `nl2br`, `striptags`, `replace`,
  `format`, `number_format`, `round`, `abs`, `date`, `default`, `length`, `first`, `last`, `join`, `split`, `slice`,
  `keys`, `merge`, `batch`, `column`, `money`, `asset`
- **Functions:** none
- **Methods and properties of objects:** none
- **Tests** such as `is empty`, `is defined`, `is odd`, and all **operators**, including `~`, `in`, `??` and arithmetic,
  are not restricted.

Everything else is rejected, for example `extends`, `macro`, `import`, `with`, `apply`, `sort`, `map`, `filter`,
`range` and `constant`. `loop` inside `for` works. `money` and `asset` are added by belegwerk.

## Fonts and images

Put them into the template's `assets/` folder (or pass `assets:` to the constructor) and use the `asset` filter. It
embeds the file as a `data:` URI, so Gotenberg needs no network access. This works in `html`, `header` and `footer`,
in `<img>` as well as in CSS:

```html
<style>
  @font-face { font-family: Brand; src: url({{ 'Brand.woff2'|asset }}) format('woff2'); }
  body { font-family: Brand, Arial, sans-serif; }
</style>
<img src="{{ 'logo.png'|asset }}">
```

- Supported extensions: png, jpg, jpeg, gif, webp, svg, woff2, woff, ttf, otf.
- Asset names are plain file names without a path: letters, digits, `_`, `-` and `.`, not starting with a dot, ending
  in a supported extension (case-insensitive). The lookup by name is case-sensitive.
- An invalid name or type in the constructor throws an `InvalidArgumentException`. An unknown name in a template
  throws a `Twig\Error\RuntimeError` that lists the available assets.
- Every use of `|asset` inlines the whole file again. A font used in `html`, `header` and `footer` is embedded three
  times, so use it only where needed.
- Images that change per customer, such as a tenant's logo, can be passed in the data as a data URI, e.g. as
  `custom.logo` in the `Belegwerk` defaults or the call data:

```html
{% if custom.logo %}<img src="{{ custom.logo }}">{% endif %}
```

## Starting point

[`examples/invoice-template`](../examples/invoice-template) is a complete German invoice layout (`html.twig`,
`footer.twig`, no header) with GiroCode, deliver-to address, attachment list and exemption reasons. The optional
`custom` fields it reads are declared in its `defaults.json`: `logo`, `legal` (footer lines such as the commercial
register), `occasion`, `project`, `orderer` and `section_title`. Copy it and adapt texts and layout;
[`examples/invoice.json`](../examples/invoice.json) is sample data for it.
