// Composable: Gutachten-Import – fremdes Gutachten (PDF/Foto) per KI auslesen
// und Fahrzeug, km-Stand, Gutachtendaten und Positionen in den Auftrag übernehmen.
// Schwester des SilverDAT-Imports für Dateien ohne Schnittstelle.

import { ref, computed } from 'vue'
import { fakturaStore } from '@/core/stores/faktura.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { aiModelStore } from '@/core/stores/ai-model.store.js'

const ACCEPTED_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']

export function useGutachtenImport({ t }) {
    const faktura = fakturaStore()
    const oserp = oserpStore()
    const aiModels = aiModelStore()

    const showDialog = ref(false)
    const analyzing = ref(false)
    const importing = ref(false)
    const importError = ref('')
    const fileName = ref('')
    const fileBase64 = ref('')
    const mimeType = ref('')

    // Ergebnis der Analyse
    const items = ref([])            // {category, part_type, description, partnumber, qty, unit, sellprice, longdescription, selected}
    const vehicle = ref(null)        // {license_plate, plate_normalized, vin, manufacturer, model, first_registration, mileage, color}
    const appraisal = ref(null)      // {number, date, appraiser, insurer, claim_number, damage_date, damage_description, repair_costs_net, …}
    const context = ref(null)        // {c_id, c_ln, c_fin, km_stand} – Fahrzeug des Auftrags
    const confidence = ref(0)
    const aiNotes = ref('')
    const deltaNet = ref(null)
    const lastAnalysis = ref(null)   // komplettes Analyse-Ergebnis – Grundlage der Weroni-Rechtsprüfung

    // Was übernommen werden soll – vorbelegt, abwählbar im Dialog
    const options = ref({ apply_vehicle: true, apply_km: true, apply_notes: true, store_pdf: true })

    const selectedItems = computed(() => items.value.filter(i => i.selected))

    const summary = computed(() => {
        const sel = selectedItems.value
        const sum = (arr) => arr.reduce((s, i) => s + (i.qty * i.sellprice), 0)
        const by = (cat) => sel.filter(i => i.category === cat)
        return {
            totalCount: sel.length,
            partsCount: by('part').length,
            labourCount: by('labour').length,
            lacquerCount: by('lacquer').length,
            additionalCount: by('additional').length,
            partsSum: sum(by('part')),
            labourSum: sum(by('labour')),
            lacquerSum: sum(by('lacquer')),
            additionalSum: sum(by('additional')),
            totalNet: sum(sel)
        }
    })

    // Kennzeichen im Gutachten weicht vom verknüpften Fahrzeug ab
    const plateMismatch = computed(() => {
        const pdf = vehicle.value?.plate_normalized
        const order = context.value?.c_ln
        return !!pdf && !!order && pdf.replace(/[^A-Z0-9]/g, '') !== String(order).toUpperCase().replace(/[^A-Z0-9]/g, '')
    })

    // Fahrzeug-Status für den Dialog: verknüpft / wird angelegt / kein Kennzeichen
    const vehicleAction = computed(() => {
        if (!vehicle.value) return 'none'
        if (context.value?.c_id) return 'linked'
        if (vehicle.value.plate_normalized) return 'create'
        return vehicle.value.license_plate ? 'plate_invalid' : 'none'
    })

    function readFileBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
            reader.onerror = reject
            reader.readAsDataURL(file)
        })
    }

    async function onFileSelect(event, oeId) {
        const file = event.target?.files?.[0]
        if (!file) return
        await processFile(file, oeId)
    }

    async function processFile(file, oeId) {
        importError.value = ''
        items.value = []
        vehicle.value = null
        appraisal.value = null
        fileName.value = file.name

        const type = file.type || (file.name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : '')
        if (!ACCEPTED_TYPES.includes(type)) {
            importError.value = t('FakturaView.faktura.gutachten.invalidFile')
            return
        }
        mimeType.value = type
        fileBase64.value = await readFileBase64(file)
        await analyze(oeId)
    }

    // Analyse (erneut) ausführen – z. B. nach Modellwechsel
    async function analyze(oeId) {
        if (!fileBase64.value || !oeId) return
        analyzing.value = true
        importError.value = ''
        try {
            const result = await faktura.analyzeGutachten({
                oeId,
                fileBase64: fileBase64.value,
                filename: fileName.value,
                mimeType: mimeType.value,
                aiModel: aiModels.requestModel('gutachten_import')
            })
            lastAnalysis.value = result
            items.value = (result.items || []).map(i => ({ ...i, selected: i.selected !== false }))
            vehicle.value = result.vehicle || null
            appraisal.value = result.appraisal || null
            context.value = result.context || null
            confidence.value = Number(result.confidence || 0)
            aiNotes.value = result.notes || ''
            deltaNet.value = result.delta_net ?? null
            // Kennzeichen im Gutachten passt nicht zum verknüpften Fahrzeug: Fahrzeugdaten
            // nicht vorbelegt übernehmen – das Backend ergänzt in dem Fall ohnehin nichts
            options.value.apply_vehicle = !plateMismatch.value
            if (items.value.length === 0 && !vehicle.value?.license_plate) {
                importError.value = t('FakturaView.faktura.gutachten.nothingFound')
            }
        } catch (e) {
            console.error('Gutachten analyze error:', e)
            importError.value = t('FakturaView.faktura.gutachten.analyzeError') + (e?.message ? ': ' + e.message : '')
        } finally {
            analyzing.value = false
        }
    }

    /**
     * Übernahme in den Auftrag. Liefert das Backend-Ergebnis oder null bei Fehler;
     * bei fremdem Halter { exists_other_owner, owner_name, plate } – der Aufrufer fragt nach
     * und ruft mit takeOver = true erneut auf.
     */
    async function doImport(oeId, takeOver = false) {
        if (!oeId) return null

        const buchungsgruppen = oserp.session?.company_config?.buchungsgruppen || []
        const defaultBG = buchungsgruppen.find(bg => !bg.obsolete) || buchungsgruppen[0]
        if (selectedItems.value.length > 0 && !defaultBG) {
            importError.value = t('FakturaView.faktura.silverdat.noBuchungsgruppe')
            return null
        }

        importing.value = true
        importError.value = ''
        try {
            const itemsPayload = selectedItems.value.map(item => ({
                description: item.description,
                partnumber: item.partnumber,
                qty: item.qty,
                sellprice: item.sellprice,
                unit: item.unit,
                part_type: item.part_type,
                longdescription: item.longdescription || ''
            }))
            return await faktura.applyGutachten({
                oeId,
                items: itemsPayload,
                buchungsgruppeId: defaultBG?.id || 0,
                vehicle: vehicle.value || {},
                appraisal: appraisal.value || {},
                options: { ...options.value, take_over: takeOver },
                fileBase64: options.value.store_pdf ? fileBase64.value : '',
                filename: fileName.value,
                extracted: lastAnalysis.value
            })
        } catch (e) {
            console.error('Gutachten import error:', e)
            importError.value = t('FakturaView.faktura.gutachten.importError') + (e?.message ? ': ' + e.message : '')
            return null
        } finally {
            importing.value = false
        }
    }

    function reset() {
        lastAnalysis.value = null
        items.value = []
        vehicle.value = null
        appraisal.value = null
        context.value = null
        confidence.value = 0
        aiNotes.value = ''
        deltaNet.value = null
        importError.value = ''
        fileName.value = ''
        fileBase64.value = ''
        mimeType.value = ''
        analyzing.value = false
        importing.value = false
        options.value = { apply_vehicle: true, apply_km: true, apply_notes: true, store_pdf: true }
    }

    return {
        showDialog,
        analyzing,
        importing,
        importError,
        fileName,
        items,
        vehicle,
        appraisal,
        context,
        confidence,
        aiNotes,
        deltaNet,
        options,
        summary,
        vehicleAction,
        plateMismatch,
        onFileSelect,
        analyze,
        doImport,
        reset
    }
}
