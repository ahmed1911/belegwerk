<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Invoice\InvoiceNormalizer;
use Belegwerk\Invoice\ValidationException;
use PHPUnit\Framework\TestCase;

final class InvoiceNormalizerTest extends TestCase
{
    public static function example(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../examples/invoice.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public function testCalculatesLinesTaxGroupsAndTotals(): void
    {
        $invoice = (new InvoiceNormalizer())->normalize(self::example());

        self::assertSame([78000, 4990, 6990], array_column($invoice['lines'], 'net_cents'));
        self::assertSame(['HUR', 'MON', 'H87'], array_column($invoice['lines'], 'unit_code'));
        self::assertSame([
            ['category' => 'S', 'rate' => '19', 'base_cents' => 82990, 'amount_cents' => 15768, 'exemption_reason' => null],
            ['category' => 'S', 'rate' => '7', 'base_cents' => 6990, 'amount_cents' => 489, 'exemption_reason' => null],
        ], $invoice['tax_groups']);
        self::assertSame(['net_cents' => 89980, 'tax_cents' => 16257, 'gross_cents' => 106237], $invoice['totals']);
        self::assertSame('2026-09-24', $invoice['date']);
        self::assertSame('DE89370400440532013000', $invoice['bank']['iban']);
        self::assertSame(['scheme' => 'EM', 'id' => 'eingangsrechnung@beispielstadt.de'], $invoice['buyer']['electronic_address']);
    }

    public function testCollectsAllErrorsWithFieldPaths(): void
    {
        $data = self::example();
        $data['date'] = '2026-02-30';
        $data['bank']['iban'] = 'DE00123';
        $data['items'][0]['unit_price_cents'] = 12.5;
        $data['items'][1]['unit'] = 'Kiste';

        try {
            (new InvoiceNormalizer())->normalize($data);
            self::fail('ValidationException expected');
        } catch (ValidationException $error) {
            self::assertSame(['date', 'bank.iban', 'items.0.unit_price_cents', 'items.1.unit_code'], array_keys($error->errors));
            self::assertStringContainsString('bank.iban: Invalid IBAN.', $error->getMessage());
        }
    }

    public function testChecksCodesAgainstOfficialCodeLists(): void
    {
        $data = self::example();
        $data['buyer']['address']['country'] = 'XX';
        $data['buyer']['electronic_address'] = ['scheme' => '9999', 'id' => 'x'];
        $data['items'][0]['unit_code'] = 'ABC';
        $data['items'][1]['unit'] = 'KWT';

        try {
            (new InvoiceNormalizer())->normalize($data);
            self::fail('ValidationException expected');
        } catch (ValidationException $error) {
            self::assertSame(['buyer.electronic_address', 'buyer.address.country', 'items.0.unit_code'], array_keys($error->errors));
        }
    }

    public function testPassesCustomDataThrough(): void
    {
        $data = self::example();
        $data['items'][0]['custom'] = ['date' => '2026-09-03'];
        $invoice = (new InvoiceNormalizer())->normalize($data);

        self::assertSame('Relaunch Website', $invoice['custom']['project']);
        self::assertSame(['date' => '2026-09-03'], $invoice['lines'][0]['custom']);
        self::assertSame([], $invoice['lines'][1]['custom']);

        $data['custom'] = 'not an object';
        $this->expectException(ValidationException::class);
        (new InvoiceNormalizer())->normalize($data);
    }

    public function testSmallBusinessRequiresExemptionReason(): void
    {
        $data = self::example();
        foreach ($data['items'] as &$item) {
            $item['tax_rate'] = 0;
            $item['tax_category'] = 'E';
        }
        unset($item);

        try {
            (new InvoiceNormalizer())->normalize($data);
            self::fail('ValidationException expected');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('tax_exemption_reason', $error->errors);
        }

        $data['tax_exemption_reason'] = 'Kleinunternehmer gemäß § 19 UStG';
        $invoice = (new InvoiceNormalizer())->normalize($data);
        self::assertSame(0, $invoice['totals']['tax_cents']);
        self::assertSame('Kleinunternehmer gemäß § 19 UStG', $invoice['tax_groups'][0]['exemption_reason']);
    }

    public function testXRechnungRequiresBuyerReference(): void
    {
        $normalizer = new InvoiceNormalizer();
        $data = self::example();
        unset($data['buyer_reference']);

        $this->expectException(ValidationException::class);
        $normalizer->assertXRechnung($normalizer->normalize($data));
    }

    public function testIbanChecksum(): void
    {
        self::assertTrue(InvoiceNormalizer::isValidIban('DE89370400440532013000'));
        self::assertFalse(InvoiceNormalizer::isValidIban('DE88370400440532013000'));
    }
}
