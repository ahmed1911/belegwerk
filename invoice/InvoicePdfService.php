<?php

declare(strict_types=1);

final class InvoicePdfService
{
    public function __construct(private readonly ?string $gotenbergUrl = null)
    {
    }

    /**
     * All money values are already calculated integer cents. Dates are DateTimeInterface.
     * Required: number, date, due_date, company, customer, bank, items, taxes, totals.
     * company/customer: name, address (array of lines); company also: legal (array of lines),
     * optional logo_path (local PNG/JPEG/SVG). bank: holder, iban, optional bic, name.
     * items: description, quantity (up to 3 decimals), unit, unit_price_cents, total_cents.
     * taxes: rate (percent), base_cents, amount_cents. totals: net_cents, gross_cents.
     * Optional layout fields: service_date (DateTimeInterface), period, occasion,
     * project, orderer, section_title, note; company.city.
     */
    public function renderPdf(array $invoice): string
    {
        $url = $this->gotenbergUrl ?? (getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3000');
        if (!is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('GOTENBERG_URL ist nicht konfiguriert.');
        }

        $this->validate($invoice);
        $logo = null;
        $logoName = null;

        if (!empty($invoice['company']['logo_path'])) {
            $path = $invoice['company']['logo_path'];
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException('Das Rechnungslogo ist nicht lesbar.');
            }
            $mime = mime_content_type($path);
            $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'];
            if (!isset($extensions[$mime])) {
                throw new InvalidArgumentException('Das Rechnungslogo muss PNG, JPEG oder SVG sein.');
            }
            $logo = file_get_contents($path);
            if ($logo === false) {
                throw new RuntimeException('Das Rechnungslogo konnte nicht gelesen werden.');
            }
            $logoName = 'invoice-logo.' . $extensions[$mime];
        }

        $html = $this->renderHtml($invoice, $logoName);
        $css = file_get_contents(__DIR__ . '/invoice.css');
        if ($css === false) {
            throw new RuntimeException('Das Rechnungs-CSS konnte nicht gelesen werden.');
        }

        $boundary = 'invoice-' . bin2hex(random_bytes(16));
        $parts = [
            $this->filePart($boundary, 'index.html', 'text/html; charset=UTF-8', $html),
            $this->filePart($boundary, 'invoice.css', 'text/css; charset=UTF-8', $css),
            $this->filePart($boundary, 'footer.html', 'text/html; charset=UTF-8', $this->renderFooter($invoice)),
        ];
        if ($logo !== null) {
            $parts[] = $this->filePart($boundary, $logoName, $mime, $logo);
        }
        $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"preferCssPageSize\"\r\n\r\ntrue\r\n";
        $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"printBackground\"\r\n\r\ntrue\r\n";
        $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"failOnResourceLoadingFailed\"\r\n\r\ntrue\r\n";
        $body = implode('', $parts) . "--{$boundary}--\r\n";

        $curl = curl_init(rtrim($url, '/') . '/forms/chromium/convert/html');
        if ($curl === false) {
            throw new RuntimeException('Gotenberg-Anfrage konnte nicht vorbereitet werden.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Content-Type: multipart/form-data; boundary={$boundary}", 'Accept: application/pdf'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
        ]);
        $pdf = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($pdf === false) {
            throw new RuntimeException('Gotenberg ist nicht erreichbar: ' . $error);
        }
        if ($status !== 200 || !str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Gotenberg konnte die Rechnung nicht rendern (HTTP ' . $status . '): ' . substr(trim($pdf), 0, 500));
        }
        return $pdf;
    }

