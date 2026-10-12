<?php

declare(strict_types=1);

namespace Belegwerk\Gotenberg;

use CURLStringFile;

/**
 * Minimal client for Gotenberg's Chromium HTML route.
 *
 * @internal
 */
final class GotenbergClient
{
    public function __construct(private readonly string $url, private readonly int $timeout)
    {
    }

    /**
     * @param array<string, string> $files  file name => HTML, must contain index.html
     */
    public function convertHtml(array $files): string
    {
        $body = ['preferCssPageSize' => 'true', 'printBackground' => 'true', 'failOnResourceLoadingFailed' => 'true'];
        // Gotenberg takes every uploaded file regardless of its field name.
        foreach ($files as $name => $html) {
            $body[$name] = new CURLStringFile($html, $name, 'text/html');
        }

        $curl = curl_init(rtrim($this->url, '/') . '/forms/chromium/convert/html');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $pdf = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($pdf === false) {
            throw new GotenbergException('Gotenberg is not reachable: ' . curl_error($curl));
        }
        if ($status !== 200 || !str_starts_with($pdf, '%PDF-')) {
            throw new GotenbergException("Gotenberg could not render the document (HTTP {$status}): " . substr(trim($pdf), 0, 500));
        }
        return $pdf;
    }
}
