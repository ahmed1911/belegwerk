<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use Belegwerk\Invoice\EInvoiceBuilder;
use Belegwerk\Invoice\InvoiceNormalizer;
use Belegwerk\Invoice\ValidationException;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdProfiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * XML creation needs no Gotenberg, so these tests always run.
 */
final class EInvoiceBuilderTest extends TestCase
{
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

    public function testSixQuantityDecimalsReachTheEInvoice(): void
    {
        $data = InvoiceNormalizerTest::example();
        $data['items'] = [[
            'description' => 'Team shift',
            'quantity' => 3.703701,
            'unit' => 'Std.',
            'unit_price_cents' => 1000,
        ]];

        $xml = $this->belegwerk()->xrechnung($data);

        self::assertStringContainsString('<ram:BilledQuantity unitCode="HUR">3.703701</ram:BilledQuantity>', $xml);
        self::assertStringContainsString('<ram:LineTotalAmount>37.04</ram:LineTotalAmount>', $xml);
    }

    public function testCreditNoteReferencesThePrecedingInvoice(): void
    {
        $data = InvoiceNormalizerTest::example();
        $data['type_code'] = '381';
        $data['preceding_invoice'] = ['number' => 'RE-2026-0041', 'date' => '2026-03-01'];

        $xml = $this->belegwerk()->xrechnung($data);

        self::assertMatchesRegularExpression(
            '#<ram:InvoiceReferencedDocument>\s*<ram:IssuerAssignedID>RE-2026-0041</ram:IssuerAssignedID>\s*'
            . '<ram:FormattedIssueDateTime>\s*<qdt:DateTimeString format="102">20260301</qdt:DateTimeString>#',
            $xml,
        );
    }

    public function testCashDiscountAndPrepaidAmountAreWrittenToXml(): void
    {
        $data = InvoiceNormalizerTest::example();
        $data['paid_cents'] = 6237;
        $data['cash_discount'] = ['days' => 14, 'percent' => 2];

        $xml = $this->belegwerk()->xrechnung($data);

        self::assertStringContainsString("<ram:Description>#SKONTO#TAGE=14#PROZENT=2.00#\nZahlbar bis zum", $xml);
        self::assertStringContainsString('<ram:TotalPrepaidAmount>62.37</ram:TotalPrepaidAmount>', $xml);
        self::assertStringContainsString('<ram:DuePayableAmount>1000.00</ram:DuePayableAmount>', $xml);
    }

    public function testDirectDebitIsWrittenToXml(): void
    {
        $data = InvoiceNormalizerTest::example();
        unset($data['bank']);
        $data['direct_debit'] = ['mandate_reference' => 'M-7', 'creditor_id' => 'DE98ZZZ09999999999', 'iban' => 'DE02120300000000202051'];

        $xml = $this->belegwerk()->xrechnung($data);

        self::assertStringContainsString('<ram:TypeCode>59</ram:TypeCode>', $xml);
        self::assertStringContainsString('<ram:CreditorReferenceID>DE98ZZZ09999999999</ram:CreditorReferenceID>', $xml);
        self::assertStringContainsString('<ram:DirectDebitMandateID>M-7</ram:DirectDebitMandateID>', $xml);
        self::assertStringContainsString('<ram:IBANID>DE02120300000000202051</ram:IBANID>', $xml);
        self::assertStringContainsString('per SEPA-Lastschrift eingezogen', $xml);
    }

    public function testZugferdXmlIsCheckedAgainstTheSchema(): void
    {
        $invoice = (new InvoiceNormalizer())->normalize(InvoiceNormalizerTest::example());
        $invoice['number'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('violates the schema');
        (new EInvoiceBuilder())->toXml($invoice, ZugferdProfiles::PROFILE_EN16931);
    }

    public function testSmallBusinessWithoutVatIdUsesTaxNumberAsSellerIdentifier(): void
    {
        $data = InvoiceNormalizerTest::zeroRated(InvoiceNormalizerTest::example(), 'E');
        unset($data['seller']['vat_id']);
        $data['tax_exemption_reason'] = 'Kleinunternehmer gemäß § 19 UStG';

        $xml = $this->belegwerk()->xrechnung($data);

        self::assertMatchesRegularExpression('#<ram:SellerTradeParty>\s*<ram:ID>143/123/45678</ram:ID>#', $xml);
        self::assertStringNotContainsString('schemeID="VA"', $xml);
    }

    public function testDefaultsAreMergedFieldByField(): void
    {
        $data = InvoiceNormalizerTest::example();
        $belegwerk = new Belegwerk('http://127.0.0.1:9', defaults: ['seller' => $data['seller'], 'bank' => $data['bank']]);
        unset($data['bank']);
        $data['seller'] = ['phone' => '+49 30 999'];

        $xml = $belegwerk->xrechnung($data);

        self::assertStringContainsString('<ram:Name>Musterfirma GmbH</ram:Name>', $xml);
        self::assertStringContainsString('<ram:CompleteNumber>+49 30 999</ram:CompleteNumber>', $xml);
        self::assertStringContainsString('<ram:IBANID>DE89370400440532013000</ram:IBANID>', $xml);
    }

    public function testXRechnungEnforcesItsMandatoryFields(): void
    {
        $data = InvoiceNormalizerTest::example();
        unset($data['seller']['phone']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('seller.phone: Required for XRechnung.');
        $this->belegwerk()->xrechnung($data);
    }

    private function belegwerk(): Belegwerk
    {
        return new Belegwerk('http://127.0.0.1:9');
    }
}
