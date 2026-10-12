<?php

declare(strict_types=1);

namespace Belegwerk;

use Belegwerk\Gotenberg\GotenbergClient;
use Belegwerk\Invoice\EInvoiceBuilder;
use Belegwerk\Invoice\EInvoiceReader;
use Belegwerk\Invoice\InvoiceNormalizer;
use Belegwerk\Invoice\PaymentQrCode;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdProfiles;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\Sandbox;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;

/**
 * Renders PDF documents from Twig templates via Gotenberg and creates e-invoices
 * (XRechnung, ZUGFeRD/Factur-X) from one invoice array.
 *
 * InvoiceData is the invoice array, for PHPStan and IDEs; import it with
 * `@phpstan-import-type InvoiceData from Belegwerk`. seller and bank are optional because they usually come from
 * the defaults; tax_number and phone apply to the seller only.
 *
 * @phpstan-type Address array{street?: string, additional?: string, zip?: string, city: string, country?: string}
 * @phpstan-type Party array{
 *     name: string,
 *     address: Address,
 *     vat_id?: string,
 *     tax_number?: string,
 *     email?: string,
 *     phone?: string,
 *     contact_name?: string,
 *     electronic_address?: string|array{scheme: string, id: string},
 * }
 * @phpstan-type Adjustment array{reason: string, amount_cents?: int, percent?: int|float|numeric-string, reason_code?: string}
 * @phpstan-type InvoiceAdjustment array{
 *     reason: string,
 *     amount_cents?: int,
 *     percent?: int|float|numeric-string,
 *     reason_code?: string,
 *     tax_rate?: int|float|numeric-string,
 *     tax_category?: 'S'|'Z'|'E'|'AE'|'K'|'G',
 * }
 * @phpstan-type Item array{
 *     description: string,
 *     unit_price_cents: int,
 *     quantity?: int|float|numeric-string,
 *     name?: string,
 *     unit?: string,
 *     unit_code?: string,
 *     tax_rate?: int|float|numeric-string,
 *     tax_category?: 'S'|'Z'|'E'|'AE'|'K'|'G',
 *     allowances?: list<Adjustment>,
 *     charges?: list<Adjustment>,
 *     custom?: array<string, mixed>,
 * }
 * @phpstan-type InvoiceData array{
 *     number: string,
 *     date: string,
 *     due_date: string,
 *     buyer: Party,
 *     items: non-empty-list<Item>,
 *     seller?: Party,
 *     bank?: array{holder: string, iban: string, bic?: string, name?: string},
 *     direct_debit?: array{mandate_reference: string, creditor_id: string, iban: string},
 *     type_code?: '326'|'380'|'381'|'384'|'389'|'875'|'876'|'877',
 *     currency?: 'EUR',
 *     service_date?: string,
 *     period?: array{start: string, end: string},
 *     buyer_reference?: string,
 *     order_reference?: string,
 *     preceding_invoice?: array{number: string, date?: string},
 *     payment_terms?: string,
 *     note?: string,
 *     tax_exemption_reason?: string,
 *     cash_discount?: array{days: int, percent: int|float|numeric-string},
 *     paid_cents?: int,
 *     allowances?: list<InvoiceAdjustment>,
 *     charges?: list<InvoiceAdjustment>,
 *     custom?: array<string, mixed>,
 * }
 */
final class Belegwerk
{
    /** Templates are untrusted input: other tags, filters, all functions and method or property calls are rejected. */
    private const ALLOWED_TAGS = ['if', 'for', 'set', 'include'];
    private const ALLOWED_FILTERS = [
        'escape', 'e', 'raw', 'upper', 'lower', 'capitalize', 'title', 'trim', 'nl2br', 'striptags', 'replace',
        'format', 'number_format', 'round', 'abs', 'date', 'default', 'length', 'first', 'last', 'join', 'split',
        'slice', 'keys', 'merge', 'batch', 'column', 'money', 'asset',
    ];

    private readonly GotenbergClient $gotenberg;
    private readonly InvoiceNormalizer $normalizer;
    private readonly EInvoiceBuilder $builder;

