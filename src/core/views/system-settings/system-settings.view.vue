<!-- src/core/views/system-settings/system-settings.view.vue -->
<!--
    Systemeinstellungen: die settings.ini dieser Installation bearbeiten.
    Nur für Systemadministratoren — die Route verlangt requiresAdmin, das
    Backend prüft requireSystemAdmin().

    Das Formular entsteht aus dem Schema, das das Backend mitliefert
    (systemSettingsSchema): Abschnitte, Schlüssel, Arten. Eine eigene Liste
    führt die Ansicht nicht. Beschriftet ist jedes Feld mit seinem Schlüssel
    aus der Datei; die Beschreibung erscheint am i-Symbol.

    Gespeichert wird nicht automatisch, sondern nach einer Rückfrage mit allen
    Änderungen: ein falscher Datenbankzugang sperrt alle aus.
-->
<template>
    <div>
        <navbar-view :title="t('SystemSettingsView.title')" :show-back-button="true" />

        <v-container fluid class="system-settings-container">
            <div class="d-flex flex-wrap align-center ga-3 mb-4">
                <div>
                    <h1 class="text-h5 font-weight-bold mb-0">{{ t('SystemSettingsView.title') }}</h1>
                    <div class="text-body-2 text-medium-emphasis">{{ t('SystemSettingsView.subtitle') }}</div>
                </div>
                <v-spacer />
                <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="laden">
                    {{ t('SystemSettingsView.reload') }}
                </v-btn>
                <v-btn
                    color="primary"
                    prepend-icon="mdi-content-save"
                    :disabled="!aenderungen.length || !stand?.writable"
                    @click="rueckfrage = true"
                >
                    {{ t('SystemSettingsView.save') }}
                </v-btn>
            </div>

            <v-alert v-if="fehler" type="error" variant="tonal" class="mb-4">{{ fehler }}</v-alert>

            <v-skeleton-loader v-if="loading && !stand" type="card, card" />

            <template v-else-if="stand">
                <v-alert type="info" variant="tonal" density="compact" class="mb-2">
                    <div class="text-body-2">
                        <strong>{{ t('SystemSettingsView.file') }}:</strong> <code>{{ stand.file }}</code>
                    </div>
                    <div class="text-body-2 mt-1">{{ t('SystemSettingsView.notice') }}</div>
                </v-alert>
                <v-alert v-if="!stand.writable" type="warning" variant="tonal" density="compact" class="mb-4">
                    {{ t('SystemSettingsView.notWritable') }}
                </v-alert>

                <v-card v-for="abschnitt in stand.sections" :key="abschnitt.name" variant="outlined" class="my-4">
                    <v-card-item>
                        <v-card-title class="text-subtitle-1">
                            <code>[{{ abschnitt.name }}]</code>
                            <span class="ms-2">{{ t(`SystemSettingsView.sections.${abschnitt.name}`) }}</span>
                        </v-card-title>
                    </v-card-item>
                    <v-card-text>
                        <v-row>
                            <v-col v-for="feld in abschnitt.fields" :key="feld.key" cols="12" md="6">
                                <!-- Ja/Nein -->
                                <v-switch
                                    v-if="feld.type === 'bool'"
                                    v-model="werte[abschnitt.name][feld.key]"
                                    :label="feld.key"
                                    hide-details="auto"
                                    color="primary"
                                    density="compact"
                                    inset
                                >
                                    <template #append>
                                        <FeldHilfe :text="beschreibung(abschnitt.name, feld)" />
                                    </template>
                                </v-switch>

                                <!-- Feste Auswahl -->
                                <v-select
                                    v-else-if="feld.type === 'select'"
                                    v-model="werte[abschnitt.name][feld.key]"
                                    :items="feld.options"
                                    :label="feld.key"
                                    :placeholder="platzhalter(feld)"
                                    :persistent-placeholder="!!platzhalter(feld)"
                                    hide-details="auto"
                                    clearable
                                    density="compact"
                                    variant="outlined"
                                >
                                    <template #append-inner>
                                        <FeldHilfe :text="beschreibung(abschnitt.name, feld)" />
                                    </template>
                                </v-select>

                                <!-- Zeitzone -->
                                <v-autocomplete
                                    v-else-if="feld.type === 'timezone'"
                                    v-model="werte[abschnitt.name][feld.key]"
                                    :items="stand.timezones"
                                    :label="feld.key"
                                    :placeholder="platzhalter(feld)"
                                    :persistent-placeholder="!!platzhalter(feld)"
                                    hide-details="auto"
                                    clearable
                                    density="compact"
                                    variant="outlined"
                                >
                                    <template #append-inner>
                                        <FeldHilfe :text="beschreibung(abschnitt.name, feld)" />
                                    </template>
                                </v-autocomplete>

                                <!-- Text, Zahl, Pfad, Passwort -->
                                <v-text-field
                                    v-else
                                    v-model="werte[abschnitt.name][feld.key]"
                                    :label="feld.key"
                                    :type="feld.type === 'secret' ? 'password' : 'text'"
                                    :autocomplete="feld.type === 'secret' ? 'new-password' : 'off'"
                                    :placeholder="platzhalter(feld)"
                                    :persistent-placeholder="!!platzhalter(feld)"
                                    hide-details="auto"
                                    :rules="regeln(feld)"
                                    :clearable="feld.type !== 'secret'"
                                    density="compact"
                                    variant="outlined"
                                >
                                    <template #append-inner>
                                        <FeldHilfe :text="beschreibung(abschnitt.name, feld)" />
                                    </template>
                                </v-text-field>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <!-- Was die Datei sonst enthält: nur die Namen, die Werte könnten Geheimnisse sein -->
                <v-card v-if="stand.extra.length" variant="outlined" class="mb-4">
                    <v-card-item>
                        <v-card-title class="text-subtitle-1">{{ t('SystemSettingsView.extraTitle') }}</v-card-title>
                        <v-card-subtitle>{{ t('SystemSettingsView.extraHint') }}</v-card-subtitle>
                    </v-card-item>
                    <v-card-text>
                        <v-chip
                            v-for="eintrag in stand.extra"
                            :key="eintrag.section + '.' + eintrag.key"
                            size="small"
                            variant="tonal"
                            class="me-2 mb-2"
                        >
                            <code>{{ eintrag.section }}.{{ eintrag.key }}</code>
                        </v-chip>
                    </v-card-text>
                </v-card>
            </template>
        </v-container>

        <!-- Rückfrage vor dem Speichern -->
        <v-dialog v-model="rueckfrage" max-width="680">
            <v-card>
                <v-card-title>{{ t('SystemSettingsView.confirmTitle') }}</v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-2">{{ t('SystemSettingsView.confirmIntro') }}</div>
                    <v-table density="compact">
                        <tbody>
                            <tr v-for="aenderung in aenderungen" :key="aenderung.name">
                                <td class="text-no-wrap"><code>{{ aenderung.name }}</code></td>
                                <td>{{ anzeige(aenderung) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-alert v-if="warnungDatenbank" type="warning" variant="tonal" density="compact" class="mt-3">
                        {{ t('SystemSettingsView.warnDatabase') }}
                    </v-alert>
                    <v-alert v-if="warnungCookie" type="warning" variant="tonal" density="compact" class="mt-3">
                        {{ t('SystemSettingsView.warnCookie') }}
                    </v-alert>
                    <v-alert v-if="warnungDemo" type="error" variant="tonal" density="compact" class="mt-3">
                        {{ t('SystemSettingsView.warnDemo') }}
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="rueckfrage = false">{{ t('SystemSettingsView.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="speichert" @click="speichern">
                        {{ t('SystemSettingsView.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, h } from 'vue'
import { useI18n } from 'vue-i18n'
import { VIcon, VTooltip } from 'vuetify/components'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as toasts from '@/core/utils/toasts.js'

const { t, te } = useI18n()
const store = oserpStore()

/** Antwort von getSystemSettings: Datei, Abschnitte, weitere Einträge, Zeitzonen */
const stand = ref(null)
const loading = ref(false)
const fehler = ref('')
const rueckfrage = ref(false)
const speichert = ref(false)

/** Formularwerte und Stand beim Laden: Abschnitt -> Schlüssel -> Wert */
const werte = reactive({})
const ausgang = reactive({})

/** Fehlercodes, bei denen die Einzelheiten des Backends mit angezeigt werden */
const MIT_EINZELHEITEN = ['INVALID_VALUE', 'DB_CONNECTION_FAILED', 'SETTINGS_NOT_WRITABLE']

const istWahr = (wert) => wert === true || wert === 1 || wert === '1' || wert === 'true'

/**
 * Startwert eines Feldes
 *
 * Passwort immer leer (es kommt nie mit), Schalter aus dem Wert oder der
 * geltenden Vorgabe, alles andere als Text — leer, wenn der Eintrag fehlt.
 */
function startwert(feld) {
    if (feld.type === 'secret') return ''
    if (feld.type === 'bool') return istWahr(feld.present ? feld.value : feld.effective)
    return feld.present && feld.value !== null ? String(feld.value) : ''
}

function uebernehmen() {
    for (const schluessel of Object.keys(werte)) delete werte[schluessel]
    for (const schluessel of Object.keys(ausgang)) delete ausgang[schluessel]
    for (const abschnitt of stand.value.sections) {
        werte[abschnitt.name] = {}
        ausgang[abschnitt.name] = {}
        for (const feld of abschnitt.fields) {
            werte[abschnitt.name][feld.key] = startwert(feld)
            ausgang[abschnitt.name][feld.key] = startwert(feld)
        }
    }
}

/** Alle geänderten Felder; ein Passwort gilt als geändert, sobald etwas drinsteht */
const aenderungen = computed(() => {
    if (!stand.value) return []
    const liste = []
    for (const abschnitt of stand.value.sections) {
        for (const feld of abschnitt.fields) {
            const neu = werte[abschnitt.name]?.[feld.key]
            const alt = ausgang[abschnitt.name]?.[feld.key]
            const geaendert = feld.type === 'secret'
                ? String(neu ?? '') !== ''
                : String(neu ?? '') !== String(alt ?? '')
            if (geaendert) {
                liste.push({ abschnitt: abschnitt.name, schluessel: feld.key, name: `${abschnitt.name}.${feld.key}`, typ: feld.type, neu })
            }
        }
    }
    return liste
})

const warnungDatenbank = computed(() => aenderungen.value.some((a) => a.abschnitt === 'database'))
const warnungCookie = computed(() => aenderungen.value.some((a) => a.name === 'session.cookie_name'))
const warnungDemo = computed(() => aenderungen.value.some((a) => a.name === 'demo.enabled' && a.neu === true))

function beschreibung(abschnitt, feld) {
    return t(`SystemSettingsView.fields.${abschnitt}.${feld.key}`)
}

/**
 * Platzhalter im leeren Feld
 *
 * Beim Passwort, ob eines hinterlegt ist. Sonst die Vorgabe aus config.php —
 * aber nur, wenn der Eintrag in der Datei fehlt: steht er dort, zeigte der
 * Platzhalter nach dem Leeren den alten Wert statt der Vorgabe.
 */
function platzhalter(feld) {
    if (feld.type === 'secret') {
        return t(feld.set ? 'SystemSettingsView.secretKeep' : 'SystemSettingsView.secretEmpty')
    }
    if (feld.present || feld.effective === null || feld.effective === undefined || feld.effective === '') {
        return undefined
    }
    return t('SystemSettingsView.defaultValue', { value: String(feld.effective) })
}

/**
 * Prüfregeln — nur Anzeige, geprüft wird verbindlich im Backend
 *
 * Zeilenumbrüche, Anführungszeichen, Backslash und ${ sind verboten: sie
 * brächen die Datei auf oder würden beim Lesen ersetzt.
 */
function regeln(feld) {
    if (feld.type === 'secret') return []
    const liste = []
    if (feld.required) {
        liste.push((wert) => String(wert ?? '').trim() !== '' || t('SystemSettingsView.required'))
    }
    liste.push((wert) => !wert
        || !(/[\u0000-\u001f\u007f"\\]/.test(String(wert)) || String(wert).includes('${'))
        || t('SystemSettingsView.invalidChars'))
    if (feld.type === 'int') {
        liste.push((wert) => !wert || /^\d+$/.test(String(wert).trim()) || t('SystemSettingsView.invalidInteger'))
    }
    if (feld.type === 'path') {
        liste.push((wert) => !wert || String(wert).trim().startsWith('/') || t('SystemSettingsView.invalidPath'))
    }
    return liste
}

function anzeige(aenderung) {
    if (aenderung.typ === 'secret') return '••••••••'
    if (aenderung.typ === 'bool') return aenderung.neu ? 'true' : 'false'
    return String(aenderung.neu ?? '').trim() === '' ? t('SystemSettingsView.removed') : String(aenderung.neu)
}

function fehlertext(error) {
    const code = error?.code
    const schluessel = `SystemSettingsView.errors.${code}`
    const text = code && te(schluessel) ? t(schluessel) : ''
    const einzelheiten = (!text || MIT_EINZELHEITEN.includes(code)) && error?.message && error.message !== code ? error.message : ''
    return [text, einzelheiten].filter(Boolean).join(' — ') || String(error)
}

async function laden() {
    loading.value = true
    fehler.value = ''
    try {
        stand.value = await store.adminSystemSettings()
        uebernehmen()
    } catch (error) {
        fehler.value = fehlertext(error)
    } finally {
        loading.value = false
    }
}

async function speichern() {
    speichert.value = true
    fehler.value = ''
    const values = {}
    for (const aenderung of aenderungen.value) {
        values[aenderung.abschnitt] ??= {}
        values[aenderung.abschnitt][aenderung.schluessel] = aenderung.neu
    }
    try {
        const ergebnis = await store.adminSaveSystemSettings(values)
        toasts.success(ergebnis?.reload_fpm ? t('SystemSettingsView.savedReload') : t('SystemSettingsView.saved'))
        rueckfrage.value = false
        await laden()
    } catch (error) {
        fehler.value = fehlertext(error)
        rueckfrage.value = false
    } finally {
        speichert.value = false
    }
}

/**
 * Das i-Symbol mit der Kurzbeschreibung — wie in den Shop-Einstellungen
 * der Firmenkonfiguration (shop-config-field.component.vue)
 */
const FeldHilfe = (props) => h(VTooltip, { location: 'top', maxWidth: 360 }, {
    activator: ({ props: aktivator }) =>
        h(VIcon, { ...aktivator, size: 'small', color: 'grey' }, () => 'mdi-information-outline'),
    default: () => props.text,
})
FeldHilfe.props = { text: String }

onMounted(laden)
</script>

<style scoped>
.system-settings-container {
    max-width: 1200px;
}
</style>
