<!-- src/core/views/config/tabs/shop-config-field.component.vue -->
<!--
    Ein Einstellungsfeld der Shop-Erweiterung.

    Herausgelöst aus shop-defaults.tab.vue, damit dieselbe Darstellung sowohl
    auf oberster Ebene als auch innerhalb einer Gruppe gilt — die PayPal-Felder
    stehen in zwei Gruppen, je eine für Test- und Echtbetrieb.
-->
<template>
    <!-- Checkbox -->
    <v-row v-if="field.type === 'checkbox'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-checkbox
                v-model="werte[field.name]"
                :label="t(field.label)"
                hide-details="auto"
                density="compact"
            >
                <template v-if="field.tooltip" #append>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-checkbox>
        </v-col>
    </v-row>

    <!-- Eingabefeld / Passwort -->
    <v-row v-else-if="field.type === 'input' || field.type === 'password'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <component
                :is="feldKomponente(field)"
                v-model="werte[field.name]"
                v-bind="zusatz(field)"
                :label="t(field.label)"
                :type="field.inputType || 'text'"
                :placeholder="field.type === 'password' ? t(hinterlegt ? 'crm_fields.shopSecretKeep' : 'crm_fields.shopSecretEmpty') : (vorgabe || undefined)"
                :persistent-placeholder="field.type === 'password' || !!vorgabe"
                :hint="sichtbar ? t('crm_fields.shopKeyGeneratedHint') : (vorgabe ? t('crm_fields.shopFallbackFromIni') : undefined)"
                :rules="regeln(field)"
                :persistent-hint="sichtbar || !!vorgabe"
                :style="field.fieldstyle"
                hide-details="auto"
                density="compact"
                variant="outlined"
                autocomplete="new-password"
            >
                <template v-if="field.tooltip" #append-inner>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
                <!-- Neuen Schlüssel erzeugen: nur beim Shop-Schlüssel, der frei
                     wählbar ist. Die PayPal-Geheimnisse vergibt PayPal. -->
                <template v-if="field.generate" #append>
                    <v-btn
                        icon="mdi-key-variant"
                        variant="text"
                        size="small"
                        :title="t('crm_fields.shopKeyGenerate')"
                        @click="schluesselErzeugen"
                    />
                </template>
            </component>
        </v-col>
    </v-row>

    <!-- Auswahl mit festen Werten -->
    <v-row v-else-if="field.type === 'select'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-select
                v-model="werte[field.name]"
                :items="field.items"
                :label="t(field.label)"
                :style="field.fieldstyle"
                hide-details="auto"
                density="compact"
                variant="outlined"
            >
                <template v-if="field.tooltip" #append-inner>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-select>
        </v-col>
    </v-row>

    <!-- Auswahl aus company_config -->
    <v-row v-else-if="field.type === 'dynamic-select'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-select
                v-model="werte[field.name]"
                :items="auswahl(field.source)"
                :item-title="field.itemTitle || 'title'"
                :item-value="field.itemValue || 'value'"
                :label="t(field.label)"
                :style="field.fieldstyle"
                hide-details="auto"
                density="compact"
                variant="outlined"
                clearable
            >
                <template v-if="field.tooltip" #append-inner>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-select>
        </v-col>
    </v-row>
</template>

