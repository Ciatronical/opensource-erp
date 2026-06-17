// Composable: SilverDAT VXS-Import – XML parsen und Positionen importieren

import { ref, computed } from 'vue'
import { fakturaStore } from '@/core/stores/faktura.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'

const VXS_NS = 'http://www.dat.de/vxs'

function qn(tag) {
    return `{${VXS_NS}}${tag}`
}

/**
 * Holt den Textinhalt eines direkten Kind-Elements
 */
function childText(el, tag) {
    const child = el.querySelector(tag)
    return child?.textContent?.trim() ?? ''
}

/**
 * Parst einen VXS-XML-String und extrahiert Fahrzeugdaten + alle Positionen
 */
function parseVXS(xmlString) {
    const parser = new DOMParser()
    const doc = parser.parseFromString(xmlString, 'text/xml')

    const parseError = doc.querySelector('parsererror')
    if (parseError) throw new Error('XML-Parse-Fehler')

    // Namespace-aware queries via getElementsByTagNameNS
    const ns = VXS_NS

    // ── Fahrzeugdaten ──
    const dossierVehicles = doc.getElementsByTagNameNS(ns, 'Vehicle')
    let vehicleInfo = null

    // Dossier > Vehicle enthält die Registrierungsdaten
    for (let i = 0; i < dossierVehicles.length; i++) {
        const v = dossierVehicles[i]
        const mfr = v.getElementsByTagNameNS(ns, 'ManufacturerName')[0]?.textContent?.trim()
        const model = v.getElementsByTagNameNS(ns, 'BaseModelName')[0]?.textContent?.trim()
        const subModel = v.getElementsByTagNameNS(ns, 'SubModelName')[0]?.textContent?.trim()
        const mileage = v.getElementsByTagNameNS(ns, 'MileageOdometer')[0]?.textContent?.trim()
        const vin = v.getElementsByTagNameNS(ns, 'VehicleIdentNumber')[0]?.textContent?.trim()
        const regData = v.getElementsByTagNameNS(ns, 'RegistrationData')[0]
        const licensePlate = regData?.getElementsByTagNameNS(ns, 'LicenseNumber')[0]?.textContent?.trim()

        if (mfr || licensePlate) {
            vehicleInfo = {
                manufacturer: mfr || '',
                model: [model, subModel].filter(Boolean).join(' '),
                licensePlate: licensePlate || '',
                vin: vin || '',
                mileage: mileage ? parseInt(mileage, 10) : null
            }
            break
        }
    }

    // ── CalcResultCommon – enthält die berechneten Positionen ──
    const calcResult = doc.getElementsByTagNameNS(ns, 'CalcResultCommon')[0]
    if (!calcResult) throw new Error('Kein CalcResultCommon im VXS gefunden')

    const items = []

    // 1. MaterialPositions (Ersatzteile)
    const materialPositions = calcResult.getElementsByTagNameNS(ns, 'MaterialPosition')
    for (let i = 0; i < materialPositions.length; i++) {
        const mp = materialPositions[i]
        const included = mp.getElementsByTagNameNS(ns, 'IncludedInCalculation')[0]?.textContent
        if (included === 'false') continue

        const description = mp.getElementsByTagNameNS(ns, 'Description')[0]?.textContent?.trim() || ''
        const partnumber = mp.getElementsByTagNameNS(ns, 'PartNumber')[0]?.textContent?.trim() || ''
        const amount = parseFloat(mp.getElementsByTagNameNS(ns, 'Amount')[0]?.textContent || '1')
        const valuePerUnit = parseFloat(mp.getElementsByTagNameNS(ns, 'ValuePerUnit')[0]?.textContent || '0')
        const valueTotal = parseFloat(mp.getElementsByTagNameNS(ns, 'ValueTotalCorrected')[0]?.textContent
            || mp.getElementsByTagNameNS(ns, 'ValueTotal')[0]?.textContent || '0')
        const isRepairSet = mp.getElementsByTagNameNS(ns, 'IsRepairSet')[0]?.textContent === 'true'

        // Reparatursatz-Überschriften ohne Preis überspringen
        if (isRepairSet && !valuePerUnit && !valueTotal) continue
        if (!description) continue

        items.push({
            category: 'part',
            part_type: 'part',
            description,
            partnumber,
            qty: amount || 1,
            sellprice: valuePerUnit || (amount > 0 ? valueTotal / amount : valueTotal),
            unit: 'Stck',
            longdescription: ''
        })
    }

    // 1b. Kleinersatzteile-/Verbrauchsmaterialzuschlag
    // Steht im VXS nur in der Summary (RepairCalculationSummary > SparePartsCosts),
    // nicht als eigene MaterialPosition – muss daher separat als Position ergänzt werden.
    const sparePartsCosts = calcResult.getElementsByTagNameNS(ns, 'SparePartsCosts')[0]
    if (sparePartsCosts) {
        const consumables = parseFloat(
            sparePartsCosts.getElementsByTagNameNS(ns, 'ConsumablesSurcharge')[0]?.textContent || '0'
        )
        if (consumables > 0) {
            const pct = sparePartsCosts.getElementsByTagNameNS(ns, 'ConsumablesSurchargePercentage')[0]?.textContent?.trim()
            items.push({
                category: 'part',
                // bewusst 'service': Position ohne Teilenummer wird so über die
                // funktionierende servicenumber-Vergabe angelegt (Buchungsgruppe identisch)
                part_type: 'service',
                description: 'Kleinersatzteile' + (pct ? ` (${pct}%)` : ''),
                partnumber: '',
                qty: 1,
                sellprice: Math.round(consumables * 100) / 100,
                unit: 'Stck',
                longdescription: ''
            })
        }
    }

    // 2. LabourPositions (Arbeitszeit)
    const labourPositions = calcResult.getElementsByTagNameNS(ns, 'LabourPosition')
    for (let i = 0; i < labourPositions.length; i++) {
        const lp = labourPositions[i]
        const included = lp.getElementsByTagNameNS(ns, 'IncludedInCalculation')[0]?.textContent
        if (included === 'false') continue

        const description = lp.getElementsByTagNameNS(ns, 'Description')[0]?.textContent?.trim() || ''
        const duration = parseFloat(lp.getElementsByTagNameNS(ns, 'Duration')[0]?.textContent || '0')
        const valueCorrected = parseFloat(lp.getElementsByTagNameNS(ns, 'ValueTotalCorrected')[0]?.textContent
            || lp.getElementsByTagNameNS(ns, 'ValueTotal')[0]?.textContent || '0')
        const wageType = lp.getElementsByTagNameNS(ns, 'WageType')[0]?.textContent?.trim() || ''

        if (!description || valueCorrected === 0) continue

        const sellprice = duration > 0 ? valueCorrected / duration : valueCorrected

        items.push({
            category: 'labour',
            part_type: 'service',
            description: description.replace(/\n/g, ' '),
            partnumber: '',
            qty: duration || 1,
            sellprice: Math.round(sellprice * 100) / 100,
            unit: 'AW',
            longdescription: wageType ? `Lohnart: ${wageType}` : ''
        })
    }

    // 3. LacquerPositions (Lackierung)
    const lacquerPositions = calcResult.getElementsByTagNameNS(ns, 'LacquerPosition')
    for (let i = 0; i < lacquerPositions.length; i++) {
        const lp = lacquerPositions[i]
        const included = lp.getElementsByTagNameNS(ns, 'IncludedInCalculation')[0]?.textContent
        if (included === 'false') continue

        const description = lp.getElementsByTagNameNS(ns, 'Description')[0]?.textContent?.trim() || ''
        const duration = parseFloat(lp.getElementsByTagNameNS(ns, 'Duration')[0]?.textContent || '0')
        const valueCorrected = parseFloat(lp.getElementsByTagNameNS(ns, 'ValueTotalCorrected')[0]?.textContent
            || lp.getElementsByTagNameNS(ns, 'ValueTotal')[0]?.textContent || '0')
        const material = parseFloat(lp.getElementsByTagNameNS(ns, 'Material')[0]?.textContent || '0')

        if (!description || valueCorrected === 0) continue

        const sellprice = duration > 0 ? valueCorrected / duration : valueCorrected

        items.push({
            category: 'lacquer',
            part_type: 'service',
            description: description.replace(/\n/g, ' '),
            partnumber: '',
            qty: duration || 1,
            sellprice: Math.round(sellprice * 100) / 100,
            unit: 'AW',
            longdescription: material > 0 ? `inkl. Material ${material.toFixed(2)} €` : ''
        })
    }

    // 4. AdditionalCostsPositions (Zusatzkosten)
    const additionalPositions = calcResult.getElementsByTagNameNS(ns, 'AdditionalCostsPosition')
    for (let i = 0; i < additionalPositions.length; i++) {
        const ap = additionalPositions[i]
        const description = ap.getElementsByTagNameNS(ns, 'Description')[0]?.textContent?.trim() || ''
        const valueTotal = parseFloat(ap.getElementsByTagNameNS(ns, 'ValueTotal')[0]?.textContent || '0')
        const amount = parseFloat(ap.getElementsByTagNameNS(ns, 'Amount')[0]?.textContent || '1')
        const valuePerUnit = parseFloat(ap.getElementsByTagNameNS(ns, 'ValuePerUnit')[0]?.textContent || '0')

        if (!description || valueTotal === 0) continue

        items.push({
            category: 'additional',
            part_type: 'service',
            description,
            partnumber: ap.getElementsByTagNameNS(ns, 'PartNumber')[0]?.textContent?.trim() || '',
            qty: amount || 1,
            sellprice: valuePerUnit || (amount > 0 ? valueTotal / amount : valueTotal),
            unit: 'Stck',
            longdescription: ''
        })
    }

    return { vehicleInfo, items }
}

