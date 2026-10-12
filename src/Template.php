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
        public readonly array $partials = [],
    ) {
        foreach ($partials as $name => $source) {
            $isPartial = is_string($name) && is_string($source) && preg_match('#^partials/[a-zA-Z0-9_-]+\.twig$#D', $name) === 1;
            if (!$isPartial) {
                throw new InvalidArgumentException('Template partials must use the partials/<name>.twig format.');
            }
        }
    }

    /**
     * Loads html.twig and the optional header.twig and footer.twig from a directory.
     */
    public static function fromDirectory(string $directory): self
    {
        $read = static fn (string $file): ?string => is_file("{$directory}/{$file}")
            ? (string) file_get_contents("{$directory}/{$file}")
            : null;

        $partials = [];
        foreach (array_filter(glob("{$directory}/partials/*.twig") ?: [], is_file(...)) as $path) {
            $partials['partials/' . basename($path)] = (string) file_get_contents($path);
        }

        return new self(
            $read('html.twig') ?? throw new InvalidArgumentException("No html.twig found in {$directory}."),
            $read('header.twig'),
            $read('footer.twig'),
            $partials,
        );
    }
}
