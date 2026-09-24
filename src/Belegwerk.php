<?php

declare(strict_types=1);

namespace Belegwerk;

use Belegwerk\Gotenberg\GotenbergClient;
use Belegwerk\Invoice\EInvoiceBuilder;
use Belegwerk\Invoice\InvoiceNormalizer;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;

/**
 * Renders PDF documents from Twig templates via Gotenberg and creates e-invoices
 * (XRechnung, ZUGFeRD/Factur-X) from one invoice array.
 */
final class Belegwerk
{
    public const PROFILE_EN16931 = 'en16931';
    public const PROFILE_XRECHNUNG = 'xrechnung';

    /** Templates are untrusted input: everything else, including method and property calls, is rejected. */
    private const ALLOWED_TAGS = ['if', 'for', 'set'];
    private const ALLOWED_FILTERS = [
        'escape', 'e', 'raw', 'upper', 'lower', 'capitalize', 'title', 'trim', 'nl2br', 'striptags', 'replace',
        'format', 'number_format', 'round', 'abs', 'date', 'default', 'length', 'first', 'last', 'join', 'split',
        'slice', 'keys', 'merge', 'batch', 'column', 'money',
    ];
    private const ALLOWED_FUNCTIONS = ['range', 'min', 'max', 'cycle'];

    private readonly GotenbergClient $gotenberg;
    private readonly InvoiceNormalizer $normalizer;
    private readonly EInvoiceBuilder $builder;

    public function __construct(string $gotenbergUrl = 'http://127.0.0.1:3000', int $timeout = 60)
    {
        $this->gotenberg = new GotenbergClient($gotenbergUrl, $timeout);
        $this->normalizer = new InvoiceNormalizer();
        $this->builder = new EInvoiceBuilder();
    }

    /**
     * Renders an invoice as PDF, by default with the built-in standard layout.
     */
    public function invoicePdf(array $invoice, ?Template $template = null): string
    {
        return $this->render($template ?? Template::standard(), $this->normalize($invoice, self::PROFILE_EN16931));
    }

    /**
     * Renders a ZUGFeRD/Factur-X invoice: a PDF/A-3b with the invoice XML embedded.
     */
    public function zugferd(array $invoice, ?Template $template = null, string $profile = self::PROFILE_EN16931): string
    {
        $invoice = $this->normalize($invoice, $profile);
        $document = $this->builder->build($invoice, $profile);

        return (new ZugferdDocumentPdfBuilder($document, $this->render($template ?? Template::standard(), $invoice)))
            ->setAdditionalCreatorTool('belegwerk')
            ->setAttachmentRelationshipTypeToAlternative()
            ->generateDocument()
            ->downloadString();
    }

    /**
     * Creates an XRechnung 3.0 in CII syntax.
     */
    public function xrechnung(array $invoice): string
    {
        return $this->builder->toXml($this->normalize($invoice, self::PROFILE_XRECHNUNG), self::PROFILE_XRECHNUNG);
    }

    /**
     * Renders any template with free-form data (arrays and scalars only), e.g. a quote or a reminder.
     */
    public function documentPdf(Template $template, array $data = []): string
    {
        return $this->render($template, $data);
    }

    private function normalize(array $invoice, string $profile): array
    {
        $invoice = $this->normalizer->normalize($invoice);
        if ($profile === self::PROFILE_XRECHNUNG) {
            $this->normalizer->assertXRechnung($invoice);
        }
        return $invoice;
    }

    private function render(Template $template, array $context): string
    {
        $sources = array_filter([
            'index.html' => $template->html,
            'header.html' => $template->header,
            'footer.html' => $template->footer,
        ], static fn (?string $source): bool => $source !== null);

        $twig = new Environment(new ArrayLoader($sources));
        $twig->addExtension(new SandboxExtension(
            new SecurityPolicy(self::ALLOWED_TAGS, self::ALLOWED_FILTERS, [], [], self::ALLOWED_FUNCTIONS),
            true,
        ));
        $twig->addFilter(new TwigFilter(
            'money',
            static fn (int|float $cents): string => number_format($cents / 100, 2, ',', '.') . ' €',
        ));

        $files = [];
        foreach (array_keys($sources) as $name) {
            $files[$name] = $twig->render($name, $context);
        }

        return $this->gotenberg->convertHtml($files, [
            'preferCssPageSize' => 'true',
            'printBackground' => 'true',
            'failOnResourceLoadingFailed' => 'true',
        ]);
    }
}
