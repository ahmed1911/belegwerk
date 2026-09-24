<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use InvalidArgumentException;

final class ValidationException extends InvalidArgumentException
{
    /** @param array<string, string> $errors  Feldpfad => Fehlermeldung */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Die Rechnungsdaten sind ungültig.');
    }
}
