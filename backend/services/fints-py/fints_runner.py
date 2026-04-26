#!/usr/bin/env python3
"""
FinTS-Runner — Python-CLI als Brücke zwischen PHP und python-fints.

phpFinTS unterstützt VoP (Verification of Payee) nicht. python-fints schon.
Daher führen wir SEPA-Überweisungen über dieses Script aus.

Aufruf:
    transfer    --session FILE --config JSON --transfer JSON
    tan         --session FILE --config JSON --tan TAN
    approve_vop --session FILE --config JSON

I/O: JSON über stdout. Fehler über stderr + exit code != 0.

State (Dialog-Pause-Blob + NeedRetryResponse-Blob) wird in der Session-Datei
zwischen Aufrufen persistiert. Die Datei muss zwischen User-Sessions getrennt
sein (PHP übergibt einen User-spezifischen Pfad).
"""
import argparse
import base64
import json
import pickle
import sys
import traceback
from decimal import Decimal
from pathlib import Path

from fints.client import (
    FinTS3PinTanClient,
    FinTSClientMode,
    NeedTANResponse,
    NeedVOPResponse,
    TransactionResponse,
)
from fints.hhd.flicker import parse as flicker_parse  # noqa: F401  (registriert)


def out(payload: dict) -> None:
    """Schreibt JSON nach stdout und beendet sauber."""
    sys.stdout.write(json.dumps(payload, default=str, ensure_ascii=False))
    sys.stdout.flush()


def fail(code: str, message: str, **extra) -> None:
    # stderr-Trace landet im PHP-Log; stdout-JSON traegt zusaetzlich `trace`,
    # damit der PHP-Wrapper bei Bedarf detaillierten Kontext loggen kann.
    if "trace" in extra:
        sys.stderr.write(extra["trace"] + "\n")
        sys.stderr.flush()
    out({"status": "error", "code": code, "message": message, **extra})
    sys.exit(2)


def setup_debug_logging():
    """Aktiviert pythonseitiges FinTS-Logging nach stderr (= ins PHP-Debug-Log).
    Hilft beim Diagnostizieren von BPD-Init-/SSL-/Bank-Fehlern."""
    import logging
    logging.basicConfig(
        level=logging.DEBUG,
        stream=sys.stderr,
        format="[fints-py] %(name)s %(levelname)s: %(message)s",
    )
    # python-fints' eigene Logger einschalten
    logging.getLogger("fints").setLevel(logging.DEBUG)


def make_client(config: dict, mode: FinTSClientMode = FinTSClientMode.INTERACTIVE) -> FinTS3PinTanClient:
    """Erzeugt einen FinTS-Client. config muss enthalten:
       fints_url, fints_bank_code (BLZ), fints_username, pin
    """
    return FinTS3PinTanClient(
        config["fints_bank_code"],
        config["fints_username"],
        config["pin"],
        config["fints_url"],
        product_id=config.get("product_id") or "9FA6681DEC0CF3046BFC2F8A6",
        product_version=config.get("product_version") or "1.0",
        mode=mode,
    )


def _need_tan_payload(response: "NeedTANResponse") -> dict:
    """Frontendseitige Felder fuer eine TAN-Anforderung extrahieren."""
    challenge_matrix = None
    if response.challenge_matrix:
        mime, data = response.challenge_matrix
        challenge_matrix = {"mime": mime, "data_b64": base64.b64encode(data).decode("ascii")}
    return {
        "decoupled": bool(response.decoupled),
        "challenge": response.challenge or "",
        "challenge_html": response.challenge_html or "",
        "challenge_hhduc": response.challenge_hhduc,
        "challenge_matrix": challenge_matrix,
        "vop_match_status": _vop_status(response.vop_result),
    }


def _save_state(session_path: Path, kind: str, client: FinTS3PinTanClient,
                response, transfer: dict | None = None) -> None:
    """Pausiert Dialog + persistiert Client-Daten + ggf. Transfer-Job fuer den
    naechsten Subcommand-Aufruf. PIN wird nie gespeichert — der Aufrufer muss
    sie beim Resume erneut mitliefern (aus Sicherheits-Gruenden).
    """
    dialog_data = client.pause_dialog()
    blob = {
        "kind": kind,                                # 'login_tan' | 'tan' | 'vop'
        "dialog_data": dialog_data,
        "client_data": client.deconstruct(including_private=True),
        "retry_blob": response.get_data(),
    }
    if transfer is not None:
        blob["transfer"] = transfer
    session_path.write_bytes(pickle.dumps(blob))


