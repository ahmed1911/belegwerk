<?php
/** @var array $invoice */
/** @var ?string $logoName */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$quantity = static fn (int|float|string $value): string => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
$date = static fn (DateTimeInterface $value): string => $value->format('d.m.Y');
$weekday = static fn (DateTimeInterface $value): string => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int) $value->format('w')];
$sender = $invoice['company']['name'] . ' | ' . implode(' | ', $invoice['company']['address']);
$period = $invoice['period'] ?? (isset($invoice['service_date']) ? $date($invoice['service_date']) : null);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Rechnung <?= $e($invoice['number']) ?></title>
    <link rel="stylesheet" href="invoice.css">
</head>
<body>
    <header class="letterhead">
        <?php if ($logoName !== null): ?>
            <img class="brand-logo" src="<?= $e($logoName) ?>" alt="">
        <?php else: ?>
            <div class="brand-name"><?= $e($invoice['company']['name']) ?></div>
        <?php endif; ?>
    </header>

    <div class="sender-line"><?= $e($sender) ?></div>
    <section class="address-block">
        <div class="recipient"><strong><?= $e($invoice['customer']['name']) ?></strong><?php foreach ($invoice['customer']['address'] as $line): ?><br><?= $e($line) ?><?php endforeach; ?></div>
        <div class="invoice-date"><?php if (!empty($invoice['company']['city'])): ?><?= $e($invoice['company']['city']) ?>, den <?php endif; ?><?= $date($invoice['date']) ?></div>
    </section>

    <section class="invoice-facts">
        <?php if ($period !== null): ?><div class="fact fact-stacked"><strong>Rechnung für Zeitraum:</strong><span><?= $e($period) ?></span></div><?php endif; ?>
        <?php if (!empty($invoice['occasion'])): ?><div class="fact"><strong>Anlass/Einsatzort:</strong><span><?= nl2br($e($invoice['occasion'])) ?></span></div><?php endif; ?>
        <div class="fact fact-stacked"><strong>Rechnungsnummer:</strong><span><?= $e($invoice['number']) ?></span></div>
        <?php if (!empty($invoice['project'])): ?><div class="fact"><strong>Kostenstelle/Projekt:</strong><span><?= $e($invoice['project']) ?></span></div><?php endif; ?>
        <?php if (!empty($invoice['buyer_reference'])): ?><div class="fact"><strong>Ihre Referenz:</strong><span><?= $e($invoice['buyer_reference']) ?></span></div><?php endif; ?>
        <?php if (!empty($invoice['orderer'])): ?><div class="fact fact-wide"><strong>Besteller:</strong><span><?= $e($invoice['orderer']) ?></span></div><?php endif; ?>
    </section>

    <?php if (!empty($invoice['note'])): ?><p class="intro"><?= nl2br($e($invoice['note'])) ?></p><?php endif; ?>
    <?php if (!empty($invoice['service_date'])): ?><h1 class="service-heading"><?= $weekday($invoice['service_date']) ?>, <?= $date($invoice['service_date']) ?></h1><?php endif; ?>

    <table class="items">
        <colgroup><col class="description-col"><col class="quantity-col"><col class="price-col"><col class="total-col"></colgroup>
        <thead><tr><th><?= $e($invoice['section_title'] ?? 'Leistungen') ?></th><th class="num">Anzahl</th><th class="num">Preis</th><th class="num">Gesamt</th></tr></thead>
        <tbody>
            <?php foreach ($invoice['items'] as $item): ?>
                <tr>
                    <td><?= nl2br($e($item['description'])) ?></td>
                    <td class="num"><?= $quantity($item['quantity']) ?> <?= $e($item['unit']) ?></td>
                    <td class="num"><?= $money($item['unit_price_cents']) ?></td>
                    <td class="num"><?= $money($item['total_cents']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <section class="invoice-totals" aria-label="Rechnungssummen">
        <div class="total-row"><span>Summe netto</span><span><?= $money($invoice['totals']['net_cents']) ?></span></div>
        <?php foreach ($invoice['taxes'] as $tax): ?>
            <div class="total-row"><span><?= isset($tax['label']) ? $e($tax['label']) : 'zzgl. USt. ' . $quantity($tax['rate']) . ' %' ?></span><span><?= $money($tax['amount_cents']) ?></span></div>
        <?php endforeach; ?>
        <div class="total-row grand-total"><span>Gesamtbetrag</span><strong><?= $money($invoice['totals']['gross_cents']) ?></strong></div>
        <?php if (!empty($invoice['tax_note'])): ?><p class="tax-note"><?= nl2br($e($invoice['tax_note'])) ?></p><?php endif; ?>
    </section>

    <section class="payment-info">
        Bitte überweisen Sie den Gesamtbetrag bis zum <?= $date($invoice['due_date']) ?> auf das unten genannte Konto<?= !empty($invoice['bank']['holder']) ? ' von ' . $e($invoice['bank']['holder']) : '' ?>.
    </section>
</body>
</html>
