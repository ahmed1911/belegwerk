<?php

declare(strict_types=1);

namespace Belegwerk;

use Belegwerk\Gotenberg\GotenbergClient;
use Belegwerk\Invoice\EInvoiceBuilder;
use Belegwerk\Invoice\InvoiceNormalizer;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\Sandbox;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;

/**
 * Renders PDF documents from Twig templates via Gotenberg and creates e-invoices
 * (XRechnung, ZUGFeRD/Factur-X) from one invoice array.
 */
final class Belegwerk
{
    /** Templates are untrusted input: other tags, filters, all functions and method or property calls are rejected. */
    private const ALLOWED_TAGS = ['if', 'for', 'set'];
    private const ALLOWED_FILTERS = [
        'escape', 'e', 'raw', 'upper', 'lower', 'capitalize', 'title', 'trim', 'nl2br', 'striptags', 'replace',
        'format', 'number_format', 'round', 'abs', 'date', 'default', 'length', 'first', 'last', 'join', 'split',
        'slice', 'keys', 'merge', 'batch', 'column', 'money',
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
     * Without a template, the built-in standard layout is used.
     */
    public function invoice(array $invoice, ?Template $template = null): string
    {
        $invoice = $this->normalizer->normalize($this->withDefaults($invoice));
        $document = $this->builder->build($invoice, EInvoiceBuilder::PROFILE_EN16931);

        return (new ZugferdDocumentPdfBuilder($document, $this->render($template ?? Template::standard(), $invoice)))
            ->setAdditionalCreatorTool('belegwerk')
            ->setAttachmentRelationshipTypeToAlternative()
            ->generateDocument()
            ->downloadString();
    }

    /**
     * Creates an XRechnung 3.0 in CII syntax, e.g. for German public authorities.
     */
    public function xrechnung(array $invoice): string
    {
        $invoice = $this->normalizer->normalize($this->withDefaults($invoice));
        $this->normalizer->assertXRechnung($invoice);
        return $this->builder->toXml($invoice, EInvoiceBuilder::PROFILE_XRECHNUNG);
    }

    /**
     * Renders any template with free-form data (arrays and scalars only), e.g. a quote or a reminder.
     */
    public function document(Template $template, array $data = []): string
    {
        return $this->render($template, $this->withDefaults($data));
    }

    private function withDefaults(array $data): array
    {
        return array_replace_recursive($this->defaults, $data);
    }

    private function render(Template $template, array $context): string
    {
        $sources = array_filter([
            'index.html' => $template->html,
            'header.html' => $template->header,
            'footer.html' => $template->footer,
        ], static fn (?string $source): bool => $source !== null);

        $twig = new Environment(new ArrayLoader($sources));
        $twig->addFilter(new TwigFilter(
            'money',
            static fn (int|float $cents): string => number_format($cents / 100, 2, ',', '.') . ' €',
        ));
        $policy = new SecurityPolicy(self::ALLOWED_TAGS, self::ALLOWED_FILTERS);
        $policy->setStrict(true);
        $sandbox = new Sandbox($twig, $policy);

        $files = [];
        foreach (array_keys($sources) as $name) {
            $files[$name] = $sandbox->render($name, $context);
        }

        return $this->gotenberg->convertHtml($files, [
            'preferCssPageSize' => 'true',
            'printBackground' => 'true',
            'failOnResourceLoadingFailed' => 'true',
        ]);
    }
}
