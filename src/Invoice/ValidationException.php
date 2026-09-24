<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use InvalidArgumentException;

/**
 * The invoice data is invalid; $errors maps field paths such as "items.0.unit_price_cents" to messages.
 */
final class ValidationException extends InvalidArgumentException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        $details = array_map(static fn (string $path, string $message): string => "{$path}: {$message}", array_keys($errors), $errors);
        parent::__construct('Invalid invoice data. ' . implode(' ', $details));
    }
}
