<?php

declare(strict_types=1);

namespace Belegwerk\Tests;

use Belegwerk\Belegwerk;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Validates generated XRechnung XML with the official Schematron rules (EN 16931 CII and XRechnung),
 * compiled to XSLT and shipped by phive-rules on Maven Central. Needs Java; the jars are cached in the
 * system temp directory. Run without it: phpunit --exclude-group schematron
 */
#[Group('schematron')]
final class SchematronTest extends TestCase
{
    private const JARS = [
        'saxon.jar' => 'net/sf/saxon/Saxon-HE/12.5/Saxon-HE-12.5.jar',
        'xmlresolver.jar' => 'org/xmlresolver/xmlresolver/5.2.2/xmlresolver-5.2.2.jar',
        'en16931.jar' => 'com/helger/phive/rules/phive-rules-en16931/4.6.0/phive-rules-en16931-4.6.0.jar',
        'xrechnung.jar' => 'com/helger/phive/rules/phive-rules-xrechnung/4.6.0/phive-rules-xrechnung-4.6.0.jar',
    ];
    private const RULES = [
        'en16931.jar' => 'external/schematron/1.3.16/cii/EN16931-CII-validation.xslt',
        'xrechnung.jar' => 'external/schematron/3.0.2/XRechnung-CII-validation.xslt',
    ];

    private static string $cache;

    public static function setUpBeforeClass(): void
    {
        exec('java -version 2>&1', $output, $code);
        if ($code !== 0) {
            self::markTestSkipped('Java is not installed.');
        }

        self::$cache = sys_get_temp_dir() . '/belegwerk-schematron';
        @mkdir(self::$cache);
        foreach (self::JARS as $file => $path) {
            $target = self::$cache . '/' . $file;
            if (!is_file($target) && !@copy('https://repo1.maven.org/maven2/' . $path, $target)) {
                self::markTestSkipped("Could not download {$path}.");
            }
        }
        foreach (self::RULES as $jar => $xslt) {
            if (!is_file(self::$cache . '/' . $xslt)) {
                $zip = new ZipArchive();
                $zip->open(self::$cache . '/' . $jar);
                $zip->extractTo(self::$cache, $xslt);
                $zip->close();
            }
        }
    }

    public static function invoices(): array
    {
        $standard = InvoiceNormalizerTest::example();

        $zeroRated = static function (array $invoice, string $category): array {
            foreach ($invoice['items'] as &$item) {
                $item['tax_rate'] = 0;
                $item['tax_category'] = $category;
            }
            return $invoice;
        };

        $smallBusiness = $zeroRated($standard, 'E');
        unset($smallBusiness['seller']['vat_id']);
        $smallBusiness['tax_exemption_reason'] = 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.';

        $reverseCharge = $zeroRated($standard, 'AE');
        $reverseCharge['buyer']['vat_id'] = 'ATU12345678';
        $reverseCharge['buyer']['address'] = ['street' => 'Ring 1', 'zip' => '1010', 'city' => 'Wien', 'country' => 'AT'];

        $creditNote = $standard;
        $creditNote['type_code'] = '381';

        $negativeLine = $standard;
        $negativeLine['items'][] = ['description' => 'Korrektur', 'unit' => 'LS', 'unit_price_cents' => -1000];

        return [
            'standard' => [$standard],
            'small business (§ 19 UStG)' => [$smallBusiness],
            'reverse charge' => [$reverseCharge],
            'credit note' => [$creditNote],
            'negative line' => [$negativeLine],
        ];
    }

    #[DataProvider('invoices')]
    public function testXRechnungPassesOfficialRules(array $invoice): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'belegwerk');
        file_put_contents($file, (new Belegwerk('http://127.0.0.1:9'))->xrechnung($invoice));

        foreach (self::RULES as $xslt) {
            self::assertSame([], $this->violations($file, self::$cache . '/' . $xslt), basename($xslt));
        }
        unlink($file);
    }

    /** @return list<string> fatal or error assertions that failed */
    private function violations(string $file, string $xslt): array
    {
        $classpath = self::$cache . '/saxon.jar' . PATH_SEPARATOR . self::$cache . '/xmlresolver.jar';
        $command = sprintf('java -cp %s net.sf.saxon.Transform -s:%s -xsl:%s 2>/dev/null', escapeshellarg($classpath), escapeshellarg($file), escapeshellarg($xslt));
        $report = new DOMDocument();
        self::assertTrue(@$report->loadXML((string) shell_exec($command)), 'The Schematron run produced no report.');

        $xpath = new DOMXPath($report);
        $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
        self::assertGreaterThan(0, $xpath->query('//svrl:fired-rule')->length, 'No Schematron rule was applied.');

        $violations = [];
        foreach ($xpath->query('//svrl:failed-assert[@flag != "warning"]') as $assert) {
            $violations[] = trim(preg_replace('/\s+/', ' ', $assert->textContent) ?? '');
        }
        return $violations;
    }
}
