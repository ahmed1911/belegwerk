<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Prüft das API-JSON einer Rechnung und berechnet Positionen, Steuergruppen und Summen.
 *
 * Beträge werden als ganze Cent erwartet und berechnet: Positionsbetrag = round(Menge × Einzelpreis),
 * Steuer je Kategorie/Satz = round(Bemessungsgrundlage × Satz / 100). Das Ergebnis ist ein
 * normalisiertes Array, aus dem sowohl das PDF als auch das CII-XML erzeugt werden.
 */
final class InvoiceNormalizer
{
    /** Erlaubte Rechnungsarten (UNTDID 1001) laut XRechnung. */
    public const TYPE_CODES = ['326', '380', '381', '384', '389', '875', '876', '877'];

    /** Unterstützte Umsatzsteuerkategorien (UNTDID 5305). */
    public const TAX_CATEGORIES = ['S', 'Z', 'E', 'AE', 'K', 'G'];

    /** Einheiten (UN/ECE Rec. 20) mit deutscher Anzeige; Schlüssel sind die Codes. */
    public const UNITS = [
        'H87' => 'Stk.', 'C62' => 'Stk.', 'HUR' => 'Std.', 'MIN' => 'Min.', 'DAY' => 'Tag(e)', 'WEE' => 'Woche(n)',
        'MON' => 'Monat(e)', 'ANN' => 'Jahr(e)', 'LS' => 'pauschal', 'KGM' => 'kg', 'GRM' => 'g', 'TNE' => 't',
        'MTR' => 'm', 'KMT' => 'km', 'MTK' => 'm²', 'MTQ' => 'm³', 'LTR' => 'l', 'KWH' => 'kWh', 'SET' => 'Set',
        'PR' => 'Paar', 'XPK' => 'Paket',
    ];

    /** Gängige deutsche Einheitenbezeichnungen und ihr Code. */
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

