<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use RuntimeException;

/**
 * The content passed to Belegwerk::read() is no readable e-invoice.
 */
final class ReadException extends RuntimeException
{
}
