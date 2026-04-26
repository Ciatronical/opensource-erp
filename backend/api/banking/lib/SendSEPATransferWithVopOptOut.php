<?php
// backend/api/banking/lib/SendSEPATransferWithVopOptOut.php
//
// Erweiterte Transfer-Action: sendet HKVOO (VoP-Opt-Out) zusammen mit
// HKCCS/HKCSE in derselben Nachricht. Damit übergeht die Sparkasse die
// VoP-Pflicht (Code 9076) für diese eine Überweisung — sofern das Konto
// für HKVOO freigeschaltet ist (in HIUPD als erlaubter Geschäftsvorfall).
//
// Architektur: phpFinTS erlaubt createRequest() ein Array von Segmenten
// zurückzugeben. getNextRequest() + MessageBuilder verarbeiten das korrekt,
// und die TAN-Referenz zeigt weiterhin auf HKCCS (nicht auf HKVOO).

namespace OserpBanking;

use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Segment\Common\Kti; // für $transfer->kontoverbindungInternational
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\SPA\HISPAS;
use Fhp\Segment\VOO\HKVOOv1;
use Fhp\Syntax\Bin;
use Fhp\UnsupportedException;

/**
 * SEPA-Überweisung mit explizitem VoP-Opt-Out (HKVOO).
 * Identische Logik wie \Fhp\Action\SendSEPATransfer, gibt aber zusätzlich
 * ein HKVOO-Segment zurück, damit die Bank die VoP-Prüfung überspringt.
 */
class SendSEPATransferWithVopOptOut extends BaseAction
{
    private SEPAAccount $account;
    private string $painMessage;
    private string $xmlSchema;

    public static function create(SEPAAccount $account, string $painMessage): self
    {
        if (preg_match('/xmlns="(.*?)"/', $painMessage, $match) === false) {
            throw new \InvalidArgumentException('xmlns nicht im PAIN-XML gefunden');
        }
        $result = new self();
        $result->account = $account;
        $result->painMessage = $painMessage;
        $result->xmlSchema = $match[1];
        return $result;
    }

    protected function createRequest(BPD $bpd, ?UPD $upd)
    {
        // XML analysieren (identisch mit SendSEPATransfer)
        $xml = simplexml_load_string($this->painMessage, 'SimpleXMLElement', LIBXML_NOCDATA);
        $nbOfTxs     = (int)$xml->CstmrCdtTrfInitn->GrpHdr->NbOfTxs;
        $hasExecDate = false;
        foreach ($xml->CstmrCdtTrfInitn?->PmtInf as $pmtInf) {
            if (isset($pmtInf->ReqdExctnDt)
                && ($pmtInf->ReqdExctnDt->Dt ?? $pmtInf->ReqdExctnDt) != '1999-01-01'
            ) {
                $hasExecDate = true;
                break;
            }
        }

        // Passendes Transfer-Segment wählen
        if ($nbOfTxs > 1 && $hasExecDate) {
            $segmentID = 'HICMES';
            $transfer  = \Fhp\Segment\CME\HKCMEv1::createEmpty();
        } elseif ($nbOfTxs === 1 && $hasExecDate) {
            $segmentID = 'HICSES';
            $transfer  = \Fhp\Segment\CSE\HKCSEv1::createEmpty();
        } elseif ($nbOfTxs > 1) {
            $segmentID = 'HICSES';
            $transfer  = \Fhp\Segment\CCM\HKCCMv1::createEmpty();
        } else {
            $segmentID = 'HICCSS';
            $transfer  = \Fhp\Segment\CCS\HKCCSv1::createEmpty();
        }

        if (!$bpd->supportsParameters($segmentID, 1)) {
            throw new UnsupportedException('Die Bank unterstützt ' . $segmentID . 'v1 nicht');
        }

        /** @var HISPAS $hispas */
        $hispas           = $bpd->requireLatestSupportedParameters('HISPAS');
        $supportedSchemas = $hispas->getParameter()->getUnterstuetzteSEPADatenformate();
        $xmlSchema        = $this->xmlSchema;
        $matching         = array_filter($supportedSchemas, fn($v) => str_starts_with($v, $xmlSchema));

        if (count($matching) === 0) {
            throw new UnsupportedException(
                "Bank unterstützt XML-Schema $this->xmlSchema nicht, nur: " . implode(', ', $supportedSchemas)
            );
        }

        $transfer->kontoverbindungInternational = Kti::fromAccount($this->account);
        $transfer->sepaDescriptor               = $this->xmlSchema;
        $transfer->sepaPainMessage              = new Bin($this->painMessage);

        // HKVOO: reines Flag-Segment ohne Datenelemente.
        // Banktest ergab: Kti als DE 2 → 9110 "Unbekannter Aufbau".
        // Leeres Segment (nur Header) signalisiert der Bank: kein VoP für diese Überweisung.
        $vopOptOut = HKVOOv1::createEmpty();

        return [$vopOptOut, $transfer];
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response);
        if ($response->findRueckmeldung(Rueckmeldungscode::ENTGEGENGENOMMEN) === null
            && $response->findRueckmeldung(Rueckmeldungscode::AUSGEFUEHRT) === null
        ) {
            throw new UnexpectedResponseException('Bank hat SEPA-Überweisung nicht bestätigt');
        }
    }
}
