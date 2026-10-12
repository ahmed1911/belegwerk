<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use Belegwerk\Invoice\InvoiceNormalizer;
use Belegwerk\Invoice\ReadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EInvoiceReaderTest extends TestCase
{
    public function testReadsBackWhatXRechnungWrites(): void
    {
        $data = InvoiceNormalizerTest::withAllowancesAndCharges(InvoiceNormalizerTest::example());
        $data['paid_cents'] = 10000;
        $data['cash_discount'] = ['days' => 14, 'percent' => 2];
        $expected = (new InvoiceNormalizer())->normalize($data);

        $invoice = $this->belegwerk()->read($this->belegwerk()->xrechnung($data));

        self::assertSame('CII', $invoice['syntax']);
        self::assertSame('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $invoice['profile']);
        foreach (['number', 'type_code', 'currency', 'date', 'due_date', 'period', 'buyer_reference', 'order_reference', 'note', 'allowances', 'charges', 'tax_groups', 'totals'] as $key) {
            self::assertSame($expected[$key], $invoice[$key], $key);
        }
        self::assertSame(['days' => 14, 'percent' => '2'], $invoice['cash_discount']);
        self::assertSame(array_replace($expected['bank'], ['name' => null]), $invoice['bank'], 'the bank name is not part of the XML');
        self::assertSame($expected['seller']['address'], $invoice['seller']['address']);
        self::assertSame('DE123456789', $invoice['seller']['vat_id']);
        self::assertSame('143/123/45678', $invoice['seller']['tax_number']);
        self::assertSame(['scheme' => 'EM', 'id' => 'eingangsrechnung@beispielstadt.de'], $invoice['buyer']['electronic_address']);

        $line = $invoice['lines'][0];
        self::assertSame(['1', '6.5', 'HUR', 12000, 70200, 'S', '19'], [$line['id'], $line['quantity'], $line['unit_code'], $line['unit_price_cents'], $line['net_cents'], $line['tax_category'], $line['tax_rate']]);
        self::assertSame($expected['lines'][0]['allowances'], $line['allowances']);
        self::assertSame($expected['lines'][1]['charges'], $invoice['lines'][1]['charges']);
    }

    public function testReadsBackDeliveryAndAttachments(): void
    {
        $data = InvoiceNormalizerTest::withDeliveryAndAttachments(InvoiceNormalizerTest::example());
        $expected = (new InvoiceNormalizer())->normalize($data);

        $invoice = $this->belegwerk()->read($this->belegwerk()->xrechnung($data));

        self::assertSame($expected['delivery'], $invoice['delivery']);
        self::assertSame('LS-1234', $invoice['despatch_reference']);
        self::assertSame($expected['attachments'], $invoice['attachments']);
        self::assertSame(InvoiceNormalizerTest::PDF, $invoice['attachments'][0]['content']);

        $plain = $this->belegwerk()->read($this->belegwerk()->xrechnung(InvoiceNormalizerTest::example()));
        self::assertSame([null, null, []], [$plain['delivery'], $plain['despatch_reference'], $plain['attachments']]);
    }

    public function testReadsDirectDebitAndCreditNoteReference(): void
    {
        $data = InvoiceNormalizerTest::example();
        unset($data['bank']);
        $data['direct_debit'] = ['mandate_reference' => 'M-7', 'creditor_id' => 'DE98ZZZ09999999999', 'iban' => 'DE02120300000000202051'];
        $data['type_code'] = '381';
        $data['preceding_invoice'] = ['number' => 'RE-2026-0041', 'date' => '2026-03-01'];

        $invoice = $this->belegwerk()->read($this->belegwerk()->xrechnung($data));

        self::assertNull($invoice['bank']);
        self::assertSame($data['direct_debit'], $invoice['direct_debit']);
        self::assertSame('381', $invoice['type_code']);
        self::assertSame($data['preceding_invoice'], $invoice['preceding_invoice']);
    }

    public function testReadsUblXRechnung(): void
    {
        $invoice = $this->belegwerk()->read((string) file_get_contents(__DIR__ . '/fixtures/incoming/xrechnung-ubl.xml'));

        self::assertSame(['UBL', '2026-1187', '380', '2026-10-01', '2026-10-31'], [$invoice['syntax'], $invoice['number'], $invoice['type_code'], $invoice['date'], $invoice['due_date']]);
        self::assertSame(['start' => '2026-09-01', 'end' => '2026-09-30'], $invoice['period']);
        self::assertSame(['991-01234-56', 'B-4711'], [$invoice['buyer_reference'], $invoice['order_reference']]);
        self::assertSame(['days' => 10, 'percent' => '2'], $invoice['cash_discount']);

        self::assertSame('Bürobedarf Lieferant GmbH', $invoice['seller']['name']);
        self::assertSame(['DE987654321', 'Max Muster', '+49 40 123456'], [$invoice['seller']['vat_id'], $invoice['seller']['contact_name'], $invoice['seller']['phone']]);
        self::assertSame(['street' => 'Hauptstraße 1', 'additional' => 'Hinterhaus', 'zip' => '80331', 'city' => 'München', 'country' => 'DE'], $invoice['buyer']['address']);
        self::assertSame(['holder' => 'Bürobedarf Lieferant GmbH', 'iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'name' => null], $invoice['bank']);
        self::assertSame([
            'name' => 'Musterfirma GmbH, Lager Freising',
            'address' => ['street' => 'Lagerstraße 3', 'additional' => null, 'zip' => '85354', 'city' => 'Freising', 'country' => 'DE'],
            'location_id' => ['scheme' => '0088', 'id' => '4012345000009'],
        ], $invoice['delivery']);
        self::assertSame('LS-0815', $invoice['despatch_reference']);
        // The invoiced object identifier (code 130) is no attachment.
        self::assertSame(['LS-0815', 'Abnahme-12'], array_column($invoice['attachments'], 'id'));
        self::assertSame(['Lieferschein', 'lieferschein.csv', 'text/csv', "Artikel;Menge\nKugelschreiber;500\n"], [
            $invoice['attachments'][0]['description'], $invoice['attachments'][0]['filename'], $invoice['attachments'][0]['mime'], $invoice['attachments'][0]['content'],
        ]);
        self::assertSame(['https://lieferant.example/abnahme/12', null], [$invoice['attachments'][1]['url'], $invoice['attachments'][1]['content']]);

        // 25.00 € per 100 pieces.
        self::assertSame(['500', 25, 10000], [$invoice['lines'][0]['quantity'], $invoice['lines'][0]['unit_price_cents'], $invoice['lines'][0]['net_cents']]);
        self::assertSame([['reason' => 'Mengenrabatt', 'reason_code' => null, 'percent' => '20', 'base_cents' => 12500, 'amount_cents' => 2500]], $invoice['lines'][0]['allowances']);
        self::assertSame('Kalender 2027', $invoice['lines'][1]['description']);
        self::assertSame(['FC', 590, '19'], [$invoice['charges'][0]['reason_code'], $invoice['charges'][0]['amount_cents'], $invoice['charges'][0]['tax_rate']]);
        self::assertSame(['3', 10000, 300], [$invoice['allowances'][0]['percent'], $invoice['allowances'][0]['base_cents'], $invoice['allowances'][0]['amount_cents']]);
        self::assertSame([1955, 139], array_column($invoice['tax_groups'], 'amount_cents'));
        self::assertSame([
            'line_total_cents' => 11980, 'allowance_total_cents' => 300, 'charge_total_cents' => 590, 'net_cents' => 12270,
            'tax_cents' => 2094, 'gross_cents' => 14364, 'paid_cents' => 2000, 'due_cents' => 12364,
        ], $invoice['totals']);
    }

    public static function unreadable(): array
    {
        return [
            'empty' => ['', 'Not a PDF'],
            'text' => ['Rechnung 42', 'Not a PDF'],
            'other XML' => ['<?xml version="1.0"?><order/>', 'Unknown XML'],
            'PDF without XML' => ["%PDF-1.4\n%%EOF", 'no e-invoice XML'],
        ];
    }

    #[DataProvider('unreadable')]
    public function testRejectsContentThatIsNoEInvoice(string $content, string $message): void
    {
        $this->expectException(ReadException::class);
        $this->expectExceptionMessage($message);
        $this->belegwerk()->read($content);
    }

    private function belegwerk(): Belegwerk
    {
        return new Belegwerk('http://127.0.0.1:9');
    }
}
