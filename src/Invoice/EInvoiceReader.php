<?php

declare(strict_types=1);

namespace Belegwerk\Invoice;

use DOMDocument;
use DOMNode;
use DOMXPath;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use Throwable;

/**
 * Reads an incoming e-invoice: XRechnung or ZUGFeRD/Factur-X as XML in CII or UBL syntax, or a PDF with the XML
 * embedded. The result uses the keys of the invoice data and of the template context, with the amounts the invoice
 * states. Nothing is recalculated or validated beyond what is needed to read it.
 *
 * @internal
 */
final class EInvoiceReader
{
    private const NAMESPACES = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
        'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cn' => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    /** Paths of an allowance or charge (BG-20/21, BG-27/28), relative to its element. */
    private const CII_ADJUSTMENT = [
        'indicator' => 'ram:ChargeIndicator/udt:Indicator',
        'reason' => 'ram:Reason',
        'reason_code' => 'ram:ReasonCode',
        'percent' => 'ram:CalculationPercent',
        'base' => 'ram:BasisAmount',
        'amount' => 'ram:ActualAmount',
        'tax_category' => 'ram:CategoryTradeTax/ram:CategoryCode',
        'tax_rate' => 'ram:CategoryTradeTax/ram:RateApplicablePercent',
    ];
    private const UBL_ADJUSTMENT = [
        'indicator' => 'cbc:ChargeIndicator',
        'reason' => 'cbc:AllowanceChargeReason',
        'reason_code' => 'cbc:AllowanceChargeReasonCode',
        'percent' => 'cbc:MultiplierFactorNumeric',
        'base' => 'cbc:BaseAmount',
        'amount' => 'cbc:Amount',
        'tax_category' => 'cac:TaxCategory/cbc:ID',
        'tax_rate' => 'cac:TaxCategory/cbc:Percent',
    ];

    private DOMXPath $xpath;

    public function read(string $content): array
    {
        if (str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF')) {
            try {
                $content = ZugferdDocumentPdfReader::getXmlFromContent($content);
            } catch (Throwable $error) {
                throw new ReadException('The PDF contains no e-invoice XML.', previous: $error);
            }
        }

        $document = new DOMDocument();
        // No network access and no entity substitution, the content comes from outside.
        if ($content === '' || !@$document->loadXML($content, LIBXML_NONET)) {
            throw new ReadException('Not a PDF and not well-formed XML.');
        }
        $this->xpath = new DOMXPath($document);
        foreach (self::NAMESPACES as $prefix => $uri) {
            $this->xpath->registerNamespace($prefix, $uri);
        }

        $root = $document->documentElement;
        return match ($root?->namespaceURI) {
            self::NAMESPACES['rsm'] => $this->cii($root),
            self::NAMESPACES['inv'], self::NAMESPACES['cn'] => $this->ubl($root),
            default => throw new ReadException('Unknown XML: expected a CII or UBL invoice.'),
        };
    }

