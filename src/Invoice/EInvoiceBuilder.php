<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DateTimeImmutable;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdSettings;
use horstoeko\zugferd\ZugferdXsdValidator;
use RuntimeException;

/**
 * Maps a normalized invoice (see InvoiceNormalizer) onto the ZugferdDocumentBuilder of horstoeko/zugferd,
 * which writes the CII XML for XRechnung and ZUGFeRD/Factur-X.
 *
 * @internal
 */
final class EInvoiceBuilder
{
    /**
     * Writes the XML and checks it against the XSD schema of the profile.
     *
     * @param int $profile  a ZugferdProfiles::PROFILE_* constant
     */
    public function toXml(array $invoice, int $profile): string
    {
        // horstoeko reads the quantity decimals globally while serializing; widen them for this invoice only.
        $originalDecimals = ZugferdSettings::getQuantityDecimals();
        $quantityDecimals = array_map(InvoiceNormalizer::decimalPlaces(...), array_column($invoice['lines'], 'quantity'));
        ZugferdSettings::setQuantityDecimals(max($originalDecimals, ...$quantityDecimals));
        try {
            $document = $this->build($invoice, $profile);
            $validator = (new ZugferdXsdValidator($document))->validate();
            if ($validator->hasValidationErrors()) {
                throw new RuntimeException('The generated e-invoice XML violates the schema: ' . implode('; ', $validator->validationErrors()));
            }
            return $document->getContent();
        } finally {
            ZugferdSettings::setQuantityDecimals($originalDecimals);
        }
    }

