<template>
    <span class="phone-action-bar d-inline-flex align-center">
        <span v-if="showNumber" class="phone-number me-1" :title="t('PhoneActions.copyNumber')" @click.stop="onCopy">{{ formatPhone(phone) }}</span>
        <!-- Die Knöpfe bleiben eine Gruppe: fehlt der Platz neben der Nummer, rutschen
             sie gemeinsam in die nächste Zeile statt einzeln abgeschnitten zu werden -->
        <span class="phone-actions d-inline-flex align-center flex-nowrap">
            <v-btn
                icon
                size="small"
                density="comfortable"
                variant="text"
                color="primary"
                :title="t('PhoneActions.clickToCall')"
                @click.stop="onClickToCall"
            >
                <v-icon size="default">mdi-phone-outgoing</v-icon>
            </v-btn>
            <v-btn
                v-if="showWhatsapp"
                icon
                size="small"
                density="comfortable"
                variant="text"
                color="green"
                :title="t('PhoneActions.openWhatsApp')"
                @click.stop="onWhatsApp"
            >
                <v-icon size="default">mdi-whatsapp</v-icon>
            </v-btn>
            <!-- SMS: nur wenn der SMS-Versand eingerichtet ist -->
            <v-btn
                v-if="showSms && smsConfig.configured"
                icon
                size="small"
                density="comfortable"
                variant="text"
                color="indigo"
                :title="t('PhoneActions.sendSms')"
                @click.stop="onSms"
            >
                <v-icon size="default">mdi-message-text-outline</v-icon>
            </v-btn>
            <v-btn
                icon
                size="small"
                density="comfortable"
                variant="text"
                color="grey-darken-1"
                :title="t('PhoneActions.phoneSettings')"
                @click.stop="showConfig = true"
            >
                <v-icon size="default">mdi-cog</v-icon>
            </v-btn>
        </span>
        <PhoneConfigDialog
            v-model="showConfig"
            :phone="phone"
            :name="name"
        />
    </span>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePhoneActions } from '@/core/composables/usePhoneActions.js'
import PhoneConfigDialog from '@/core/components/phone-config-dialog.vue'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import { useSmsConfig } from '@/core/composables/useSmsConfig.js'
import { useRouter } from 'vue-router'

const props = defineProps({
    phone: { type: String, required: true },
    name: { type: String, default: '' },
    showWhatsapp: { type: Boolean, default: true },
    showNumber: { type: Boolean, default: true },
    whatsappTab: { type: Boolean, default: false },
    showSms: { type: Boolean, default: true },
    /** true: Elternansicht öffnet ihren SMS-Reiter (Ereignis sms), sonst Kontakt → SMS */
    smsTab: { type: Boolean, default: false }
})

const emit = defineEmits(['whatsapp', 'sms'])

const { t } = useI18n()
const { clickToCall, openWhatsApp, copyToClipboard, loadPhoneConfig } = usePhoneActions()
const showConfig = ref(false)
const router = useRouter()
const { smsConfig, loadSmsConfig } = useSmsConfig()
loadSmsConfig()

async function onClickToCall() {
    const config = await loadPhoneConfig()
    if (!config.user_external_context || !config.user_internal_phone) {
        showConfig.value = true
        return
    }
    clickToCall(props.phone, props.name)
}

function onWhatsApp() {
    if (props.whatsappTab) {
        emit('whatsapp', props.phone)
    } else {
        openWhatsApp(props.phone, props.name)
    }
}

function onSms() {
    if (props.smsTab) {
        emit('sms', props.phone)
    } else {
        router.push({ name: 'sms', query: { phone: props.phone, name: props.name || undefined } })
    }
}

function onCopy() {
    copyToClipboard(props.phone)
}
</script>

<style scoped>
.phone-action-bar {
    gap: 0;
    /* In schmalen Karten (Kontaktdaten auf Laptops) rutscht die Knopfgruppe unter
       die Nummer, statt rechts abgeschnitten zu werden */
    flex-wrap: wrap;
}
.phone-number {
    white-space: nowrap;
    cursor: pointer;
}
.phone-number:hover {
    text-decoration: underline;
}
</style>
