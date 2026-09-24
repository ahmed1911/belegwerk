<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use horstoeko\zugferd\ZugferdDocumentPdfBuilder;

/**
 * Erzeugt ZUGFeRD 2.x / Factur-X: Gotenberg rendert das sichtbare PDF, horstoeko/zugferd bettet das
 * CII-XML ein und schreibt PDF/A-3 samt Factur-X-XMP-Metadaten.
 */
final class ZugferdService
{
    public function __construct(
        private readonly InvoicePdfService $pdfService = new InvoicePdfService(),
        private readonly EInvoiceBuilder $builder = new EInvoiceBuilder(),
        private readonly InvoiceNormalizer $normalizer = new InvoiceNormalizer(),
    ) {
    }

    /** @param array $invoice  normalisierte Rechnung (InvoiceNormalizer::normalize) */
    public function render(array $invoice, string $profile = EInvoiceBuilder::PROFILE_EN16931): string
    {
        $document = $this->builder->build($invoice, $profile);
        $pdf = $this->pdfService->renderPdf($this->normalizer->toPdfData($invoice));

        return (new ZugferdDocumentPdfBuilder($document, $pdf))
            ->setAdditionalCreatorTool('belegwerk')
            ->setAttachmentRelationshipTypeToAlternative()
            ->generateDocument()
            ->downloadString();
    }
}
