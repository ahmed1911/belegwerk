<?php

declare(strict_types=1);

namespace Belegwerk\Gotenberg;

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
     * @param array<string, string> $files   file name => HTML, must contain index.html
     * @param array<string, string> $fields  additional form fields, e.g. preferCssPageSize
     */
    public function convertHtml(array $files, array $fields): string
    {
        $boundary = 'belegwerk-' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($files as $name => $content) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"{$name}\"\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n{$content}\r\n";
        }
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $curl = curl_init(rtrim($this->url, '/') . '/forms/chromium/convert/html');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Content-Type: multipart/form-data; boundary={$boundary}", 'Accept: application/pdf'],
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