    private function renderHtml(array $invoice, ?string $logoName): string
    {
        ob_start();
        try {
            require __DIR__ . '/invoice.php';
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    private function renderFooter(array $invoice): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $address = $e(implode(', ', $invoice['company']['address']));
        $legal = $e(implode(' · ', $invoice['company']['legal']));
        $bankName = $e($invoice['bank']['name'] ?? $invoice['bank']['holder']);
        $bic = !empty($invoice['bank']['bic']) ? '<br>BIC ' . $e($invoice['bank']['bic']) : '';
        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'html,body{margin:0;padding:0;font-family:Arial,sans-serif;color:#666;font-size:9px;line-height:1.15}'
            . '*{box-sizing:border-box}.wrap{width:100%;padding:0 18.5mm}.page{text-align:right;margin:0 0 13mm}'
            . '.columns{display:flex;gap:5mm;border-top:1px solid #ddd;padding-top:3mm}'
            . '.col{width:33.333%;overflow-wrap:anywhere}.col b{font-weight:400;color:#444}'
            . '</style></head><body><div class="wrap">'
            . '<div class="page">Seite <span class="pageNumber"></span> von <span class="totalPages"></span></div>'
            . '<div class="columns"><div class="col"><b>Firmenanschrift</b><br>' . $e($invoice['company']['name']) . '<br>' . $address . '</div>'
            . '<div class="col"><b>Firmendaten</b><br>' . $legal . '</div>'
            . '<div class="col"><b>Bankverbindung</b><br>' . $bankName . '<br>IBAN ' . $e($invoice['bank']['iban']) . $bic . '</div></div>'
            . '</div></body></html>';
    }

    private function filePart(string $boundary, string $name, string $mime, string $content): string
    {
        return "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"{$name}\"\r\nContent-Type: {$mime}\r\n\r\n{$content}\r\n";
    }

    private function validate(array $invoice): void
    {
        foreach (['number', 'date', 'due_date', 'company', 'customer', 'bank', 'items', 'taxes', 'totals'] as $key) {
            if (!array_key_exists($key, $invoice)) {
                throw new InvalidArgumentException("Rechnungsfeld fehlt: {$key}");
            }
        }
        foreach (['date', 'due_date'] as $key) {
            if (!$invoice[$key] instanceof DateTimeInterface) {
                throw new InvalidArgumentException("{$key} muss ein Datum sein.");
            }
        }
        if (isset($invoice['service_date']) && !$invoice['service_date'] instanceof DateTimeInterface) {
            throw new InvalidArgumentException('service_date muss ein Datum sein.');
        }
        if (!is_string($invoice['number']) || trim($invoice['number']) === '') {
            throw new InvalidArgumentException('number muss eine Rechnungsnummer sein.');
        }
        if (isset($invoice['note']) && !is_string($invoice['note'])) {
            throw new InvalidArgumentException('note muss Text sein.');
        }
        foreach (['period', 'occasion', 'project', 'orderer', 'section_title'] as $key) {
            if (isset($invoice[$key]) && !is_string($invoice[$key])) {
                throw new InvalidArgumentException("{$key} muss Text sein.");
            }
        }
        foreach (['company', 'customer'] as $key) {
            if (!is_array($invoice[$key]) || !is_string($invoice[$key]['name'] ?? null)
                || trim($invoice[$key]['name']) === '' || !is_array($invoice[$key]['address'] ?? null)
                || $invoice[$key]['address'] === []) {
                throw new InvalidArgumentException("{$key} benötigt name und address.");
            }
            foreach ($invoice[$key]['address'] as $line) {
                if (!is_string($line)) {
                    throw new InvalidArgumentException("{$key}.address darf nur Textzeilen enthalten.");
                }
            }
        }
        if (!is_array($invoice['company']['legal'] ?? null) || $invoice['company']['legal'] === []) {
            throw new InvalidArgumentException('company.legal benötigt die rechtlich relevanten Firmendaten.');
        }
        foreach ($invoice['company']['legal'] as $line) {
            if (!is_string($line)) {
                throw new InvalidArgumentException('company.legal darf nur Textzeilen enthalten.');
            }
        }
        if (isset($invoice['company']['city']) && !is_string($invoice['company']['city'])) {
            throw new InvalidArgumentException('company.city muss Text sein.');
        }
        if (!is_array($invoice['bank']) || !is_string($invoice['bank']['holder'] ?? null)
            || trim($invoice['bank']['holder']) === '' || !is_string($invoice['bank']['iban'] ?? null)
            || trim($invoice['bank']['iban']) === '') {
            throw new InvalidArgumentException('bank benötigt holder und iban.');
        }
        foreach (['name', 'bic'] as $key) {
            if (isset($invoice['bank'][$key]) && !is_string($invoice['bank'][$key])) {
                throw new InvalidArgumentException("bank.{$key} muss Text sein.");
            }
        }
        if (!is_array($invoice['items']) || $invoice['items'] === [] || !is_array($invoice['taxes'])
            || !is_array($invoice['totals'])) {
            throw new InvalidArgumentException('items, taxes und totals müssen vorhanden sein.');
        }
        foreach ($invoice['items'] as $item) {
            if (!is_array($item) || !is_string($item['description'] ?? null)
                || !isset($item['quantity'], $item['unit'], $item['unit_price_cents'], $item['total_cents'])
                || !is_numeric($item['quantity']) || !is_string($item['unit'])
                || abs((float) $item['quantity'] - round((float) $item['quantity'], 3)) > 1e-9
                || !is_int($item['unit_price_cents']) || !is_int($item['total_cents'])) {
                throw new InvalidArgumentException('Eine Rechnungsposition ist unvollständig.');
            }
        }
        foreach ($invoice['taxes'] as $tax) {
            if (!is_array($tax) || !isset($tax['rate'], $tax['base_cents'], $tax['amount_cents'])
                || !is_numeric($tax['rate']) || !is_int($tax['base_cents']) || !is_int($tax['amount_cents'])) {
                throw new InvalidArgumentException('Ein Steuersatz ist unvollständig.');
            }
        }
        foreach (['net_cents', 'gross_cents'] as $key) {
            if (!is_int($invoice['totals'][$key] ?? null)) {
                throw new InvalidArgumentException("totals.{$key} muss ein Cent-Betrag sein.");
            }
        }
    }
}
