<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use Belegwerk\Invoice\ValidationException;
use horstoeko\zugferd\ZugferdDocumentReader;
use PHPUnit\Framework\TestCase;

/**
 * XML creation needs no Gotenberg, so these tests always run.
 */
final class EInvoiceBuilderTest extends TestCase
{
    private function belegwerk(): Belegwerk
    {
        return new Belegwerk('http://127.0.0.1:9');
    }

    public function testXRechnungXmlIsSchemaValidAndRoundTrips(): void
    {
        $xml = $this->belegwerk()->xrechnung(InvoiceNormalizerTest::example());

        self::assertStringContainsString('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $xml);

        $reader = ZugferdDocumentReader::readAndGuessFromContent($xml);
        $reader->getDocumentInformation($number, $type, $date, $currency, $name, $language, $period, $billingPeriodEnd);
        $reader->getDocumentSummation($grand, $due, $lineTotal, $charges, $allowances, $taxBasis, $tax, $rounding, $prepaid);
        $reader->getDocumentBuyerReference($buyerReference);

        self::assertSame('RE-2026-0042', $number);
        self::assertSame('380', $type);
        self::assertSame('2026-09-24', $date->format('Y-m-d'));
        self::assertSame('04011000-12345-34', $buyerReference);
        self::assertEqualsWithDelta(1062.37, $grand, 0.001);
        self::assertEqualsWithDelta(162.57, $tax, 0.001);
    }

    public function testNegativeUnitPriceMovesSignToQuantity(): void
    {
        $data = InvoiceNormalizerTest::example();
        $data['items'] = [['description' => 'Korrektur', 'quantity' => 1, 'unit' => 'LS', 'unit_price_cents' => -1000]];
        $xml = $this->belegwerk()->xrechnung($data);

        self::assertStringContainsString('<ram:ChargeAmount>10.00</ram:ChargeAmount>', $xml);
        self::assertStringContainsString('<ram:BilledQuantity unitCode="LS">-1.00</ram:BilledQuantity>', $xml);
        self::assertStringContainsString('<ram:LineTotalAmount>-10.00</ram:LineTotalAmount>', $xml);
    }

    public function testSmallBusinessWithoutVatIdUsesTaxNumberAsSellerIdentifier(): void
    {
        $data = InvoiceNormalizerTest::example();
        unset($data['seller']['vat_id']);
        $data['tax_exemption_reason'] = 'Kleinunternehmer gemäß § 19 UStG';
        foreach ($data['items'] as &$item) {
            $item['tax_rate'] = 0;
            $item['tax_category'] = 'E';
        }
        unset($item);

        $xml = $this->belegwerk()->xrechnung($data);

        self::assertMatchesRegularExpression('#<ram:SellerTradeParty>\s*<ram:ID>143/123/45678</ram:ID>#', $xml);
        self::assertStringNotContainsString('schemeID="VA"', $xml);
    }

    public function testXRechnungEnforcesItsMandatoryFields(): void
    {
        $data = InvoiceNormalizerTest::example();
        unset($data['seller']['phone']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('seller.phone: Required for XRechnung.');
        $this->belegwerk()->xrechnung($data);
    }
}
