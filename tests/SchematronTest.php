<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use horstoeko\zugferd\ZugferdKositValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Validates generated XRechnung XML with the official KoSIT validator (XSD, EN 16931 and XRechnung Schematron).
 * Needs Java; validator and rules are downloaded once and cached in the system temp directory.
 * With KOSIT_URL set, a running KoSIT daemon is used instead. Run without it: phpunit --exclude-group schematron
 */
#[Group('schematron')]
final class SchematronTest extends TestCase
{
    /** XRechnung 3.0.2 with CEN rules 1.3.16; horstoeko's default configuration is older. */
    private const CONFIGURATION = 'https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/v2026-08-31/xrechnung-3.0.2-validator-configuration-2026-08-31.zip';

    public static function invoices(): array
    {
        $standard = InvoiceNormalizerTest::example();

        $smallBusiness = InvoiceNormalizerTest::zeroRated($standard, 'E');
        unset($smallBusiness['seller']['vat_id']);
        $smallBusiness['tax_exemption_reason'] = 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.';

        $reverseCharge = InvoiceNormalizerTest::zeroRated($standard, 'AE');
        $reverseCharge['buyer']['vat_id'] = 'ATU12345678';
        $reverseCharge['buyer']['address'] = ['street' => 'Ring 1', 'zip' => '1010', 'city' => 'Wien', 'country' => 'AT'];

        $creditNote = $standard;
        $creditNote['type_code'] = '381';
        $creditNote['preceding_invoice'] = ['number' => 'RE-2026-0041', 'date' => '2026-03-01'];

        $correctedInvoice = $standard;
        $correctedInvoice['type_code'] = '384';
        $correctedInvoice['preceding_invoice'] = ['number' => 'RE-2026-0041'];

        $cashDiscount = $standard;
        $cashDiscount['cash_discount'] = ['days' => 14, 'percent' => 2];

        $directDebit = $standard;
        unset($directDebit['bank']);
        $directDebit['direct_debit'] = ['mandate_reference' => 'M-7', 'creditor_id' => 'DE98ZZZ09999999999', 'iban' => 'DE02120300000000202051'];

        $partiallyPaid = $standard;
        $partiallyPaid['paid_cents'] = 50000;

        $fullyPaid = $standard;
        $fullyPaid['paid_cents'] = 106237;

        $negativeLine = $standard;
        $negativeLine['items'][] = ['description' => 'Korrektur', 'unit' => 'LS', 'unit_price_cents' => -1000];

        return [
            'standard' => [$standard],
            'small business (§ 19 UStG)' => [$smallBusiness],
            'reverse charge' => [$reverseCharge],
            'credit note' => [$creditNote],
            'corrected invoice' => [$correctedInvoice],
            'negative line' => [$negativeLine],
            'cash discount' => [$cashDiscount],
            'direct debit' => [$directDebit],
            'partially paid' => [$partiallyPaid],
            'fully paid' => [$fullyPaid],
        ];
    }

    #[DataProvider('invoices')]
    public function testXRechnungPassesOfficialRules(array $invoice): void
    {
        $xml = (new Belegwerk('http://127.0.0.1:9'))->xrechnung($invoice);
        $daemon = getenv('KOSIT_URL');

        self::assertSame([], $daemon ? self::daemonErrors($daemon, $xml) : self::localErrors($xml));
    }

    private static function localErrors(string $xml): array
    {
        // Without cleanup the validator keeps its downloads for the next run.
        $validator = (new ZugferdKositValidator($xml))
            ->setBaseDirectory(self::cacheDirectory())
            ->setValidatorScenarioDownloadUrl(self::CONFIGURATION)
            ->disableCleanup()
            ->validate();

        if ($validator->hasProcessErrors()) {
            self::markTestSkipped(implode('; ', $validator->getProcessErrors()));
        }
        return array_values($validator->getValidationErrors());
    }

    /**
     * A KoSIT validator running as a daemon (KOSIT_URL, as in CI) skips the JVM start for every invoice.
     * It answers 200 for accepted and 406 for rejected documents; horstoeko's remote mode can miss a rejection.
     */
    private static function daemonErrors(string $url, string $xml): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $report = curl_exec($curl);
        if ($report === false) {
            self::fail("KoSIT daemon is not reachable at {$url}: " . curl_error($curl));
        }

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 200) {
            return [];
        }
        preg_match_all('#<rep:message\b[^>]*level="error"[^>]*>(.*?)</rep:message>#s', $report, $matches);
        return ["KoSIT rejected the invoice (HTTP {$status})", ...array_map(trim(...), $matches[1])];
    }

    public static function tearDownAfterClass(): void
    {
        // disableCleanup() also keeps every validated file and its report.
        array_map(unlink(...), glob(self::cacheDirectory() . '/kositvalidator-*/filetovalidate-*') ?: []);
    }

    private static function cacheDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/belegwerk-kosit';
        @mkdir($directory);
        return $directory;
    }
}
