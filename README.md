# belegwerk

[![CI](https://github.com/ahmed1911/belegwerk/actions/workflows/ci.yml/badge.svg)](https://github.com/ahmed1911/belegwerk/actions/workflows/ci.yml)

PHP library that renders PDF documents from Twig templates via [Gotenberg](https://gotenberg.dev) and creates
e-invoices for Germany and the EU:

- **XRechnung 3.0** (UN/CEFACT CII syntax)
- **ZUGFeRD/Factur-X**: a PDF/A-3b with the invoice XML embedded (profile EN 16931)
- **Reading incoming e-invoices**: XRechnung and ZUGFeRD/Factur-X as CII or UBL XML, or as PDF
- **Invoice PDFs** and **any other document** (quotes, delivery notes, reminders) from your own templates

It is built for developers of invoicing software whose users design their own templates: templates run in Twig's
sandbox, and Gotenberg runs without JavaScript and without network access. belegwerk stores nothing. Your application
keeps templates and data and passes them in.

The e-invoice XML is written by [horstoeko/zugferd](https://github.com/horstoeko/zugferd).

## Installation

```sh
composer require ahmed1911/belegwerk
```

Requirements:

- PHP 8.2 or newer with the extensions curl, dom, fileinfo, simplexml and zip
- Gotenberg 8. This repository's [`compose.yaml`](compose.yaml) starts one on `127.0.0.1:3000`: `docker compose up -d`

Templates may come from untrusted users. Start Gotenberg with the flags from
[Security](docs/security.md#gotenberg-settings) (already set in `compose.yaml`) and keep it reachable only from your
application.

## Quick start

```php
use Belegwerk\Belegwerk;
use Belegwerk\Template;

// Master data once; merged into every call (see docs/api.md#defaults)
$belegwerk = new Belegwerk('http://127.0.0.1:3000', defaults: [
    'seller' => [
        'name' => 'Musterfirma GmbH',
        'address' => ['street' => 'Hauptstraße 1', 'zip' => '80331', 'city' => 'München'],
        'vat_id' => 'DE123456789',
        'email' => 'rechnung@musterfirma.de',
        'phone' => '+49 89 1234567',
    ],
    'bank' => ['holder' => 'Musterfirma GmbH', 'iban' => 'DE89 3704 0044 0532 0130 00'],
]);

$invoice = [
    'number' => 'RE-2026-0042',
    'date' => '2026-09-24',
    'due_date' => '2026-10-08',
    'buyer' => [
        'name' => 'Beispiel AG',
        'address' => ['street' => 'Ring 1', 'zip' => '10115', 'city' => 'Berlin'],
        'email' => 'einkauf@beispiel.example',
    ],
    'items' => [
        ['description' => 'Beratung', 'quantity' => 2, 'unit' => 'Std', 'unit_price_cents' => 9000],
    ],
];

// ZUGFeRD: a normal-looking PDF that carries the invoice XML
$pdf = $belegwerk->invoice($invoice, Template::fromDirectory(__DIR__ . '/templates/invoice'));

// XRechnung XML for German public authorities (needs buyer_reference as well)
$xml = $belegwerk->xrechnung($invoice + ['buyer_reference' => '04011000-12345-34']);
```

Both calls return the file content as a string. Templates and fields are described in the pages below;
[`examples/invoice.json`](examples/invoice.json) and [`examples/invoice-template/`](examples/invoice-template) are a
complete example.

## Documentation

- [API reference](docs/api.md): `Belegwerk`, `Template`, defaults, exceptions
- [Invoice data](docs/invoice-data.md): all fields, units, allowances and charges, attachments, VAT categories
- [Templates](docs/templates.md): directory layout, header and footer, context, template defaults, formatting, what
  templates may use, fonts and images
- [Reading incoming e-invoices](docs/reading-e-invoices.md): supported formats, the structure `read()` returns,
  attachments
- [Security](docs/security.md): Gotenberg settings, what the template sandbox does and does not limit, reading
  untrusted invoices
- [Validating e-invoices](docs/validation.md): what belegwerk checks, the KoSIT validator, what is not supported

## License

MIT, see [LICENSE](LICENSE).