def _serialize_response(client: FinTS3PinTanClient, response, session_path: Path,
                        transfer: dict | None = None) -> dict:
    """Bereitet ein Response-Objekt für PHP auf und persistiert ggf. die Session."""
    if isinstance(response, NeedTANResponse):
        _save_state(session_path, "tan", client, response, transfer)
        return {"status": "need_tan", **_need_tan_payload(response)}

    if isinstance(response, NeedVOPResponse):
        _save_state(session_path, "vop", client, response, transfer)
        evpe = response.vop_result.vop_single_result
        return {
            "status": "need_vop_approval",
            "vop": {
                "result": getattr(evpe, "result", None),
                "close_match_name": getattr(evpe, "close_match_name", None),
                "recipient_iban": getattr(evpe, "recipient_IBAN", None),
                "info_iban": getattr(evpe, "info_IBAN", None),
                "na_reason": getattr(evpe, "na_reason", None),
            },
            "manual_authorization_notice": getattr(response.vop_result, "manual_authorization_notice", None),
        }

    if isinstance(response, TransactionResponse):
        if session_path.exists():
            session_path.unlink()
        return {
            "status": "success",
            "responses": [{"code": r.code, "ref": r.reference, "text": r.text} for r in response.responses],
            "data": dict(response.data) if response.data else {},
        }

    # True/None: simple_sepa_transfer hat das Geschäft schon abgeschlossen
    if session_path.exists():
        session_path.unlink()
    return {"status": "success", "responses": [], "data": {}}


def _vop_status(vop_result) -> str | None:
    """Zieht den VoP-Result-Code aus einem HIVPP-Segment für Anzeige."""
    if not vop_result:
        return None
    evpe = getattr(vop_result, "vop_single_result", None)
    if not evpe:
        return None
    return getattr(evpe, "result", None)


def _load_state(session_path: Path) -> dict:
    if not session_path.exists():
        fail("no_session", "Keine pausierte FinTS-Session vorhanden. Bitte Auftrag neu starten.")
    return pickle.loads(session_path.read_bytes())


def _restore_client(config: dict, blob: dict):
    """Stellt FinTS3PinTanClient + NeedRetryResponse aus dem Pickle wieder her."""
    from fints.client import NeedRetryResponse, FinTSClientMode
    client = FinTS3PinTanClient(
        config["fints_bank_code"],
        config["fints_username"],
        config["pin"],
        config["fints_url"],
        product_id=config.get("product_id") or "9FA6681DEC0CF3046BFC2F8A6",
        product_version=config.get("product_version") or "1.0",
        from_data=blob["client_data"],
        mode=FinTSClientMode.INTERACTIVE,
    )
    retry_response = NeedRetryResponse.from_data(blob["retry_blob"])
    return client, retry_response


def _bootstrap_tan_settings(client: FinTS3PinTanClient, config: dict) -> None:
    """Setzt TAN-Verfahren + Medium analog `minimal_interactive_cli_bootstrap`.

    MUSS vor `with client:` aufgerufen werden — sonst nutzt die initiale
    Login-Nachricht falsche Sicherheits-Parameter und die Bank antwortet
    9340 'Ungueltige Signatur' (zaehlt typischerweise als PIN-Fehlversuch!).
    """
    if not client.get_current_tan_mechanism():
        client.fetch_tan_mechanisms()
        available = client.get_tan_mechanisms() or {}
        desired = str(config.get("fints_tan_mode") or "").strip()
        if desired and desired in available:
            client.set_tan_mechanism(desired)
        else:
            sys.stderr.write(
                f"[fints-py] desired tan_mode={desired!r} not in {list(available.keys())}, "
                f"using default {client.get_current_tan_mechanism()}\n"
            )

    if client.selected_tan_medium is None and client.is_tan_media_required():
        _usage, media = client.get_tan_media()
        if media:
            client.set_tan_medium(media[0])
            sys.stderr.write(f"[fints-py] selected tan medium: {media[0].tan_medium_name}\n")
        else:
            client.selected_tan_medium = ""


def cmd_transfer(args):
    if args.debug:
        setup_debug_logging()
    config = json.loads(args.config)
    transfer = json.loads(args.transfer)
    session_path = Path(args.session)

    sys.stderr.write(
        f"[fints-py] transfer start: blz={config.get('fints_bank_code')} "
        f"url={config.get('fints_url')} user={config.get('fints_username')} "
        f"sender_iban={transfer.get('sender_iban')}\n"
    )

    client = make_client(config)
    try:
        _bootstrap_tan_settings(client, config)

        with client:
            # Bei Sparkassen mit pushTAN-Pflicht ist hier oft schon eine
            # Login-TAN nötig (Code 3955). python-fints setzt dann
            # `init_tan_response` und wartet darauf, dass wir send_tan() rufen.
            if client.init_tan_response:
                _save_state(session_path, "login_tan", client,
                            client.init_tan_response, transfer)
                out({
                    "status": "need_tan",
                    "stage": "login",
                    **_need_tan_payload(client.init_tan_response),
                })
                return

            _execute_transfer(client, transfer, session_path)
    except SystemExit:
        raise
    except Exception as e:
        fail("fints_error", str(e), trace=traceback.format_exc())


