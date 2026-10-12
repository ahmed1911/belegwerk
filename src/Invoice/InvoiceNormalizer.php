<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DateTimeImmutable;
use horstoeko\zugferd\codelistsenum\ZugferdAllowanceCodes;
use horstoeko\zugferd\codelistsenum\ZugferdChargeCodes;
use horstoeko\zugferd\codelistsenum\ZugferdCountryCodes;
use horstoeko\zugferd\codelistsenum\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelistsenum\ZugferdUnitCodes;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Validation;

/**
 * Validates invoice data and calculates lines, tax groups and totals.
 *
 * Amounts are integer cents: line amount = round(quantity × unit price) − allowances + charges, percentage
 * allowances and charges = round(base × percent / 100), tax per category and rate = round(base × rate / 100),
 * all calculated exactly and rounded half away from zero. The result contains only
 * arrays and scalars, so it serves both as the input for the e-invoice XML and as the context of (sandboxed)
 * Twig templates.
 *
 * @internal
 */
final class InvoiceNormalizer
{
    /** Invoice type codes (UNTDID 1001) allowed by XRechnung. */
    private const TYPE_CODES = ['326', '380', '381', '384', '389', '875', '876', '877'];

    /** Supported VAT categories (UNTDID 5305). */
    private const TAX_CATEGORIES = ['S', 'Z', 'E', 'AE', 'K', 'G'];

    /** Units (UN/ECE Rec. 20) with their German display label; the labels are accepted as input, too. */
    private const UNITS = [
        'H87' => 'Stk.', 'C62' => 'Stk.', 'HUR' => 'Std.', 'MIN' => 'Min.', 'DAY' => 'Tag(e)', 'WEE' => 'Woche(n)',
        'MON' => 'Monat(e)', 'ANN' => 'Jahr(e)', 'LS' => 'pauschal', 'KGM' => 'kg', 'GRM' => 'g', 'TNE' => 't',
        'MTR' => 'm', 'KMT' => 'km', 'MTK' => 'm²', 'MTQ' => 'm³', 'LTR' => 'l', 'KWH' => 'kWh', 'SET' => 'Set',
        'PR' => 'Paar', 'XPK' => 'Paket',
    ];

