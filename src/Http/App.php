<?php

declare(strict_types=1);

namespace Belegwerk\Http;

use Belegwerk\Gotenberg\GotenbergClient;
use Belegwerk\Gotenberg\GotenbergException;
use Belegwerk\Invoice\EInvoiceBuilder;
use Belegwerk\Invoice\InvoiceNormalizer;
use Belegwerk\Invoice\InvoicePdfService;
use Belegwerk\Invoice\ValidationException;
use Belegwerk\Invoice\ZugferdService;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Routen:
 *   GET  /health                   Status der API und von Gotenberg
 *   POST /v1/invoices/pdf          Rechnung als PDF
 *   POST /v1/invoices/xrechnung    Rechnung als XRechnung (CII-XML)
 *   POST /v1/invoices/zugferd      Rechnung als ZUGFeRD/Factur-X (PDF/A-3 mit XML), ?profile=en16931|xrechnung
 *   POST /v1/documents/pdf         beliebiges HTML als PDF
 */
final class App
{
    private const MAX_BODY_BYTES = 10 * 1024 * 1024;

    private readonly InvoiceNormalizer $normalizer;
    private readonly InvoicePdfService $pdfService;
    private readonly ZugferdService $zugferd;

    public function __construct(
        private readonly GotenbergClient $gotenberg = new GotenbergClient(),
        private readonly ?string $apiKey = null,
    ) {
        $this->normalizer = new InvoiceNormalizer();
        $this->pdfService = new InvoicePdfService($gotenberg);
        $this->zugferd = new ZugferdService($this->pdfService, new EInvoiceBuilder(), $this->normalizer);
    }

    /** @param array<string, string> $query */
    public function handle(string $method, string $path, array $query, string $body, ?string $authorization): Response
    {
        $path = '/' . trim($path, '/');
        $routes = [
            '/health' => ['GET', fn () => $this->health()],
            '/v1/invoices/pdf' => ['POST', fn () => $this->invoicePdf($body)],
            '/v1/invoices/xrechnung' => ['POST', fn () => $this->xrechnung($body)],
            '/v1/invoices/zugferd' => ['POST', fn () => $this->zugferd($body, $query['profile'] ?? EInvoiceBuilder::PROFILE_EN16931)],
            '/v1/documents/pdf' => ['POST', fn () => $this->documentPdf($body)],
        ];

        if (!isset($routes[$path])) {
            return Response::error(404, 'Route nicht gefunden.');
        }
        [$allowed, $handler] = $routes[$path];
        if ($method !== $allowed) {
            return new Response(405, '', ['Allow' => $allowed]);
        }
        if ($path !== '/health' && $this->apiKey !== null && !hash_equals('Bearer ' . $this->apiKey, (string) $authorization)) {
            return Response::error(401, 'Ungültiger oder fehlender API-Schlüssel.');
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return Response::error(413, 'Anfrage ist zu groß (max. 10 MB).');
        }

        try {
            return $handler();
        } catch (JsonException $error) {
            return Response::error(400, 'Ungültiges JSON: ' . $error->getMessage());
        } catch (ValidationException $error) {
            return Response::error(422, $error->getMessage(), $error->errors);
        } catch (InvalidArgumentException $error) {
            return Response::error(422, $error->getMessage());
        } catch (GotenbergException $error) {
            error_log('[belegwerk] ' . $error->getMessage());
            return Response::error(502, 'PDF-Erzeugung fehlgeschlagen.');
        } catch (Throwable $error) {
            error_log('[belegwerk] ' . $error);
            return Response::error(500, 'Interner Fehler.');
        }
    }

    private function health(): Response
    {
        $up = $this->gotenberg->isHealthy();
        return Response::json(['status' => $up ? 'ok' : 'degraded', 'gotenberg' => $up ? 'up' : 'down'], $up ? 200 : 503);
    }

    private function invoicePdf(string $body): Response
    {
        $invoice = $this->normalizer->normalize($this->decode($body));
        $pdf = $this->pdfService->renderPdf($this->normalizer->toPdfData($invoice));
        return Response::file($pdf, 'application/pdf', $this->filename($invoice, 'pdf'));
    }

    private function xrechnung(string $body): Response
    {
        $invoice = $this->normalizer->normalize($this->decode($body));
        $this->normalizer->assertXRechnung($invoice);
        $xml = (new EInvoiceBuilder())->toXml($invoice, EInvoiceBuilder::PROFILE_XRECHNUNG);
        return Response::file($xml, 'application/xml; charset=utf-8', $this->filename($invoice, 'xml'));
    }

    private function zugferd(string $body, string $profile): Response
    {
        if (!in_array($profile, [EInvoiceBuilder::PROFILE_EN16931, EInvoiceBuilder::PROFILE_XRECHNUNG], true)) {
            return Response::error(422, 'profile muss "en16931" oder "xrechnung" sein.');
        }
        $invoice = $this->normalizer->normalize($this->decode($body));
        if ($profile === EInvoiceBuilder::PROFILE_XRECHNUNG) {
            $this->normalizer->assertXRechnung($invoice);
        }
        $pdf = $this->zugferd->render($invoice, $profile);
        return Response::file($pdf, 'application/pdf', $this->filename($invoice, 'pdf'));
    }

    private function documentPdf(string $body): Response
    {
        $data = $this->decode($body);
        if (!is_array($data) || !is_string($data['html'] ?? null) || trim($data['html']) === '') {
            throw new ValidationException(['html' => 'Pflichtfeld (HTML-Dokument als Text).']);
        }
        $html = $data['html'];
        $files = [];
        if (isset($data['css'])) {
            if (!is_string($data['css'])) {
                throw new ValidationException(['css' => 'Text erwartet.']);
            }
            $files[] = ['name' => 'style.css', 'mime' => 'text/css; charset=UTF-8', 'content' => $data['css']];
            $link = '<link rel="stylesheet" href="style.css">';
            $html = stripos($html, '</head>') !== false ? preg_replace('#</head>#i', $link . '</head>', $html, 1) : $link . $html;
        }
        array_unshift($files, ['name' => 'index.html', 'mime' => 'text/html; charset=UTF-8', 'content' => $html]);

        $fields = ['printBackground' => 'true', 'preferCssPageSize' => 'true'];
        if (($data['pdfa'] ?? false) === true) {
            $fields['pdfa'] = 'PDF/A-3b';
        }
        $filename = is_string($data['filename'] ?? null) && preg_match('/^[\w.-]{1,100}$/', $data['filename'])
            ? $data['filename']
            : 'dokument.pdf';
        return Response::file($this->gotenberg->convertHtml($files, $fields), 'application/pdf', $filename);
    }

    private function decode(string $body): mixed
    {
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }

    private function filename(array $invoice, string $extension): string
    {
        return 'rechnung-' . (preg_replace('/[^A-Za-z0-9._-]+/', '_', $invoice['number']) ?? 'rechnung') . '.' . $extension;
    }
}