<script setup>
import { computed, h, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { VIcon, VTextField, VTooltip } from 'vuetify/components'
import PasswordField from '@/core/components/password-field.vue'
import PathField from '@/core/components/path-field.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n()
const store = oserpStore()

const props = defineProps({
    /** Felddefinition aus shopDefaultsConfig.js */
    field: { type: Object, required: true },
    /** Die Einstellungen des Mandanten (defaults_oserp) */
    werte: { type: Object, required: true },
    /**
     * Zusätzliche Auswahllisten des Tabs, nach Quelle.
     *
     * Für Listen, die nicht in der Firmenkonfiguration stehen — die
     * Vorlagensätze etwa liegen im Dateisystem und holt der Tab beim Shop.
     */
    quellen: { type: Object, default: () => ({}) },
    /**
     * Geheimnisse: Schlüssel -> hinterlegt ja/nein.
     *
     * Ihre Werte liefert das Backend nie aus. Ohne diese Angabe stünde an jedem
     * Passwortfeld "hinterlegt", auch wenn nichts gespeichert ist.
     */
    gesetzt: { type: Object, default: () => ({}) },
    /**
     * Vorgaben aus der settings.ini für leere Felder: Schlüssel -> Wert.
     *
     * Erscheinen als Platzhalter, nicht als Wert: der Tab speichert bei jeder
     * Änderung alle Felder, und ein eingetragener Rückfall wäre danach keiner
     * mehr.
     */
    vorgaben: { type: Object, default: () => ({}) },
})

/** Die Vorgabe aus der settings.ini — nur solange das Feld leer ist */
const vorgabe = computed(() => {
    const wert = props.werte[props.field.name]
    const leer = wert === undefined || wert === null || String(wert).trim() === ''
    return leer ? (props.vorgaben[props.field.name] || '') : ''
})

/** Ist zu diesem Feld ein Wert gespeichert? */
const hinterlegt = computed(() => true === props.gesetzt[props.field.name])

/**
 * Zeigt den Wert eines Passwortfeldes im Klartext
 *
 * Nur nach dem Erzeugen: Der gespeicherte Wert wird ohnehin nie ausgeliefert,
 * und den frisch erzeugten Schlüssel muss man ablesen können — er gehört auch
 * in den Reverse-Proxy, wenn einer die Stelle des mitgelieferten übernimmt.
 */
const sichtbar = ref(false)

/**
 * Welche Komponente ein Feld bekommt
 *
 * Geheimnisse mit Auge, relative Verzeichnisse mit Ordner-Symbol, alles
 * Übrige als Textfeld. Die absoluten Pfade (Webseiten-Wurzel, Hugo-Programm)
 * bleiben ohne Auswahl: Sie zeigen auf das Dateisystem des Servers, und das
 * durchblättern nur Systemadministratoren in den Systemeinstellungen.
 *
 * @param {object} field Felddefinition aus shopDefaultsConfig.js
 * @returns {object} Vue-Komponente
 */
function feldKomponente(field) {
    if ('password' === field.type) return PasswordField
    if (field.browse) return PathField
    return VTextField
}

/**
 * Zusätzliche Eigenschaften je nach Feldart
 *
 * @param {object} field Felddefinition
 * @returns {object}
 */
function zusatz(field) {
    if ('password' === field.type) {
        return { visible: sichtbar.value, 'onUpdate:visible': (wert) => (sichtbar.value = wert) }
    }
    if (field.browse) {
        return { scope: 'shop', base: field.browse }
    }
    return {}
}

/**
 * Erzeugt einen neuen Shop-Schlüssel
 *
 * 32 Byte aus dem Zufallsgenerator des Browsers, hexadezimal: 64 Zeichen aus
 * 0-9a-f, die in nginx-Konfigurationen und auf der Kommandozeile keinen Ärger
 * machen. Gespeichert wird er wie jede andere Änderung an diesem Tab; der
 * Läufer trägt ihn beim nächsten Lauf in oserp-shop/config.php ein.
 */
function schluesselErzeugen() {
    const bytes = crypto.getRandomValues(new Uint8Array(32))
    props.werte[props.field.name] = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
    sichtbar.value = true
}

/**
 * Prüfregeln aus der Felddefinition
 *
 * absolutePath: nur ein Pfad — beginnt mit /, ohne Leerraum. Ob es das
 * Verzeichnis gibt und ein ausführbares Programm darin liegt, kann nur der
 * Server prüfen; das tut er vor jedem Bau und meldet es in der Shop-Übersicht.
 *
 * relativePath: ohne führenden Schrägstrich — der Pfad gilt unterhalb der
 * Webseite. Dass er dort nicht herausführt, prüft ebenfalls der Server.
 */
function regeln(field) {
    if ('absolutePath' === field.validate) {
        return [(wert) => !wert || /^\/\S*$/.test(wert) || t('crm_fields.shopPathInvalid')]
    }
    // Relative Verzeichnisse gelten unterhalb der Webseite; ein führender
    // Schrägstrich wäre ein absoluter Pfad und wird abgewiesen.
    if ('relativePath' === field.validate) {
        return [(wert) => !wert || !String(wert).startsWith('/') || t('crm_fields.shopPathNotRelative')]
    }
    return []
}

/** Auswahlwerte: erst die des Tabs, sonst die der Firmenkonfiguration */
function auswahl(source) {
    return props.quellen?.[source] || store.session?.[source] || store.session?.company_config?.[source] || []
}

/**
 * Das Fragezeichen mit dem Hilfetext
 *
 * Als kleine Funktionskomponente statt viermal derselbe Block — das Muster
 * wiederholt sich für jeden Feldtyp.
 */
const FeldHilfe = (props) => h(VTooltip, { location: 'top' }, {
    activator: ({ props: aktivator }) =>
        h(VIcon, { ...aktivator, size: 'small', color: 'grey' }, () => 'mdi-information-outline'),
    default: () => props.text,
})
FeldHilfe.props = { text: String }
</script>
