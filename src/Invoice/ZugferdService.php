<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Erzeugt ZUGFeRD 2.x / Factur-X: ein PDF/A-3b mit eingebetteter CII-Rechnung und passenden XMP-Metadaten.
 */
final class ZugferdService
{
    private const PROFILES = [
        CiiInvoiceWriter::PROFILE_EN16931 => ['file' => 'factur-x.xml', 'level' => 'EN 16931'],
        CiiInvoiceWriter::PROFILE_XRECHNUNG => ['file' => 'xrechnung.xml', 'level' => 'XRECHNUNG'],
    ];

    public function __construct(
        private readonly InvoicePdfService $pdfService = new InvoicePdfService(),
        private readonly CiiInvoiceWriter $writer = new CiiInvoiceWriter(),
        private readonly InvoiceNormalizer $normalizer = new InvoiceNormalizer(),
    ) {
    }

    /** @param array $invoice  normalisierte Rechnung (InvoiceNormalizer::normalize) */
    public function render(array $invoice, string $profile = CiiInvoiceWriter::PROFILE_EN16931): string
    {
        $config = self::PROFILES[$profile] ?? throw new \InvalidArgumentException("Unbekanntes Profil: {$profile}");
        $xml = $this->writer->write($invoice, $profile);

        $title = 'Rechnung ' . $invoice['number'];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $metadata = [
            'Title' => $title,
            'Author' => $invoice['seller']['name'],
            'Producer' => 'belegwerk',
            'Creator' => 'belegwerk',
            'CreationDate' => $now->format('Y:m:d H:i:sP'),
            'ModDate' => $now->format('Y:m:d H:i:sP'),
            'XMP' => $this->xmp($title, $invoice['seller']['name'], $now, $config['file'], $config['level']),
        ];

        return $this->pdfService->renderPdf($this->normalizer->toPdfData($invoice), [
            'pdfa' => 'PDF/A-3b',
            'embedsMetadata' => json_encode([$config['file'] => ['mimeType' => 'text/xml', 'relationship' => 'Alternative']], JSON_THROW_ON_ERROR),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], [
            ['name' => $config['file'], 'mime' => 'text/xml', 'content' => $xml],
        ]);
    }

    /**
     * XMP-Paket inkl. PDF/A-Extension-Schema für den Factur-X-Namespace (einzeilig, da Gotenberg
     * Steuerzeichen in Metadatenwerten ablehnt).
     */
    private function xmp(string $title, string $author, DateTimeImmutable $date, string $file, string $level): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $timestamp = $date->format('Y-m-d\TH:i:sP');
        $property = static fn (string $name, string $type, string $description): string => '<rdf:li rdf:parseType="Resource">'
            . "<pdfaProperty:name>{$name}</pdfaProperty:name><pdfaProperty:valueType>{$type}</pdfaProperty:valueType>"
            . "<pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>{$description}</pdfaProperty:description></rdf:li>";

        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">'
            . '<pdfaid:part>3</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:format>application/pdf</dc:format>'
            . '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $e($title) . '</rdf:li></rdf:Alt></dc:title>'
            . '<dc:creator><rdf:Seq><rdf:li>' . $e($author) . '</rdf:li></rdf:Seq></dc:creator></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/"><pdf:Producer>belegwerk</pdf:Producer></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">'
            . "<xmp:CreatorTool>belegwerk</xmp:CreatorTool><xmp:CreateDate>{$timestamp}</xmp:CreateDate>"
            . "<xmp:ModifyDate>{$timestamp}</xmp:ModifyDate><xmp:MetadataDate>{$timestamp}</xmp:MetadataDate></rdf:Description>"
            . '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"'
            . ' xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
            . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>fx</pdfaSchema:prefix><pdfaSchema:property><rdf:Seq>'
            . $property('DocumentFileName', 'Text', 'The name of the embedded XML document')
            . $property('DocumentType', 'Text', 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER')
            . $property('Version', 'Text', 'The actual version of the standard applying to the embedded XML document')
            . $property('ConformanceLevel', 'Text', 'The conformance level of the embedded XML document')
            . '</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">'
            . "<fx:DocumentType>INVOICE</fx:DocumentType><fx:DocumentFileName>{$file}</fx:DocumentFileName>"
            . "<fx:Version>1.0</fx:Version><fx:ConformanceLevel>{$level}</fx:ConformanceLevel></rdf:Description>"
            . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }
}