    private function cii(DOMNode $root): array
    {
        $header = $this->node('rsm:ExchangedDocument', $root);
        $agreement = $this->node('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement', $root);
        $settlement = $this->node('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement', $root);
        $sum = $this->node('ram:SpecifiedTradeSettlementHeaderMonetarySummation', $settlement);
        $currency = $this->text('ram:InvoiceCurrencyCode', $settlement);

        $means = $this->node('ram:SpecifiedTradeSettlementPaymentMeans', $settlement);
        $terms = $this->node('ram:SpecifiedTradePaymentTerms', $settlement);
        $meansCode = $this->text('ram:TypeCode', $means);
        $seller = $this->ciiParty($this->node('ram:SellerTradeParty', $agreement));

        $lines = [];
        foreach ($this->nodes('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem', $root) as $item) {
            $price = $this->node('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice', $item);
            $tax = $this->node('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax', $item);
            $lines[] = $this->line(
                $this->text('ram:AssociatedDocumentLineDocument/ram:LineID', $item),
                $this->text('ram:SpecifiedTradeProduct/ram:Name', $item),
                $this->text('ram:SpecifiedTradeProduct/ram:Description', $item),
                $this->node('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $item),
                $this->text('ram:ChargeAmount', $price),
                $this->text('ram:BasisQuantity', $price),
                $this->text('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $item),
                $this->text('ram:CategoryCode', $tax),
                $this->text('ram:RateApplicablePercent', $tax),
                $this->adjustments($this->nodes('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge', $item), self::CII_ADJUSTMENT),
            );
        }

        $taxGroups = [];
        foreach ($this->nodes('ram:ApplicableTradeTax', $settlement) as $tax) {
            $taxGroups[] = $this->taxGroup(
                $this->text('ram:CategoryCode', $tax),
                $this->text('ram:RateApplicablePercent', $tax),
                $this->text('ram:BasisAmount', $tax),
                $this->text('ram:CalculatedAmount', $tax),
                $this->text('ram:ExemptionReason', $tax),
            );
        }
        $adjustments = $this->adjustments($this->nodes('ram:SpecifiedTradeAllowanceCharge', $settlement), self::CII_ADJUSTMENT, true);
        $delivery = $this->node('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery', $root);
        $shipTo = $this->node('ram:ShipToTradeParty', $delivery);
        $shipToAddress = $this->node('ram:PostalTradeAddress', $shipTo);
        $globalId = $this->node('ram:GlobalID', $shipTo);

        return $this->invoice([
            'syntax' => 'CII',
            'profile' => $this->text('rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID', $root),
            'number' => $this->text('ram:ID', $header),
            'type_code' => $this->text('ram:TypeCode', $header),
            'currency' => $currency,
            'date' => $this->ciiDate('ram:IssueDateTime/udt:DateTimeString', $header),
            'due_date' => $this->ciiDate('ram:DueDateDateTime/udt:DateTimeString', $terms),
            'service_date' => $this->ciiDate('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString', $root),
            'period' => $this->period(
                $this->ciiDate('ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString', $settlement),
                $this->ciiDate('ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString', $settlement),
            ),
            'buyer_reference' => $this->text('ram:BuyerReference', $agreement),
            'order_reference' => $this->text('ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID', $agreement),
            'despatch_reference' => $this->text('ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID', $delivery),
            'preceding_invoice' => $this->precedingInvoice(
                $this->text('ram:InvoiceReferencedDocument/ram:IssuerAssignedID', $settlement),
                $this->ciiDate('ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString', $settlement),
            ),
            'payment_terms' => $paymentTerms = $this->text('ram:Description', $terms),
            'cash_discount' => self::cashDiscount($paymentTerms),
            'note' => $this->joined('ram:IncludedNote/ram:Content', $header),
            'seller' => $seller,
            'buyer' => $this->ciiParty($this->node('ram:BuyerTradeParty', $agreement)),
            'delivery' => $shipTo === null ? null : $this->delivery(
                $this->text('ram:Name', $shipTo),
                $shipToAddress === null ? null : [
                    $this->text('ram:LineOne', $shipToAddress),
                    $this->text('ram:LineTwo', $shipToAddress),
                    $this->text('ram:PostcodeCode', $shipToAddress),
                    $this->text('ram:CityName', $shipToAddress),
                    $this->text('ram:CountryID', $shipToAddress),
                ],
                $globalId ?? $this->node('ram:ID', $shipTo),
            ),
            'bank' => $this->bank(
                $meansCode,
                $this->text('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $means),
                $this->text('ram:PayeePartyCreditorFinancialAccount/ram:AccountName', $means) ?? $seller['name'],
                $this->text('ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID', $means),
            ),
            'direct_debit' => $this->directDebit(
                $meansCode,
                $this->text('ram:DirectDebitMandateID', $terms),
                $this->text('ram:CreditorReferenceID', $settlement),
                $this->text('ram:PayerPartyDebtorFinancialAccount/ram:IBANID', $means),
            ),
            'lines' => $lines,
            'allowances' => $adjustments['allowances'],
            'charges' => $adjustments['charges'],
            'tax_groups' => $taxGroups,
            'attachments' => array_map(fn (DOMNode $document): array => $this->attachment(
                $this->text('ram:IssuerAssignedID', $document),
                $this->text('ram:Name', $document),
                $this->text('ram:URIID', $document),
                $this->node('ram:AttachmentBinaryObject', $document),
            ), $this->nodes("ram:AdditionalReferencedDocument[ram:TypeCode='916']", $agreement)),
            'totals' => $this->totals(
                $this->text('ram:LineTotalAmount', $sum),
                $this->text('ram:AllowanceTotalAmount', $sum),
                $this->text('ram:ChargeTotalAmount', $sum),
                $this->text('ram:TaxBasisTotalAmount', $sum),
                // A second TaxTotalAmount may state the tax in the accounting currency (BT-111).
                $this->text("ram:TaxTotalAmount[@currencyID='{$currency}']", $sum) ?? $this->text('ram:TaxTotalAmount', $sum),
                $this->text('ram:GrandTotalAmount', $sum),
                $this->text('ram:TotalPrepaidAmount', $sum),
                $this->text('ram:DuePayableAmount', $sum),
            ),
        ]);
    }

    private function ciiParty(?DOMNode $party): array
    {
        $address = $this->node('ram:PostalTradeAddress', $party);
        $contact = $this->node('ram:DefinedTradeContact', $party);
        $electronic = $this->node('ram:URIUniversalCommunication/ram:URIID', $party);
        return $this->party(
            $this->text('ram:Name', $party),
            $this->text('ram:LineOne', $address),
            $this->text('ram:LineTwo', $address),
            $this->text('ram:PostcodeCode', $address),
            $this->text('ram:CityName', $address),
            $this->text('ram:CountryID', $address),
            $this->text("ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']", $party),
            $this->text("ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']", $party),
            $this->text('ram:EmailURIUniversalCommunication/ram:URIID', $contact),
            $this->text('ram:TelephoneUniversalCommunication/ram:CompleteNumber', $contact),
            $this->text('ram:PersonName', $contact),
            $electronic,
        );
    }

    /** CII dates are mostly in format 102 (YYYYMMDD). */
    private function ciiDate(string $path, ?DOMNode $context): ?string
    {
        $value = $this->text($path, $context);
        return $value !== null && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $match) ? "{$match[1]}-{$match[2]}-{$match[3]}" : $value;
    }

    private function ubl(DOMNode $root): array
    {
        $creditNote = $root->namespaceURI === self::NAMESPACES['cn'];
        $currency = $this->text('cbc:DocumentCurrencyCode', $root);
        $means = $this->node('cac:PaymentMeans', $root);
        $meansCode = $this->text('cbc:PaymentMeansCode', $means);
        $sum = $this->node('cac:LegalMonetaryTotal', $root);
        $seller = $this->ublParty($this->node('cac:AccountingSupplierParty/cac:Party', $root));

        $lines = [];
        foreach ($this->nodes($creditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine', $root) as $item) {
            $tax = $this->node('cac:Item/cac:ClassifiedTaxCategory', $item);
            $lines[] = $this->line(
                $this->text('cbc:ID', $item),
                $this->text('cac:Item/cbc:Name', $item),
                $this->text('cac:Item/cbc:Description', $item),
                $this->node($creditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', $item),
                $this->text('cac:Price/cbc:PriceAmount', $item),
                $this->text('cac:Price/cbc:BaseQuantity', $item),
                $this->text('cbc:LineExtensionAmount', $item),
                $this->text('cbc:ID', $tax),
                $this->text('cbc:Percent', $tax),
                $this->adjustments($this->nodes('cac:AllowanceCharge', $item), self::UBL_ADJUSTMENT),
            );
        }

        $taxGroups = [];
        foreach ($this->nodes("cac:TaxTotal[cbc:TaxAmount/@currencyID='{$currency}']/cac:TaxSubtotal", $root) as $tax) {
            $taxGroups[] = $this->taxGroup(
                $this->text('cac:TaxCategory/cbc:ID', $tax),
                $this->text('cac:TaxCategory/cbc:Percent', $tax),
                $this->text('cbc:TaxableAmount', $tax),
                $this->text('cbc:TaxAmount', $tax),
                $this->text('cac:TaxCategory/cbc:TaxExemptionReason', $tax),
            );
        }
        $adjustments = $this->adjustments($this->nodes('cac:AllowanceCharge', $root), self::UBL_ADJUSTMENT, true);

        return $this->invoice([
            'syntax' => 'UBL',
            'profile' => $this->text('cbc:CustomizationID', $root),
            'number' => $this->text('cbc:ID', $root),
            'type_code' => $this->text($creditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode', $root),
            'currency' => $currency,
            'date' => $this->text('cbc:IssueDate', $root),
            'due_date' => $this->text('cbc:DueDate', $root) ?? $this->text('cbc:PaymentDueDate', $means),
            'service_date' => $this->text('cac:Delivery/cbc:ActualDeliveryDate', $root),
            'period' => $this->period(
                $this->text('cac:InvoicePeriod/cbc:StartDate', $root),
                $this->text('cac:InvoicePeriod/cbc:EndDate', $root),
            ),
            'buyer_reference' => $this->text('cbc:BuyerReference', $root),
            'order_reference' => $this->text('cac:OrderReference/cbc:ID', $root),
            'despatch_reference' => $this->text('cac:DespatchDocumentReference/cbc:ID', $root),
            'preceding_invoice' => $this->precedingInvoice(
                $this->text('cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID', $root),
                $this->text('cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate', $root),
            ),
            'payment_terms' => $paymentTerms = $this->text('cac:PaymentTerms/cbc:Note', $root),
            'cash_discount' => self::cashDiscount($paymentTerms),
            'note' => $this->joined('cbc:Note', $root),
            'seller' => $seller,
            'buyer' => $this->ublParty($this->node('cac:AccountingCustomerParty/cac:Party', $root)),
            'delivery' => $this->ublDelivery($this->node('cac:Delivery', $root)),
            'bank' => $this->bank(
                $meansCode,
                $this->text('cac:PayeeFinancialAccount/cbc:ID', $means),
                $this->text('cac:PayeeFinancialAccount/cbc:Name', $means) ?? $seller['name'],
                $this->text('cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID', $means),
            ),
            'direct_debit' => $this->directDebit(
                $meansCode,
                $this->text('cac:PaymentMandate/cbc:ID', $means),
                $this->text("cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID[@schemeID='SEPA']", $root)
                    ?? $this->text("cac:PayeeParty/cac:PartyIdentification/cbc:ID[@schemeID='SEPA']", $root),
                $this->text('cac:PaymentMandate/cac:PayerFinancialAccount/cbc:ID', $means),
            ),
            'lines' => $lines,
            'allowances' => $adjustments['allowances'],
            'charges' => $adjustments['charges'],
            'tax_groups' => $taxGroups,
            // Code 130 marks the invoiced object identifier (BT-18), not a supporting document.
            'attachments' => array_map(fn (DOMNode $document): array => $this->attachment(
                $this->text('cbc:ID', $document),
                $this->text('cbc:DocumentDescription', $document),
                $this->text('cac:Attachment/cac:ExternalReference/cbc:URI', $document),
                $this->node('cac:Attachment/cbc:EmbeddedDocumentBinaryObject', $document),
            ), $this->nodes("cac:AdditionalDocumentReference[not(cbc:DocumentTypeCode='130')]", $root)),
            'totals' => $this->totals(
                $this->text('cbc:LineExtensionAmount', $sum),
                $this->text('cbc:AllowanceTotalAmount', $sum),
                $this->text('cbc:ChargeTotalAmount', $sum),
                $this->text('cbc:TaxExclusiveAmount', $sum),
                $this->text("cac:TaxTotal/cbc:TaxAmount[@currencyID='{$currency}']", $root),
                $this->text('cbc:TaxInclusiveAmount', $sum),
                $this->text('cbc:PrepaidAmount', $sum),
                $this->text('cbc:PayableAmount', $sum),
            ),
        ]);
    }

    private function ublParty(?DOMNode $party): array
    {
        $address = $this->node('cac:PostalAddress', $party);
        $contact = $this->node('cac:Contact', $party);
        return $this->party(
            $this->text('cac:PartyLegalEntity/cbc:RegistrationName', $party) ?? $this->text('cac:PartyName/cbc:Name', $party),
            $this->text('cbc:StreetName', $address),
            $this->text('cbc:AdditionalStreetName', $address),
            $this->text('cbc:PostalZone', $address),
            $this->text('cbc:CityName', $address),
            $this->text('cac:Country/cbc:IdentificationCode', $address),
            $this->text("cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID", $party),
            $this->text("cac:PartyTaxScheme[cac:TaxScheme/cbc:ID!='VAT']/cbc:CompanyID", $party),
            $this->text('cbc:ElectronicMail', $contact),
            $this->text('cbc:Telephone', $contact),
            $this->text('cbc:Name', $contact),
            $this->node('cbc:EndpointID', $party),
        );
    }

    /** BG-13 in UBL: the name, location ID and address of a delivery, or null if it names none of them. */
    private function ublDelivery(?DOMNode $delivery): ?array
    {
        $name = $this->text('cac:DeliveryParty/cac:PartyName/cbc:Name', $delivery);
        $address = $this->node('cac:DeliveryLocation/cac:Address', $delivery);
        $location = $this->node('cac:DeliveryLocation/cbc:ID', $delivery);
        if ($name === null && $address === null && $location === null) {
            return null;
        }
        return $this->delivery(
            $name,
            $address === null ? null : [
                $this->text('cbc:StreetName', $address),
                $this->text('cbc:AdditionalStreetName', $address),
                $this->text('cbc:PostalZone', $address),
                $this->text('cbc:CityName', $address),
                $this->text('cac:Country/cbc:IdentificationCode', $address),
            ],
            $location,
        );
    }

    /** @param ?list<?string> $address street, additional, zip, city, country */
    private function delivery(?string $name, ?array $address, ?DOMNode $location): array
    {
        $scheme = $location instanceof \DOMElement ? $location->getAttribute('schemeID') : '';
        $id = trim((string) $location?->textContent);
        return [
            'name' => $name,
            'address' => $address === null ? null : array_combine(['street', 'additional', 'zip', 'city', 'country'], $address),
            'location_id' => $id !== '' ? ['scheme' => $scheme !== '' ? $scheme : null, 'id' => $id] : null,
        ];
    }

    /** BG-24; content holds the decoded file, which comes from outside: never use its filename as a path unchecked. */
    private function attachment(?string $id, ?string $description, ?string $url, ?DOMNode $binary): array
    {
        $content = $binary === null ? false : base64_decode(preg_replace('/\s+/', '', $binary->textContent) ?? '', true);
        $element = $binary instanceof \DOMElement ? $binary : null;
        return [
            'id' => $id,
            'description' => $description,
            'filename' => $element?->getAttribute('filename') ?: null,
            'mime' => $element?->getAttribute('mimeCode') ?: null,
            'size' => $content !== false ? strlen($content) : null,
            'url' => $url,
            'content' => $content !== false && $content !== '' ? $content : null,
        ];
    }

    private function invoice(array $invoice): array
    {
        if ($invoice['number'] === null) {
            throw new ReadException('The invoice has no number (BT-1); this is not an invoice.');
        }
        return $invoice;
    }

    private function party(
        ?string $name,
        ?string $street,
        ?string $additional,
        ?string $zip,
        ?string $city,
        ?string $country,
        ?string $vatId,
        ?string $taxNumber,
        ?string $email,
        ?string $phone,
        ?string $contactName,
        ?DOMNode $electronicAddress,
    ): array {
        $scheme = $electronicAddress instanceof \DOMElement ? $electronicAddress->getAttribute('schemeID') : '';
        $id = trim((string) $electronicAddress?->textContent);
        return [
            'name' => $name,
            'address' => ['street' => $street, 'additional' => $additional, 'zip' => $zip, 'city' => $city, 'country' => $country],
            'vat_id' => $vatId,
            'tax_number' => $taxNumber,
            'email' => $email,
            'phone' => $phone,
            'contact_name' => $contactName,
            'electronic_address' => $id !== '' ? ['scheme' => $scheme, 'id' => $id] : null,
        ];
    }

    private function line(
        ?string $id,
        ?string $name,
        ?string $description,
        ?DOMNode $quantity,
        ?string $price,
        ?string $basisQuantity,
        ?string $net,
        ?string $category,
        ?string $rate,
        array $adjustments,
    ): array {
        // The net price may refer to a base quantity (BT-149), e.g. 100 pieces.
        $unitPrice = self::cents($price);
        $basis = (float) ($basisQuantity ?? 1);
        if ($unitPrice !== null && $basis !== 0.0 && $basis !== 1.0) {
            $unitPrice /= $basis;
        }
        return [
            'id' => $id,
            'name' => $name,
            'description' => $description ?? $name,
            'quantity' => self::decimal($quantity?->textContent),
            'unit_code' => $quantity instanceof \DOMElement ? ($quantity->getAttribute('unitCode') ?: null) : null,
            'unit_price_cents' => is_float($unitPrice) && floor($unitPrice) === $unitPrice ? (int) $unitPrice : $unitPrice,
            'allowances' => $adjustments['allowances'],
            'charges' => $adjustments['charges'],
            'net_cents' => self::cents($net),
            'tax_category' => $category,
            'tax_rate' => self::decimal($rate),
        ];
    }

    /**
     * @param array<string, string> $paths  CII_ADJUSTMENT or UBL_ADJUSTMENT
     * @return array{allowances: list<array>, charges: list<array>}
     */
    private function adjustments(array $nodes, array $paths, bool $withTax = false): array
    {
        $result = ['allowances' => [], 'charges' => []];
        foreach ($nodes as $node) {
            $adjustment = [
                'reason' => $this->text($paths['reason'], $node),
                'reason_code' => $this->text($paths['reason_code'], $node),
                'percent' => self::decimal($this->text($paths['percent'], $node)),
                'base_cents' => self::cents($this->text($paths['base'], $node)),
                'amount_cents' => self::cents($this->text($paths['amount'], $node)),
            ];
            if ($withTax) {
                $adjustment['tax_category'] = $this->text($paths['tax_category'], $node);
                $adjustment['tax_rate'] = self::decimal($this->text($paths['tax_rate'], $node));
            }
            $result[$this->text($paths['indicator'], $node) === 'true' ? 'charges' : 'allowances'][] = $adjustment;
        }
        return $result;
    }

    private function taxGroup(?string $category, ?string $rate, ?string $base, ?string $amount, ?string $exemptionReason): array
    {
        return [
            'category' => $category,
            'rate' => self::decimal($rate),
            'base_cents' => self::cents($base),
            'amount_cents' => self::cents($amount),
            'exemption_reason' => $exemptionReason,
        ];
    }

    private function bank(?string $meansCode, ?string $iban, ?string $holder, ?string $bic): ?array
    {
        // 30 and 58 are (SEPA) credit transfers; other payment means carry no account of the seller.
        if ($iban === null || !in_array($meansCode, ['30', '58'], true)) {
            return null;
        }
        return ['holder' => $holder, 'iban' => $iban, 'bic' => $bic, 'name' => null];
    }

    private function directDebit(?string $meansCode, ?string $mandate, ?string $creditorId, ?string $iban): ?array
    {
        if (!in_array($meansCode, ['49', '59'], true)) {
            return null;
        }
        return ['mandate_reference' => $mandate, 'creditor_id' => $creditorId, 'iban' => $iban];
    }

    /** The first cash discount in the XRechnung notation #SKONTO#TAGE=14#PROZENT=2.00#. */
    private static function cashDiscount(?string $paymentTerms): ?array
    {
        if ($paymentTerms === null || !preg_match('/#SKONTO#TAGE=(\d+)#PROZENT=(\d+(?:\.\d+)?)#/', $paymentTerms, $match)) {
            return null;
        }
        return ['days' => (int) $match[1], 'percent' => self::decimal($match[2])];
    }

    private function period(?string $start, ?string $end): ?array
    {
        return $start === null && $end === null ? null : ['start' => $start, 'end' => $end];
    }

    private function precedingInvoice(?string $number, ?string $date): ?array
    {
        return $number === null ? null : ['number' => $number, 'date' => $date];
    }

    private function totals(?string ...$amounts): array
    {
        $cents = array_map(static fn (?string $amount): int => (int) self::cents($amount), $amounts);
        return array_combine(
            ['line_total_cents', 'allowance_total_cents', 'charge_total_cents', 'net_cents', 'tax_cents', 'gross_cents', 'paid_cents', 'due_cents'],
            $cents,
        );
    }

    private function node(string $path, ?DOMNode $context): ?DOMNode
    {
        return $context === null ? null : ($this->xpath->query($path, $context)->item(0) ?? null);
    }

    /** @return list<DOMNode> */
    private function nodes(string $path, ?DOMNode $context): array
    {
        return $context === null ? [] : iterator_to_array($this->xpath->query($path, $context), false);
    }

    private function text(string $path, ?DOMNode $context): ?string
    {
        $value = trim((string) $this->node($path, $context)?->textContent);
        return $value === '' ? null : $value;
    }

    private function joined(string $path, ?DOMNode $context): ?string
    {
        $texts = array_filter(array_map(static fn (DOMNode $node): string => trim($node->textContent), $this->nodes($path, $context)));
        return $texts === [] ? null : implode("\n\n", $texts);
    }

    /** Amount in cents: an integer for up to two decimals (all totals), otherwise a float (e.g. a unit price). */
    private static function cents(?string $amount): int|float|null
    {
        if ($amount === null || !is_numeric($amount)) {
            return null;
        }
        $decimal = (string) self::decimal($amount);
        if (InvoiceNormalizer::decimalPlaces($decimal) <= 2) {
            return InvoiceNormalizer::roundedProduct(0, $decimal, '100');
        }
        return (float) $amount * 100;
    }

    /** A decimal without trailing zeros, e.g. "6.5000" becomes "6.5". */
    private static function decimal(?string $value): ?string
    {
        if ($value === null || !is_numeric($value)) {
            return $value;
        }
        $value = ltrim(trim($value), '+');
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '-0' || $value === '' ? '0' : $value;
    }
}
