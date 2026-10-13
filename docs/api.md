# API reference

Two classes make up the public API: `Belegwerk\Belegwerk` does the work, `Belegwerk\Template` holds the Twig sources.
Everything is stateless; belegwerk stores nothing between calls. Classes marked `@internal` may change without notice.

## Belegwerk

```php
new Belegwerk(
    string $gotenbergUrl = 'http://127.0.0.1:3000',
    array $defaults = [],
    int $timeout = 60,
)
```

| Parameter | Description |
| --- | --- |
| `gotenbergUrl` | Base URL of Gotenberg 8, with credentials if basic auth is on ([Security](security.md#gotenberg-settings)). A trailing slash is ignored. |
| `defaults` | Data merged into every `invoice()`, `xrechnung()` and `document()` call, e.g. `seller`, `bank` and `custom.logo`. See [Defaults](#defaults). |
| `timeout` | Seconds PHP waits for Gotenberg per request (default 60). |

The constructor does not contact Gotenberg. An unreachable server shows up on the first rendering call.

```php
$belegwerk = new Belegwerk('http://gotenberg:3000', defaults: ['seller' => [...], 'bank' => [...]], timeout: 30);
```

### invoice()

```php
invoice(array $invoice, Template $template): string
```

Renders a ZUGFeRD/Factur-X invoice (profile EN 16931): a PDF/A-3b with the invoice XML embedded. Returns the PDF as
a string.

The invoice fields are described in [Invoice data](invoice-data.md#fields). `defaults` are merged in first. Totals,
tax groups and cash discount are calculated from the line items; you never pass them in. The template receives the
validated invoice as context ([Templates: Context](templates.md#context)). It cannot change the XML, not even through
[template defaults](templates.md#template-defaults).

Invalid data throws a `ValidationException` before anything is rendered. Beyond that, `invoice()` throws
`Twig\Error\Error` for template errors and `GotenbergException` for rendering failures; see [Errors](#errors).

### xrechnung()

```php
xrechnung(array $invoice): string
```

Creates an XRechnung 3.0 in CII syntax and returns the XML as a string. No template and no Gotenberg are involved.

Merges `defaults`, validates exactly like `invoice()` and then checks the
[XRechnung requirements](invoice-data.md#xrechnung-requirements). Throws `ValidationException`.

### document()

```php
document(Template $template, array $data = []): string
```

Renders any template with free-form data, e.g. a quote, a delivery note or a reminder, and returns the PDF as a string.

`$data` is merged over `defaults` and passed to the template as is. It is not validated and nothing is calculated or
embedded. It may only contain arrays and scalars, because templates cannot call methods. Apart from `ValidationException`
it throws the same exceptions as `invoice()`.

```php
$pdf = $belegwerk->document(Template::fromDirectory('/path/to/quote'), ['number' => 'A-7', 'items' => $items]);
```

### read()

```php
read(string $content): array
```

Reads an incoming e-invoice (CII or UBL XML, or a PDF with embedded XML) and returns it as an array with the amounts
the invoice states, see [Reading e-invoices](reading-e-invoices.md). It neither validates nor recalculates. Throws
`ReadException` if the content is no e-invoice.

## Template

A `Template` holds the Twig sources of one document. All parameters are public readonly properties of the same name.

```php
new Template(
    string $html,
    ?string $header = null,
    ?string $footer = null,
    array $partials = [],
    array $assets = [],
    array $defaults = [],
)
```

| Parameter | Description |
| --- | --- |
| `html` | Twig source of the page: a complete HTML document. Required. |
| `header`, `footer` | Twig source of the header and footer repeated on every page. Optional. See [Templates: Header and footer](templates.md#header-and-footer). |
| `partials` | Includable Twig sources, keyed `partials/<name>.twig`. |
| `assets` | Images and fonts, keyed by file name such as `logo.png`, with the file content as value; see [Templates: Fonts and images](templates.md#fonts-and-images). |
| `defaults` | Data the template brings itself, merged below the data you pass. Must be an object (string keys), not a list. See [Defaults](#defaults). |

The constructor throws `InvalidArgumentException` for a partial or asset with an invalid name, an asset with an
unsupported type and `defaults` that are a list. It does not parse the Twig sources; syntax errors surface when a
document is rendered. Example: [Templates: Directory layout](templates.md#directory-layout).

### Template::fromDirectory()

```php
Template::fromDirectory(string $directory): Template
```

Reads `html.twig`, `header.twig`, `footer.twig`, `partials/*.twig`, `assets/*` and `defaults.json` into the
constructor parameters of the same name; see [Templates: Directory layout](templates.md#directory-layout). It throws
`InvalidArgumentException` if `html.twig` is missing, `defaults.json` is no JSON object, or a partial or asset breaks
the constructor's rules.

## Defaults

Both `Belegwerk` and `Template` take `defaults`. They are merged, lowest priority first:

1. `defaults` of the `Template`
2. `defaults` of `Belegwerk`
3. the data passed to the call (`$invoice` or `$data`)

| Method | `Belegwerk` defaults | `Template` defaults |
| --- | --- | --- |
| `invoice()` | merged into the invoice, so they reach the XML and the template | template context only, never the XML |
| `xrechnung()` | merged into the invoice | none (no template) |
| `document()` | merged into the data | template context |
| `read()` | not used | not used |

How the merge works:

- Arrays are merged key by key at every depth, so `['seller' => ['phone' => '123']]` in a call adds a phone number to
  the default seller instead of replacing it.
- A value in the call replaces the default, including `null`. `'phone' => null` removes a default phone number.
- An empty array in the call keeps the default.
- Lists are merged by position, not replaced and not appended. A default `['a', 'b']` and a call `['x']` give
  `['x', 'b']`. Keep lists such as `items` out of the defaults.
- Template defaults have two special cases, the `line` key and invoice fields that are always set (`null` wins over a
  default); see [Templates: Template defaults](templates.md#template-defaults).

## Errors

| Exception | Thrown by | When |
| --- | --- | --- |
| `Belegwerk\Invoice\ValidationException` | `invoice()`, `xrechnung()` | Invalid invoice data. |
| `Twig\Error\Error` | `invoice()`, `document()` | Template syntax error, undefined variable or key, unknown asset, sandbox violation. |
| `Belegwerk\Gotenberg\GotenbergException` | `invoice()`, `document()` | Gotenberg is unreachable, times out or cannot render the document, e.g. because a relative URL points to a file that was not sent (`<img src="logo.png">` without the `asset` filter). |
| `Belegwerk\Invoice\ReadException` | `read()` | The content is no e-invoice; see [Reading e-invoices](reading-e-invoices.md#errors). |
| `InvalidArgumentException` | `new Template()`, `Template::fromDirectory()` | Invalid partials, assets or defaults, or no `html.twig`. |
| `RuntimeException` | `invoice()`, `xrechnung()` | The generated XML violates the XSD schema. A safety net; valid input should never trigger it. |

`ValidationException` extends `InvalidArgumentException`, `GotenbergException` and `ReadException` extend
`RuntimeException`.

### ValidationException

`$e->errors` is an `array<string, string>` that maps field paths to messages. Nested fields use dots, list entries
their index:

```php
try {
    $belegwerk->invoice($invoice, $template);
} catch (ValidationException $e) {
    // ['due_date' => '...', 'items.0.unit_price_cents' => '...', 'buyer.address.city' => '...']
    foreach ($e->errors as $path => $message) {
        echo "{$path}: {$message}\n";
    }
}
```

`getMessage()` lists all errors in one string. Checks that need the calculated totals run only after the field checks
passed, e.g. `paid_cents` above the invoice total, a missing `tax_exemption_reason` for category E or a missing
`seller.vat_id` for category AE, K or G. They appear in a second exception.

### Twig\Error\Error

Subclasses: `SyntaxError`, `RuntimeError` (undefined variable or key, unknown asset), `LoaderError` (unknown partial)
and `Twig\Sandbox\SecurityError` (outside [what templates may use](templates.md#what-templates-may-use)).
`$e->getTemplateLine()` returns the line, `$e->getSourceContext()?->getName()` the source: `index.html` for `html`,
`header.html`, `footer.html` or the partial's name.

### Preview on save

Render a preview when a user saves a template, with example data, so errors show up there and not during invoicing.
URLs blocked by Gotenberg raise no error ([Security](security.md#gotenberg-settings)), so look at the preview PDF too:

```php
try {
    $belegwerk->invoice(json_decode(file_get_contents(__DIR__ . '/examples/invoice.json'), true), $template);
} catch (\Twig\Error\Error $e) {
    $message = "Line {$e->getTemplateLine()}: {$e->getRawMessage()}";
}
```

The preview data must cover the fields the template reads. `document()` templates need example data of their own.
