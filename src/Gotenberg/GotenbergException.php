<?php

declare(strict_types=1);

namespace Belegwerk\Gotenberg;

use RuntimeException;

/**
 * Gotenberg was unreachable or refused to render, e.g. because a template loads a blocked URL.
 */
final class GotenbergException extends RuntimeException
{
}
