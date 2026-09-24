<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use Belegwerk\Template;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Twig\Sandbox\SecurityError;

/**
 * Rendering tests need a running Gotenberg (GOTENBERG_URL, default http://127.0.0.1:3000) and are skipped without one.
 */
final class BelegwerkTest extends TestCase
{
    public function testSandboxRejectsCallableFiltersBeforeRendering(): void
    {
        $this->expectException(SecurityError::class);
        (new Belegwerk('http://127.0.0.1:9'))->documentPdf(new Template("{{ ['id']|filter('system')|join }}"));
    }

    public function testInvoicePdfWithStandardTemplate(): void
    {
        $text = $this->text($this->gotenberg()->invoicePdf(InvoiceNormalizerTest::example()));

        self::assertStringContainsString('RE-2026-0042', $text);
        self::assertStringContainsString('1.062,37 €', $text);
        self::assertStringContainsString('Relaunch Website', $text);
        self::assertStringContainsString('IBAN DE89 3704 0044 0532 0130 00', $text);
    }

    public function testZugferdEmbedsInvoiceXml(): void
    {
        $pdf = $this->gotenberg()->zugferd(InvoiceNormalizerTest::example());
        $xml = ZugferdDocumentPdfReader::getXmlFromContent($pdf);

        self::assertStringContainsString('<ram:ID>RE-2026-0042</ram:ID>', $xml);
        self::assertStringContainsString('<ram:ID>urn:cen.eu:en16931:2017</ram:ID>', $xml);
    }

    public function testDocumentPdfRendersFreeData(): void
    {
        $template = new Template(
            '<h1>Angebot {{ number }}</h1>{% for item in items %}<p>{{ item.name }}: {{ item.price|money }}</p>{% endfor %}',
        );
        $text = $this->text($this->gotenberg()->documentPdf($template, [
            'number' => 'A-7',
            'items' => [['name' => 'Workshop', 'price' => 1250]],
        ]));

        self::assertStringContainsString('Angebot A-7', $text);
        self::assertStringContainsString('Workshop: 12,50 €', $text);
    }

    private function gotenberg(): Belegwerk
    {
        $url = getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3000';
        $parts = parse_url($url);
        $socket = @fsockopen($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 80, $code, $message, 1);
        if ($socket === false) {
            self::markTestSkipped("Gotenberg is not reachable at {$url}.");
        }
        fclose($socket);
        return new Belegwerk($url);
    }

    private function text(string $pdf): string
    {
        return (new Parser())->parseContent($pdf)->getText();
    }
}