def _execute_transfer(client: FinTS3PinTanClient, transfer: dict,
                      session_path: Path) -> None:
    """Konto suchen + simple_sepa_transfer ausführen + Antwort serialisieren.
    Wird sowohl direkt aus `cmd_transfer` als auch nach erfolgter Login-TAN
    aus `cmd_tan` aufgerufen."""
    accounts = client.get_sepa_accounts()
    sender_iban = transfer["sender_iban"].replace(" ", "")
    account = next((a for a in accounts if a.iban == sender_iban), None)
    if not account:
        fail(
            "account_not_found",
            f"Konto {sender_iban} nicht im Online-Banking gefunden",
            available=[a.iban for a in accounts],
        )

    response = client.simple_sepa_transfer(
        account=account,
        iban=transfer["recipient_iban"].replace(" ", ""),
        bic=transfer.get("recipient_bic") or "",
        recipient_name=transfer["recipient_name"],
        amount=Decimal(str(transfer["amount"])),
        account_name=transfer.get("sender_name") or "",
        reason=transfer["purpose"],
    )

    out(_serialize_response(client, response, session_path, transfer))


def cmd_tan(args):
    if args.debug:
        setup_debug_logging()
    config = json.loads(args.config)
    session_path = Path(args.session)

    try:
        blob = _load_state(session_path)
        kind = blob.get("kind")
        client, need_tan = _restore_client(config, blob)

        with client.resume_dialog(blob["dialog_data"]):
            response = client.send_tan(need_tan, args.tan)

            # Polling/erneute TAN: pushTAN noch nicht in App bestaetigt, oder
            # nach Login-TAN braucht jetzt der eigentliche Auftrag eine TAN.
            if isinstance(response, NeedTANResponse):
                # Bei Login-TAN: Wenn die App noch nicht bestaetigt wurde,
                # schickt python-fints denselben init_tan_response zurueck.
                _save_state(session_path, kind, client, response, blob.get("transfer"))
                out({"status": "need_tan", "stage": kind, **_need_tan_payload(response)})
                return

            # Login-TAN erfolgreich? Dann den vorgemerkten Transfer jetzt ausfuehren.
            if kind == "login_tan" and blob.get("transfer"):
                _execute_transfer(client, blob["transfer"], session_path)
                return

            out(_serialize_response(client, response, session_path, blob.get("transfer")))
    except SystemExit:
        raise
    except Exception as e:
        fail("fints_error", str(e), trace=traceback.format_exc())


def cmd_approve_vop(args):
    if args.debug:
        setup_debug_logging()
    config = json.loads(args.config)
    session_path = Path(args.session)

    try:
        blob = _load_state(session_path)
        client, need_vop = _restore_client(config, blob)
        with client.resume_dialog(blob["dialog_data"]):
            response = client.approve_vop_response(need_vop)
            out(_serialize_response(client, response, session_path, blob.get("transfer")))
    except SystemExit:
        raise
    except Exception as e:
        fail("fints_error", str(e), trace=traceback.format_exc())


def main():
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd", required=True)

    pt = sub.add_parser("transfer")
    pt.add_argument("--session", required=True)
    pt.add_argument("--config", required=True, help="JSON: fints_url, fints_bank_code, fints_username, pin, fints_tan_mode")
    pt.add_argument("--transfer", required=True, help="JSON: sender_iban, sender_name, recipient_iban, recipient_bic, recipient_name, amount, purpose")
    pt.add_argument("--debug", action="store_true", help="Aktiviert python-fints Debug-Logging nach stderr")
    pt.set_defaults(func=cmd_transfer)

    ptan = sub.add_parser("tan")
    ptan.add_argument("--session", required=True)
    ptan.add_argument("--config", required=True)
    ptan.add_argument("--tan", default="", help="leer bei pushTAN/decoupled")
    ptan.add_argument("--debug", action="store_true")
    ptan.set_defaults(func=cmd_tan)

    pvop = sub.add_parser("approve_vop")
    pvop.add_argument("--session", required=True)
    pvop.add_argument("--config", required=True)
    pvop.add_argument("--debug", action="store_true")
    pvop.set_defaults(func=cmd_approve_vop)

    args = p.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
