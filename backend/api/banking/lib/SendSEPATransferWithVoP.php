<?php
// backend/api/banking/lib/SendSEPATransferWithVoP.php
//
// SEPA-Überweisung mit Verification of Payee (VoP), implementiert für phpFinTS.
//
// Schritt 1 (createWithCheck):
//   Sendet HKVPP + HKCCS/HKCSE. Bank führt Namensabgleich durch und gibt
//   HIVPP(RCVC) + TAN-Challenge zurück (exakter Treffer) oder
//   HIVPP(RVMC/RVNM/RVNA) ohne TAN (Abweichung, User muss bestätigen).
//
// Schritt 2 (createWithApproval):
//   Sendet HKVPA(vopId) + HKCCS/HKCSE nach User-Bestätigung.
//   Bank gibt TAN-Challenge zurück.

namespace OserpBanking;

use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Segment\Common\Kti;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\SPA\HISPAS;
use Fhp\Segment\VPA\HKVPAv1;
use Fhp\Segment\VPP\HKVPPv1;
use Fhp\Segment\VPP\HIVPPv1;
use Fhp\Syntax\Bin;
use Fhp\UnsupportedException;

class SendSEPATransferWithVoP extends BaseAction
{
    // pain.002 URN den wir als Antwortformat ankündigen (Standard EU-Format)
    const PAIN002_URN = 'urn:iso:std:iso:20022:tech:xsd:pain.002.001.10';

    private SEPAAccount $account;
    private string $painMessage;
    private string $xmlSchema;
    private ?string $vopId; // null = Schritt 1 (HKVPP), gesetzt = Schritt 2 (HKVPA)

    /** Gesetzt nach processResponse wenn VoP-Abweichung (RVMC/RVNM/RVNA) */
    public ?HIVPPv1 $vopResponse = null;

    public static function createWithCheck(SEPAAccount $account, string $painMessage): self
    {
        if (preg_match('/xmlns="(.*?)"/', $painMessage, $m) === false) {
            throw new \InvalidArgumentException('xmlns nicht im PAIN-XML gefunden');
        }
        $r = new self();
        $r->account     = $account;
        $r->painMessage = $painMessage;
        $r->xmlSchema   = $m[1];
        $r->vopId       = null;
        return $r;
    }

    public static function createWithApproval(SEPAAccount $account, string $painMessage, string $vopId): self
    {
        if (preg_match('/xmlns="(.*?)"/', $painMessage, $m) === false) {
            throw new \InvalidArgumentException('xmlns nicht im PAIN-XML gefunden');
        }
        $r = new self();
        $r->account     = $account;
        $r->painMessage = $painMessage;
        $r->xmlSchema   = $m[1];
        $r->vopId       = $vopId;
        return $r;
    }

    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        $transfer = $this->buildTransferSegment($bpd);

        if ($this->vopId !== null) {
            // Schritt 2: HKVPA (User hat VoP-Abweichung bestätigt)
            $hkvpa = HKVPAv1::createEmpty();
            if ($this->vopId !== '') {
                $hkvpa->vopId = new Bin($this->vopId);
            }
            return [$hkvpa, $transfer];
        }

        // Schritt 1: HKVPP (Namensabgleich anfragen)
        $hkvpp = HKVPPv1::createEmpty();
        $hkvpp->psrd = self::PAIN002_URN;
        return [$hkvpp, $transfer];
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response); // extrahiert HITAN → tanRequest

        // VoP-Ergebnis auswerten
        /** @var HIVPPv1|null $hivpp */
        $hivpp = $response->findSegment(HIVPPv1::class);
        if ($hivpp !== null) {
            $result   = $hivpp->vopSingleResult?->result ?? null;
            $has3945  = $response->findRueckmeldung(3945) !== null;

            // RVMC/RVNM/RVNA oder RCVC mit Sperr-Code 3945 → User muss bestätigen
            if ($has3945 || ($result !== null && $result !== 'RCVC')) {
                $this->vopResponse = $hivpp;
                $this->tanRequest  = null; // kein TAN nötig in diesem Schritt
                $this->isDone      = true;
                return;
            }
            // RCVC → exakter Treffer, weiter wie normal
        }

        // Überweisung muss entgegengenommen oder direkt ausgeführt worden sein
        if (!$this->needsTan() && !$this->isDone) {
            if ($response->findRueckmeldung(Rueckmeldungscode::ENTGEGENGENOMMEN) === null
                && $response->findRueckmeldung(Rueckmeldungscode::AUSGEFUEHRT) === null
            ) {
                throw new UnexpectedResponseException('Bank hat SEPA-Überweisung nicht bestätigt');
            }
            $this->isDone = true;
        }
    }

    private function buildTransferSegment(BPD $bpd)
    {
        $xml      = simplexml_load_string($this->painMessage, 'SimpleXMLElement', LIBXML_NOCDATA);
        $nbOfTxs  = (int)$xml->CstmrCdtTrfInitn->GrpHdr->NbOfTxs;
        $hasDate  = false;
        foreach ($xml->CstmrCdtTrfInitn?->PmtInf as $pmtInf) {
            if (isset($pmtInf->ReqdExctnDt)
                && ($pmtInf->ReqdExctnDt->Dt ?? $pmtInf->ReqdExctnDt) != '1999-01-01'
            ) {
                $hasDate = true;
                break;
            }
        }

        if ($nbOfTxs > 1 && $hasDate) {
            $segId    = 'HICMES';
            $transfer = \Fhp\Segment\CME\HKCMEv1::createEmpty();
        } elseif ($nbOfTxs === 1 && $hasDate) {
            $segId    = 'HICSES';
            $transfer = \Fhp\Segment\CSE\HKCSEv1::createEmpty();
        } elseif ($nbOfTxs > 1) {
            $segId    = 'HICSES';
            $transfer = \Fhp\Segment\CCM\HKCCMv1::createEmpty();
        } else {
            $segId    = 'HICCSS';
            $transfer = \Fhp\Segment\CCS\HKCCSv1::createEmpty();
        }

        if (!$bpd->supportsParameters($segId, 1)) {
            throw new UnsupportedException('Die Bank unterstützt ' . $segId . 'v1 nicht');
        }

        /** @var \Fhp\Segment\SPA\HISPAS $hispas */
        $hispas   = $bpd->requireLatestSupportedParameters('HISPAS');
        $schemas  = $hispas->getParameter()->getUnterstuetzteSEPADatenformate();
        $schema   = $this->xmlSchema;
        $matching = array_filter($schemas, fn($v) => str_starts_with($v, $schema));
        if (count($matching) === 0) {
            throw new UnsupportedException(
                "Bank unterstützt XML-Schema $schema nicht, nur: " . implode(', ', $schemas)
            );
        }

        $transfer->kontoverbindungInternational = Kti::fromAccount($this->account);
        $transfer->sepaDescriptor               = $this->xmlSchema;
        $transfer->sepaPainMessage              = new Bin($this->painMessage);
        return $transfer;
    }
}
