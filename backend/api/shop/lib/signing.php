<?php
// backend/api/shop/lib/signing.php
//
// Signaturschlüssel (dev/shop-php-signatur.md) — seit dem Kundenportal im
// Kern (backend/api/portal/lib/signing.php). Hier bleiben die bisherigen
// Namen für die Shop-Erweiterung und tools/shop-signing-key.php.

require_once __DIR__.'/../../portal/lib/signing.php';

const SHOP_SIGNING_CONTEXT = PORTAL_SIGNING_CONTEXT;

function shopSigningKeyFile(): string { return portalSigningKeyFile(); }
function shopSigningSecretKey(): string { return portalSigningSecretKey(); }
function shopSigningPublicKey(): string { return portalSigningPublicKey(); }
function shopSigningSign(string $pfad, string $sha256): string { return portalSigningSign($pfad, $sha256); }
function shopSigningStatus(): array { return portalSigningStatus(); }
function shopSigningCreate(bool $ersetzen = false): array { return portalSigningCreate($ersetzen); }
function shopSigningUnreadableText(): string { return portalSigningUnreadableText(); }
