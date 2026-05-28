// Composable: lxcars Fahrzeug-Verknüpfung, oe_ext Felder, Datumsfelder

import { ref, computed, watch } from 'vue'
import { formatDateDE } from '@/features/lxcars/utils/validation.js'
import * as toast from '@/core/utils/toasts.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { getValues } from '@/core/utils/configColors.js'

export function useVehicleSection({ carsStore, fakturaId, fakturaType, t }) {

    const store = oserpStore()

    const isInvoice = computed(() => fakturaType.value === 'invoice')

    // Fahrzeug-Verknüpfung
    const selectedCarId = ref(null)
    const customerCars = ref([])
    const fhzart = ref('')

    const isTrailer = computed(() => fhzart.value === 'trailer')

    // Notizen
    const vehicleNotes = ref('')

    // oe_ext Zusatzfelder
    const oeExtData = ref({
        km_stand: null,
        kfz_ort: null,
        status: null,
        gedruckt: false,
        intern: false,
        bringetermin: null,
        fertigstellung: null,
        no_whatsapp: false,
        c_sk: false,
        c_zrd: null,
        c_zrk: null,
        c_bf: null,
        c_wd: null
    })

    // Dropdown-Optionen für kfz_ort (aus Firmeneinstellungen, Fallback auf i18n)
    // Unterstützt Format "Wert:Farbe, Wert2:Farbe2" – nur Werte werden zurückgegeben
    const kfzOrtOptions = computed(() => {
        const raw = store.session?.company_config?.defaults_oserp?.lxcars_kfz_ort_options || ''
        if (raw) return getValues(raw)
        return [
            t('FakturaView.faktura.kfzOrtOptions.here'),
            t('FakturaView.faktura.kfzOrtOptions.notHere'),
            t('FakturaView.faktura.kfzOrtOptions.order'),
            t('FakturaView.faktura.kfzOrtOptions.broughtForRepair')
        ]
    })

    // Dropdown-Optionen für Status (aus Firmeneinstellungen)
    // Unterstützt Format "Wert:Farbe, Wert2:Farbe2" – nur Werte werden zurückgegeben
    const statusOptions = computed(() => {
        const raw = store.session?.company_config?.defaults_oserp?.lxcars_order_statuses || ''
        if (!raw) return []
        return getValues(raw)
    })

    // ── km-Stand mit Tausendertrennung ──

    const displayKmStand = ref('')

    function formatKm(val) {
        if (val === null || val === undefined || val === '') return ''
        return Number(val).toLocaleString('de-DE')
    }

    watch(() => oeExtData.value.km_stand, (val) => { displayKmStand.value = formatKm(val) })

    function onBlurKmStand() {
        const raw = displayKmStand.value.replace(/\./g, '').replace(/\s/g, '').trim()
        if (!raw) {
            oeExtData.value.km_stand = null
            displayKmStand.value = ''
            onOeExtFieldChange('km_stand', null)
            return
        }
        const num = parseInt(raw, 10)
        if (isNaN(num)) return
        oeExtData.value.km_stand = num
        displayKmStand.value = formatKm(num)
        onOeExtFieldChange('km_stand', num)
    }

    function toggleIntern() {
        oeExtData.value.intern = !oeExtData.value.intern
        onOeExtFieldChange('intern', oeExtData.value.intern)
    }

    // ── Datumsfelder: kombinierter Date-Time-Picker ──

    // Config: Default-Zeiten und Zeitbereich aus defaults_oserp
    const defaults = computed(() => store.session?.company_config?.defaults_oserp || {})
    const defaultAbgabezeit = computed(() => defaults.value.lxcars_default_abgabezeit || '08:00')
    const defaultFertigstellungszeit = computed(() => defaults.value.lxcars_default_fertigstellungszeit || '17:00')

    const timeSlots = computed(() => {
        const range = defaults.value.lxcars_time_range || '07:00-18:00'
        const parts = range.split('-')
        const [startH, startM] = (parts[0] || '07:00').split(':').map(Number)
        const [endH, endM] = (parts[1] || '18:00').split(':').map(Number)
        const startMin = startH * 60 + (startM || 0)
        const endMin = endH * 60 + (endM || 0)
        const slots = []
        for (let m = startMin; m <= endMin; m += 30) {
            const hh = String(Math.floor(m / 60)).padStart(2, '0')
            const mm = String(m % 60).padStart(2, '0')
            slots.push(hh + ':' + mm)
        }
        return slots
    })

    // Display-Werte für die Textfelder (z.B. "19.03.2026 14:30")
    const displayBringetermin = ref('')
    const displayFertigstellung = ref('')

    // Temporäre Picker-Werte (Date-Objekt für v-date-picker, String "HH:MM" für v-time-picker)
    const pickerDateBringetermin = ref(undefined)
    const pickerTimeBringetermin = ref('')
    const pickerDateFertigstellung = ref(undefined)
    const pickerTimeFertigstellung = ref('')

    // Menu-Steuerung
    const showPickerBringetermin = ref(false)
    const showPickerFertigstellung = ref(false)

    // Hilfsfunktionen
    function tsToDisplay(ts) {
        if (!ts) return ''
        const parts = ts.split(/[ T]/)
        const date = formatDateDE(parts[0])
        const time = parts[1] ? parts[1].substring(0, 5) : ''
        return time && time !== '00:00' ? date + ' ' + time : date
    }

    function tsToPickerDate(ts) {
        if (!ts) return undefined
        const iso = ts.split(/[ T]/)[0]
        return new Date(iso + 'T00:00:00')
    }

    function tsToPickerTime(ts) {
        if (!ts) return ''
        const parts = ts.split(/[ T]/)
        if (parts.length < 2) return ''
        const time = parts[1].substring(0, 5)
        return time === '00:00' ? '' : time
    }

    function buildTimestamp(dateIso, time) {
        if (!dateIso) return null
        if (!time) return dateIso + ' 00:00:00'
        return dateIso + ' ' + time + ':00'
    }

    function pickerDateToIso(d) {
        if (!d) return null
        const dt = new Date(d)
        return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0')
    }

    // Watchers: DB-Wert → Display + Picker-Werte synchronisieren
    watch(() => oeExtData.value.bringetermin, (val) => {
        displayBringetermin.value = tsToDisplay(val)
        pickerDateBringetermin.value = tsToPickerDate(val)
        pickerTimeBringetermin.value = tsToPickerTime(val)
    })
    watch(() => oeExtData.value.fertigstellung, (val) => {
        displayFertigstellung.value = tsToDisplay(val)
        pickerDateFertigstellung.value = tsToPickerDate(val)
        pickerTimeFertigstellung.value = tsToPickerTime(val)
    })

    // Validierung: Fertigstellung darf nicht vor Bringetermin liegen
    function validateDateOrder(field, newTs) {
        if (!newTs) return true
        const other = field === 'fertigstellung' ? oeExtData.value.bringetermin : oeExtData.value.fertigstellung
        if (!other) return true
        if (field === 'fertigstellung' && newTs < other) {
            toast.warning(t('FakturaView.faktura.fertigstellungBeforeBringetermin'))
            return false
        }
        if (field === 'bringetermin' && oeExtData.value.fertigstellung && oeExtData.value.fertigstellung < newTs) {
            toast.warning(t('FakturaView.faktura.fertigstellungBeforeBringetermin'))
            return false
        }
        return true
    }

    // Picker-Events: Datum oder Zeit geändert → sofort speichern
    function onPickerDateSelect(field, date) {
        if (!date) return
        const iso = pickerDateToIso(date)
        const timeRef = field === 'bringetermin' ? pickerTimeBringetermin : pickerTimeFertigstellung
        // Default-Zeit setzen wenn noch keine Zeit oder 00:00
        if (!timeRef.value || timeRef.value === '00:00') {
            const defTime = field === 'bringetermin' ? defaultAbgabezeit.value : defaultFertigstellungszeit.value
            timeRef.value = defTime
        }
        const ts = buildTimestamp(iso, timeRef.value)
        if (!validateDateOrder(field, ts)) return
        oeExtData.value[field] = ts
        onOeExtFieldChange(field, ts)
    }

    function onPickerTimeSelect(field, time) {
        const dateRef = field === 'bringetermin' ? pickerDateBringetermin : pickerDateFertigstellung
        const iso = pickerDateToIso(dateRef.value)
        if (!iso) return
        const ts = buildTimestamp(iso, time)
        if (!validateDateOrder(field, ts)) return
        oeExtData.value[field] = ts
        onOeExtFieldChange(field, ts)
    }

    function onClearDatetime(field) {
        oeExtData.value[field] = null
        onOeExtFieldChange(field, null)
    }

    // ── oe_ext Auto-Save (debounced) ──

    let oeExtSaveTimeout = null
    let waNotifyTimeout = null

    function onOeExtFieldChange(field, value) {
        oeExtData.value[field] = value
        if (!fakturaId.value) return

        if (oeExtSaveTimeout) {
            clearTimeout(oeExtSaveTimeout)
        }
        oeExtSaveTimeout = setTimeout(async () => {
            try {
                if (isInvoice.value) {
                    await carsStore.updateArExt(fakturaId.value, { [field]: value })
                } else {
                    await carsStore.updateOeExt(fakturaId.value, { [field]: value })
                }
            } catch (e) {
                console.error('Error updating ext:', e)
            }
        }, 500)

        // WhatsApp-Terminbestaetigung: 360s Debounce damit bei Datum+Uhrzeit-Eingabe
        // nur EINE Nachricht rausgeht und der Kunde noch umdisponieren kann
        if (field === 'bringetermin' && !isInvoice.value) {
            if (waNotifyTimeout) {
                clearTimeout(waNotifyTimeout)
                waNotifyTimeout = null
            }
            if (value) {
                waNotifyTimeout = setTimeout(async () => {
                    try {
                        await carsStore.sendBringeterminWa(fakturaId.value)
                    } catch (e) {
                        console.error('Error sending Bringetermin WA:', e)
                    }
                }, 360000)
            }
        }
    }

    // ── Fahrzeug wechseln ──

    function onCarChange(carId) {
        if (!fakturaId.value) return
        selectedCarId.value = carId || null
        const linkFn = isInvoice.value
            ? carsStore.linkCarToInvoice
            : carsStore.linkCarToFaktura
        linkFn(fakturaId.value, carId || null)
            .then(() => {
                const car = customerCars.value.find(c => c.c_id === carId)
                vehicleNotes.value = car?.c_text || ''
                fhzart.value = car?.fhzart || ''
            })
            .catch(e => { console.error('Error linking car:', e) })
    }

    // ── Daten laden (wird von onMounted in der Hauptdatei aufgerufen) ──

    function _applyCarData(car) {
        selectedCarId.value = car?.c_id || null
        vehicleNotes.value = car?.c_text || ''
        fhzart.value = car?.fhzart || ''
        oeExtData.value.km_stand = car?.km_stand || null
        oeExtData.value.kfz_ort = car?.kfz_ort || null
        oeExtData.value.status = car?.status || null
        oeExtData.value.gedruckt = car?.gedruckt === true || car?.gedruckt === 't'
        oeExtData.value.intern = car?.intern === true || car?.intern === 't'
        oeExtData.value.bringetermin = car?.bringetermin || null
        oeExtData.value.fertigstellung = car?.fertigstellung || null
        oeExtData.value.no_whatsapp = car?.no_whatsapp === true || car?.no_whatsapp === 't'
        oeExtData.value.c_sk = car?.c_sk === true || car?.c_sk === 't'
        oeExtData.value.c_zrd = car?.c_zrd || null
        oeExtData.value.c_zrk = car?.c_zrk ?? null
        oeExtData.value.c_bf = car?.c_bf || null
        oeExtData.value.c_wd = car?.c_wd || null
    }

    // Wird mit vorgeladenen Daten aus getLxCarsFakturaInit aufgerufen (preloaded != null)
    // oder ohne Daten für den Einzelaufruf-Pfad.
    async function loadVehicleData(customerId, preloaded = null) {
        if (preloaded) {
            customerCars.value = preloaded.customer_cars || []
            if (preloaded.car_data) _applyCarData(preloaded.car_data)
            return
        }

        // Einzelaufruf-Pfad (Neu-Modus, Kundenwechsel etc.)
        if (customerId) {
            try {
                const cars = await carsStore.loadCustomerCars(customerId)
                customerCars.value = cars || []
            } catch {}
        }

        if (!fakturaId.value) return

        try {
            const car = isInvoice.value
                ? await carsStore.loadCarForInvoice(fakturaId.value)
                : await carsStore.loadCarForOrder(fakturaId.value)
            _applyCarData(car)
        } catch {}
    }

    // ── Fahrzeug im Neu-Modus vorauswählen (c_id aus Query-Parameter) ──

    async function preselectCar(customerId, carId) {
        if (!customerId || !carId) return
        try {
            const cars = await carsStore.loadCustomerCars(customerId)
            customerCars.value = cars || []
            if (cars?.some(c => c.c_id === carId)) {
                selectedCarId.value = carId
                const car = cars.find(c => c.c_id === carId)
                vehicleNotes.value = car?.c_text || ''
            }
        } catch {}
    }

    // ── Reset bei Kundenwechsel ──

    const shouldFocusCar = ref(false)

    function onCustomerChangeVehicle(customerId) {
        selectedCarId.value = null
        vehicleNotes.value = ''
        shouldFocusCar.value = false
        if (customerId) {
            carsStore.loadCustomerCars(customerId)
                .then(cars => {
                    customerCars.value = cars || []
                    if (cars?.length === 1) {
                        // Einzelnes Fahrzeug: automatisch auswählen
                        onCarChange(cars[0].c_id)
                    } else if (cars?.length > 1) {
                        // Mehrere Fahrzeuge: Kennzeichen-Dropdown fokussieren
                        shouldFocusCar.value = true
                    }
                })
                .catch(() => {})
        } else {
            customerCars.value = []
        }
    }

    return {
        isInvoice,
        selectedCarId,
        customerCars,
        vehicleNotes,
        isTrailer,
        oeExtData,
        kfzOrtOptions,
        statusOptions,
        displayKmStand,
        onBlurKmStand,
        toggleIntern,
        displayBringetermin,
        displayFertigstellung,
        pickerDateBringetermin,
        pickerTimeBringetermin,
        pickerDateFertigstellung,
        pickerTimeFertigstellung,
        showPickerBringetermin,
        showPickerFertigstellung,
        timeSlots,
        onPickerDateSelect,
        onPickerTimeSelect,
        onClearDatetime,
        onOeExtFieldChange,
        onCarChange,
        loadVehicleData,
        preselectCar,
        onCustomerChangeVehicle,
        shouldFocusCar
    }
}