    private const EXEMPTION_DEFAULTS = [
        'AE' => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge)',
        'K' => 'Steuerfreie innergemeinschaftliche Lieferung',
        'G' => 'Steuerfreie Ausfuhrlieferung',
    ];

    /** @var array<string, string> */
    private array $errors = [];

    public function normalize(mixed $data): array
    {
        $this->errors = [];
        if (!is_array($data) || array_is_list($data)) {
            throw new ValidationException(['' => 'Es wird ein JSON-Objekt erwartet.']);
        }

        $currency = $this->optionalString($data, 'currency') ?? 'EUR';
        if ($currency !== 'EUR') {
            $this->errors['currency'] = 'Derzeit wird nur EUR unterstützt.';
        }
        $typeCode = (string) ($data['type_code'] ?? '380');
        if (!in_array($typeCode, self::TYPE_CODES, true)) {
            $this->errors['type_code'] = 'Erlaubt sind: ' . implode(', ', self::TYPE_CODES) . '.';
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
            'occasion' => $this->optionalString($data, 'occasion'),
            'project' => $this->optionalString($data, 'project'),
            'orderer' => $this->optionalString($data, 'orderer'),
            'section_title' => $this->optionalString($data, 'section_title'),
            'tax_exemption_reason' => $this->optionalString($data, 'tax_exemption_reason'),
            'seller' => $this->seller($data['seller'] ?? null),
            'buyer' => $this->buyer($data['buyer'] ?? null),
            'bank' => $this->bank($data['bank'] ?? null),
            'lines' => $this->lines($data['items'] ?? null),
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
     * Zusätzliche Pflichtangaben für den Empfang durch öffentliche Auftraggeber (XRechnung-CIUS).
     */
    public function assertXRechnung(array $invoice): void
    {
        $errors = [];
        if ($invoice['buyer_reference'] === null) {
            $errors['buyer_reference'] = 'Für XRechnung ist die Käuferreferenz (z. B. Leitweg-ID) Pflicht.';
        }
        if ($invoice['seller']['phone'] === null) {
            $errors['seller.phone'] = 'Für XRechnung ist eine Telefonnummer des Verkäufers Pflicht.';
        }
        if ($invoice['seller']['email'] === null) {
            $errors['seller.email'] = 'Für XRechnung ist eine E-Mail-Adresse des Verkäufers Pflicht.';
        }
        if ($invoice['seller']['electronic_address'] === null) {
            $errors['seller.electronic_address'] = 'Für XRechnung ist eine elektronische Adresse des Verkäufers Pflicht.';
        }
        if ($invoice['buyer']['electronic_address'] === null) {
            $errors['buyer.electronic_address'] = 'Für XRechnung ist eine elektronische Adresse des Käufers Pflicht (z. B. E-Mail oder Leitweg-ID).';
        }
        foreach (['zip', 'city'] as $key) {
            if ($invoice['buyer']['address'][$key] === null) {
                $errors["buyer.address.{$key}"] = 'Für XRechnung ist dieses Feld Pflicht.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Wandelt die normalisierte Rechnung in das Array-Format von InvoicePdfService um.
     */
    public function toPdfData(array $invoice): array
    {
        $seller = $invoice['seller'];
        $formatDate = static fn (DateTimeImmutable $date): string => $date->format('d.m.Y');
        $period = $invoice['period'] !== null
            ? $formatDate($invoice['period']['start']) . ' – ' . $formatDate($invoice['period']['end'])
            : null;

        $taxes = [];
        foreach ($invoice['tax_groups'] as $group) {
            $rate = self::formatRate($group['rate']);
            $taxes[] = [
                'rate' => $group['rate'],
                'base_cents' => $group['base_cents'],
                'amount_cents' => $group['amount_cents'],
                'label' => match ($group['category']) {
                    'S' => "zzgl. USt. {$rate} %",
                    'Z' => 'USt. 0 % (Nullsatz)',
                    'E' => 'USt. 0 % (steuerbefreit)',
                    'AE' => 'USt. 0 % (Reverse Charge)',
                    'K' => 'USt. 0 % (innergemeinschaftliche Lieferung)',
                    'G' => 'USt. 0 % (Ausfuhrlieferung)',
                },
            ];
        }
        $taxNotes = array_values(array_unique(array_filter(array_column($invoice['tax_groups'], 'exemption_reason'))));

        return array_filter([
            'number' => $invoice['number'],
            'date' => $invoice['date'],
            'due_date' => $invoice['due_date'],
            'service_date' => $invoice['service_date'],
            'period' => $period,
            'occasion' => $invoice['occasion'],
            'project' => $invoice['project'],
            'orderer' => $invoice['orderer'],
            'buyer_reference' => $invoice['buyer_reference'],
            'section_title' => $invoice['section_title'],
            'note' => $invoice['note'],
            'tax_note' => $taxNotes === [] ? null : implode("\n", $taxNotes),
            'company' => array_filter([
                'name' => $seller['name'],
                'address' => self::addressLines($seller['address']),
                'legal' => $seller['legal'],
                'city' => $seller['address']['city'],
                'logo_data' => $seller['logo_data'],
            ], static fn ($value) => $value !== null),
            'customer' => [
                'name' => $invoice['buyer']['name'],
                'address' => self::addressLines($invoice['buyer']['address']),
            ],
            'bank' => array_filter(
                ['iban' => trim(chunk_split($invoice['bank']['iban'], 4, ' '))] + $invoice['bank'],
                static fn ($value) => $value !== null,
            ),
            'items' => array_map(static fn (array $line): array => [
                'description' => $line['description'] ?? $line['name'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price_cents' => $line['unit_price_cents'],
                'total_cents' => $line['net_cents'],
            ], $invoice['lines']),
            'taxes' => $taxes,
            'totals' => ['net_cents' => $invoice['totals']['net_cents'], 'gross_cents' => $invoice['totals']['gross_cents']],
        ], static fn ($value) => $value !== null);
    }

    public static function formatRate(string $rate): string
    {
        return str_replace('.', ',', $rate);
    }

    /** @return list<string> */
    public static function addressLines(array $address): array
    {
        $lines = array_filter([$address['street'], $address['additional']]);
        $city = trim(($address['zip'] ?? '') . ' ' . ($address['city'] ?? ''));
        if ($city !== '') {
            $lines[] = $city;
        }
        if ($address['country'] !== 'DE') {
            $lines[] = $address['country'];
        }
        return array_values($lines);
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
            $this->errors['seller.vat_id'] = 'Verkäufer benötigt vat_id oder tax_number.';
        }
        if (in_array('E', $categories, true) && $invoice['tax_exemption_reason'] === null) {
            $this->errors['tax_exemption_reason'] = 'Für steuerbefreite Positionen (Kategorie E) ist ein Befreiungsgrund Pflicht, '
                . 'z. B. "Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG".';
        }
        if (array_intersect(['AE', 'K', 'G'], $categories) !== [] && $seller['vat_id'] === null) {
            $this->errors['seller.vat_id'] = 'Für Kategorie AE, K oder G ist die USt-IdNr. des Verkäufers Pflicht.';
        }
        if (array_intersect(['AE', 'K'], $categories) !== [] && $invoice['buyer']['vat_id'] === null) {
            $this->errors['buyer.vat_id'] = 'Für Kategorie AE oder K ist die USt-IdNr. des Käufers Pflicht.';
        }
        if ($invoice['period'] !== null && $invoice['period']['end'] < $invoice['period']['start']) {
            $this->errors['period.end'] = 'Das Ende des Leistungszeitraums liegt vor dem Beginn.';
        }
    }

    private function seller(mixed $data): array
    {
        $party = $this->party($data, 'seller', true);
        if (!is_array($data)) {
            return $party;
        }

        $logo = null;
        if (isset($data['logo'])) {
            $logo = is_string($data['logo']) ? base64_decode(preg_replace('#^data:[^,]*,#', '', $data['logo']) ?? '', true) : false;
            if ($logo === false || $logo === '') {
                $this->errors['seller.logo'] = 'Das Logo muss als Base64 (PNG, JPEG oder SVG) übergeben werden.';
                $logo = null;
            }
        }

        $party += [
            'tax_number' => $this->optionalString($data, 'tax_number', 'seller.'),
            'register' => $this->optionalString($data, 'register', 'seller.'),
            'phone' => $this->optionalString($data, 'phone', 'seller.'),
            'logo_data' => $logo,
        ];
        $party['contact_name'] ??= $party['name'];

        $legal = $this->optionalStringList($data, 'legal', 'seller.');
        if ($legal === null) {
            $legal = array_values(array_filter([
                $party['register'],
                $party['vat_id'] !== null ? 'USt-IdNr.: ' . $party['vat_id'] : null,
                $party['tax_number'] !== null ? 'Steuernr.: ' . $party['tax_number'] : null,
                $party['phone'] !== null ? 'Tel.: ' . $party['phone'] : null,
                $party['email'],
            ]));
        }
        $party['legal'] = $legal === [] ? [$party['name'] ?? ''] : $legal;
        return $party;
    }

    private function buyer(mixed $data): array
    {
        return $this->party($data, 'buyer', false);
    }

    private function party(mixed $data, string $path, bool $streetRequired): array
    {
        if (!is_array($data)) {
            $this->errors[$path] = 'Pflichtfeld (Objekt).';
            return [];
        }
        $prefix = $path . '.';
        $email = $this->optionalString($data, 'email', $prefix);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$prefix . 'email'] = 'Ungültige E-Mail-Adresse.';
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
            $this->errors[$prefix . 'electronic_address'] = 'Erwartet E-Mail-Adresse oder {"scheme": "0204", "id": "..."}.';
            $electronic = null;
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
            $this->errors[$path] = 'Pflichtfeld (Objekt mit street, zip, city, country).';
            return ['street' => null, 'additional' => null, 'zip' => null, 'city' => null, 'country' => 'DE'];
        }
        $prefix = $path . '.';
        $country = strtoupper($this->optionalString($data, 'country', $prefix) ?? 'DE');
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $this->errors[$prefix . 'country'] = 'Ländercode nach ISO 3166-1 alpha-2 erwartet, z. B. "DE".';
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
            $this->errors['bank'] = 'Pflichtfeld (Objekt mit holder, iban).';
            return [];
        }
        $iban = $this->requiredString($data, 'iban', 'bank.');
        if ($iban !== null) {
            $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
            if (!self::isValidIban($iban)) {
                $this->errors['bank.iban'] = 'Ungültige IBAN.';
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
            $this->errors['items'] = 'Mindestens eine Position (Liste) ist Pflicht.';
            return [];
        }

        $lines = [];
        foreach ($items as $index => $item) {
            $prefix = "items.{$index}.";
            if (!is_array($item)) {
                $this->errors[rtrim($prefix, '.')] = 'Position muss ein Objekt sein.';
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
                $this->errors[$prefix . 'quantity'] = 'Zahl mit höchstens 3 Nachkommastellen erwartet.';
                $quantity = 0;
            }

            $price = $item['unit_price_cents'] ?? null;
            if (!is_int($price)) {
                $this->errors[$prefix . 'unit_price_cents'] = 'Einzelpreis in ganzen Cent (Integer) erwartet.';
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
            $code = self::UNIT_ALIASES[mb_strtolower($unit)] ?? (isset(self::UNITS[strtoupper($unit)]) ? strtoupper($unit) : null);
            if ($code === null) {
                $this->errors[$prefix . 'unit_code'] = "Einheit \"{$unit}\" ist unbekannt; bitte unit_code (UN/ECE Rec. 20, z. B. H87, HUR, LS) angeben.";
                $code = 'H87';
            }
        }
        $code ??= 'H87';
        if (!preg_match('/^[A-Z0-9]{1,3}$/', $code)) {
            $this->errors[$prefix . 'unit_code'] = 'Ungültiger Einheitencode (UN/ECE Rec. 20 erwartet).';
        }
        if ($unit === null || isset(self::UNITS[strtoupper($unit)]) && strtoupper($unit) === $code) {
            $unit = self::UNITS[$code] ?? $code;
        }
        return [$code, $unit];
    }

    /** @return array{0: string, 1: string} */
    private function tax(array $item, string $prefix): array
    {
        $rate = $item['tax_rate'] ?? 19;
        if (!is_int($rate) && !is_float($rate) && !(is_string($rate) && is_numeric($rate)) || $rate < 0 || $rate > 100) {
            $this->errors[$prefix . 'tax_rate'] = 'Steuersatz in Prozent erwartet, z. B. 19 oder 7.';
            $rate = 0;
        }
        $rate = self::formatDecimal((float) $rate, 2);

        $category = strtoupper($this->optionalString($item, 'tax_category', $prefix) ?? ($rate === '0' ? 'Z' : 'S'));
        if (!in_array($category, self::TAX_CATEGORIES, true)) {
            $this->errors[$prefix . 'tax_category'] = 'Erlaubt sind: ' . implode(', ', self::TAX_CATEGORIES) . '.';
        } elseif ($category === 'S' && $rate === '0') {
            $this->errors[$prefix . 'tax_rate'] = 'Kategorie S benötigt einen Steuersatz größer 0.';
        } elseif ($category !== 'S' && $rate !== '0') {
            $this->errors[$prefix . 'tax_rate'] = "Kategorie {$category} erfordert den Steuersatz 0.";
        }
        return [$category, $rate];
    }

    private function period(array $data): ?array
    {
        if (!isset($data['period'])) {
            return null;
        }
        if (!is_array($data['period'])) {
            $this->errors['period'] = 'Objekt mit start und end (YYYY-MM-DD) erwartet.';
            return null;
        }
        $start = $this->requiredDate($data['period'], 'start', 'period.');
        $end = $this->requiredDate($data['period'], 'end', 'period.');
        return $start !== null && $end !== null ? ['start' => $start, 'end' => $end] : null;
    }

    private function requiredString(array $data, string $key, string $prefix = ''): ?string
    {
        $value = $this->optionalString($data, $key, $prefix);
        if ($value === null && !isset($this->errors[$prefix . $key])) {
            $this->errors[$prefix . $key] = 'Pflichtfeld.';
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
            $this->errors[$prefix . $key] = 'Text erwartet.';
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @return list<string>|null */
    private function optionalStringList(array $data, string $key, string $prefix): ?array
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!is_array($data[$key]) || !array_is_list($data[$key])
            || array_filter($data[$key], static fn ($line) => !is_string($line)) !== []) {
            $this->errors[$prefix . $key] = 'Liste von Textzeilen erwartet.';
            return null;
        }
        return $data[$key];
    }

    private function requiredDate(array $data, string $key, string $prefix = ''): ?DateTimeImmutable
    {
        if (!isset($data[$key])) {
            $this->errors[$prefix . $key] = 'Pflichtfeld (YYYY-MM-DD).';
            return null;
        }
        return $this->optionalDate($data, $key, $prefix);
    }

    private function optionalDate(array $data, string $key, string $prefix = ''): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $date = is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Berlin'))
            : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$prefix . $key] = 'Datum im Format YYYY-MM-DD erwartet.';
            return null;
        }
        return $date;
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
