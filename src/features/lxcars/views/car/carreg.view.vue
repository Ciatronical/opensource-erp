<!-- src/features/lxcars/views/car/carreg.view.vue -->

<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Titel -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-icon color="primary" class="mr-1">mdi-card-account-details</v-icon>
            <h1 class="text-h6 mb-0">{{ t('CarRegView.title') }}</h1>
            <v-chip v-if="form.kennzeichen" size="small" variant="tonal" color="primary" class="font-weight-bold">
                {{ form.kennzeichen }}
            </v-chip>
            <v-spacer />
            <v-btn variant="text" size="small" @click="router.back()">
                <v-icon start size="small">mdi-arrow-left</v-icon>
                {{ t('CarRegView.back') }}
            </v-btn>
        </div>

        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('CarRegView.loading') }}
        </v-alert>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
            {{ error }}
        </v-alert>

        <v-form ref="formRef" v-model="formValid" @submit.prevent="onGeneratePdf">

            <!-- 1. Auftragsart -->
            <v-card variant="outlined" class="mb-3">
                <v-card-title class="text-subtitle-1">{{ t('CarRegView.sections.auftragsart') }}</v-card-title>
                <v-card-text>
                    <v-radio-group v-model="form.auftragsart" inline hide-details>
                        <v-radio :label="t('CarRegView.auftragsarten.zulassung')" value="zulassung" />
                        <v-radio :label="t('CarRegView.auftragsarten.umschreibung')" value="umschreibung" />
                        <v-radio :label="t('CarRegView.auftragsarten.abmeldung')" value="abmeldung" />
                        <v-radio :label="t('CarRegView.auftragsarten.aenderung')" value="aenderung" />
                        <v-radio :label="t('CarRegView.auftragsarten.ersatz')" value="ersatz" />
                    </v-radio-group>
                </v-card-text>
            </v-card>

            <!-- 2. Fahrzeugdaten -->
            <v-card variant="outlined" class="mb-3">
                <v-card-title class="text-subtitle-1">{{ t('CarRegView.sections.fahrzeug') }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.kennzeichen" :label="t('CarRegView.fields.kennzeichen')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.fin" :label="t('CarRegView.fields.fin')"
                                variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <!-- 3. Halter-Daten -->
            <v-card variant="outlined" class="mb-3">
                <v-card-title class="text-subtitle-1">{{ t('CarRegView.sections.halter') }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.vorname" :label="t('CarRegView.fields.vorname')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.name" :label="t('CarRegView.fields.name')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="displayGebdatum" :label="t('CarRegView.fields.gebdatum')"
                                :rules="[rules.required, rules.date]" variant="outlined" density="compact"
                                placeholder="TT.MM.JJJJ" @blur="onBlurGebdatum" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.gebort" :label="t('CarRegView.fields.gebort')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.gebname" :label="t('CarRegView.fields.gebname')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.strasse" :label="t('CarRegView.fields.strasse')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.hsnr" :label="t('CarRegView.fields.hsnr')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.plz" :label="t('CarRegView.fields.plz')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.ort" :label="t('CarRegView.fields.ort')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select v-model="form.geschlecht" :label="t('CarRegView.fields.geschlecht')"
                                :items="geschlechtItems" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.evb_nummer" :label="t('CarRegView.fields.evb_nummer')"
                                :rules="[rules.required]" variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <!-- 4. Gewerbeanschrift (nur bei Firma) -->
            <v-card v-if="form.geschlecht === 'firma'" variant="outlined" class="mb-3">
                <v-card-title class="text-subtitle-1">{{ t('CarRegView.sections.gewerbe') }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.beruf" :label="t('CarRegView.fields.beruf')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.firma" :label="t('CarRegView.fields.firma')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.gewerbe_strasse" :label="t('CarRegView.fields.strasse')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.gewerbe_hsnr" :label="t('CarRegView.fields.hsnr')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.gewerbe_plz" :label="t('CarRegView.fields.plz')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.gewerbe_ort" :label="t('CarRegView.fields.ort')"
                                variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <!-- 5. Versicherung an Eides Statt (nur bei Ersatz) -->
            <v-card v-if="form.auftragsart === 'ersatz'" variant="outlined" class="mb-3">
                <v-card-title class="text-subtitle-1">{{ t('CarRegView.sections.eid') }}</v-card-title>
                <v-card-text>
                    <v-checkbox v-model="form.fahrzeugschein" :label="t('CarRegView.eid.fahrzeugschein')" density="compact" hide-details />
                    <v-checkbox v-model="form.fahrzeugbrief" :label="t('CarRegView.eid.fahrzeugbrief')" density="compact" hide-details />
                    <v-checkbox v-model="form.amtlicheskenn" :label="t('CarRegView.eid.amtlicheskenn')" density="compact" hide-details />
                    <v-checkbox v-model="form.roterschein" :label="t('CarRegView.eid.roterschein')" density="compact" hide-details />
                    <v-checkbox v-model="form.fuehrerschein" :label="t('CarRegView.eid.fuehrerschein')" density="compact" hide-details />
                    <v-checkbox v-model="form.betriebserlaubnis" :label="t('CarRegView.eid.betriebserlaubnis')" density="compact" hide-details />
                    <v-checkbox v-model="form.sonstiges" :label="t('CarRegView.eid.sonstiges')" density="compact" hide-details />
                    <v-text-field v-if="form.sonstiges" v-model="form.sonstiges_text"
                        :label="t('CarRegView.eid.sonstigesText')" variant="outlined" density="compact" class="mt-2" />
                    <v-textarea v-model="form.erklaerung" :label="t('CarRegView.eid.erklaerung')"
                        variant="outlined" density="compact" rows="3" maxlength="200" counter class="mt-3" />
                </v-card-text>
            </v-card>

            <!-- 6. SEPA-Lastschriftmandat (nur bei Zulassung/Umschreibung) -->
            <v-card v-if="form.auftragsart === 'zulassung' || form.auftragsart === 'umschreibung'" variant="outlined" class="mb-3">
                <v-card-title class="text-subtitle-1">{{ t('CarRegView.sections.sepa') }}</v-card-title>
                <v-card-text>
                    <v-checkbox v-model="form.mandat_identisch" :label="t('CarRegView.sepa.identisch')"
                        density="compact" hide-details class="mb-3" />

                    <v-row v-if="!form.mandat_identisch" dense>
                        <v-col cols="12">
                            <v-text-field v-model="form.mandats_name" :label="t('CarRegView.sepa.name')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.mandats_strasse" :label="t('CarRegView.fields.strasse')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.mandats_plz" :label="t('CarRegView.fields.plz')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field v-model="form.mandats_ort" :label="t('CarRegView.fields.ort')"
                                variant="outlined" density="compact" />
                        </v-col>
                    </v-row>

                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="form.mandats_iban" :label="t('CarRegView.sepa.iban')"
                                :rules="[rules.iban]" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="3">
                            <v-text-field v-model="form.mandats_bic" :label="t('CarRegView.sepa.bic')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="3">
                            <v-text-field v-model="form.mandats_bank" :label="t('CarRegView.sepa.bank')"
                                variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.mandats_land" :label="t('CarRegView.sepa.land')"
                                variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <!-- 7. Actions -->
            <div class="d-flex ga-2 mt-4 flex-wrap">
                <v-btn color="success" type="submit" :loading="generating" :disabled="!formValid">
                    <v-icon start>mdi-file-pdf-box</v-icon>
                    {{ t('CarRegView.generatePdf') }}
                </v-btn>
                <v-btn
                    color="warning"
                    variant="tonal"
                    :loading="generating"
                    @click="onGenerateDraftPdf"
                >
                    <v-icon start>mdi-file-document-alert-outline</v-icon>
                    {{ t('CarRegView.generateDraftPdf') }}
                </v-btn>
                <v-btn variant="text" @click="router.back()">
                    {{ t('CarRegView.back') }}
                </v-btn>
            </div>
        </v-form>

    </v-container>
</template>

<script>
import { ref, reactive, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { parseShortDate, formatDateDE } from '@/features/lxcars/utils/validation.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'

export default {
    name: 'CarRegView',
    components: { NavbarView },

    props: {
        id: {
            type: String,
            required: true
        }
    },

    setup(props) {
        const { t } = useI18n()
        const router = useRouter()
        const carsStore = lxcarsStore()

        const loading = ref(false)
        const error = ref(null)
        const generating = ref(false)
        const formRef = ref(null)
        const formValid = ref(false)

        const form = reactive({
            auftragsart: 'zulassung',
            kennzeichen: '',
            fin: '',
            vorname: '',
            name: '',
            gebdatum: '',
            gebort: '',
            gebname: '',
            strasse: '',
            hsnr: '',
            plz: '',
            ort: '',
            geschlecht: 'maennlich',
            evb_nummer: '',
            // Gewerbe
            beruf: '',
            firma: '',
            gewerbe_strasse: '',
            gewerbe_hsnr: '',
            gewerbe_plz: '',
            gewerbe_ort: '',
            // eID
            fahrzeugschein: false,
            fahrzeugbrief: false,
            amtlicheskenn: false,
            roterschein: false,
            fuehrerschein: false,
            betriebserlaubnis: false,
            sonstiges: false,
            sonstiges_text: '',
            erklaerung: '',
            // SEPA
            mandat_identisch: true,
            mandats_name: '',
            mandats_strasse: '',
            mandats_plz: '',
            mandats_ort: '',
            mandats_iban: '',
            mandats_bic: '',
            mandats_bank: '',
            mandats_land: 'Deutschland'
        })

        // Geburtsdatum: Display-Ref (deutsches Format), intern ISO
        const displayGebdatum = ref('')

        function onBlurGebdatum() {
            const raw = displayGebdatum.value.trim()
            if (!raw) { form.gebdatum = ''; return }
            const parsed = parseShortDate(raw)
            if (parsed) {
                form.gebdatum = parsed
                displayGebdatum.value = formatDateDE(parsed)
            }
        }

        const geschlechtItems = computed(() => [
            { title: t('CarRegView.geschlecht.weiblich'), value: 'weiblich' },
            { title: t('CarRegView.geschlecht.maennlich'), value: 'maennlich' },
            { title: t('CarRegView.geschlecht.divers'), value: 'divers' },
            { title: t('CarRegView.geschlecht.firma'), value: 'firma' }
        ])

        const rules = {
            required: v => !!v || t('CarRegView.validation.required'),
            date: v => !v || !!parseShortDate(v) || t('CarRegView.validation.dateFormat'),
            iban: v => !v || /^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/.test(v.replace(/\s/g, '')) || t('CarRegView.validation.ibanFormat')
        }

        onMounted(async () => {
            loading.value = true
            try {
                const data = await carsStore.getCarregData(props.id)
                form.kennzeichen = data.kennzeichen || ''
                form.fin = data.fin || ''
                form.vorname = data.vorname || ''
                form.name = data.name || ''
                form.strasse = data.strasse || ''
                form.hsnr = data.hsnr || ''
                form.plz = data.plz || ''
                form.ort = data.ort || ''
                form.mandats_iban = data.iban || ''
                form.mandats_bic = data.bic || ''
                form.mandats_bank = data.bank || ''
            } catch (e) {
                error.value = e.message || t('CarRegView.loadError')
            } finally {
                loading.value = false
            }
        })

        async function generatePdf(skipValidation) {
            if (!skipValidation) {
                const { valid } = await formRef.value.validate()
                if (!valid) return
            }

            generating.value = true
            error.value = null
            try {
                const payload = { ...form }
                // Geburtsdatum als deutsches Format fürs PDF
                if (form.gebdatum) payload.gebdatum = formatDateDE(form.gebdatum)
                const result = await carsStore.generateCarregPdf(payload)
                // Base64-PDF im neuen Tab öffnen
                const byteChars = atob(result.pdf)
                const byteNumbers = new Array(byteChars.length)
                for (let i = 0; i < byteChars.length; i++) {
                    byteNumbers[i] = byteChars.charCodeAt(i)
                }
                const byteArray = new Uint8Array(byteNumbers)
                const blob = new Blob([byteArray], { type: 'application/pdf' })
                const url = URL.createObjectURL(blob)
                window.open(url, '_blank')
            } catch (e) {
                error.value = e.message || t('CarRegView.pdfError')
            } finally {
                generating.value = false
            }
        }

        const onGeneratePdf = () => generatePdf(false)
        const onGenerateDraftPdf = () => generatePdf(true)

        return {
            t, router, loading, error, generating, formRef, formValid,
            form, displayGebdatum, onBlurGebdatum, geschlechtItems, rules,
            onGeneratePdf, onGenerateDraftPdf
        }
    }
}
</script>
