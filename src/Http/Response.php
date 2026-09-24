<?php

declare(strict_types=1);

namespace Belegwerk\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** @param array<string, string> $details */
    public static function error(int $status, string $message, array $details = []): self
    {
        return self::json(['error' => $message] + ($details === [] ? [] : ['details' => $details]), $status);
    }

    public static function file(string $content, string $mime, string $filename): self
    {
        return new self(200, $content, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        header('Content-Length: ' . strlen($this->body));
        echo $this->body;
    }
}
