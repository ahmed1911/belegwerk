<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DateTimeInterface;
use DOMDocument;
use DOMElement;

/**
 * Erzeugt eine UN/CEFACT Cross Industry Invoice (CII, D16B) gemäß EN 16931 aus einer
 * normalisierten Rechnung (siehe InvoiceNormalizer).
 *
 * Dieselbe Syntax dient als XRechnung (CII-Variante) und als eingebettete factur-x.xml in ZUGFeRD.
 */
final class CiiInvoiceWriter
{
    public const PROFILE_EN16931 = 'en16931';
    public const PROFILE_XRECHNUNG = 'xrechnung';

    private const GUIDELINES = [
        self::PROFILE_EN16931 => 'urn:cen.eu:en16931:2017',
        self::PROFILE_XRECHNUNG => 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0',
    ];

    private const NS = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
    ];

    private DOMDocument $doc;

    public function write(array $invoice, string $profile = self::PROFILE_EN16931): string
    {
        if (!isset(self::GUIDELINES[$profile])) {
            throw new \InvalidArgumentException("Unbekanntes Profil: {$profile}");
        }

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $root = $this->doc->createElementNS(self::NS['rsm'], 'rsm:CrossIndustryInvoice');
        foreach (self::NS as $prefix => $uri) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:{$prefix}", $uri);
        }
        $this->doc->appendChild($root);

        $context = $this->add($root, 'rsm:ExchangedDocumentContext');
        $this->add($this->add($context, 'ram:BusinessProcessSpecifiedDocumentContextParameter'), 'ram:ID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->add($this->add($context, 'ram:GuidelineSpecifiedDocumentContextParameter'), 'ram:ID', self::GUIDELINES[$profile]);

        $document = $this->add($root, 'rsm:ExchangedDocument');
        $this->add($document, 'ram:ID', $invoice['number']);
        $this->add($document, 'ram:TypeCode', $invoice['type_code']);
        $this->date($this->add($document, 'ram:IssueDateTime'), $invoice['date']);
        if ($invoice['note'] !== null) {
            $this->add($this->add($document, 'ram:IncludedNote'), 'ram:Content', $invoice['note']);
        }

        $transaction = $this->add($root, 'rsm:SupplyChainTradeTransaction');
        foreach ($invoice['lines'] as $line) {
            $this->line($transaction, $line);
        }
        $this->agreement($transaction, $invoice);
        $this->delivery($transaction, $invoice);
        $this->settlement($transaction, $invoice);

        return $this->doc->saveXML();
    }

    private function line(DOMElement $parent, array $line): void
    {
        $item = $this->add($parent, 'ram:IncludedSupplyChainTradeLineItem');
        $this->add($this->add($item, 'ram:AssociatedDocumentLineDocument'), 'ram:LineID', $line['id']);

        $product = $this->add($item, 'ram:SpecifiedTradeProduct');
        $this->add($product, 'ram:Name', $line['name']);
        if ($line['description'] !== null && $line['description'] !== $line['name']) {
            $this->add($product, 'ram:Description', $line['description']);
        }

        $agreement = $this->add($item, 'ram:SpecifiedLineTradeAgreement');
        $this->add($this->add($agreement, 'ram:NetPriceProductTradePrice'), 'ram:ChargeAmount', $this->amount(abs($line['unit_price_cents'])));

        $quantity = $line['unit_price_cents'] < 0 ? $this->negate($line['quantity']) : $line['quantity'];
        $this->add($this->add($item, 'ram:SpecifiedLineTradeDelivery'), 'ram:BilledQuantity', $quantity, ['unitCode' => $line['unit_code']]);

        $settlement = $this->add($item, 'ram:SpecifiedLineTradeSettlement');
        $tax = $this->add($settlement, 'ram:ApplicableTradeTax');
        $this->add($tax, 'ram:TypeCode', 'VAT');
        $this->add($tax, 'ram:CategoryCode', $line['tax_category']);
        $this->add($tax, 'ram:RateApplicablePercent', $line['tax_rate']);
        $this->add($this->add($settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation'), 'ram:LineTotalAmount', $this->amount($line['net_cents']));
    }

    private function agreement(DOMElement $parent, array $invoice): void
    {
        $agreement = $this->add($parent, 'ram:ApplicableHeaderTradeAgreement');
        if ($invoice['buyer_reference'] !== null) {
            $this->add($agreement, 'ram:BuyerReference', $invoice['buyer_reference']);
        }
        $this->party($this->add($agreement, 'ram:SellerTradeParty'), $invoice['seller'], true);
        $this->party($this->add($agreement, 'ram:BuyerTradeParty'), $invoice['buyer'], false);
        if ($invoice['order_reference'] !== null) {
            $this->add($this->add($agreement, 'ram:BuyerOrderReferencedDocument'), 'ram:IssuerAssignedID', $invoice['order_reference']);
        }
    }

    private function party(DOMElement $party, array $data, bool $isSeller): void
    {
        $this->add($party, 'ram:Name', $data['name']);

        if ($isSeller) {
            $contact = $this->add($party, 'ram:DefinedTradeContact');
            $this->add($contact, 'ram:PersonName', $data['contact_name']);
            if ($data['phone'] !== null) {
                $this->add($this->add($contact, 'ram:TelephoneUniversalCommunication'), 'ram:CompleteNumber', $data['phone']);
            }
            if ($data['email'] !== null) {
                $this->add($this->add($contact, 'ram:EmailURIUniversalCommunication'), 'ram:URIID', $data['email']);
            }
        } elseif ($data['contact_name'] !== null) {
            $this->add($this->add($party, 'ram:DefinedTradeContact'), 'ram:PersonName', $data['contact_name']);
        }

        $address = $this->add($party, 'ram:PostalTradeAddress');
        foreach (['zip' => 'ram:PostcodeCode', 'street' => 'ram:LineOne', 'additional' => 'ram:LineTwo', 'city' => 'ram:CityName'] as $key => $element) {
            if ($data['address'][$key] !== null) {
                $this->add($address, $element, $data['address'][$key]);
            }
        }
        $this->add($address, 'ram:CountryID', $data['address']['country']);

        if ($data['electronic_address'] !== null) {
            $this->add($this->add($party, 'ram:URIUniversalCommunication'), 'ram:URIID', $data['electronic_address']['id'], [
                'schemeID' => $data['electronic_address']['scheme'],
            ]);
        }
        if ($data['vat_id'] !== null) {
            $this->add($this->add($party, 'ram:SpecifiedTaxRegistration'), 'ram:ID', $data['vat_id'], ['schemeID' => 'VA']);
        }
        if ($isSeller && $data['tax_number'] !== null) {
            $this->add($this->add($party, 'ram:SpecifiedTaxRegistration'), 'ram:ID', $data['tax_number'], ['schemeID' => 'FC']);
        }
    }

    private function delivery(DOMElement $parent, array $invoice): void
    {
        $delivery = $this->add($parent, 'ram:ApplicableHeaderTradeDelivery');
        if ($invoice['service_date'] !== null) {
            $event = $this->add($delivery, 'ram:ActualDeliverySupplyChainEvent');
            $this->date($this->add($event, 'ram:OccurrenceDateTime'), $invoice['service_date']);
        }
    }

    private function settlement(DOMElement $parent, array $invoice): void
    {
        $settlement = $this->add($parent, 'ram:ApplicableHeaderTradeSettlement');
        $this->add($settlement, 'ram:PaymentReference', $invoice['number']);
        $this->add($settlement, 'ram:InvoiceCurrencyCode', $invoice['currency']);

        $means = $this->add($settlement, 'ram:SpecifiedTradeSettlementPaymentMeans');
        $this->add($means, 'ram:TypeCode', '58');
        $account = $this->add($means, 'ram:PayeePartyCreditorFinancialAccount');
        $this->add($account, 'ram:IBANID', $invoice['bank']['iban']);
        $this->add($account, 'ram:AccountName', $invoice['bank']['holder']);
        if ($invoice['bank']['bic'] !== null) {
            $this->add($this->add($means, 'ram:PayeeSpecifiedCreditorFinancialInstitution'), 'ram:BICID', $invoice['bank']['bic']);
        }

        foreach ($invoice['tax_groups'] as $group) {
            $tax = $this->add($settlement, 'ram:ApplicableTradeTax');
            $this->add($tax, 'ram:CalculatedAmount', $this->amount($group['amount_cents']));
            $this->add($tax, 'ram:TypeCode', 'VAT');
            if ($group['exemption_reason'] !== null) {
                $this->add($tax, 'ram:ExemptionReason', $group['exemption_reason']);
            }
            $this->add($tax, 'ram:BasisAmount', $this->amount($group['base_cents']));
            $this->add($tax, 'ram:CategoryCode', $group['category']);
            $this->add($tax, 'ram:RateApplicablePercent', $group['rate']);
        }

        if ($invoice['period'] !== null) {
            $period = $this->add($settlement, 'ram:BillingSpecifiedPeriod');
            $this->date($this->add($period, 'ram:StartDateTime'), $invoice['period']['start']);
            $this->date($this->add($period, 'ram:EndDateTime'), $invoice['period']['end']);
        }

        $terms = $this->add($settlement, 'ram:SpecifiedTradePaymentTerms');
        $this->add($terms, 'ram:Description', $invoice['payment_terms']
            ?? 'Zahlbar bis zum ' . $invoice['due_date']->format('d.m.Y') . ' ohne Abzug.');
        $this->date($this->add($terms, 'ram:DueDateDateTime'), $invoice['due_date']);

        $totals = $invoice['totals'];
        $summation = $this->add($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->add($summation, 'ram:LineTotalAmount', $this->amount($totals['net_cents']));
        $this->add($summation, 'ram:TaxBasisTotalAmount', $this->amount($totals['net_cents']));
        $this->add($summation, 'ram:TaxTotalAmount', $this->amount($totals['tax_cents']), ['currencyID' => $invoice['currency']]);
        $this->add($summation, 'ram:GrandTotalAmount', $this->amount($totals['gross_cents']));
        $this->add($summation, 'ram:DuePayableAmount', $this->amount($totals['gross_cents']));
    }

    /** @param array<string, string> $attributes */
    private function add(DOMElement $parent, string $name, ?string $value = null, array $attributes = []): DOMElement
    {
        $element = $this->doc->createElementNS(self::NS[strstr($name, ':', true)], $name);
        if ($value !== null) {
            $element->appendChild($this->doc->createTextNode($value));
        }
        foreach ($attributes as $attribute => $attributeValue) {
            $element->setAttribute($attribute, $attributeValue);
        }
        $parent->appendChild($element);
        return $element;
    }

    private function date(DOMElement $parent, DateTimeInterface $date): void
    {
        $this->add($parent, 'udt:DateTimeString', $date->format('Ymd'), ['format' => '102']);
    }

    private function amount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    private function negate(string $decimal): string
    {
        return str_starts_with($decimal, '-') ? substr($decimal, 1) : ($decimal === '0' ? '0' : '-' . $decimal);
    }
}
