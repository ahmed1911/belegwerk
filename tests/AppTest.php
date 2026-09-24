<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Gotenberg\GotenbergClient;
use Belegwerk\Http\App;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function app(?string $apiKey = null): App
    {
        return new App(new GotenbergClient(getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3000'), $apiKey);
    }

    private function body(): string
    {
        return json_encode(InvoiceNormalizerTest::example(), JSON_THROW_ON_ERROR);
    }

    public function testRoutingErrors(): void
    {
        self::assertSame(404, $this->app()->handle('GET', '/nope', [], '', null)->status);
        self::assertSame(405, $this->app()->handle('GET', '/v1/invoices/pdf', [], '', null)->status);
        self::assertSame(400, $this->app()->handle('POST', '/v1/invoices/xrechnung', [], '{', null)->status);
        self::assertSame(422, $this->app()->handle('POST', '/v1/invoices/zugferd', ['profile' => 'basic'], $this->body(), null)->status);
    }

    public function testApiKey(): void
    {
        $app = $this->app('geheim');
        self::assertSame(401, $app->handle('POST', '/v1/invoices/xrechnung', [], $this->body(), null)->status);
        self::assertSame(401, $app->handle('POST', '/v1/invoices/xrechnung', [], $this->body(), 'Bearer falsch')->status);
        self::assertSame(200, $app->handle('POST', '/v1/invoices/xrechnung', [], $this->body(), 'Bearer geheim')->status);
    }

    public function testValidationErrorsAreReturnedAsJson(): void
    {
        $response = $this->app()->handle('POST', '/v1/invoices/xrechnung', [], '{"number": "1"}', null);
        $data = json_decode($response->body, true);

        self::assertSame(422, $response->status);
        self::assertArrayHasKey('seller', $data['details']);
        self::assertArrayHasKey('items', $data['details']);
    }

    public function testXRechnungResponse(): void
    {
        $response = $this->app()->handle('POST', '/v1/invoices/xrechnung', [], $this->body(), null);

        self::assertSame(200, $response->status);
        self::assertSame('application/xml; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('rechnung-RE-2026-0042.xml', $response->headers['Content-Disposition']);
    }

    public function testZugferdPdfContainsInvoiceXml(): void
    {
        $app = $this->app();
        if ($app->handle('GET', '/health', [], '', null)->status !== 200) {
            self::markTestSkipped('Gotenberg ist nicht erreichbar (GOTENBERG_URL).');
        }

        $response = $app->handle('POST', '/v1/invoices/zugferd', [], $this->body(), null);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringStartsWith('%PDF-', $response->body);

        $xml = ZugferdDocumentPdfReader::getXmlFromContent($response->body);
        self::assertStringContainsString('<ram:ID>RE-2026-0042</ram:ID>', $xml);
        self::assertStringContainsString('urn:cen.eu:en16931:2017</ram:ID>', $xml);
    }
}