    private function build(array $invoice, int $profile): ZugferdDocumentBuilder
    {
        $amount = static fn (int $cents): float => $cents / 100;
        $date = static fn (?string $date): ?DateTimeImmutable => $date === null ? null : new DateTimeImmutable($date);

        // horstoeko skips null values, so optional fields need no guard unless the parameter is a plain string.
        $document = ZugferdDocumentBuilder::createNew($profile)
            ->setDocumentBusinessProcess('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0')
            ->setDocumentInformation($invoice['number'], $invoice['type_code'], $date($invoice['date']), $invoice['currency'])
            ->setDocumentBuyerReference($invoice['buyer_reference'])
            ->setDocumentBuyerOrderReferencedDocument($invoice['order_reference']);
        if ($invoice['note'] !== null) {
            $document->addDocumentNote($invoice['note']);
        }
        if ($invoice['preceding_invoice'] !== null) {
            $preceding = $invoice['preceding_invoice'];
            $document->addDocumentInvoiceReferencedDocument(
                $preceding['number'],
                null,
                $date($preceding['date']),
            );
        }

        $seller = $invoice['seller'];
        $document
            // BR-CO-26 needs a seller identifier; without a VAT ID the tax number serves as one (FeRD example E13_01).
            ->setDocumentSeller($seller['name'], $seller['vat_id'] === null ? $seller['tax_number'] : null)
            ->setDocumentSellerAddress(...self::address($seller['address']))
            ->setDocumentSellerContact($seller['contact_name'], null, $seller['phone'], null, $seller['email'])
            ->addDocumentSellerTaxRegistration('VA', $seller['vat_id'])
            ->addDocumentSellerTaxRegistration('FC', $seller['tax_number'])
            ->setDocumentSellerCommunication($seller['electronic_address']['scheme'] ?? null, $seller['electronic_address']['id'] ?? null);

        $buyer = $invoice['buyer'];
        $document
            ->setDocumentBuyer($buyer['name'])
            ->setDocumentBuyerAddress(...self::address($buyer['address']))
            ->addDocumentBuyerTaxRegistration('VA', $buyer['vat_id'])
            ->setDocumentBuyerCommunication($buyer['electronic_address']['scheme'] ?? null, $buyer['electronic_address']['id'] ?? null)
            ->setDocumentSupplyChainEvent($date($invoice['service_date']))
            ->setDocumentBillingPeriod($date($invoice['period']['start'] ?? null), $date($invoice['period']['end'] ?? null), null);
        // The contact setter does not skip null; it would add an empty contact.
        if ($buyer['contact_name'] !== null) {
            $document->setDocumentBuyerContact($buyer['contact_name'], null, null, null, null);
        }

        $delivery = $invoice['delivery'];
        if ($delivery !== null) {
            // BT-71 with a scheme is a global ID (e.g. a GLN), without one an ID agreed with the buyer.
            $location = $delivery['location_id'];
            $global = $location !== null && $location['scheme'] !== null;
            $document->setDocumentShipTo($delivery['name'], $global ? null : $location['id'] ?? null);
            if ($global) {
                $document->addDocumentShipToGlobalId($location['id'], $location['scheme']);
            }
            if ($delivery['address'] !== null) {
                $document->setDocumentShipToAddress(...self::address($delivery['address']));
            }
        }
        if ($invoice['despatch_reference'] !== null) {
            $document->setDocumentDespatchAdviceReferencedDocument($invoice['despatch_reference']);
        }
        foreach ($invoice['attachments'] as $attachment) {
            // 916: supporting document (BG-24); horstoeko detects the MIME type from the content again.
            $document->addDocumentAdditionalReferencedDocument(
                $attachment['id'],
                '916',
                $attachment['url'],
                $attachment['description'],
                binaryDataFilename: $attachment['filename'],
                base64EncodedData: $attachment['content'] !== null ? base64_encode($attachment['content']) : null,
            );
        }

        $directDebit = $invoice['direct_debit'];
        if ($directDebit !== null) {
            $document->addDocumentPaymentMeanToDirectDebit($directDebit['iban'], $directDebit['creditor_id']);
        } else {
            $document->addDocumentPaymentMeanToCreditTransfer(
                $invoice['bank']['iban'],
                $invoice['bank']['holder'],
                null,
                $invoice['bank']['bic'],
                $invoice['number'],
            );
        }
        $discount = $invoice['cash_discount'];
        $document->addDocumentPaymentTermXRechnung(
            $invoice['payment_terms'] ?? $this->defaultPaymentTerms($invoice),
            $discount !== null ? [$discount['days']] : [],
            $discount !== null ? [(float) $discount['percent']] : [],
            [],
            $date($invoice['due_date']),
            $directDebit['mandate_reference'] ?? null,
        );

        foreach (['allowances' => false, 'charges' => true] as $type => $isCharge) {
            foreach ($invoice[$type] as $adjustment) {
                $document->addDocumentAllowanceCharge(
                    $amount($adjustment['amount_cents']),
                    $isCharge,
                    $adjustment['tax_category'],
                    'VAT',
                    (float) $adjustment['tax_rate'],
                    ...self::adjustmentDetails($adjustment),
                );
            }
        }

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
            $amount($totals['due_cents']),
            $amount($totals['line_total_cents']),
            $totals['charge_total_cents'] !== 0 ? $amount($totals['charge_total_cents']) : null,
            $totals['allowance_total_cents'] !== 0 ? $amount($totals['allowance_total_cents']) : null,
            $amount($totals['net_cents']),
            $amount($totals['tax_cents']),
            null,
            $totals['paid_cents'] !== 0 ? $amount($totals['paid_cents']) : null,
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
            foreach (['allowances' => false, 'charges' => true] as $type => $isCharge) {
                foreach ($line[$type] as $adjustment) {
                    $document->addDocumentPositionAllowanceCharge(
                        $amount($adjustment['amount_cents']),
                        $isCharge,
                        ...self::adjustmentDetails($adjustment),
                    );
                }
            }
        }
        return $document;
    }

    /** Named arguments shared by horstoeko's allowance and charge setters for the invoice and for a line. */
    private static function adjustmentDetails(array $adjustment): array
    {
        return [
            'calculationPercent' => $adjustment['percent'] !== null ? (float) $adjustment['percent'] : null,
            'basisAmount' => $adjustment['base_cents'] !== null ? $adjustment['base_cents'] / 100 : null,
            'reasonCode' => $adjustment['reason_code'],
            'reason' => $adjustment['reason'],
        ];
    }

    /** Arguments of set{Seller,Buyer}Address: lines one to three, zip, city, country. */
    private static function address(array $address): array
    {
        return [$address['street'], $address['additional'], null, $address['zip'], $address['city'], $address['country']];
    }

    /** BT-20 lands in the XML and is read by people, hence German. The cash discount line is prepended separately. */
    private function defaultPaymentTerms(array $invoice): string
    {
        $dueDate = (new DateTimeImmutable($invoice['due_date']))->format('d.m.Y');
        return match (true) {
            $invoice['totals']['due_cents'] <= 0 => 'Der Rechnungsbetrag ist bereits beglichen.',
            $invoice['direct_debit'] !== null => "Der Betrag wird zum {$dueDate} per SEPA-Lastschrift eingezogen.",
            default => "Zahlbar bis zum {$dueDate} ohne Abzug.",
        };
    }
}