    /** Common German unit names besides the labels in UNITS, and their code. */
    private const UNIT_ALIASES = [
        'stk' => 'H87', 'stück' => 'H87', 'st' => 'H87', 'st.' => 'H87', 'x' => 'C62',
        'std' => 'HUR', 'stunde' => 'HUR', 'stunden' => 'HUR', 'h' => 'HUR',
        'min' => 'MIN', 'minute' => 'MIN', 'minuten' => 'MIN',
        'tag' => 'DAY', 'tage' => 'DAY', 'woche' => 'WEE', 'wochen' => 'WEE',
        'monat' => 'MON', 'monate' => 'MON', 'jahr' => 'ANN', 'jahre' => 'ANN',
        'pausch.' => 'LS', 'psch.' => 'LS', 'pauschale' => 'LS',
        'm2' => 'MTK', 'qm' => 'MTK', 'm3' => 'MTQ', 'liter' => 'LTR',
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

        $directDebit = $this->directDebit($data['direct_debit'] ?? null);
        // With a direct debit the seller's bank account is optional; templates may still print it.
        $bankOmitted = $directDebit !== null && !isset($data['bank']);
        $paid = $data['paid_cents'] ?? 0;
        if (!is_int($paid) || $paid < 0) {
            $this->errors['paid_cents'] = 'Expected non-negative integer cents.';
            $paid = 0;
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
            'preceding_invoice' => $this->precedingInvoice($data, $typeCode),
            'payment_terms' => $this->optionalString($data, 'payment_terms'),
            'note' => $this->optionalString($data, 'note'),
            'tax_exemption_reason' => $this->optionalString($data, 'tax_exemption_reason'),
            'seller' => $this->seller($data['seller'] ?? null),
            'buyer' => $this->party($data['buyer'] ?? null, 'buyer', false),
            'bank' => $bankOmitted ? null : $this->bank($data['bank'] ?? null),
            'direct_debit' => $directDebit,
            'cash_discount' => $this->cashDiscount($data['cash_discount'] ?? null),
            'lines' => $this->lines($data['items'] ?? null),
            'allowances' => $this->adjustments($data, 'allowances', '', true),
            'charges' => $this->adjustments($data, 'charges', '', true),
            'custom' => $this->custom($data),
        ];

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        $this->calculate($invoice, $paid);
        $this->checkConsistency($invoice);

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
        if ($invoice['buyer']['address']['zip'] === null) {
            $errors['buyer.address.zip'] = 'Required for XRechnung.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function calculate(array &$invoice, int $paid): void
    {
        $groups = [];
        $lineTotal = 0;
        foreach ($invoice['lines'] as $line) {
            $key = $line['tax_category'] . '|' . $line['tax_rate'];
            $groups[$key] ??= ['category' => $line['tax_category'], 'rate' => $line['tax_rate'], 'base_cents' => 0];
            $groups[$key]['base_cents'] += $line['net_cents'];
            $lineTotal += $line['net_cents'];
        }

        // Percentages on the whole invoice refer to the line amounts, not to each other.
        $lineGroups = $groups;
        $adjustmentTotals = [];
        foreach (['allowances' => -1, 'charges' => 1] as $type => $sign) {
            $invoice[$type] = $this->resolveAdjustments($invoice[$type], $type, $lineGroups);
            $adjustmentTotals[$type] = 0;
            foreach ($invoice[$type] as $adjustment) {
                $key = $adjustment['tax_category'] . '|' . $adjustment['tax_rate'];
                $groups[$key] ??= ['category' => $adjustment['tax_category'], 'rate' => $adjustment['tax_rate'], 'base_cents' => 0];
                $groups[$key]['base_cents'] += $sign * $adjustment['amount_cents'];
                $adjustmentTotals[$type] += $adjustment['amount_cents'];
            }
        }
        $net = $lineTotal - $adjustmentTotals['allowances'] + $adjustmentTotals['charges'];

        $taxTotal = 0;
        foreach ($groups as &$group) {
            $group['amount_cents'] = self::roundedProduct(2, (string) $group['base_cents'], $group['rate']);
            $group['exemption_reason'] = $group['category'] === 'S' || $group['category'] === 'Z'
                ? null
                : ($invoice['tax_exemption_reason'] ?? self::EXEMPTION_DEFAULTS[$group['category']] ?? null);
            $taxTotal += $group['amount_cents'];
        }
        unset($group);

        $invoice['tax_groups'] = array_values($groups);
        $gross = $net + $taxTotal;
        $due = $gross - $paid;
        $invoice['totals'] = [
            'line_total_cents' => $lineTotal,
            'allowance_total_cents' => $adjustmentTotals['allowances'],
            'charge_total_cents' => $adjustmentTotals['charges'],
            'net_cents' => $net,
            'tax_cents' => $taxTotal,
            'gross_cents' => $gross,
            'paid_cents' => $paid,
            'due_cents' => $due,
        ];

        // XRechnung applies a cash discount without BASISBETRAG to the amount due (BT-115).
        if ($invoice['cash_discount'] !== null) {
            $discount = self::roundedProduct(2, (string) $due, $invoice['cash_discount']['percent']);
            $invoice['cash_discount'] += [
                'date' => (new DateTimeImmutable($invoice['date']))->modify("+{$invoice['cash_discount']['days']} days")->format('Y-m-d'),
                'amount_cents' => $discount,
                'payable_cents' => $due - $discount,
            ];
        }
    }

    /**
     * Resolves allowances or charges on the whole invoice to one VAT category and rate each (EN 16931 BG-20/BG-21).
     * A percentage without tax_rate or tax_category applies to every tax group of the lines; an amount without
     * them takes the tax of the lines, if they all have the same.
     *
     * @param array<string, array{category: string, rate: string, base_cents: int}> $lineGroups
     */
    private function resolveAdjustments(array $adjustments, string $type, array $lineGroups): array
    {
        $resolved = [];
        foreach ($adjustments as $index => $adjustment) {
            $tax = $adjustment['tax'];
            unset($adjustment['tax']);
            if ($tax !== null) {
                $targets = [$lineGroups[$tax[0] . '|' . $tax[1]] ?? ['category' => $tax[0], 'rate' => $tax[1], 'base_cents' => null]];
            } elseif ($adjustment['percent'] !== null || count($lineGroups) === 1) {
                $targets = $lineGroups;
            } else {
                $this->errors["{$type}.{$index}.tax_rate"] = 'Required when the lines have different VAT rates.';
                continue;
            }

            foreach ($targets as $group) {
                if ($adjustment['percent'] !== null && $group['base_cents'] === null) {
                    $this->errors["{$type}.{$index}.tax_rate"] = 'No line has this VAT category and rate.';
                    continue;
                }
                $resolved[] = self::adjustmentAmount($adjustment, $group['base_cents']) + [
                    'tax_category' => $group['category'],
                    'tax_rate' => $group['rate'],
                ];
            }
        }
        return $resolved;
    }

    /** Calculates the amount of a percentage allowance or charge from its base. */
    private static function adjustmentAmount(array $adjustment, ?int $base): array
    {
        $percent = $adjustment['percent'];
        return [
            'reason' => $adjustment['reason'],
            'reason_code' => $adjustment['reason_code'],
            'percent' => $percent,
            'base_cents' => $percent !== null ? $base : null,
            'amount_cents' => $percent !== null ? self::roundedProduct(2, (string) $base, $percent) : $adjustment['amount_cents'],
        ];
    }

    /**
     * Multiplies decimal strings exactly, divides by 10^$shift and rounds half away from zero to an integer,
     * e.g. roundedProduct(0, '0.58', '25') = 15, where float math yields 14.
     */
    private static function roundedProduct(int $shift, string ...$factors): int
    {
        $digits = 1;
        $scale = $shift;
        foreach ($factors as $factor) {
            $digits *= (int) str_replace('.', '', $factor);
            $scale += self::decimalPlaces($factor);
        }
        if (!is_int($digits)) {
            throw new ValidationException(['items' => 'Amounts exceed the supported range.']);
        }
        $divisor = 10 ** $scale;
        $magnitude = abs($digits);
        $rounded = intdiv($magnitude, $divisor) + ($magnitude % $divisor * 2 >= $divisor ? 1 : 0);
        return $digits < 0 ? -$rounded : $rounded;
    }

    private function checkConsistency(array $invoice): void
    {
        if ($invoice['totals']['paid_cents'] > max(0, $invoice['totals']['gross_cents'])) {
            $this->errors['paid_cents'] = 'Exceeds the invoice total.';
        }
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
        $streetAndZip = $streetRequired ? $this->requiredString(...) : $this->optionalString(...);
        return [
            'street' => $streetAndZip($data, 'street', $prefix),
            'additional' => $this->optionalString($data, 'additional', $prefix),
            'zip' => $streetAndZip($data, 'zip', $prefix),
            'city' => $this->requiredString($data, 'city', $prefix),
            'country' => $country,
        ];
    }

    private function bank(mixed $data): array
    {
        if (!is_array($data)) {
            $this->errors['bank'] = 'Required (object with holder, iban) unless direct_debit is given.';
            return [];
        }
        return [
            'holder' => $this->requiredString($data, 'holder', 'bank.'),
            'iban' => $this->iban($data, 'bank.'),
            'bic' => $this->optionalString($data, 'bic', 'bank.'),
            'name' => $this->optionalString($data, 'name', 'bank.'),
        ];
    }

    private function iban(array $data, string $prefix): ?string
    {
        $iban = $this->requiredString($data, 'iban', $prefix);
        if ($iban === null) {
            return null;
        }
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (!Validation::createIsValidCallable(new Iban())($iban)) {
            $this->errors[$prefix . 'iban'] = 'Invalid IBAN.';
        }
        return $iban;
    }

    /** SEPA direct debit (payment means 59): BT-89 mandate, BT-90 creditor ID, BT-91 debited account. */
    private function directDebit(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            $this->errors['direct_debit'] = 'Expected an object with mandate_reference, creditor_id and iban.';
            return null;
        }
        return [
            'mandate_reference' => $this->requiredString($data, 'mandate_reference', 'direct_debit.'),
            'creditor_id' => $this->requiredString($data, 'creditor_id', 'direct_debit.'),
            'iban' => $this->iban($data, 'direct_debit.'),
        ];
    }

    private function cashDiscount(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data)) {
            $this->errors['cash_discount'] = 'Expected an object with days and percent.';
            return null;
        }
        $days = $data['days'] ?? null;
        if (!is_int($days) || $days < 1) {
            $this->errors['cash_discount.days'] = 'Expected a positive integer.';
        }
        $percent = $data['percent'] ?? null;
        if (self::isInvalidDecimal($percent, 2) || (float) $percent <= 0 || (float) $percent >= 100) {
            $this->errors['cash_discount.percent'] = 'Expected a number between 0 and 100 with at most 2 decimals.';
            $percent = 0;
        }
        return ['days' => $days, 'percent' => self::formatDecimal((float) $percent, 2)];
    }

    private static function isInvalidDecimal(mixed $value, int $maxDecimals): bool
    {
        return !is_numeric($value) || abs((float) $value - round((float) $value, $maxDecimals)) > 1e-9;
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
            if (self::isInvalidDecimal($quantity, 6)) {
                $this->errors[$prefix . 'quantity'] = 'Expected a number with at most 6 decimals.';
                $quantity = 0;
            }

            $quantity = self::formatDecimal((float) $quantity, 6);

            $price = $item['unit_price_cents'] ?? null;
            if (!is_int($price)) {
                $this->errors[$prefix . 'unit_price_cents'] = 'Expected integer cents.';
                $price = 0;
            }

            [$unitCode, $unitLabel] = $this->unit($item, $prefix);
            [$category, $rate] = $this->tax($item, $prefix);

            $subtotal = self::roundedProduct(0, $quantity, (string) $price);
            $net = $subtotal;
            $adjustments = [];
            foreach (['allowances' => -1, 'charges' => 1] as $type => $sign) {
                $adjustments[$type] = array_map(
                    static fn (array $adjustment): array => self::adjustmentAmount($adjustment, $subtotal),
                    $this->adjustments($item, $type, $prefix, false),
                );
                $net += $sign * array_sum(array_column($adjustments[$type], 'amount_cents'));
            }

            $lines[] = [
                'id' => (string) ($index + 1),
                'name' => $name,
                'description' => $description,
                'quantity' => $quantity,
                'unit' => $unitLabel,
                'unit_code' => $unitCode,
                'unit_price_cents' => $price,
                'subtotal_cents' => $subtotal,
                'allowances' => $adjustments['allowances'],
                'charges' => $adjustments['charges'],
                'net_cents' => $net,
                'tax_category' => $category,
                'tax_rate' => $rate,
                'custom' => $this->custom($item, $prefix),
            ];
        }
        return $lines;
    }

    /**
     * Allowances or charges of a line or of the whole invoice: reason, amount_cents or percent, optional reason_code
     * (UNTDID 5189 for allowances, 7161 for charges). On the whole invoice also tax_rate and tax_category.
     */
    private function adjustments(array $data, string $type, string $prefix, bool $withTax): array
    {
        $entries = $data[$type] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            $this->errors[$prefix . $type] = 'Expected a list.';
            return [];
        }

        $adjustments = [];
        foreach ($entries as $index => $entry) {
            $path = "{$prefix}{$type}.{$index}.";
            if (!is_array($entry)) {
                $this->errors[rtrim($path, '.')] = 'Expected an object with reason and amount_cents or percent.';
                continue;
            }

            $amount = $entry['amount_cents'] ?? null;
            $percent = $entry['percent'] ?? null;
            if (($amount === null) === ($percent === null)) {
                $this->errors[$path . 'amount_cents'] = 'Expected either amount_cents or percent.';
            } elseif ($amount !== null && (!is_int($amount) || $amount <= 0)) {
                $this->errors[$path . 'amount_cents'] = 'Expected positive integer cents.';
            } elseif ($percent !== null && (self::isInvalidDecimal($percent, 2) || (float) $percent <= 0 || (float) $percent > 100)) {
                $this->errors[$path . 'percent'] = 'Expected a number above 0 and up to 100 with at most 2 decimals.';
            }

            $code = $this->optionalString($entry, 'reason_code', $path);
            $knownCode = $type === 'allowances'
                ? ctype_digit((string) $code) && ZugferdAllowanceCodes::tryFrom((int) $code) !== null
                : ZugferdChargeCodes::tryFrom((string) $code) !== null;
            if ($code !== null && !$knownCode) {
                $this->errors[$path . 'reason_code'] = $type === 'allowances'
                    ? 'Unknown allowance reason code (UNTDID 5189, e.g. 95 for a discount).'
                    : 'Unknown charge reason code (UNTDID 7161, e.g. FC for freight).';
            }

            $adjustment = [
                'reason' => $this->requiredString($entry, 'reason', $path),
                'reason_code' => $code,
                'percent' => is_numeric($percent) ? self::formatDecimal((float) $percent, 2) : null,
                'amount_cents' => is_int($amount) ? $amount : 0,
            ];
            if ($withTax) {
                $adjustment['tax'] = isset($entry['tax_rate']) || isset($entry['tax_category']) ? $this->tax($entry, $path) : null;
            }
            $adjustments[] = $adjustment;
        }
        return $adjustments;
    }

    /** @return array{0: string, 1: string} */
    private function unit(array $item, string $prefix): array
    {
        $unit = $this->optionalString($item, 'unit', $prefix);
        $code = $this->optionalString($item, 'unit_code', $prefix);

        if ($code === null && $unit !== null) {
            $lower = mb_strtolower($unit);
            // array_search returns the first match, so "Stk." maps to H87 rather than C62.
            $code = self::UNIT_ALIASES[$lower]
                ?? (array_search($lower, array_map(mb_strtolower(...), self::UNITS), true) ?: null)
                ?? ZugferdUnitCodes::tryFrom(strtoupper($unit))?->value;
            if ($code === null) {
                $this->errors[$prefix . 'unit_code'] = "Unknown unit \"{$unit}\"; pass a unit_code (UN/ECE Rec. 20, e.g. H87, HUR, LS).";
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
        // A category other than S defaults to the rate 0, which is the only one it allows.
        $rate = $item['tax_rate'] ?? (strtoupper((string) ($item['tax_category'] ?? 'S')) === 'S' ? 19 : 0);
        if (!is_numeric($rate) || $rate < 0 || $rate > 100) {
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
        if ($start === null || $end === null) {
            return null;
        }
        if ($end < $start) {
            $this->errors['period.end'] = 'The period ends before it starts.';
        }
        return ['start' => $start, 'end' => $end];
    }

    /** BT-25/BT-26: the invoice a credit note (381) or corrected invoice (384) refers to. */
    private function precedingInvoice(array $data, string $typeCode): ?array
    {
        if (!isset($data['preceding_invoice'])) {
            if ($typeCode === '384') {
                $this->errors['preceding_invoice'] = 'Required for a corrected invoice (type 384).';
            }
            return null;
        }
        if (!is_array($data['preceding_invoice'])) {
            $this->errors['preceding_invoice'] = 'Expected an object with number and optional date (YYYY-MM-DD).';
            return null;
        }
        $number = $this->requiredString($data['preceding_invoice'], 'number', 'preceding_invoice.');
        $date = $this->optionalDate($data['preceding_invoice'], 'date', 'preceding_invoice.');
        return $number !== null ? ['number' => $number, 'date' => $date] : null;
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

    private static function formatDecimal(float $value, int $decimals): string
    {
        $formatted = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
        return $formatted === '-0' ? '0' : $formatted;
    }

    public static function decimalPlaces(string $decimal): int
    {
        $point = strpos($decimal, '.');
        return $point === false ? 0 : strlen($decimal) - $point - 1;
    }
}
