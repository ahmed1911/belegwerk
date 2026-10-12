<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

/**
 * GiroCode (EPC QR code, EPC069-12 version 002) for a SEPA credit transfer of the amount due. Banking apps scan it
 * and fill in recipient, IBAN, amount and the invoice number as remittance text.
 *
 * @internal
 */
final class PaymentQrCode
{
    /** EPC069-12 allows at most 999,999,999.99 €. */
    private const MAX_CENTS = 99_999_999_999;

    /**
     * The QR code as an SVG data URI, or null if nothing is to be transferred: paid, a direct debit or no bank account.
     *
     * @param array $invoice  a normalized invoice (see InvoiceNormalizer)
     */
    public static function dataUri(array $invoice): ?string
    {
        $payload = self::payload($invoice);
        if ($payload === null) {
            return null;
        }
        // Without the ECI prefix for UTF-8, which some banking apps cannot read; the payload declares UTF-8 itself.
        $qrCode = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8', null, false);
        $svg = (new ImageRenderer(new RendererStyle(256, 0), new SvgImageBackEnd()))->render($qrCode);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function payload(array $invoice): ?string
    {
        $bank = $invoice['bank'];
        $due = $invoice['totals']['due_cents'];
        if ($bank === null || $invoice['direct_debit'] !== null || $due <= 0 || $due > self::MAX_CENTS) {
            return null;
        }
        return implode("\n", [
            'BCD',
            '002',
            '1', // UTF-8
            'SCT',
            $bank['bic'] ?? '',
            mb_substr($bank['holder'], 0, 70),
            $bank['iban'],
            sprintf('EUR%d.%02d', intdiv($due, 100), $due % 100),
            '', // purpose code
            '', // structured creditor reference
            mb_substr($invoice['number'], 0, 140),
        ]);
    }
}
