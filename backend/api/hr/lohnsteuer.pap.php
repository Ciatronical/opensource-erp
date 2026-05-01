<?php
// backend/api/hr/lohnsteuer.pap.php
// Implementierung des Lohnsteuer-Programmablaufplans (PAP) 2025/2026
// Quelle: BMF §32a EStG, amtlicher PAP (2025: Jan 2025, 2026: Nov 2025)

class LohnsteuerPAP {

    // ── Jahres-Konstanten (pro Jahr aktualisieren) ────────────────────────────

    /**
     * Gibt die Konstanten fuer ein bestimmtes Steuerjahr zurueck.
     * Neue Jahre hier ergaenzen.
     */
    public static function getKonstanten(int $jahr): array {
        $k = [
            2026 => [
                // §32a Grundfreibetrag und Zonengrenzen
                'GFB'   => 12348,   // Grundfreibetrag 2026
                'Z2'    => 17799,   // Zone 2/3-Grenze
                'Z3'    => 69878,   // Zone 3/4-Grenze (42%-Einstieg)
                'Z4'    => 277825,  // Zone 4/5-Grenze (45%-Einstieg)
                // Zone-2-Polynom: (A2 * y + B2) * y
                'A2'    => 914.51,
                'B2'    => 1400.0,
                // Zone-3-Polynom: (A3 * z + B3) * z + C3
                'A3'    => 173.10,
                'B3'    => 2397.0,
                'C3'    => 1034.87,
                // Zone-4-Lineare: D4 * zvE - E4
                'D4'    => 0.42,
                'E4'    => 11135.63,
                // Zone-5-Lineare: D5 * zvE - E5
                'D5'    => 0.45,
                'E5'    => 19470.38,
                // Pauschbeträge
                'ANP'   => 1230,    // Arbeitnehmer-Pauschbetrag
                'SAP'   => 36,      // Sonderausgaben-Pauschbetrag
                'EAB'   => 4260,    // Entlastungsbetrag Alleinerziehende (SK2)
                // Solidaritätszuschlag
                'SOLI_RATE'         => 0.055,
                'SOLI_FREIGRENZE'   => 20350,   // Jahreslohnsteuer (SK1,2,4,5,6)
                'SOLI_FREIGRENZE3'  => 40700,   // Jahreslohnsteuer (SK3)
                'SOLI_MILDERUNG'    => 0.119,   // Milderungszonenkoeffizient
                // Beitragsbemessungsgrenzen (für Vorsorgepauschale)
                'BBG_RV'  => 101400, // BBG Rentenversicherung West 2026
                'BBG_KV'  => 69750,  // BBG Kranken-/Pflegeversicherung 2026
                // Standard-SV-Sätze AN (wenn nicht aus Einstellungen bekannt)
                'RV_AN'   => 9.3,
                'KV_AN'   => 7.3,
                'PV_AN'   => 1.7,
            ],
            2025 => [
                // §32a Grundfreibetrag und Zonengrenzen
                'GFB'   => 12096,   // Grundfreibetrag (Zone 1/2-Grenze)
                'Z2'    => 17443,   // Zone 2/3-Grenze
                'Z3'    => 68480,   // Zone 3/4-Grenze (42%-Einstieg)
                'Z4'    => 277825,  // Zone 4/5-Grenze (45%-Einstieg)
                // Zone-2-Polynom: (A2 * y + B2) * y
                'A2'    => 932.30,
                'B2'    => 1400.0,
                // Zone-3-Polynom: (A3 * z + B3) * z + C3
                'A3'    => 176.64,
                'B3'    => 2397.0,
                'C3'    => 1015.13,
                // Zone-4-Lineare: D4 * zvE - E4
                'D4'    => 0.42,
                'E4'    => 10911.92,
                // Zone-5-Lineare: D5 * zvE - E5
                'D5'    => 0.45,
                'E5'    => 19246.67,
                // Pauschbetraege
                'ANP'   => 1230,    // Arbeitnehmer-Pauschbetrag
                'SAP'   => 36,      // Sonderausgaben-Pauschbetrag
                'EAB'   => 4260,    // Entlastungsbetrag Alleinerziehende (SK2)
                // Solidaritaetszuschlag
                'SOLI_RATE'         => 0.055,
                'SOLI_FREIGRENZE'   => 19950,   // Jahreslohnsteuer (SK1,2,4,5,6)
                'SOLI_FREIGRENZE3'  => 39900,   // Jahreslohnsteuer (SK3)
                'SOLI_MILDERUNG'    => 0.119,   // Milderungszonenkoeffizient
                // Beitragsbemessungsgrenzen (fuer Vorsorgepauschale)
                'BBG_RV'  => 96600, // BBG Rentenversicherung West 2025
                'BBG_KV'  => 66150, // BBG Kranken-/Pflegeversicherung 2025
                // Standard-SV-Saetze AN (wenn nicht aus Einstellungen bekannt)
                'RV_AN'   => 9.3,
                'KV_AN'   => 7.3,
                'PV_AN'   => 1.7,
            ],
            2024 => [
                'GFB'   => 11604,
                'Z2'    => 17005,
                'Z3'    => 66760,
                'Z4'    => 277825,
                'A2'    => 979.18,
                'B2'    => 1400.0,
                'A3'    => 192.59,
                'B3'    => 2397.0,
                'C3'    => 966.53,
                'D4'    => 0.42,
                'E4'    => 10602.13,
                'D5'    => 0.45,
                'E5'    => 17936.88,
                'ANP'   => 1230,
                'SAP'   => 36,
                'EAB'   => 4260,
                'SOLI_RATE'         => 0.055,
                'SOLI_FREIGRENZE'   => 17543,
                'SOLI_FREIGRENZE3'  => 35086,
                'SOLI_MILDERUNG'    => 0.119,
                'BBG_RV'  => 90600,
                'BBG_KV'  => 62100,
                'RV_AN'   => 9.3,
                'KV_AN'   => 7.3,
                'PV_AN'   => 1.7,
            ],
        ];

        if (!isset($k[$jahr])) {
            // Fallback: letztes bekanntes Jahr verwenden
            $k[$jahr] = $k[max(array_keys($k))];
        }

        return $k[$jahr];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Berechnet Lohnsteuer, Soli und Kirchensteuer fuer einen Lohnzahlungszeitraum.
     *
     * @param float $brutto_monat   Bruttogehalt pro Monat in EUR
     * @param int   $steuerklasse   Steuerklasse 1-6
     * @param float $rv_prozent     RV AN-Anteil % (aus Gehaltseinstellungen)
     * @param float $kv_prozent     KV AN-Anteil %
     * @param float $pv_prozent     PV AN-Anteil %
     * @param bool  $kirchensteuer  true wenn Kirchensteuerpflicht
     * @param float $kist_satz      Kirchensteuersatz % (9.0 Standard, 8.0 Bayern/BaWue)
     * @param int   $jahr           Steuerjahr (default: aktuelles Jahr)
     * @return array                [lohnsteuer_monat, soli_monat, kist_monat,
     *                               lohnsteuer_jahr, soli_jahr, kist_jahr, zve_jahr]
     */
    public static function berechne(
        float $brutto_monat,
        int   $steuerklasse,
        float $rv_prozent   = 0.0,
        float $kv_prozent   = 0.0,
        float $pv_prozent   = 0.0,
        bool  $kirchensteuer = false,
        float $kist_satz    = 9.0,
        int   $jahr         = 0
    ): array {
        if ($jahr <= 0) $jahr = (int) date('Y');
        $k = self::getKonstanten($jahr);

        // Fehlende SV-Saetze mit Standardwerten belegen
        if ($rv_prozent <= 0) $rv_prozent = $k['RV_AN'];
        if ($kv_prozent <= 0) $kv_prozent = $k['KV_AN'];
        if ($pv_prozent <= 0) $pv_prozent = $k['PV_AN'];

        // 1. Jahres-Arbeitslohn
        $jre4 = $brutto_monat * 12.0;

        // 2. Arbeitnehmer-Pauschbetrag
        $anp = ($steuerklasse == 6) ? 0.0 : min((float)$k['ANP'], $jre4);

        // 3. Sonderausgaben-Pauschbetrag
        $sap = ($steuerklasse == 6) ? 0.0 : (float)$k['SAP'];

        // 4. Vorsorgepauschale
        $vp = self::vorsorgepauschale($jre4, $steuerklasse, $rv_prozent, $kv_prozent, $pv_prozent, $k);

        // 5. Grundfreibetrag je Steuerklasse
        $gfb = self::grundfreibetrag($steuerklasse, $k);

        // 6. Entlastungsbetrag fuer Alleinerziehende (SK2)
        $eab = ($steuerklasse == 2) ? (float)$k['EAB'] : 0.0;

        // 7. Zu versteuerndes Einkommen (auf vollen Euro abgerundet)
        $zve = max(0.0, floor($jre4 - $anp - $sap - $vp) - $gfb - $eab);

        // 8. Einkommensteuer-Tarif (SK3: Splittingverfahren)
        if ($steuerklasse == 3) {
            $est_annual = 2.0 * self::tarif(floor($zve / 2.0), $k);
        } else {
            $est_annual = self::tarif($zve, $k);
        }
        $est_annual = floor($est_annual);

        // 9. Solidaritaetszuschlag
        $soli_annual = self::solidaritaetszuschlag($est_annual, $steuerklasse, $k);

        // 10. Kirchensteuer (optional)
        $kist_annual = $kirchensteuer ? floor($est_annual * $kist_satz / 100.0) : 0.0;

        return [
            'lohnsteuer_monat' => (int) floor($est_annual  / 12),
            'soli_monat'       => (int) floor($soli_annual / 12),
            'kist_monat'       => (int) floor($kist_annual / 12),
            'lohnsteuer_jahr'  => (int) $est_annual,
            'soli_jahr'        => (int) $soli_annual,
            'kist_jahr'        => (int) $kist_annual,
            'zve_jahr'         => (int) $zve,
        ];
    }

    // ─── Hilfsfunktionen ────────────────────────────────────────────────────

    private static function grundfreibetrag(int $stkl, array $k): float {
        if ($stkl == 3) return 2.0 * $k['GFB'];   // Splitting: doppelter GFB
        if ($stkl >= 5)  return 0.0;               // SK5/6: kein GFB
        return (float) $k['GFB'];
    }

    /**
     * Vereinfachte Vorsorgepauschale gemaess PAP.
     * Benutzt die gespeicherten SV-Saetze des Mitarbeiters.
     */
    private static function vorsorgepauschale(
        float $jre4, int $stkl,
        float $rv_pz, float $kv_pz, float $pv_pz,
        array $k
    ): float {
        // RV-Anteil bis BBG RV
        $vp_rv = ($rv_pz / 100.0) * min($jre4, (float)$k['BBG_RV']);

        // KV+PV-Anteil bis BBG KV
        $vp_kv = (($kv_pz + $pv_pz) / 100.0) * min($jre4, (float)$k['BBG_KV']);

        $vp = $vp_rv + $vp_kv;

        // SK5/6: halbe Vorsorgepauschale
        if ($stkl == 5 || $stkl == 6) $vp /= 2.0;

        return floor($vp);
    }

    /** §32a Einkommensteuertarif */
    private static function tarif(float $zve, array $k): float {
        $zve = floor($zve);
        if ($zve <= 0) return 0.0;

        // Zone 1
        if ($zve <= $k['GFB']) return 0.0;

        // Zone 2
        if ($zve <= $k['Z2']) {
            $y = ($zve - $k['GFB']) / 10000.0;
            return ($k['A2'] * $y + $k['B2']) * $y;
        }

        // Zone 3
        if ($zve <= $k['Z3']) {
            $z = ($zve - $k['Z2']) / 10000.0;
            return ($k['A3'] * $z + $k['B3']) * $z + $k['C3'];
        }

        // Zone 4
        if ($zve <= $k['Z4']) {
            return $k['D4'] * $zve - $k['E4'];
        }

        // Zone 5 (Reichensteuer)
        return $k['D5'] * $zve - $k['E5'];
    }

    /** Solidaritaetszuschlag (Milderungszone gemaess §4 SolzG) */
    private static function solidaritaetszuschlag(float $lst, int $stkl, array $k): float {
        if ($lst <= 0) return 0.0;

        $freigrenze = ($stkl == 3)
            ? (float) $k['SOLI_FREIGRENZE3']
            : (float) $k['SOLI_FREIGRENZE'];

        if ($lst <= $freigrenze) return 0.0;

        // Milderungszone: min(5,5% von LST, 11,9% von (LST - Freigrenze))
        $soli_voll      = $lst * $k['SOLI_RATE'];
        $soli_milderung = ($lst - $freigrenze) * $k['SOLI_MILDERUNG'];

        return floor(min($soli_voll, $soli_milderung));
    }
}
