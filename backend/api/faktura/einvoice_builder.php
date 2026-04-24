<?php
// backend/api/faktura/einvoice_builder.php

require_once __DIR__.'/../../vendor/autoload.php';

use horstoeko\zugferd\codelists\ZugferdCountryCodes;
use horstoeko\zugferd\codelists\ZugferdCurrencyCodes;
use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdPaymentMeans;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * Baut aus dem Rechnungs-Datensatz ein ZugferdDocumentBuilder-Dokument
 * gemäß kivitendo-Enum create_zugferd_invoices:
 *   1 = Factur-X 1.01.06 / ZUGFeRD 2.2 extended
 *   2 = Factur-X extended, Testmodus
 *   3 = XRechnung 3.0
 *   4 = XRechnung 3.0, Testmodus
 *
 * Die Klasse bleibt bewusst schlank: ein Builder pro Rechnung, ein Aufruf
 * buildDocument() erzeugt den Builder mit allen Pflichtfeldern der EN 16931.
 */
class EInvoiceBuilder {

    private array $data;
    private int $mode;

    public function __construct(array $data) {
        $this->data = $data;
        $this->mode = intval($data['einvoice_mode'] ?? 0);
    }

    public function isActive(): bool {
        return $this->mode > 0;
    }

    public function isTestMode(): bool {
        return in_array($this->mode, [2, 4], true);
    }

    public function isXRechnung(): bool {
        return in_array($this->mode, [3, 4], true);
    }

    public function getHorstoekoProfile(): int {
        return $this->isXRechnung()
            ? ZugferdProfiles::PROFILE_XRECHNUNG_3
            : ZugferdProfiles::PROFILE_EXTENDED;
    }

    public function buildDocument(): ZugferdDocumentBuilder {
        $ar       = $this->data['ar'] ?? [];
        $company  = $this->data['company'] ?? [];
        $customer = $this->data['customer'] ?? [];
        $bank     = $this->data['bank'] ?? null;
        $positions = $this->data['positions'] ?? [];
        $taxes    = $this->data['tax_breakdown'] ?? [];

        $builder = ZugferdDocumentBuilder::createNew($this->getHorstoekoProfile());

        if ($this->isTestMode()) {
            $builder->setIsTestDocument();
        }

        $builder->setDocumentInformation(
            $ar['invnumber'],
            $this->invoiceTypeCode($ar['type'] ?? 'invoice'),
            $this->toDate($ar['transdate']),
            $ar['currency_code'] ?? ZugferdCurrencyCodes::EURO
        );

        if (!empty($ar['notes'])) {
            $builder->addDocumentNote($ar['notes']);
        }

        $this->buildSeller($builder, $company);
        $this->buildBuyer($builder, $customer);

        if (!empty($ar['duedate'])) {
            $builder->addDocumentPaymentTerm(
                'Zahlbar bis ' . $this->formatDate($ar['duedate']),
                $this->toDate($ar['duedate'])
            );
        }

        if ($bank && !empty($bank['iban'])) {
            $builder->addDocumentPaymentMeanToCreditTransfer(
                $bank['iban'],
                $bank['name'] ?? $company['company'] ?? null,
                null,
                $bank['bic'] ?? null,
                $ar['invnumber']
            );
        }

        $builder->setDocumentSupplyChainEvent($this->toDate($ar['transdate']));

        $this->buildPositions($builder, $positions);
        $this->buildTaxBreakdown($builder, $taxes);

        $net = 0.0;
        $tax = 0.0;
        foreach ($taxes as $t) {
            $net += abs(round(floatval($t['basis'] ?? 0), 2));
            $tax += abs(round(floatval($t['tax_amount'] ?? 0), 2));
        }
        $gross = round($net + $tax, 2);

        $builder->setDocumentSummation(
            $gross,
            $gross,
            $net,
            0.0,
            0.0,
            $net,
            $tax
        );

        return $builder;
    }

