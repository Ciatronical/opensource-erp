import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'
import { oserpStore } from '@/core/stores/oserp.store.js'

/**
 * Composable fuer Telefon-Aktionen (Click-to-Call, WhatsApp, Kopieren).
 * Wird von phone-action-bar.vue und anderen Komponenten genutzt.
 */
export function usePhoneActions() {
    const { t } = useI18n()
    const store = oserpStore()

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
     * Oeffnet WhatsApp Web mit der angegebenen Telefonnummer und optionaler Nachricht.
     */
    function openWhatsApp(phoneNumber, contactName = '') {
        const formatted = formatPhoneForWhatsApp(phoneNumber)
        if (!formatted) return
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
        copyToClipboard,
        formatPhoneForWhatsApp,
        loadPhoneConfig,
        savePhoneConfig
    }
}
