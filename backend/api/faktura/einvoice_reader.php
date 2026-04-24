<?php
// backend/api/faktura/einvoice_reader.php

require_once __DIR__.'/../../vendor/autoload.php';

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;

/**
 * Versucht, aus einem PDF oder XML-Dokument die strukturierten ZUGFeRD/XRechnung-Daten
 * zu extrahieren. Gibt null zurück, wenn das Dokument keine E-Rechnung ist.
 *
 * Das Rückgabeformat ist kompatibel zur bestehenden Claude-KI-Extraktion in
 * invoice_upload.php (vendor, invoice, amounts, positions, booking_suggestion,
 * confidence, notes) — so ersetzt die XML-Extraktion nur den KI-Call, der
 * Rest des Upload-Flows bleibt unverändert.
 */
function extractEInvoiceData(string $fileContent, string $mimeType): ?array {
    $reader = null;
    try {
        if (str_contains($mimeType, 'pdf')) {
            $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($fileContent);
        } elseif (str_contains($mimeType, 'xml')) {
            $reader = ZugferdDocumentReader::readAndGuessFromContent($fileContent);
        }
    } catch (\Throwable $e) {
        return null;
    }
    if (!$reader instanceof ZugferdDocumentReader) {
        return null;
    }

    // Header
    $invNo = $docType = $currency = $taxCurrency = $docName = $docLang = null;
    $invDate = $periodDate = null;
    $reader->getDocumentInformation($invNo, $docType, $invDate, $currency, $taxCurrency, $docName, $docLang, $periodDate);

    // Seller
    $sellerName = $sellerDesc = null; $sellerId = [];
    $reader->getDocumentSeller($sellerName, $sellerId, $sellerDesc);

    $sellerStreet = $sellerStreet2 = $sellerStreet3 = $sellerZip = $sellerCity = $sellerCountry = null;
    $sellerSubDiv = [];
    $reader->getDocumentSellerAddress($sellerStreet, $sellerStreet2, $sellerStreet3, $sellerZip, $sellerCity, $sellerCountry, $sellerSubDiv);

    $sellerTaxReg = [];
    $reader->getDocumentSellerTaxRegistration($sellerTaxReg);
    $sellerVatId = $sellerTaxReg['VA'] ?? null;
    $sellerTaxNo = $sellerTaxReg['FC'] ?? null;

    $sellerContactEmail = $sellerUriScheme = null;
    $sellerUri = null;
    $reader->getDocumentSellerCommunication($sellerUriScheme, $sellerUri);
    if ($sellerUriScheme === 'EM') {
        $sellerContactEmail = $sellerUri;
    }

    $sellerIban = null; $sellerBic = null;
    try {
        if ($reader->firstDocumentPaymentMeans()) {
            do {
                $typeCode = $info = $cardType = $cardId = $cardHolder = $buyerIban = $payeeIban = $payeeAccount = $payeePropId = $payeeBic = null;
                $reader->getDocumentPaymentMeans($typeCode, $info, $cardType, $cardId, $cardHolder, $buyerIban, $payeeIban, $payeeAccount, $payeePropId, $payeeBic);
                if (!empty($payeeIban)) {
                    $sellerIban = $payeeIban;
                    $sellerBic  = $payeeBic;
                    break;
                }
            } while ($reader->nextDocumentPaymentMeans());
        }
    } catch (\Throwable $e) {
        // Payment Means optional
    }

    // Summation
    $grandTotal = $duePayable = $lineTotal = $chargeTotal = $allowanceTotal = $taxBasisTotal = $taxTotal = $rounding = $prepaid = null;
    $reader->getDocumentSummation($grandTotal, $duePayable, $lineTotal, $chargeTotal, $allowanceTotal, $taxBasisTotal, $taxTotal, $rounding, $prepaid);

    // Tax-Breakdown (dominanter Steuersatz für booking_suggestion)
    $dominantRate = 0.0;
    try {
        if ($reader->firstDocumentTax()) {
            $maxBasis = 0.0;
            do {
                $category = $typeCode = $reason = $reasonCode = null;
                $basis = $calc = $rate = $lineBasis = $allowanceBasis = null;
                $taxPoint = $dueDateCode = null;
                $reader->getDocumentTax($category, $typeCode, $basis, $calc, $rate, $reason, $reasonCode, $lineBasis, $allowanceBasis, $taxPoint, $dueDateCode);
                if (($basis ?? 0) > $maxBasis) {
                    $maxBasis = $basis;
                    $dominantRate = floatval($rate ?? 0);
                }
            } while ($reader->nextDocumentTax());
        }
    } catch (\Throwable $e) {
        // Tax optional — wir haben Summen aus der Summation
    }

    // Due date
    $dueDate = null;
    try {
        if ($reader->firstDocumentPaymentTerms()) {
            $description = $mandate = null;
            $dueDt = null;
            $reader->getDocumentPaymentTerm($description, $dueDt, $mandate);
            if ($dueDt instanceof DateTime) {
                $dueDate = $dueDt->format('Y-m-d');
            }
        }
    } catch (\Throwable $e) {
        // optional
    }

    // Positionen
    $positions = [];
    try {
        if ($reader->firstDocumentPosition()) {
            $idx = 0;
            do {
                $idx++;
                $lineid = $lineStatusCode = $lineStatusReasonCode = null;
                $reader->getDocumentPositionGenerals($lineid, $lineStatusCode, $lineStatusReasonCode);

                $prodName = $prodDesc = $prodSellerId = $prodBuyerId = $prodGlobalId = $prodGlobalIdType = null;
                $prodIndDesc = null; $prodIndDescType = null;
                $reader->getDocumentPositionProductDetails($prodName, $prodDesc, $prodSellerId, $prodBuyerId, $prodGlobalId, $prodGlobalIdType, $prodIndDesc, $prodIndDescType);

                $qty = $unit = null;
                $reader->getDocumentPositionQuantity($qty, $unit);

                $netPrice = $netBasisQty = $netBasisUnit = null;
                $reader->getDocumentPositionNetPrice($netPrice, $netBasisQty, $netBasisUnit);

                $posLineSum = null;
                $reader->getDocumentPositionLineSummation($posLineSum);

                $posCat = $posType = $posRate = null;
                try {
                    if ($reader->firstDocumentPositionTax()) {
                        $posCat = $posType = $posExRate = null;
                        $posExReason = $posExReasonCode = null;
                        $reader->getDocumentPositionTax($posCat, $posType, $posRate, $posExReason, $posExReasonCode);
                    }
                } catch (\Throwable $e) {
                    // tax pro Position optional
                }

                $positions[] = [
                    'description' => $prodName ?? $prodDesc ?? '',
                    'quantity'    => floatval($qty ?? 0),
                    'unit_price'  => floatval($netPrice ?? 0),
                    'net_amount'  => floatval($posLineSum ?? 0),
                    'tax_rate'    => floatval($posRate ?? 0),
                    'gross_amount'=> floatval($posLineSum ?? 0) * (1 + floatval($posRate ?? 0) / 100),
                ];
            } while ($reader->nextDocumentPosition());
        }
    } catch (\Throwable $e) {
        // Positionen optional — Summen reichen für Buchung
    }

    $gross = floatval($grandTotal ?? 0);
    $net   = floatval($taxBasisTotal ?? $lineTotal ?? 0);
    $tax   = floatval($taxTotal ?? ($gross - $net));

    $taxKey = 0;
    if (abs($dominantRate - 19) < 0.01) $taxKey = 9;
    elseif (abs($dominantRate - 7) < 0.01) $taxKey = 8;

    return [
        'vendor' => [
            'name'      => $sellerName ?? '',
            'street'    => $sellerStreet ?? '',
            'zipcode'   => $sellerZip ?? '',
            'city'      => $sellerCity ?? '',
            'country'   => $sellerCountry ?? 'DE',
            'taxnumber' => $sellerTaxNo ?? '',
            'ustid'     => $sellerVatId ?? '',
            'iban'      => $sellerIban ?? '',
            'bic'       => $sellerBic ?? '',
            'email'     => $sellerContactEmail ?? '',
        ],
        'invoice' => [
            'number'        => $invNo ?? '',
            'date'          => ($invDate instanceof DateTime) ? $invDate->format('Y-m-d') : null,
            'due_date'      => $dueDate,
            'delivery_date' => null,
        ],
        'amounts' => [
            'gross'    => $gross,
            'net'      => $net,
            'tax'      => $tax,
            'tax_rate' => $dominantRate,
            'currency' => $currency ?? 'EUR',
        ],
        'positions' => $positions,
        'booking_suggestion' => [
            'debit_account'  => '4980',
            'credit_account' => '1600',
            'tax_key'        => $taxKey,
            'description'    => ($sellerName ?? 'Eingangsrechnung') . ' - ' . ($invNo ?? ''),
        ],
        'confidence' => 1.0,
        'source'     => 'einvoice',
        'notes'      => 'Strukturierte ZUGFeRD/XRechnung-Daten aus dem Dokument extrahiert.',
    ];
}
