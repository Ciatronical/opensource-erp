// src/features/lxcars/composables/useDiagPortals.js
//
// Fahrzeug an die Online-Portale AAG-Online und HGS-Data übergeben.
// Gemeinsame Logik für Ansichten, die ein verknüpftes Fahrzeug kennen
// (Mechaniker-Auftrag). Die Portal-URLs löst das Backend auf; wir öffnen sie
// als App-Fenster (siehe aagWindow.js) und zeigen Fehler als Toast.

import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { openAppWindow, aagWindowOpen, aagWindowCarId, setAagWindowCarId } from '@/core/utils/aagWindow.js'
import { isKbaValid } from '@/core/utils/diagLinks.js'
import * as toasts from '@/core/utils/toasts.js'

/**
 * @param {object} opts
 * @param {import('vue').Ref|import('vue').ComputedRef} opts.carObj  Fahrzeug {c_id, c_ln, c_mt, c_2, c_3, c_fin} oder null
 * @param {object} opts.oserp         oserpStore (Firmen-Defaults)
 * @param {Function} opts.t           vue-i18n
 * @param {Function} [opts.warmToken] optional: lädt das AAG-Token im Hintergrund vor, sobald der Button sichtbar wird
 */
export function useDiagPortals({ carObj, oserp, t, warmToken = null }) {
    // AAG-Online konfiguriert? (Zugangsdaten in den Firmen-Defaults hinterlegt)
    const aagConfigured = computed(() =>
        !!String(oserp.getClientDefaultValue('aag_online_user', '') || '').trim() &&
        !!String(oserp.getClientDefaultValue('aag_online_passwd', '') || '').trim()
    )

    const aagLoading = ref(false)
    const aagAvailable = computed(() => aagConfigured.value && !!carObj.value?.c_id)

    if (typeof warmToken === 'function') {
        let warmed = false
        watch(aagAvailable, (ready) => {
            if (ready && !warmed) {
                warmed = true
                warmToken()
            }
        }, { immediate: true })
    }

    async function openAag() {
        const car = carObj.value
        if (!car?.c_id) return
        const cId = Number(car.c_id)

        // Zeigt das (einzige) AAG-Fenster bereits dieses Fahrzeug? Dann nur nach vorn holen —
        // ein Re-Import würde die Portal-Sitzung (gewähltes Fahrzeug/Warenkorb) neu laden.
        if (aagWindowOpen() && aagWindowCarId() === cId) {
            openAppWindow()
            return
        }

        // Fenstertitel: "Kennzeichen · Modell". Sofort öffnen (Popup-Blocker vermeiden),
        // URL setzen, sobald sie vorliegt.
        const plate = String(car.c_ln || '').trim()
        const model = String(car.c_mt || '').trim()
        const title = [plate, model].filter(Boolean).join(' · ') || 'AAG-Online'
        const name = 'aag-' + (plate.replace(/\s+/g, '') || String(cId) || 'online')
        const reusing = aagWindowOpen()
        const win = openAppWindow(name, title)

        aagLoading.value = true
        try {
            const { data } = await axios.post('/api/lxcars/', {
                action: 'getAagVehicleUrl',
                c_id: cId,
                vin: String(car.c_fin || '').trim()
            })
            const portalUrl = data?.success ? data.payload?.portalUrl : null
            if (!portalUrl) {
                if (win && !reusing) win.close() // nur frisch geöffnetes schließen
                toasts.error(t('FakturaView.faktura.aagError'))
                return
            }
            if (win) {
                win.location.href = portalUrl
                win.focus()
                setAagWindowCarId(cId)
            } else {
                window.open(portalUrl, '_blank')
            }
        } catch (e) {
            if (win && !reusing) win.close()
            console.error('AAG-Online error:', e)
            toasts.error(t('FakturaView.faktura.aagError') + (e?.message ? '\n' + e.message : ''))
        } finally {
            aagLoading.value = false
        }
    }

    // HGS-Data sucht per HSN/TSN; die vehicleId löst das Backend auf.
    const hgsLoading = ref(false)
    const hgsAvailable = computed(() =>
        !!carObj.value?.c_id && isKbaValid(carObj.value.c_2, carObj.value.c_3)
    )

    async function openHgs() {
        const car = carObj.value
        if (!car?.c_id) return
        const win = openAppWindow('hgs-data')
        hgsLoading.value = true
        try {
            const { data } = await axios.post('/api/lxcars/', {
                action: 'getHgsVehicleUrl',
                c_id: Number(car.c_id)
            })
            const portalUrl = data?.success ? data.payload?.portalUrl : null
            if (!portalUrl) {
                if (win) win.close()
                toasts.error(t('FakturaView.faktura.hgsError') + (data?.payload?.message ? '\n' + data.payload.message : ''))
                return
            }
            if (win) {
                win.location.href = portalUrl
                win.focus()
            } else {
                window.open(portalUrl, '_blank')
            }
        } catch (e) {
            if (win) win.close()
            console.error('HGS-Data error:', e)
            toasts.error(t('FakturaView.faktura.hgsError') + (e?.message ? '\n' + e.message : ''))
        } finally {
            hgsLoading.value = false
        }
    }

    return { aagConfigured, aagAvailable, aagLoading, openAag, hgsAvailable, hgsLoading, openHgs }
}
