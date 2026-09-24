<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use Belegwerk\Belegwerk;
use DateTimeImmutable;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdXsdValidator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Maps a normalized invoice (see InvoiceNormalizer) onto the ZugferdDocumentBuilder of horstoeko/zugferd,
 * which writes the CII XML for XRechnung and ZUGFeRD/Factur-X.
 *
 * @internal
 */
final class EInvoiceBuilder
{
    private const PROFILES = [
        Belegwerk::PROFILE_EN16931 => ZugferdProfiles::PROFILE_EN16931,
        Belegwerk::PROFILE_XRECHNUNG => ZugferdProfiles::PROFILE_XRECHNUNG_3,
    ];

    public function build(array $invoice, string $profile): ZugferdDocumentBuilder
    {
        $profileId = self::PROFILES[$profile] ?? throw new InvalidArgumentException("Unknown profile: {$profile}");
        $amount = static fn (int $cents): float => $cents / 100;
        $date = static fn (string $date): DateTimeImmutable => new DateTimeImmutable($date);

        $document = ZugferdDocumentBuilder::createNew($profileId)
            ->setDocumentBusinessProcess('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0')
            ->setDocumentInformation($invoice['number'], $invoice['type_code'], $date($invoice['date']), $invoice['currency']);
        if ($invoice['note'] !== null) {
            $document->addDocumentNote($invoice['note']);
        }
        if ($invoice['buyer_reference'] !== null) {
            $document->setDocumentBuyerReference($invoice['buyer_reference']);
        }
        if ($invoice['order_reference'] !== null) {
            $document->setDocumentBuyerOrderReferencedDocument($invoice['order_reference']);
        }

        $seller = $invoice['seller'];
        $document
            // BR-CO-26 needs a seller identifier; without a VAT ID the tax number serves as one (FeRD example E13_01).
            ->setDocumentSeller($seller['name'], $seller['vat_id'] === null ? $seller['tax_number'] : null)
            ->setDocumentSellerAddress(
                $seller['address']['street'],
                $seller['address']['additional'],
                null,
                $seller['address']['zip'],
                $seller['address']['city'],
                $seller['address']['country'],
            )
            ->setDocumentSellerContact($seller['contact_name'], null, $seller['phone'], null, $seller['email']);
        if ($seller['vat_id'] !== null) {
            $document->addDocumentSellerTaxRegistration('VA', $seller['vat_id']);
        }
        if ($seller['tax_number'] !== null) {
            $document->addDocumentSellerTaxRegistration('FC', $seller['tax_number']);
        }
        if ($seller['electronic_address'] !== null) {
            $document->setDocumentSellerCommunication($seller['electronic_address']['scheme'], $seller['electronic_address']['id']);
        }

        $buyer = $invoice['buyer'];
        $document
            ->setDocumentBuyer($buyer['name'])
            ->setDocumentBuyerAddress(
                $buyer['address']['street'],
                $buyer['address']['additional'],
                null,
                $buyer['address']['zip'],
                $buyer['address']['city'],
                $buyer['address']['country'],
            );
        if ($buyer['contact_name'] !== null) {
            $document->setDocumentBuyerContact($buyer['contact_name'], null, null, null, null);
        }
        if ($buyer['vat_id'] !== null) {
            $document->addDocumentBuyerTaxRegistration('VA', $buyer['vat_id']);
        }
        if ($buyer['electronic_address'] !== null) {
            $document->setDocumentBuyerCommunication($buyer['electronic_address']['scheme'], $buyer['electronic_address']['id']);
        }

        if ($invoice['service_date'] !== null) {
            $document->setDocumentSupplyChainEvent($date($invoice['service_date']));
        }
        if ($invoice['period'] !== null) {
            $document->setDocumentBillingPeriod($date($invoice['period']['start']), $date($invoice['period']['end']), null);
        }

        $document->addDocumentPaymentMeanToCreditTransfer(
            $invoice['bank']['iban'],
            $invoice['bank']['holder'],
            null,
            $invoice['bank']['bic'],
            $invoice['number'],
        );
        $document->addDocumentPaymentTerm(
            $invoice['payment_terms'] ?? 'Zahlbar bis zum ' . $date($invoice['due_date'])->format('d.m.Y') . ' ohne Abzug.',
            $date($invoice['due_date']),
        );

        foreach ($invoice['tax_groups'] as $group) {
            $document->addDocumentTax(
                $group['category'],
                'VAT',
                $amount($group['base_cents']),
                $amount($group['amount_cents']),
                (float) $group['rate'],
                $group['exemption_reason'],
            );
        }

        $totals = $invoice['totals'];
        $document->setDocumentSummation(
            $amount($totals['gross_cents']),
            $amount($totals['gross_cents']),
            $amount($totals['net_cents']),
            null,
            null,
            $amount($totals['net_cents']),
            $amount($totals['tax_cents']),
        );

        foreach ($invoice['lines'] as $line) {
            // EN 16931 forbids negative unit prices (BR-27), so the sign moves to the quantity.
            $negative = $line['unit_price_cents'] < 0;
            $document
                ->addNewPosition($line['id'])
                ->setDocumentPositionProductDetails(
                    $line['name'],
                    $line['description'] !== $line['name'] ? $line['description'] : null,
                )
                ->setDocumentPositionNetPrice($amount(abs($line['unit_price_cents'])))
                ->setDocumentPositionQuantity(($negative ? -1 : 1) * (float) $line['quantity'], $line['unit_code'])
                ->addDocumentPositionTax($line['tax_category'], 'VAT', (float) $line['tax_rate'])
                ->setDocumentPositionLineSummation($amount($line['net_cents']));
        }

        return $document;
    }

    /**
     * Writes the XML and checks it against the XSD schema of the profile.
     */
    public function toXml(array $invoice, string $profile): string
    {
        $document = $this->build($invoice, $profile);
        $validator = (new ZugferdXsdValidator($document))->validate();
        if ($validator->hasValidationErrors()) {
            throw new RuntimeException('The generated e-invoice XML violates the schema: ' . implode('; ', $validator->validationErrors()));
        }
        return $document->getContent();
    }
}
