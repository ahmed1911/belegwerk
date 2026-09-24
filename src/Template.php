<?php

declare(strict_types=1);

namespace Belegwerk;

use InvalidArgumentException;

/**
 * Twig sources of a document: the page body plus an optional header and footer that Gotenberg
 * repeats on every page. Each source is a complete HTML document.
 */
final class Template
{
    public function __construct(
        public readonly string $html,
        public readonly ?string $header = null,
        public readonly ?string $footer = null,
    ) {
    }

    /**
     * Loads html.twig and the optional header.twig and footer.twig from a directory.
     */
    public static function fromDirectory(string $directory): self
    {
        $read = static fn (string $file): ?string => is_file("{$directory}/{$file}")
            ? (string) file_get_contents("{$directory}/{$file}")
            : null;

        return new self(
            $read('html.twig') ?? throw new InvalidArgumentException("No html.twig found in {$directory}."),
            $read('header.twig'),
            $read('footer.twig'),
        );
    }

    /**
     * The built-in German invoice layout.
     */
    public static function standard(): self
    {
        return self::fromDirectory(dirname(__DIR__) . '/templates/standard');
    }
}
