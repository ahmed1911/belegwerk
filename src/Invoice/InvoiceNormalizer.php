<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DateTimeImmutable;
use horstoeko\zugferd\codelistsenum\ZugferdCountryCodes;
use horstoeko\zugferd\codelistsenum\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelistsenum\ZugferdUnitCodes;

/**
 * Validates invoice data and calculates lines, tax groups and totals.
 *
 * Amounts are integer cents: line amount = round(quantity × unit price), tax per category and rate =
 * round(base × rate / 100). The result contains only arrays and scalars, so it serves both as the
 * input for the e-invoice XML and as the context of (sandboxed) Twig templates.
 *
 * @internal
 */
final class InvoiceNormalizer
{
    /** Invoice type codes (UNTDID 1001) allowed by XRechnung. */
    public const TYPE_CODES = ['326', '380', '381', '384', '389', '875', '876', '877'];

    /** Supported VAT categories (UNTDID 5305). */
    public const TAX_CATEGORIES = ['S', 'Z', 'E', 'AE', 'K', 'G'];

    /** Units (UN/ECE Rec. 20) with their German display label. */
    public const UNITS = [
        'H87' => 'Stk.', 'C62' => 'Stk.', 'HUR' => 'Std.', 'MIN' => 'Min.', 'DAY' => 'Tag(e)', 'WEE' => 'Woche(n)',
        'MON' => 'Monat(e)', 'ANN' => 'Jahr(e)', 'LS' => 'pauschal', 'KGM' => 'kg', 'GRM' => 'g', 'TNE' => 't',
        'MTR' => 'm', 'KMT' => 'km', 'MTK' => 'm²', 'MTQ' => 'm³', 'LTR' => 'l', 'KWH' => 'kWh', 'SET' => 'Set',
        'PR' => 'Paar', 'XPK' => 'Paket',
    ];

    /** Common German unit names and their code. */
    private const UNIT_ALIASES = [
        'stk' => 'H87', 'stk.' => 'H87', 'stück' => 'H87', 'st' => 'H87', 'st.' => 'H87', 'x' => 'C62',
        'std' => 'HUR', 'std.' => 'HUR', 'stunde' => 'HUR', 'stunden' => 'HUR', 'h' => 'HUR',
        'min' => 'MIN', 'min.' => 'MIN', 'minute' => 'MIN', 'minuten' => 'MIN',
        'tag' => 'DAY', 'tage' => 'DAY', 'tag(e)' => 'DAY', 'woche' => 'WEE', 'wochen' => 'WEE',
        'monat' => 'MON', 'monate' => 'MON', 'monat(e)' => 'MON', 'jahr' => 'ANN', 'jahre' => 'ANN',
        'pauschal' => 'LS', 'pausch.' => 'LS', 'psch.' => 'LS', 'pauschale' => 'LS',
        'kg' => 'KGM', 'g' => 'GRM', 't' => 'TNE', 'm' => 'MTR', 'km' => 'KMT', 'm2' => 'MTK', 'm²' => 'MTK',
        'qm' => 'MTK', 'm3' => 'MTQ', 'm³' => 'MTQ', 'l' => 'LTR', 'liter' => 'LTR', 'kwh' => 'KWH',
        'set' => 'SET', 'paar' => 'PR', 'paket' => 'XPK',
    ];

