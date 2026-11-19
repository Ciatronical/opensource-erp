<!-- src/core/views/config/tabs/email-maxsize.config.vue -->
<!--
    Höchstgröße einer E-Mail ermitteln: Das Backend (detectEmailMaxSize) fragt
    den SMTP-Server (SIZE im EHLO) und die PHP-Grenze post_max_size ab und
    liefert den nutzbaren Wert, der hier ins Feld „Maximale E-Mail-Größe"
    eingetragen wird. Gespeichert wird erst mit dem normalen Speichern-Knopf.

    Ist PHP die engere Grenze, liefert das Backend dazu den Befehl, der
    post_max_size und upload_max_filesize in der zuständigen Datei anhebt
    (php_fix, phpLimitFix in backend/api/config.php). Er steht zum Kopieren
    unter der Meldung; ausgeführt wird er vom Administrator auf dem Server.
-->
<template>
    <div style="max-width: 60ch">
        <div class="d-flex align-center flex-wrap ga-2">
            <v-btn
                color="primary"
                variant="tonal"
                prepend-icon="mdi-scale-balance"
                :loading="laeuft"
                :disabled="!smtpKonfiguriert"
                @click="ermitteln"
            >
                {{ t('crm_fields.emailMaxSize.button') }}
            </v-btn>
            <span class="text-caption text-grey">
                {{ t('crm_fields.emailMaxSize.hint') }}
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
            <div v-if="meldung.fix" class="mt-2">
                <div class="text-caption">
                    {{ t('crm_fields.emailMaxSize.phpFixCommand', { value: meldung.fix.value, file: meldung.fix.file }) }}
                </div>
                <div class="d-flex align-start ga-1 mt-1">
                    <code class="befehl text-caption">{{ meldung.fix.command }}</code>
                    <v-btn
                        icon="mdi-content-copy"
                        size="x-small"
                        variant="text"
                        :aria-label="t('crm_fields.emailMaxSize.phpFixCopy')"
                        :title="t('crm_fields.emailMaxSize.phpFixCopy')"
                        @click="kopieren"
                    />
                </div>
                <div class="text-caption mt-1">{{ t('crm_fields.emailMaxSize.phpFixWebserver') }}</div>
            </div>
        </v-alert>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    // Die crmDefaults der Firmenkonfiguration — das Größenfeld wird direkt befüllt
    crmDefaults: {
        type: Object,
        required: true
    }
});

const laeuft = ref(false);
const meldung = reactive({ type: 'success', text: '', details: '', fix: null });

const smtpKonfiguriert = computed(() => !!(props.crmDefaults.email_smtp_host || '').trim());

function mb(bytes) {
    return `${(bytes / (1024 * 1024)).toFixed(bytes % (1024 * 1024) ? 1 : 0)} MB`;
}

function kopieren() {
    navigator.clipboard?.writeText(meldung.fix.command);
}

async function ermitteln() {
    laeuft.value = true;
    meldung.text = '';
    meldung.details = '';
    meldung.fix = null;
    try {
        const d = props.crmDefaults;
        const resp = await axios.post('/api/email/', {
            action: 'detectEmailMaxSize',
            email_smtp_host: d.email_smtp_host,
            email_smtp_port: d.email_smtp_port,
            email_smtp_encryption: d.email_smtp_encryption,
            email_username: d.email_username,
            email_password: d.email_password
        });
        if (!resp.data.success) {
            meldung.type = 'error';
            meldung.text = resp.data.payload || resp.data.text || t('crm_fields.emailMaxSize.error');
            return;
        }

        const { smtp_max_bytes, php_max_bytes, recommended_mb, limited_by, php_fix } = resp.data.payload;
        d.email_max_message_size_mb = recommended_mb;

        meldung.type = 'success';
        meldung.text = t('crm_fields.emailMaxSize.result', { mb: recommended_mb });
        const teile = [];
        teile.push(smtp_max_bytes
            ? t('crm_fields.emailMaxSize.smtpLimit', { size: mb(smtp_max_bytes) })
            : t('crm_fields.emailMaxSize.noSmtpLimit'));
        if (php_max_bytes) teile.push(t('crm_fields.emailMaxSize.phpLimit', { size: mb(php_max_bytes) }));
        meldung.details = teile.join(' · ');
        if (limited_by === 'php' && php_max_bytes) {
            meldung.type = 'warning';
            meldung.details += ' — ' + t('crm_fields.emailMaxSize.phpIsBottleneck');
            meldung.fix = php_fix || null;
        }
    } catch (err) {
        meldung.type = 'error';
        meldung.text = err.response?.data?.payload || err.response?.data?.text || t('crm_fields.emailMaxSize.error');
    } finally {
        laeuft.value = false;
    }
}
</script>

<style scoped>
/* Der Befehl ist lang: umbrechen statt die Meldung zu sprengen, als Ganzes markierbar */
.befehl {
    white-space: pre-wrap;
    word-break: break-all;
    user-select: all;
}
</style>
