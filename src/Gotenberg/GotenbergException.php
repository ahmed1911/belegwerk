<?php

declare(strict_types=1);

namespace Belegwerk\Gotenberg;

use RuntimeException;

/**
 * Gotenberg was unreachable or refused to render, e.g. because a template references a file that was not sent.
 */
final class GotenbergException extends RuntimeException
{
}
