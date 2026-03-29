<template>
    <span class="phone-action-bar d-inline-flex align-center">
        <span v-if="showNumber" class="phone-number me-1" :title="t('PhoneActions.copyNumber')" @click.stop="onCopy">{{ formatPhone(phone) }}</span>
        <v-btn
            icon
            size="small"
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
            variant="text"
            color="green"
            :title="t('PhoneActions.openWhatsApp')"
            @click.stop="onWhatsApp"
        >
            <v-icon size="default">mdi-whatsapp</v-icon>
        </v-btn>
        <v-btn
            icon
            size="small"
            variant="text"
            color="grey-darken-1"
            :title="t('PhoneActions.phoneSettings')"
            @click.stop="showConfig = true"
        >
            <v-icon size="default">mdi-cog</v-icon>
        </v-btn>
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

const props = defineProps({
    phone: { type: String, required: true },
    name: { type: String, default: '' },
    showWhatsapp: { type: Boolean, default: true },
    showNumber: { type: Boolean, default: true },
    whatsappTab: { type: Boolean, default: false }
})

const emit = defineEmits(['whatsapp'])

const { t } = useI18n()
const { clickToCall, openWhatsApp, copyToClipboard, loadPhoneConfig } = usePhoneActions()
const showConfig = ref(false)

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


function onCopy() {
    copyToClipboard(props.phone)
}
</script>

<style scoped>
.phone-action-bar {
    gap: 0;
}
.phone-number {
    white-space: nowrap;
    cursor: pointer;
}
.phone-number:hover {
    text-decoration: underline;
}
</style>
