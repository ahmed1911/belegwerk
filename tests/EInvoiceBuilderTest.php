<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Invoice\EInvoiceBuilder;
use Belegwerk\Invoice\InvoiceNormalizer;
use horstoeko\zugferd\ZugferdDocumentReader;
use PHPUnit\Framework\TestCase;

final class EInvoiceBuilderTest extends TestCase
{
    public function testXRechnungXmlIsSchemaValidAndRoundTrips(): void
    {
        $invoice = (new InvoiceNormalizer())->normalize(InvoiceNormalizerTest::example());
        $xml = (new EInvoiceBuilder())->toXml($invoice, EInvoiceBuilder::PROFILE_XRECHNUNG);

        self::assertStringContainsString('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $xml);

        $reader = ZugferdDocumentReader::readAndGuessFromContent($xml);
        $reader->getDocumentInformation($number, $type, $date, $currency, $name, $language, $period, $billingPeriodEnd);
        $reader->getDocumentSummation($grand, $due, $lineTotal, $charges, $allowances, $taxBasis, $tax, $rounding, $prepaid);
        $reader->getDocumentBuyerReference($buyerReference);

        self::assertSame('RE-2026-0042', $number);
        self::assertSame('380', $type);
        self::assertSame('04011000-12345-34', $buyerReference);
        self::assertEqualsWithDelta(1062.37, $grand, 0.001);
        self::assertEqualsWithDelta(162.57, $tax, 0.001);
    }

    public function testNegativeUnitPriceMovesSignToQuantity(): void
    {
        $data = InvoiceNormalizerTest::example();
        $data['items'] = [['description' => 'Korrektur', 'quantity' => 1, 'unit' => 'LS', 'unit_price_cents' => -1000]];
        $invoice = (new InvoiceNormalizer())->normalize($data);
        $xml = (new EInvoiceBuilder())->toXml($invoice, EInvoiceBuilder::PROFILE_EN16931);

        self::assertStringContainsString('<ram:ChargeAmount>10.00</ram:ChargeAmount>', $xml);
        self::assertStringContainsString('<ram:BilledQuantity unitCode="LS">-1.00</ram:BilledQuantity>', $xml);
        self::assertStringContainsString('<ram:LineTotalAmount>-10.00</ram:LineTotalAmount>', $xml);
    }
}
