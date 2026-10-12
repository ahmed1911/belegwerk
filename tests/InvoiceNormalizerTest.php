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
        self::assertSame(['net_cents' => 89980, 'tax_cents' => 16257, 'gross_cents' => 106237, 'paid_cents' => 0, 'due_cents' => 106237], $invoice['totals']);
        self::assertSame('2026-09-24', $invoice['date']);
        self::assertSame('DE89370400440532013000', $invoice['bank']['iban']);
        self::assertSame(['scheme' => 'EM', 'id' => 'eingangsrechnung@beispielstadt.de'], $invoice['buyer']['electronic_address']);
    }

    public function testLineAmountsAreExactAndRoundHalfAwayFromZero(): void
    {
        $data = self::example();
        $data['items'] = [
            ['description' => 'Float math yields 14', 'quantity' => 0.58, 'unit_price_cents' => 25],
            ['description' => 'Negative half', 'quantity' => -0.58, 'unit_price_cents' => 25],
            ['description' => 'Six decimals', 'quantity' => 0.123456, 'unit_price_cents' => 100],
            ['description' => 'Six decimals, rounds up', 'quantity' => 1.234567, 'unit_price_cents' => 10000],
        ];

        $lines = (new InvoiceNormalizer())->normalize($data)['lines'];

        self::assertSame([15, -15, 12, 12346], array_column($lines, 'net_cents'));
        self::assertSame(['0.58', '-0.58', '0.123456', '1.234567'], array_column($lines, 'quantity'));
    }

    public function testRejectsMoreThanSixQuantityDecimals(): void
    {
        $data = self::example();
        $data['items'][0]['quantity'] = 1.2345678;

        self::assertArrayHasKey('items.0.quantity', self::errors($data));
    }

    public function testCorrectedInvoiceRequiresThePrecedingInvoice(): void
    {
        $data = self::example();
        $data['type_code'] = '384';
        self::assertArrayHasKey('preceding_invoice', self::errors($data));

        $data['preceding_invoice'] = ['number' => 'RE-2026-0041', 'date' => '01.03.2026'];
        self::assertArrayHasKey('preceding_invoice.date', self::errors($data));
    }

    public function testCashDiscountAndPaidAmountReduceWhatIsDue(): void
    {
        $data = self::example();
        $data['paid_cents'] = 6237;
        $data['cash_discount'] = ['days' => 14, 'percent' => 2.5];

        $invoice = (new InvoiceNormalizer())->normalize($data);

        self::assertSame(6237, $invoice['totals']['paid_cents']);
        self::assertSame(100000, $invoice['totals']['due_cents']);
        self::assertSame(
            ['days' => 14, 'percent' => '2.5', 'date' => '2026-10-08', 'amount_cents' => 2500, 'payable_cents' => 97500],
            $invoice['cash_discount'],
        );
    }

    public function testDirectDebitMakesTheSellerBankOptional(): void
    {
        $data = self::example();
        unset($data['bank']);
        $data['direct_debit'] = ['mandate_reference' => 'M-7', 'creditor_id' => 'DE98ZZZ09999999999', 'iban' => 'de02 1203 0000 0000 2020 51'];

        $invoice = (new InvoiceNormalizer())->normalize($data);

        self::assertNull($invoice['bank']);
        self::assertSame('DE02120300000000202051', $invoice['direct_debit']['iban']);
    }

    public function testRejectsInvalidPaymentData(): void
    {
        $data = self::example();
        unset($data['bank']);
        $data['paid_cents'] = 106238;
        $data['cash_discount'] = ['days' => 0, 'percent' => 100];
        self::assertEqualsCanonicalizing(['bank', 'cash_discount.days', 'cash_discount.percent'], array_keys(self::errors($data)));

        $data = self::example();
        $data['paid_cents'] = 106238;
        $data['direct_debit'] = ['mandate_reference' => 'M-7', 'iban' => 'DE00'];
        self::assertEqualsCanonicalizing(['direct_debit.creditor_id', 'direct_debit.iban'], array_keys(self::errors($data)));

        unset($data['direct_debit']);
        self::assertSame(['paid_cents'], array_keys(self::errors($data)));
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

    public function testIbanNeedsChecksumAndCountryLength(): void
    {
        // Wrong checksum; correct checksum but 23 instead of 22 characters for DE.
        foreach (['DE88370400440532013000', 'DE543704004405320130001'] as $iban) {
            $data = self::example();
            $data['bank']['iban'] = $iban;
            self::assertSame(['bank.iban'], array_keys(self::errors($data)), $iban);
        }
    }

    /** @return array<string, string> the errors of the ValidationException that $data must cause */
    private static function errors(array $data): array
    {
        try {
            (new InvoiceNormalizer())->normalize($data);
        } catch (ValidationException $error) {
            return $error->errors;
        }
        self::fail('ValidationException expected.');
    }
}