export function useSilverDATImport({ t }) {
    const faktura = fakturaStore()
    const oserp = oserpStore()

    const showDialog = ref(false)
    const importItems = ref([])
    const vehicleInfo = ref(null)
    const importing = ref(false)
    const importError = ref('')
    const fileName = ref('')

    const summary = computed(() => {
        const parts = importItems.value.filter(i => i.category === 'part')
        const labour = importItems.value.filter(i => i.category === 'labour')
        const lacquer = importItems.value.filter(i => i.category === 'lacquer')
        const additional = importItems.value.filter(i => i.category === 'additional')

        const sum = (arr) => arr.reduce((s, i) => s + (i.qty * i.sellprice), 0)

        return {
            totalCount: importItems.value.length,
            partsCount: parts.length,
            labourCount: labour.length,
            lacquerCount: lacquer.length,
            additionalCount: additional.length,
            partsSum: sum(parts),
            labourSum: sum(labour),
            lacquerSum: sum(lacquer),
            additionalSum: sum(additional),
            totalNet: sum(importItems.value)
        }
    })

    function readFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => resolve(reader.result)
            reader.onerror = reject
            reader.readAsText(file, 'UTF-8')
        })
    }

    async function onFileSelect(event) {
        const file = event.target?.files?.[0]
        if (!file) return
        await processFile(file)
    }

    async function onDrop(event) {
        const file = event.dataTransfer?.files?.[0]
        if (!file) return
        await processFile(file)
    }

    async function processFile(file) {
        importError.value = ''
        importItems.value = []
        vehicleInfo.value = null
        fileName.value = file.name

        try {
            const xmlString = await readFile(file)
            const result = parseVXS(xmlString)
            importItems.value = result.items
            vehicleInfo.value = result.vehicleInfo

            if (result.items.length === 0) {
                importError.value = t('FakturaView.faktura.silverdat.invalidFile')
            }
        } catch (e) {
            console.error('SilverDAT parse error:', e)
            importError.value = t('FakturaView.faktura.silverdat.invalidFile')
        }
    }

    async function doImport(fakturaID, fakturaType) {
        if (!fakturaID || importItems.value.length === 0) return null

        // Standard-Buchungsgruppe automatisch ermitteln (erste nicht-obsolete, immer 19%)
        const buchungsgruppen = oserp.session?.company_config?.buchungsgruppen || []
        const defaultBG = buchungsgruppen.find(bg => !bg.obsolete) || buchungsgruppen[0]
        if (!defaultBG) {
            importError.value = t('FakturaView.faktura.silverdat.noBuchungsgruppe')
            return null
        }
        const buchungsgruppeId = defaultBG.id

        importing.value = true
        importError.value = ''

        try {
            const itemsPayload = importItems.value.map(item => ({
                description: item.description,
                partnumber: item.partnumber,
                qty: item.qty,
                sellprice: item.sellprice,
                unit: item.unit,
                part_type: item.part_type,
                longdescription: item.longdescription
            }))

            const result = await faktura.importSilverDATItems(
                fakturaID, fakturaType, itemsPayload,
                buchungsgruppeId, buchungsgruppeId
            )

            return result
        } catch (e) {
            console.error('SilverDAT import error:', e)
            importError.value = t('FakturaView.faktura.silverdat.error')
            return null
        } finally {
            importing.value = false
        }
    }

    function reset() {
        importItems.value = []
        vehicleInfo.value = null
        importError.value = ''
        fileName.value = ''
        importing.value = false
    }

    return {
        showDialog,
        importItems,
        vehicleInfo,
        importing,
        importError,
        fileName,
        summary,
        onFileSelect,
        onDrop,
        doImport,
        reset
    }
}
