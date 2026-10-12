<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use Belegwerk\Gotenberg\GotenbergException;
use Belegwerk\Template;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Twig\Sandbox\SecurityError;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;

/**
 * Rendering tests need a running Gotenberg (GOTENBERG_URL, default http://127.0.0.1:3000) and are skipped without one.
 */
final class BelegwerkTest extends TestCase
{
    private const EXAMPLE_TEMPLATE = __DIR__ . '/../examples/invoice-template';

    public static function forbiddenTemplates(): array
    {
        return [
            'callable filter' => ["{{ ['id']|filter('system')|join }}"],
            'function' => ['{% for i in range(1, 3) %}{{ i }}{% endfor %}'],
            'extends' => ["{% extends 'index.html' %}"],
        ];
    }

    #[DataProvider('forbiddenTemplates')]
    public function testSandboxRejectsTemplatesBeforeRendering(string $html): void
    {
        $this->expectException(SecurityError::class);
        (new Belegwerk('http://127.0.0.1:9'))->document(new Template($html));
    }

    public function testInvoiceIsZugferdWithExampleTemplate(): void
    {
        $pdf = $this->gotenberg()->invoice(InvoiceNormalizerTest::example(), Template::fromDirectory(self::EXAMPLE_TEMPLATE));

        $text = $this->text($pdf);
        self::assertStringContainsString('RE-2026-0042', $text);
        self::assertStringContainsString('1.062,37 €', $text);
        self::assertStringContainsString('Relaunch Website', $text);
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $text);

        $xml = ZugferdDocumentPdfReader::getXmlFromContent($pdf);
        self::assertStringContainsString('<ram:ID>RE-2026-0042</ram:ID>', $xml);
        self::assertStringContainsString('<ram:ID>urn:cen.eu:en16931:2017</ram:ID>', $xml);
    }

    public function testInvoiceIsPdfA3bCompliant(): void
    {
        $url = $this->reachable(getenv('VERAPDF_URL') ?: 'http://127.0.0.1:3001', 'veraPDF');
        $pdf = $this->gotenberg()->invoice(InvoiceNormalizerTest::example(), Template::fromDirectory(self::EXAMPLE_TEMPLATE));

        $curl = curl_init(rtrim($url, '/') . '/api/validate/3b');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLStringFile($pdf, 'invoice.pdf', 'application/pdf')],
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $report = json_decode((string) curl_exec($curl), true, 512, JSON_THROW_ON_ERROR);

        $result = $report['report']['jobs'][0]['validationResult'][0];
        self::assertTrue($result['compliant'], json_encode($result['details']['ruleSummaries'], JSON_PRETTY_PRINT));
    }

    public function testDocumentRendersFreeData(): void
    {
        $template = new Template(
            '<h1>Angebot {{ number }}</h1>{% for item in items %}<p>{{ item.name }}: {{ item.price|money }}</p>{% endfor %}',
        );
        $text = $this->text($this->gotenberg()->document($template, [
            'number' => 'A-7',
            'items' => [['name' => 'Workshop', 'price' => 1250]],
        ]));

        self::assertStringContainsString('Angebot A-7', $text);
        self::assertStringContainsString('Workshop: 12,50 €', $text);
    }

    public function testTemplateIncludesOnlyProvidedPartials(): void
    {
        $template = new Template(
            '<p>{% include "partials/known.twig" %}</p>',
            partials: ['partials/known.twig' => 'Known partial'],
        );

        $this->expectException(GotenbergException::class);
        $this->expectExceptionMessage('Gotenberg is not reachable');
        (new Belegwerk('http://127.0.0.1:9'))->document($template);
    }

    public function testTemplateCannotIncludeAnUnknownPartial(): void
    {
        $this->expectException(LoaderError::class);
        (new Belegwerk('http://127.0.0.1:9'))->document(new Template(
            '<p>{% include "partials/not-provided.twig" %}</p>',
        ));
    }

    public function testTemplateRejectsUnknownVariables(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('Variable "seler" does not exist');
        (new Belegwerk('http://127.0.0.1:9'))->document(new Template('<p>{{ seler.name }}</p>'), ['seller' => ['name' => 'X']]);
    }

    public function testTemplateRejectsPartialPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Template('<p>Invoice</p>', partials: ['../../secrets.twig' => 'not available']);
    }

    public function testTemplateDirectoryLoadsOnlyPartialsFromItsPartialsFolder(): void
    {
        $template = Template::fromDirectory(__DIR__ . '/fixtures/template-with-partials');

        self::assertSame(['partials/styles.twig'], array_keys($template->partials));
    }

    private function gotenberg(): Belegwerk
    {
        return new Belegwerk($this->reachable(getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3000', 'Gotenberg'));
    }

    private function reachable(string $url, string $service): string
    {
        $parts = parse_url($url);
        $socket = @fsockopen($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 80, $code, $message, 1);
        if ($socket === false) {
            self::markTestSkipped("{$service} is not reachable at {$url}.");
        }
        fclose($socket);
        return $url;
    }

    private function text(string $pdf): string
    {
        return (new Parser())->parseContent($pdf)->getText();
    }
}
