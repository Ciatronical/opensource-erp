import { useRouter } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'

/**
 * Composable für E-Mail- und Web-Adressen aus dem Kundenstamm.
 *
 * Wie eine E-Mail-Adresse geöffnet wird, bestimmt die Firmenkonfiguration
 * (CRM → E-Mail-Client → "E-Mail-Links öffnen mit"):
 *   - 'mailto'   → externes E-Mail-Programm über einen mailto:-Link (Standard)
 *   - 'internal' → interner E-Mail-Client (Postfach-Ansicht, Compose vorbelegt)
 */
export function useEmailActions() {
    const router = useRouter()
    const store = oserpStore()

    function isInternalEmailClient() {
        return store.getClientDefaultValue('email_link_mode', 'mailto') === 'internal'
    }

    /**
     * Öffnet eine E-Mail-Adresse — intern oder per mailto:, je nach Konfiguration.
     * Liefert true, wenn intern navigiert wurde (Aufrufer kann dann z.B. im
     * Kunden bleiben und stattdessen den E-Mail-Tab öffnen).
     */
    function openEmail(address) {
        const to = (address || '').trim()
        if (!to) return false
        if (isInternalEmailClient()) {
            router.push({ name: 'emails', query: { to } })
            return true
        }
        window.location.href = 'mailto:' + to
        return false
    }

    /** Ergänzt fehlendes Schema (https://), damit der Link nicht relativ aufgelöst wird. */
    function normalizeUrl(url) {
        const raw = (url || '').trim()
        if (!raw) return ''
        return /^[a-z][a-z0-9+.-]*:\/\//i.test(raw) ? raw : 'https://' + raw
    }

    /** Öffnet eine Web-Adresse in einem neuen Tab. */
    function openUrl(url) {
        const target = normalizeUrl(url)
        if (!target) return
        window.open(target, '_blank', 'noopener')
    }

    return { isInternalEmailClient, openEmail, normalizeUrl, openUrl }
}
