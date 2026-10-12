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

    public function testAssetFilterEmbedsTemplateAssetsAsDataUris(): void
    {
        $template = new Template(
            '<img src="{{ \'logo.svg\'|asset }}">',
            footer: '<style>@font-face { src: url({{ "Brand.woff2"|asset }}); }</style>',
            assets: ['logo.svg' => '<svg/>', 'Brand.woff2' => 'font'],
        );

        $html = $this->echoed()->document($template);

        self::assertStringContainsString('<img src="data:image/svg+xml;base64,' . base64_encode('<svg/>') . '">', $html);
        self::assertStringContainsString('url(data:font/woff2;base64,' . base64_encode('font') . ')', $html);
    }

    public function testAssetFilterRejectsUnknownAssets(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('Unknown template asset "../logo.png" (available: logo.svg)');
        (new Belegwerk('http://127.0.0.1:9'))->document(new Template(
            '<img src="{{ \'../logo.png\'|asset }}">',
            assets: ['logo.svg' => '<svg/>'],
        ));
    }

    public function testTemplateRejectsAssetPathsAndUnknownTypes(): void
    {
        foreach (['../logo.png', 'sub/logo.png', '.hidden.png', 'script.js', 'logo'] as $name) {
            try {
                new Template('<p></p>', assets: [$name => 'x']);
                self::fail("Asset name {$name} was accepted.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTemplateDirectoryLoadsAssetsAndDefaults(): void
    {
        $template = Template::fromDirectory(__DIR__ . '/fixtures/template-with-assets');

        self::assertSame(['Brand.woff2', 'logo.svg'], array_keys($template->assets));
        self::assertSame(['custom' => ['time_range' => null, 'title' => 'Leistungen']], $template->defaults);
    }

    public function testTemplateDefaultsFillMissingDataButDataWins(): void
    {
        $template = new Template(
            '<p>{{ custom.title }}|{{ custom.time_range }}|{{ custom.note }}</p>',
            defaults: ['custom' => ['title' => 'Leistungen', 'time_range' => null, 'note' => 'aus Vorlage']],
        );
        $belegwerk = $this->echoed(defaults: ['custom' => ['note' => 'aus Konstruktor']]);

        self::assertStringContainsString('<p>Leistungen||aus Konstruktor</p>', $belegwerk->document($template));
        self::assertStringContainsString(
            '<p>Angebot|März|aus Konstruktor</p>',
            $belegwerk->document($template, ['custom' => ['title' => 'Angebot', 'time_range' => 'März']]),
        );
    }

    public function testTemplateDefaultsReachTheInvoiceTemplateButNotTheXml(): void
    {
        $invoice = InvoiceNormalizerTest::example();
        unset($invoice['custom']);
        $template = new Template(
            '<p>Zeitraum {{ custom.time_range }}</p>',
            defaults: ['custom' => ['time_range' => 'Q3 2026'], 'order_reference' => 'FROM-TEMPLATE'],
        );

        $pdf = $this->gotenberg()->invoice($invoice, $template);

        self::assertStringContainsString('Zeitraum Q3 2026', $this->text($pdf));
        self::assertStringNotContainsString('FROM-TEMPLATE', ZugferdDocumentPdfReader::getXmlFromContent($pdf));
    }

    public function testTemplateDirectoryRejectsInvalidDefaults(): void
    {
        $directory = sys_get_temp_dir() . '/belegwerk-invalid-defaults-' . getmypid();
        @mkdir($directory);
        file_put_contents("{$directory}/html.twig", '<p></p>');
        file_put_contents("{$directory}/defaults.json", '["not", "an object"]');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('must contain a JSON object');
            Template::fromDirectory($directory);
        } finally {
            array_map(unlink(...), glob("{$directory}/*"));
            rmdir($directory);
        }
    }

    /**
     * Belegwerk against a fake Gotenberg that returns the rendered HTML instead of a PDF.
     */
    private function echoed(array $defaults = []): Belegwerk
    {
        static $url = null;
        if ($url === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $server = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/echo-gotenberg.php'],
                [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $pipes,
            );
            register_shutdown_function(static fn () => proc_terminate($server));
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }
            $url = "http://127.0.0.1:{$port}";
        }
        return new Belegwerk($url, $defaults);
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
