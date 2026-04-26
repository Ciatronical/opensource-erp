<?php
// backend/api/banking/index.php

require_once __DIR__.'/banking.php';
require_once __DIR__.'/transactions.php';
require_once __DIR__.'/matching.php';
require_once __DIR__.'/transfers.php';
require_once __DIR__.'/fints.php';
require_once __DIR__.'/fints_py.php';
// fints_py.php enthaelt:
//   - python-fints-Variante (fintsSubmitTransferPy / *TanPy / fintsApproveVopTransfer)
//   - Engine-Dispatcher (fintsSubmitTransfer / *Tan), der je Auftrags-engine
//     auf phpFinTS oder python-fints routet.
// Die python-Variante ist als Test-Option pro Auftrag waehlbar (engine=python),
// weil sie bei Login-pushTAN den Auth-Zaehler riskiert (9340) — Default ist
// engine='php' (kein Auth-Risiko, scheitert aber bei VoP-Pflicht der Bank).

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