    private function buildSeller(ZugferdDocumentBuilder $builder, array $company): void {
        $name = $company['company'] ?? '';
        $builder->setDocumentSeller($name);

        if (!empty($company['gln'])) {
            $builder->addDocumentSellerGlobalId($company['gln'], '0088');
        }
        if (!empty($company['taxnumber'])) {
            $builder->addDocumentSellerTaxNumber($company['taxnumber']);
        }
        if (!empty($company['co_ustid'])) {
            $builder->addDocumentSellerVATRegistrationNumber($company['co_ustid']);
        }

        $builder->setDocumentSellerAddress(
            $company['address_street1'] ?? '',
            $company['address_street2'] ?? null,
            null,
            $company['address_zipcode'] ?? '',
            $company['address_city'] ?? '',
            $this->toCountryCode($company['address_country'] ?? '')
        );

        $email = $company['seller_contact_email'] ?? $company['co_ustid_email'] ?? null;
        if ($email) {
            $builder->setDocumentSellerCommunication(
                ZugferdElectronicAddressScheme::UNECE3155_EM,
                $email
            );
        }
    }

    private function buildBuyer(ZugferdDocumentBuilder $builder, array $customer): void {
        $builder->setDocumentBuyer(
            $customer['name'] ?? '',
            $customer['customernumber'] ?? null
        );

        if (!empty($customer['c_vendor_routing_id'])) {
            $builder->setDocumentBuyerReference($customer['c_vendor_routing_id']);
        }

        if (!empty($customer['gln'])) {
            $builder->addDocumentBuyerGlobalId($customer['gln'], '0088');
        }
        if (!empty($customer['ustid'])) {
            $builder->addDocumentBuyerVATRegistrationNumber($customer['ustid']);
        }

        $builder->setDocumentBuyerAddress(
            $customer['street'] ?? '',
            $customer['department_1'] ?? null,
            null,
            $customer['zipcode'] ?? '',
            $customer['city'] ?? '',
            $this->toCountryCode($customer['country'] ?? '')
        );

        if (!empty($customer['invoice_mail'])) {
            $builder->setDocumentBuyerCommunication(
                ZugferdElectronicAddressScheme::UNECE3155_EM,
                $customer['invoice_mail']
            );
        }
    }

    private function buildPositions(ZugferdDocumentBuilder $builder, array $positions): void {
        $lineid = 0;
        foreach ($positions as $pos) {
            $lineid++;
            $builder->addNewPosition((string)$lineid);

            $builder->setDocumentPositionProductDetails(
                $pos['description'] ?? '',
                $pos['longdescription'] ?? null,
                $pos['partnumber'] ?? null
            );

            $qty       = abs(floatval($pos['qty'] ?? 0));
            $sellprice = abs(floatval($pos['sellprice'] ?? 0));
            $discount  = floatval($pos['discount'] ?? 0);
            $unitPrice = $sellprice * (1 - $discount);
            $lineTotal = $qty * $unitPrice;

            $builder->setDocumentPositionNetPrice($unitPrice);
            $builder->setDocumentPositionQuantity(
                $qty,
                $this->toUnitCode($pos['unit'] ?? null)
            );

            $rate = floatval($pos['tax_rate'] ?? 0) * 100;
            $builder->addDocumentPositionTax(
                $this->vatCategoryForRate($rate),
                ZugferdVatTypeCodes::VALUE_ADDED_TAX,
                $rate
            );

            $builder->setDocumentPositionLineSummation(round($lineTotal, 2));
        }
    }

    private function buildTaxBreakdown(ZugferdDocumentBuilder $builder, array $taxes): void {
        foreach ($taxes as $t) {
            $basis     = abs(round(floatval($t['basis'] ?? 0), 2));
            $taxAmount = abs(round(floatval($t['tax_amount'] ?? 0), 2));
            $ratePct   = floatval($t['rate'] ?? 0) * 100;

            $builder->addDocumentTax(
                $this->vatCategoryForRate($ratePct),
                ZugferdVatTypeCodes::VALUE_ADDED_TAX,
                $basis,
                $taxAmount,
                $ratePct
            );
        }
    }