    /** Printed on the invoice and written to the XML, hence German. */
    private const EXEMPTION_DEFAULTS = [
        'AE' => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge)',
        'K' => 'Steuerfreie innergemeinschaftliche Lieferung',
        'G' => 'Steuerfreie Ausfuhrlieferung',
    ];

    /** @var array<string, string> */
    private array $errors = [];

    public function normalize(array $data): array
    {
        $this->errors = [];

        $currency = $this->optionalString($data, 'currency') ?? 'EUR';
        if ($currency !== 'EUR') {
            $this->errors['currency'] = 'Only EUR is supported.';
        }
        $typeCode = (string) ($data['type_code'] ?? '380');
        if (!in_array($typeCode, self::TYPE_CODES, true)) {
            $this->errors['type_code'] = 'Allowed values: ' . implode(', ', self::TYPE_CODES) . '.';
        }

        $invoice = [
            'number' => $this->requiredString($data, 'number'),
            'type_code' => $typeCode,
            'currency' => $currency,
            'date' => $this->requiredDate($data, 'date'),
            'due_date' => $this->requiredDate($data, 'due_date'),
            'service_date' => $this->optionalDate($data, 'service_date'),
            'period' => $this->period($data),
            'buyer_reference' => $this->optionalString($data, 'buyer_reference'),
            'order_reference' => $this->optionalString($data, 'order_reference'),
            'payment_terms' => $this->optionalString($data, 'payment_terms'),
            'note' => $this->optionalString($data, 'note'),
            'tax_exemption_reason' => $this->optionalString($data, 'tax_exemption_reason'),
            'seller' => $this->seller($data['seller'] ?? null),
            'buyer' => $this->party($data['buyer'] ?? null, 'buyer', false),
            'bank' => $this->bank($data['bank'] ?? null),
            'lines' => $this->lines($data['items'] ?? null),
            'custom' => $this->custom($data),
        ];

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        $this->calculate($invoice);
        $this->checkTaxConsistency($invoice);

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
        return $invoice;
    }

    /**
     * Additional fields required by the XRechnung CIUS (invoices to German public authorities).
     */
    public function assertXRechnung(array $invoice): void
    {
        $errors = [];
        if ($invoice['buyer_reference'] === null) {
            $errors['buyer_reference'] = 'Required for XRechnung (e.g. the Leitweg-ID).';
        }
        foreach (['phone', 'email', 'electronic_address'] as $key) {
            if ($invoice['seller'][$key] === null) {
                $errors["seller.{$key}"] = 'Required for XRechnung.';
            }
        }
        if ($invoice['buyer']['electronic_address'] === null) {
            $errors['buyer.electronic_address'] = 'Required for XRechnung (e.g. an email address or the Leitweg-ID).';
        }
        foreach (['zip', 'city'] as $key) {
            if ($invoice['buyer']['address'][$key] === null) {
                $errors["buyer.address.{$key}"] = 'Required for XRechnung.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function calculate(array &$invoice): void
    {
        $groups = [];
        $net = 0;
        foreach ($invoice['lines'] as $line) {
            $key = $line['tax_category'] . '|' . $line['tax_rate'];
            $groups[$key] ??= ['category' => $line['tax_category'], 'rate' => $line['tax_rate'], 'base_cents' => 0];
            $groups[$key]['base_cents'] += $line['net_cents'];
            $net += $line['net_cents'];
        }

        $taxTotal = 0;
        foreach ($groups as &$group) {
            $group['amount_cents'] = (int) round($group['base_cents'] * (float) $group['rate'] / 100);
            $group['exemption_reason'] = $group['category'] === 'S' || $group['category'] === 'Z'
                ? null
                : ($invoice['tax_exemption_reason'] ?? self::EXEMPTION_DEFAULTS[$group['category']] ?? null);
            $taxTotal += $group['amount_cents'];
        }
        unset($group);

        $invoice['tax_groups'] = array_values($groups);
        $invoice['totals'] = ['net_cents' => $net, 'tax_cents' => $taxTotal, 'gross_cents' => $net + $taxTotal];
    }

    private function checkTaxConsistency(array $invoice): void
    {
        $categories = array_column($invoice['tax_groups'], 'category');
        $seller = $invoice['seller'];

        if ($seller['vat_id'] === null && $seller['tax_number'] === null) {
            $this->errors['seller.vat_id'] = 'The seller needs a vat_id or a tax_number.';
        }
        if (in_array('E', $categories, true) && $invoice['tax_exemption_reason'] === null) {
            $this->errors['tax_exemption_reason'] = 'Required for exempt lines (category E), e.g. '
                . '"Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG".';
        }
        if (array_intersect(['AE', 'K', 'G'], $categories) !== [] && $seller['vat_id'] === null) {
            $this->errors['seller.vat_id'] = 'Required for category AE, K or G.';
        }
        if (array_intersect(['AE', 'K'], $categories) !== [] && $invoice['buyer']['vat_id'] === null) {
            $this->errors['buyer.vat_id'] = 'Required for category AE or K.';
        }
        if ($invoice['period'] !== null && $invoice['period']['end'] < $invoice['period']['start']) {
            $this->errors['period.end'] = 'The period ends before it starts.';
        }
    }

    private function seller(mixed $data): array
    {
        $party = $this->party($data, 'seller', true);
        if (!is_array($data)) {
            return $party;
        }
        $party['tax_number'] = $this->optionalString($data, 'tax_number', 'seller.');
        $party['phone'] = $this->optionalString($data, 'phone', 'seller.');
        $party['contact_name'] ??= $party['name'];
        return $party;
    }

    private function party(mixed $data, string $path, bool $streetRequired): array
    {
        if (!is_array($data)) {
            $this->errors[$path] = 'Required (object).';
            return [];
        }
        $prefix = $path . '.';
        $email = $this->optionalString($data, 'email', $prefix);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$prefix . 'email'] = 'Invalid email address.';
        }

        $electronic = $data['electronic_address'] ?? null;
        if ($electronic === null) {
            $electronic = $email !== null ? ['scheme' => 'EM', 'id' => $email] : null;
        } elseif (is_string($electronic) && trim($electronic) !== '') {
            $electronic = ['scheme' => 'EM', 'id' => trim($electronic)];
        } elseif (is_array($electronic) && is_string($electronic['scheme'] ?? null) && is_string($electronic['id'] ?? null)
            && trim($electronic['scheme']) !== '' && trim($electronic['id']) !== '') {
            $electronic = ['scheme' => trim($electronic['scheme']), 'id' => trim($electronic['id'])];
        } else {
            $this->errors[$prefix . 'electronic_address'] = 'Expected an email address or {"scheme": "0204", "id": "..."}.';
            $electronic = null;
        }
        if ($electronic !== null && ZugferdElectronicAddressScheme::tryFrom($electronic['scheme']) === null) {
            $this->errors[$prefix . 'electronic_address'] = "Unknown electronic address scheme \"{$electronic['scheme']}\" (EAS code list).";
        }

        return [
            'name' => $this->requiredString($data, 'name', $prefix),
            'address' => $this->address($data['address'] ?? null, $prefix . 'address', $streetRequired),
            'vat_id' => $this->optionalString($data, 'vat_id', $prefix),
            'email' => $email,
            'contact_name' => $this->optionalString($data, 'contact_name', $prefix),
            'electronic_address' => $electronic,
        ];
    }

    private function address(mixed $data, string $path, bool $streetRequired): array
    {
        if (!is_array($data)) {
            $this->errors[$path] = 'Required (object with street, zip, city, country).';
            return ['street' => null, 'additional' => null, 'zip' => null, 'city' => null, 'country' => 'DE'];
        }
        $prefix = $path . '.';
        $country = strtoupper($this->optionalString($data, 'country', $prefix) ?? 'DE');
        if (ZugferdCountryCodes::tryFrom($country) === null) {
            $this->errors[$prefix . 'country'] = 'Expected an ISO 3166-1 alpha-2 code, e.g. "DE".';
        }
        return [
            'street' => $streetRequired ? $this->requiredString($data, 'street', $prefix) : $this->optionalString($data, 'street', $prefix),
            'additional' => $this->optionalString($data, 'additional', $prefix),
            'zip' => $streetRequired ? $this->requiredString($data, 'zip', $prefix) : $this->optionalString($data, 'zip', $prefix),
            'city' => $this->requiredString($data, 'city', $prefix),
            'country' => $country,
        ];
    }

    private function bank(mixed $data): array
    {
        if (!is_array($data)) {
            $this->errors['bank'] = 'Required (object with holder, iban).';
            return [];
        }
        $iban = $this->requiredString($data, 'iban', 'bank.');
        if ($iban !== null) {
            $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
            if (!self::isValidIban($iban)) {
                $this->errors['bank.iban'] = 'Invalid IBAN.';
            }
        }
        return [
            'holder' => $this->requiredString($data, 'holder', 'bank.'),
            'iban' => $iban,
            'bic' => $this->optionalString($data, 'bic', 'bank.'),
            'name' => $this->optionalString($data, 'name', 'bank.'),
        ];
    }

    private function lines(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            $this->errors['items'] = 'Required (list with at least one line).';
            return [];
        }

        $lines = [];
        foreach ($items as $index => $item) {
            $prefix = "items.{$index}.";
            if (!is_array($item)) {
                $this->errors[rtrim($prefix, '.')] = 'Expected an object.';
                continue;
            }

            $description = $this->requiredString($item, 'description', $prefix);
            $name = $this->optionalString($item, 'name', $prefix);
            if ($name === null && $description !== null) {
                $name = mb_substr(strtok($description, "\n") ?: $description, 0, 200);
            }

            $quantity = $item['quantity'] ?? 1;
            if (!is_int($quantity) && !is_float($quantity) && !(is_string($quantity) && is_numeric($quantity))
                || abs((float) $quantity - round((float) $quantity, 3)) > 1e-9) {
                $this->errors[$prefix . 'quantity'] = 'Expected a number with at most 3 decimals.';
                $quantity = 0;
            }

            $price = $item['unit_price_cents'] ?? null;
            if (!is_int($price)) {
                $this->errors[$prefix . 'unit_price_cents'] = 'Expected integer cents.';
                $price = 0;
            }

            [$unitCode, $unitLabel] = $this->unit($item, $prefix);
            [$category, $rate] = $this->tax($item, $prefix);

            $lines[] = [
                'id' => (string) ($index + 1),
                'name' => $name,
                'description' => $description,
                'quantity' => self::formatDecimal((float) $quantity, 3),
                'unit' => $unitLabel,
                'unit_code' => $unitCode,
                'unit_price_cents' => $price,
                'net_cents' => (int) round((float) $quantity * $price),
                'tax_category' => $category,
                'tax_rate' => $rate,
                'custom' => $this->custom($item, $prefix),
            ];
        }
        return $lines;
    }

    /** @return array{0: string, 1: string} */
    private function unit(array $item, string $prefix): array
    {
        $unit = $this->optionalString($item, 'unit', $prefix);
        $code = $this->optionalString($item, 'unit_code', $prefix);

        if ($code === null && $unit !== null) {
            $code = self::UNIT_ALIASES[mb_strtolower($unit)] ?? ZugferdUnitCodes::tryFrom(strtoupper($unit))?->value;
            if ($code === null) {
                $this->errors[$prefix . 'unit_code'] = "Unknown unit \"{$unit}\"; pass a unit_code (UN/ECE Rec. 20, e.g. H87, HUR, LS).";
                $code = 'H87';
            }
        }
        $code ??= 'H87';
        if (ZugferdUnitCodes::tryFrom($code) === null) {
            $this->errors[$prefix . 'unit_code'] = 'Unknown unit code (UN/ECE Rec. 20/21 expected).';
        }
        if ($unit === null || strtoupper($unit) === $code) {
            $unit = self::UNITS[$code] ?? $code;
        }
        return [$code, $unit];
    }

    /** @return array{0: string, 1: string} */
    private function tax(array $item, string $prefix): array
    {
        $rate = $item['tax_rate'] ?? 19;
        if (!is_int($rate) && !is_float($rate) && !(is_string($rate) && is_numeric($rate)) || $rate < 0 || $rate > 100) {
            $this->errors[$prefix . 'tax_rate'] = 'Expected a percentage, e.g. 19 or 7.';
            $rate = 0;
        }
        $rate = self::formatDecimal((float) $rate, 2);

        $category = strtoupper($this->optionalString($item, 'tax_category', $prefix) ?? ($rate === '0' ? 'Z' : 'S'));
        if (!in_array($category, self::TAX_CATEGORIES, true)) {
            $this->errors[$prefix . 'tax_category'] = 'Allowed values: ' . implode(', ', self::TAX_CATEGORIES) . '.';
        } elseif ($category === 'S' && $rate === '0') {
            $this->errors[$prefix . 'tax_rate'] = 'Category S needs a rate above 0.';
        } elseif ($category !== 'S' && $rate !== '0') {
            $this->errors[$prefix . 'tax_rate'] = "Category {$category} requires the rate 0.";
        }
        return [$category, $rate];
    }

    private function period(array $data): ?array
    {
        if (!isset($data['period'])) {
            return null;
        }
        if (!is_array($data['period'])) {
            $this->errors['period'] = 'Expected an object with start and end (YYYY-MM-DD).';
            return null;
        }
        $start = $this->requiredDate($data['period'], 'start', 'period.');
        $end = $this->requiredDate($data['period'], 'end', 'period.');
        return $start !== null && $end !== null ? ['start' => $start, 'end' => $end] : null;
    }

    /** Free-form template data, passed through unchecked. */
    private function custom(array $data, string $prefix = ''): array
    {
        $custom = $data['custom'] ?? [];
        if (!is_array($custom)) {
            $this->errors[$prefix . 'custom'] = 'Expected an object.';
            return [];
        }
        return $custom;
    }

    private function requiredString(array $data, string $key, string $prefix = ''): ?string
    {
        $value = $this->optionalString($data, $key, $prefix);
        if ($value === null && !isset($this->errors[$prefix . $key])) {
            $this->errors[$prefix . $key] = 'Required.';
        }
        return $value;
    }

    private function optionalString(array $data, string $key, string $prefix = ''): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            $this->errors[$prefix . $key] = 'Expected a string.';
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function requiredDate(array $data, string $key, string $prefix = ''): ?string
    {
        if (!isset($data[$key])) {
            $this->errors[$prefix . $key] = 'Required (YYYY-MM-DD).';
            return null;
        }
        return $this->optionalDate($data, $key, $prefix);
    }

    private function optionalDate(array $data, string $key, string $prefix = ''): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$prefix . $key] = 'Expected a date in YYYY-MM-DD format.';
            return null;
        }
        return $value;
    }

    public static function formatDecimal(float $value, int $decimals): string
    {
        $formatted = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
        return $formatted === '-0' ? '0' : $formatted;
    }

    public static function isValidIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }
        return $remainder === 1;
    }
}
