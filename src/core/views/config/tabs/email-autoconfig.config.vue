<!-- src/core/views/config/tabs/email-autoconfig.config.vue -->
<!--
    Servereinstellungen des Postfachs automatisch ermitteln — wie beim Anlegen
    eines Kontos in Thunderbird. Das Backend (emailAutoconfig) fragt die
    Autoconfig-Quellen ab, hier werden die Felder der Firmenkonfiguration
    damit befüllt. Gespeichert wird erst mit dem normalen Speichern-Knopf.
-->
<template>
    <div style="max-width: 60ch">
        <div class="d-flex align-center flex-wrap ga-2">
            <v-btn
                color="primary"
                variant="tonal"
                prepend-icon="mdi-magnify-scan"
                :loading="suche"
                :disabled="!adresse"
                @click="ermitteln"
            >
                {{ t('crm_fields.emailAutoconfig.button') }}
            </v-btn>
            <span class="text-caption text-grey">
                {{ t('crm_fields.emailAutoconfig.hint') }}
            </span>
        </div>

        <v-alert
            v-if="meldung.text"
            :type="meldung.type"
            variant="tonal"
            density="compact"
            class="mt-3"
            closable
            @click:close="meldung.text = ''"
        >
            {{ meldung.text }}
            <div v-if="meldung.details" class="text-caption mt-1">{{ meldung.details }}</div>
        </v-alert>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    // Die crmDefaults der Firmenkonfiguration — die Felder werden direkt befüllt
    crmDefaults: {
        type: Object,
        required: true
    }
});

const suche = ref(false);
const meldung = reactive({ type: 'success', text: '', details: '' });

/** Adresse, nach der gesucht wird: E-Mail-Adresse, ersatzweise ein Benutzername mit @ */
const adresse = computed(() => {
    const mail = (props.crmDefaults.email_address || '').trim();
    if (mail.includes('@')) return mail;
    const user = (props.crmDefaults.email_username || '').trim();
    return user.includes('@') ? user : '';
});

/** Verschlüsselung als Text für die Rückmeldung */
function serverText(server) {
    if (!server) return '—';
    const enc = { ssl: 'SSL/TLS', starttls: 'STARTTLS', none: t('crm_fields.emailAutoconfig.noEncryption') }[server.encryption] || server.encryption;
    return `${server.host}:${server.port} (${enc})`;
}

async function ermitteln() {
    if (!adresse.value) return;
    suche.value = true;
    meldung.text = '';
    meldung.details = '';
    try {
        const resp = await axios.post('/api/email/', { action: 'emailAutoconfig', email: adresse.value });
        if (!resp.data.success) {
            meldung.type = 'warning';
            meldung.text = typeof resp.data.payload === 'string'
                ? resp.data.payload
                : t('crm_fields.emailAutoconfig.notFound');
            return;
        }

        const { imap, smtp, provider, username } = resp.data.payload;
        const d = props.crmDefaults;

        d.email_imap_host = imap.host;
        d.email_imap_port = imap.port;
        d.email_imap_encryption = imap.encryption;
        if (smtp) {
            d.email_smtp_host = smtp.host;
            d.email_smtp_port = smtp.port;
            d.email_smtp_encryption = smtp.encryption;
        }
        if (!(d.email_username || '').trim()) d.email_username = imap.username || username || adresse.value;
        if (!(d.email_address || '').trim()) d.email_address = adresse.value;

        meldung.type = 'success';
        meldung.text = provider
            ? t('crm_fields.emailAutoconfig.foundProvider', { provider })
            : t('crm_fields.emailAutoconfig.found');
        meldung.details = `IMAP ${serverText(imap)} · SMTP ${serverText(smtp)}`
            + (smtp ? '' : ' — ' + t('crm_fields.emailAutoconfig.noSmtp'));
    } catch (err) {
        meldung.type = 'error';
        meldung.text = err.response?.data?.payload || err.response?.data?.text || t('crm_fields.emailAutoconfig.error');
    } finally {
        suche.value = false;
    }
}
</script>