    /**
     * @param array $defaults  data merged into every invoice and document, e.g. seller, bank and custom.logo;
     *                         the data passed to a call overrides it field by field
     */
    public function __construct(
        string $gotenbergUrl = 'http://127.0.0.1:3000',
        private readonly array $defaults = [],
        int $timeout = 60,
    ) {
        $this->gotenberg = new GotenbergClient($gotenbergUrl, $timeout);
        $this->normalizer = new InvoiceNormalizer();
        $this->builder = new EInvoiceBuilder();
    }

    /**
     * Renders an invoice as ZUGFeRD/Factur-X (EN 16931): a PDF/A-3b with the invoice XML embedded.
     *
     * @param InvoiceData $invoice
     */
    public function invoice(array $invoice, Template $template): string
    {
        $invoice = $this->normalized($invoice);
        $xml = $this->builder->toXml($invoice, ZugferdProfiles::PROFILE_EN16931);

        $context = $invoice + ['payment_qr' => PaymentQrCode::dataUri($invoice)];

        return (new ZugferdDocumentPdfMerger($xml, $this->render($template, $context)))
            ->setAdditionalCreatorTool('belegwerk')
            ->setAttachmentRelationshipTypeToAlternative()
            ->generateDocument()
            ->downloadString();
    }

    /**
     * Creates an XRechnung 3.0 in CII syntax, e.g. for German public authorities.
     *
     * @param InvoiceData $invoice
     */
    public function xrechnung(array $invoice): string
    {
        $invoice = $this->normalized($invoice);
        $this->normalizer->assertXRechnung($invoice);
        return $this->builder->toXml($invoice, ZugferdProfiles::PROFILE_XRECHNUNG_3);
    }

    /**
     * Reads an incoming e-invoice: XRechnung or ZUGFeRD/Factur-X, as CII or UBL XML or as a PDF with embedded XML.
     * Returns the invoice with the amounts it states, in the keys of the template context; see the README.
     *
     * @throws Invoice\ReadException if the content is no e-invoice
     */
    public function read(string $content): array
    {
        return (new EInvoiceReader())->read($content);
    }

    /**
     * Renders any template with free-form data (arrays and scalars only), e.g. a quote or a reminder.
     */
    public function document(Template $template, array $data = []): string
    {
        return $this->render($template, array_replace_recursive($this->defaults, $data));
    }

    private function normalized(array $invoice): array
    {
        return $this->normalizer->normalize(array_replace_recursive($this->defaults, $invoice));
    }

    /**
     * The template's own defaults only reach the template context. They never change the invoice XML, because
     * templates may come from untrusted users. Their `line` key applies to each entry of `lines`.
     */
    private function render(Template $template, array $context): string
    {
        $lineDefaults = $template->defaults['line'] ?? [];
        $context = array_replace_recursive(array_diff_key($template->defaults, ['line' => true]), $context);
        if (is_array($lineDefaults) && is_array($context['lines'] ?? null)) {
            $context['lines'] = array_map(
                static fn (mixed $line): mixed => is_array($line) ? array_replace_recursive($lineDefaults, $line) : $line,
                $context['lines'],
            );
        }
        $sources = array_filter([
            'index.html' => $template->html,
            'header.html' => $template->header,
            'footer.html' => $template->footer,
        ], static fn (?string $source): bool => $source !== null);

        // Partials are only loadable via include; they are not rendered or uploaded as pages.
        // Strict: a misspelled variable fails instead of silently printing nothing on the invoice.
        $twig = new Environment(new ArrayLoader($sources + $template->partials), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter(
            'money',
            static fn (int|float $cents): string => number_format($cents / 100, 2, ',', '.') . ' €',
        ));
        $twig->addFilter(new TwigFilter('asset', $template->assetUri(...)));
        $policy = new SecurityPolicy(self::ALLOWED_TAGS, self::ALLOWED_FILTERS);
        $policy->setStrict(true);
        $sandbox = new Sandbox($twig, $policy);

        $files = [];
        foreach (array_keys($sources) as $name) {
            $files[$name] = $sandbox->render($name, $context);
        }

        return $this->gotenberg->convertHtml($files);
    }
}
