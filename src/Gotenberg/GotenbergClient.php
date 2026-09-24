<?php

declare(strict_types=1);

namespace Belegwerk\Gotenberg;

use InvalidArgumentException;

/**
 * Minimaler Client für Gotenbergs Chromium-HTML-Route (ohne externe Abhängigkeiten).
 */
final class GotenbergClient
{
    private readonly string $url;

    public function __construct(?string $url = null, private readonly int $timeout = 60)
    {
        $url ??= getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3000';
        if (trim($url) === '') {
            throw new InvalidArgumentException('GOTENBERG_URL ist nicht konfiguriert.');
        }
        $this->url = rtrim($url, '/');
    }

    /**
     * @param list<array{name: string, mime: string, content: string}> $files  muss index.html enthalten
     * @param array<string, string> $fields  weitere Formularfelder (z. B. pdfa, metadata)
     * @param list<array{name: string, mime: string, content: string}> $embeds  Dateianhänge im PDF
     */
    public function convertHtml(array $files, array $fields = [], array $embeds = []): string
    {
        $boundary = 'belegwerk-' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($files as $file) {
            $body .= $this->filePart($boundary, 'files', $file);
        }
        foreach ($embeds as $file) {
            $body .= $this->filePart($boundary, 'embeds', $file);
        }
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $pdf = $this->post('/forms/chromium/convert/html', $body, [
            "Content-Type: multipart/form-data; boundary={$boundary}",
            'Accept: application/pdf',
        ], $status);

        if ($status !== 200 || !str_starts_with($pdf, '%PDF-')) {
            throw new GotenbergException(
                'Gotenberg konnte das Dokument nicht rendern (HTTP ' . $status . '): ' . substr(trim($pdf), 0, 500)
            );
        }
        return $pdf;
    }

    public function isHealthy(): bool
    {
        try {
            $this->get('/health', $status);
            return $status === 200;
        } catch (GotenbergException) {
            return false;
        }
    }

    /** @param array{name: string, mime: string, content: string} $file */
    private function filePart(string $boundary, string $field, array $file): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file['name'])) {
            throw new InvalidArgumentException('Ungültiger Dateiname: ' . $file['name']);
        }
        return "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$file['name']}\"\r\n"
            . "Content-Type: {$file['mime']}\r\n\r\n{$file['content']}\r\n";
    }

    /** @param list<string> $headers */
    private function post(string $path, string $body, array $headers, ?int &$status): string
    {
        return $this->request($path, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ], $status);
    }

    private function get(string $path, ?int &$status): string
    {
        return $this->request($path, [CURLOPT_TIMEOUT => 5], $status);
    }

    /** @param array<int, mixed> $options */
    private function request(string $path, array $options, ?int &$status): string
    {
        $curl = curl_init($this->url . $path);
        if ($curl === false) {
            throw new GotenbergException('Gotenberg-Anfrage konnte nicht vorbereitet werden.');
        }
        curl_setopt_array($curl, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new GotenbergException('Gotenberg ist nicht erreichbar: ' . $error);
        }
        return (string) $response;
    }
}
