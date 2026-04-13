import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'
import { oserpStore } from '@/core/stores/oserp.store.js'

/**
 * Composable fuer Telefon-Aktionen (Click-to-Call, WhatsApp, Kopieren).
 * Wird von phone-action-bar.vue und anderen Komponenten genutzt.
 */
export function usePhoneActions() {
    const { t } = useI18n()
    const router = useRouter()
    const store = oserpStore()

    /**
     * Prueft ob WhatsApp Business API in der Config aktiv ist.
     * Beide Felder muessen gesetzt sein, sonst Fallback auf web.whatsapp.com.
     */
    function isWhatsappConfigured() {
        const token = store.getClientDefaultValue('whatsapp_access_token', '')
        const phoneId = store.getClientDefaultValue('whatsapp_phone_number_id', '')
        return !!(token && phoneId)
    }

    /**
     * Formatiert eine Telefonnummer fuer WhatsApp (internationales Format).
     * Entfernt alle Nicht-Ziffern ausser '+', fuegt +49 hinzu falls noetig.
     */
    function formatPhoneForWhatsApp(phone) {
        if (!phone) return ''
        let cleaned = phone.replace(/[^+\d]/g, '')
        if (cleaned.charAt(0) !== '+') {
            cleaned = '+49' + cleaned.slice(1)
        }
        return cleaned
    }

    /**
     * Initiiert einen Click-to-Call Anruf ueber das Backend (Asterisk AMI).
     */
    async function clickToCall(phoneNumber, contactName = '') {
        try {
            const response = await axios.post('/api/customer_vendor/', {
                action: 'clickToCall',
                phone_number: phoneNumber,
                contact_name: contactName,
                employee_id: store.session?.logged_in_employee?.id
            })
            if (response.data?.success) {
                toast.success(t('PhoneActions.callInitiated'))
            } else {
                toast.error(response.data?.text || t('PhoneActions.callError'))
            }
        } catch (e) {
            const msg = e.response?.data?.text || t('PhoneActions.callError')
            toast.error(msg)
        }
    }

    /**
     * Oeffnet WhatsApp fuer die angegebene Telefonnummer.
     * Default: interne WhatsApp-Ansicht (wenn Business API in der Config aktiv).
     * Fallback: web.whatsapp.com (wenn nicht konfiguriert).
     */
    function openWhatsApp(phoneNumber, contactName = '') {
        const formatted = formatPhoneForWhatsApp(phoneNumber)
        if (!formatted) return

        if (isWhatsappConfigured()) {
            // Intern: Route auf die WhatsApp-Ansicht, mit phone als Hint zum Auto-Auswaehlen
            router.push({ name: 'whatsapp', query: { phone: formatted } })
            return
        }

        // Fallback: WhatsApp Web
        const message = contactName
            ? t('PhoneActions.whatsappGreeting', { name: contactName })
            : ''
        const url = 'https://web.whatsapp.com/send?phone=' + formatted
            + (message ? '&text=' + encodeURIComponent(message) : '')
            + '&type=custom_url&app_absent=0'
        window.open(url, '_blank')
    }

    /**
     * Kopiert die Telefonnummer in die Zwischenablage und zeigt eine Snackbar.
     */
    async function copyToClipboard(phoneNumber) {
        try {
            await navigator.clipboard.writeText(phoneNumber)
            toast.success(t('PhoneActions.numberCopied'))
        } catch {
            toast.error(t('PhoneActions.copyError'))
        }
    }

    /**
     * Laedt die Telefon-Konfiguration (verfuegbare Kontexte + Telefone) vom Backend.
     */
    async function loadPhoneConfig() {
        try {
            const response = await axios.post('/api/customer_vendor/', {
                action: 'getPhoneConfig',
                employee_id: store.session?.logged_in_employee?.id
            })
            return response.data?.payload ?? {}
        } catch {
            return {}
        }
    }

    /**
     * Speichert die benutzerspezifische Telefon-Konfiguration.
     */
    async function savePhoneConfig(externalContext, internalPhone) {
        try {
            await axios.post('/api/customer_vendor/', {
                action: 'savePhoneConfig',
                employee_id: store.session?.logged_in_employee?.id,
                user_external_context: externalContext,
                user_internal_phone: internalPhone
            })
        } catch {
            toast.error(t('PhoneActions.configSaveError'))
        }
    }

    return {
        clickToCall,
        openWhatsApp,
        isWhatsappConfigured,
        copyToClipboard,
        formatPhoneForWhatsApp,
        loadPhoneConfig,
        savePhoneConfig
    }
}