    private function vatCategoryForRate(float $ratePct): string {
        return $ratePct > 0
            ? ZugferdVatCategoryCodes::STAN_RATE
            : ZugferdVatCategoryCodes::ZERO_RATE_GOOD;
    }

    private function invoiceTypeCode(string $arType): string {
        switch ($arType) {
            case 'credit_note':    return ZugferdInvoiceType::CREDITNOTE;   // 381
            case 'invoice_storno': return ZugferdInvoiceType::CORRECTION;   // 384
            default:               return ZugferdInvoiceType::INVOICE;      // 380
        }
    }

    private function toDate(?string $isoDate): DateTime {
        if (!$isoDate) {
            return new DateTime();
        }
        return new DateTime($isoDate);
    }

    private function formatDate(?string $isoDate): string {
        if (!$isoDate) return '';
        $dt = new DateTime($isoDate);
        return $dt->format('d.m.Y');
    }

    /**
     * Mappt einen Länder-Textwert aus defaults/customer auf ISO-3166-Alpha-2.
     * Unterstützt deutsche, englische und Alpha-2-Schreibweisen für die gängigsten
     * Fälle. Fallback: 'DE', da opensource-erp auf DE-Mandanten zugeschnitten ist.
     */
    private function toCountryCode(string $country): string {
        $c = strtoupper(trim($country));
        if ($c === '' || strlen($c) === 2) {
            return $c !== '' ? $c : ZugferdCountryCodes::GERMANY;
        }
        $map = [
            'DEUTSCHLAND' => 'DE', 'GERMANY' => 'DE',
            'ÖSTERREICH' => 'AT', 'OESTERREICH' => 'AT', 'AUSTRIA' => 'AT',
            'SCHWEIZ' => 'CH', 'SWITZERLAND' => 'CH',
            'FRANKREICH' => 'FR', 'FRANCE' => 'FR',
            'NIEDERLANDE' => 'NL', 'NETHERLANDS' => 'NL',
            'BELGIEN' => 'BE', 'BELGIUM' => 'BE',
            'ITALIEN' => 'IT', 'ITALY' => 'IT',
            'POLEN' => 'PL', 'POLAND' => 'PL',
        ];
        return $map[$c] ?? ZugferdCountryCodes::GERMANY;
    }

    /**
     * Mappt die kivitendo-Einheit auf UN/ECE Rec. 20 Code.
     * Fallback: 'C62' (Stück).
     */
    private function toUnitCode(?string $unit): string {
        $u = strtolower(trim($unit ?? ''));
        $map = [
            'stck' => 'C62', 'stk' => 'C62', 'stueck' => 'C62', 'stück' => 'C62', 'pcs' => 'C62', 'piece' => 'C62',
            'kg'   => 'KGM', 'kilogramm' => 'KGM',
            'g'    => 'GRM', 'gramm' => 'GRM',
            'l'    => 'LTR', 'liter' => 'LTR',
            'ml'   => 'MLT',
            'm'    => 'MTR', 'meter' => 'MTR',
            'cm'   => 'CMT',
            'mm'   => 'MMT',
            'm2'   => 'MTK', 'qm' => 'MTK',
            'm3'   => 'MTQ', 'cbm' => 'MTQ',
            'h'    => 'HUR', 'std' => 'HUR', 'stunde' => 'HUR',
            'min'  => 'MIN',
            'tag'  => 'DAY', 'day' => 'DAY',
            'pack' => 'PK',  'pkg' => 'PK',
            'set'  => 'SET',
            'km'   => 'KMT',
        ];
        return $map[$u] ?? 'C62';
    }
}
