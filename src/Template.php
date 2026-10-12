<?php

declare(strict_types=1);

namespace Belegwerk;

use InvalidArgumentException;
use JsonException;

/**
 * Twig sources of a document: the page body plus an optional header and footer that Gotenberg
 * repeats on every page. Each source is a complete HTML document.
 *
 * Assets (images, fonts) are embedded as data: URIs with the `asset` filter. Defaults are data the template
 * brings itself, e.g. optional custom fields; the data passed in overrides them.
 */
final class Template
{
    private const ASSET_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];

    /**
     * @param array<string, string> $partials  partials/<name>.twig => Twig source
     * @param array<string, string> $assets    file name such as logo.png => file content
     * @param array<string, mixed>  $defaults  data merged below the data passed to a call
     */
    public function __construct(
        public readonly string $html,
        public readonly ?string $header = null,
        public readonly ?string $footer = null,
        public readonly array $partials = [],
        public readonly array $assets = [],
        public readonly array $defaults = [],
    ) {
        foreach ($partials as $name => $source) {
            $isPartial = is_string($name) && is_string($source) && preg_match('#^partials/[a-zA-Z0-9_-]+\.twig$#D', $name) === 1;
            if (!$isPartial) {
                throw new InvalidArgumentException('Template partials must use the partials/<name>.twig format.');
            }
        }
        foreach ($assets as $name => $content) {
            if (!is_string($name) || !is_string($content) || self::assetType($name) === null) {
                $types = implode(', ', array_keys(self::ASSET_TYPES));
                throw new InvalidArgumentException("Template asset \"{$name}\" must be a file name without a path, with one of the extensions {$types}.");
            }
        }
        if ($defaults !== [] && array_is_list($defaults)) {
            throw new InvalidArgumentException('Template defaults must be an object (string keys), not a list.');
        }
    }

    /**
     * Loads html.twig and the optional header.twig, footer.twig, partials/*.twig, assets/* and defaults.json
     * from a directory.
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

        $assets = [];
        foreach (array_filter(glob("{$directory}/assets/*") ?: [], is_file(...)) as $path) {
            $assets[basename($path)] = (string) file_get_contents($path);
        }

        $defaults = [];
        if (($json = $read('defaults.json')) !== null) {
            try {
                $defaults = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException("Invalid defaults.json in {$directory}: {$e->getMessage()}", 0, $e);
            }
            if (!is_array($defaults)) {
                throw new InvalidArgumentException("defaults.json in {$directory} must contain a JSON object.");
            }
        }

        return new self(
            $read('html.twig') ?? throw new InvalidArgumentException("No html.twig found in {$directory}."),
            $read('header.twig'),
            $read('footer.twig'),
            $partials,
            $assets,
            $defaults,
        );
    }

    /**
     * The asset as a data: URI, for <img src>, CSS url() and @font-face.
     */
    public function assetUri(string $name): string
    {
        if (!isset($this->assets[$name])) {
            $available = $this->assets === [] ? 'none' : implode(', ', array_keys($this->assets));
            throw new InvalidArgumentException("Unknown template asset \"{$name}\" (available: {$available}).");
        }
        return 'data:' . self::assetType($name) . ';base64,' . base64_encode($this->assets[$name]);
    }

    private static function assetType(string $name): ?string
    {
        if (preg_match('#^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.([a-zA-Z0-9]+)$#D', $name, $match) !== 1) {
            return null;
        }
        return self::ASSET_TYPES[strtolower($match[1])] ?? null;
    }
}
