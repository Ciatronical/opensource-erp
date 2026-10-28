<?php
// backend/webhook/ebay-oauth.php
//
// Rücksprung von „Mit eBay verbinden“ (dev/shop-ebay.md). Diese Adresse steht
// bei eBay als „Auth accepted URL“ des RuName. eBay schickt den Browser mit
// ?code=…&state=… hierher — im Popup, das die eBay-Ansicht geöffnet hat.
//
// KEIN Datenbankzugriff, kein Session-Check: Die Seite gibt Code und Zustand
// per postMessage an das OSERP-Fenster, das sie öffnete;
// dort tauscht finishEbayConnect sie mit der Sitzung des Benutzers gegen die
// Token. Ohne Fenster (Popup blockiert, Seite direkt geöffnet) landen sie in
// localStorage, und die eBay-Ansicht holt sie beim nächsten Öffnen ab.
//
// Lehnt der Benutzer bei eBay ab, kommt ?error=… statt code.

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'");

$code = (string)($_GET['code'] ?? '');
$zustand = (string)($_GET['state'] ?? '');
$fehler = (string)($_GET['error_description'] ?? ($_GET['error'] ?? ''));
$nachricht = json_encode(['type' => 'oserp-ebay-oauth', 'code' => $code, 'state' => $zustand, 'error' => $fehler],
                         JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>eBay</title>
<style>
    body { font-family: system-ui, Arial, sans-serif; display: flex; align-items: center; justify-content: center;
           min-height: 100vh; margin: 0; background: #f6f8fa; color: #1f2328; }
    div { text-align: center; padding: 24px; }
</style>
</head>
<body>
<div id="text">…</div>
<script>
(function () {
    var nachricht = <?= $nachricht ?>;
    var text = document.getElementById('text');
    var ok = nachricht.code && !nachricht.error;
    // Die Seite steht außerhalb der Oberfläche (kein vue-i18n) — Deutsch, sonst Englisch
    var de = (navigator.language || '').toLowerCase().indexOf('de') === 0;
    var t = de
        ? { fertig: 'eBay-Anmeldung übernommen – das Fenster schließt sich.', abbruch: 'eBay-Anmeldung abgebrochen.',
            zurueck: 'eBay-Anmeldung übernommen. Bitte die eBay-Ansicht in OSERP wieder öffnen.' }
        : { fertig: 'eBay sign-in received – this window will close.', abbruch: 'eBay sign-in cancelled.',
            zurueck: 'eBay sign-in received. Please open the eBay view in OSERP again.' };
    if (window.opener && !window.opener.closed) {
        // Ziel '*': die Oberfläche läuft oft unter einer anderen Adresse als
        // diese öffentliche Seite (Büro-Adresse gegen ebay_public_host). Der Code
        // taugt nur mit dem Client-Secret, das allein das Backend kennt; die
        // Oberfläche prüft Art und Zustand der Nachricht.
        window.opener.postMessage(nachricht, '*');
        text.textContent = ok ? t.fertig : t.abbruch;
        setTimeout(function () { window.close(); }, ok ? 800 : 2500);
    } else {
        try { localStorage.setItem('oserp-ebay-oauth', JSON.stringify(nachricht)); } catch (e) {}
        text.textContent = ok ? t.zurueck : t.abbruch;
        setTimeout(function () { window.location.replace('/'); }, 2500);
    }
})();
</script>
</body>
</html>
