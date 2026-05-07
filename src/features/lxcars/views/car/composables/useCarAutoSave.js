// Composable: Auto-Save, Load, Focus-Tracking, Lifecycle, sendBeacon

import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'

export function useCarAutoSave({ car, isEditMode, hasValidationErrors, oserpData, carsStore, router, props, t, orders, validationCleanup, initialLoaded, pendingKbaData, kbaData, pendingScanImages, useSpecialKba, kbaSelectDialog, readonly }) {
    const saving = ref(false)
    const loading = ref(false)
    const error = ref('')
    const savedCarId = ref(null)

    const isReadonly = () => !!(readonly && readonly.value)

    let textInputFocused = false
    let hasPendingChanges = false
    let forceNextSave = false
    let saveTimeout = null
    let deleted = false

    // ── Focus-Tracking ──
    function isTextInput(el) {
        if (!el) return false
        const tag = el.tagName?.toLowerCase()
        if (tag === 'textarea') return true
        if (tag === 'input') {
            const type = (el.type || 'text').toLowerCase()
            return ['text', 'number', 'password', 'email', 'url', 'search', 'tel', 'date'].includes(type)
        }
        return false
    }

    function onFocusIn(event) {
        if (isReadonly()) return
        if (isTextInput(event.target)) {
            textInputFocused = true
        }
    }

    function onFocusOut(event) {
        if (isReadonly()) return
        if (isTextInput(event.target)) {
            textInputFocused = false
            if (hasPendingChanges) {
                hasPendingChanges = false
                triggerSave()
            }
        }
    }

    // ── Save-Logik ──
    function markDeleted() {
        deleted = true
        if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
        hasPendingChanges = false
    }

    function onDataChange() {
        if (isReadonly()) return
        if (!initialLoaded.value || deleted) return
        if (textInputFocused) {
            hasPendingChanges = true
            return
        }
        triggerSave()
    }

    function triggerSave() {
        if (isReadonly()) return
        if (saveTimeout) clearTimeout(saveTimeout)
        saveTimeout = setTimeout(() => {
            saveCarData()
        }, 500)
    }

    function toggleShield(field) {
        if (isReadonly()) return
        car.value[field] = !car.value[field]
        forceNextSave = true
        triggerSave()
    }

    watch(car, onDataChange, { deep: true })

    async function saveCarData() {
        if (saving.value || deleted) return
        const force = forceNextSave
        forceNextSave = false
        if (!force && hasValidationErrors.value) return
        if (!oserpData.customer_vendor?.profile?.id) {
            error.value = t('CarEditView.messages.noCustomer')
            return
        }

        // KBA-Dialog offen → Save komplett blockieren bis Benutzer entschieden hat
        if (kbaSelectDialog?.value) return

        saving.value = true
        error.value = ''

        try {
            const payload = {
                ...car.value,
                c_ow: oserpData.customer_vendor.profile.id
            }

            if (isEditMode.value || savedCarId.value) {
                payload.c_id = Number(props.id || savedCarId.value)
                const updateResult = await carsStore.updateCar(payload)
                // KBA-Verknüpfung aus Backend übernehmen (resolveKbaWithD2)
                if (updateResult.payload?.kba_id) {
                    car.value.kba_id = updateResult.payload.kba_id
                    if (updateResult.payload.kba && kbaData) {
                        kbaData.value = updateResult.payload.kba
                    }
                }
            } else {
                // Pflichtfelder beim Neuanlegen prüfen
                const missing = []
                if (!car.value.c_ln?.trim()) missing.push(t('CarEditView.fields.c_ln'))
                if (!car.value.c_2?.trim()) missing.push(t('CarEditView.fields.c_2'))
                if (!car.value.c_3?.trim()) missing.push(t('CarEditView.fields.c_3'))
                if (missing.length) {
                    error.value = t('CarEditView.messages.requiredFields', { fields: missing.join(', ') })
                    saving.value = false
                    return
                }
                // Beim ersten INSERT: KBA-Daten mitschicken (Scan → KBA-Tabelle)
                // Bei special_kba: keine KBA an saveCar übergeben (wird separat gespeichert)
                const kba = pendingKbaData?.value || null
                const kbaForSave = (useSpecialKba?.value) ? null : kba
                const result = await carsStore.saveCar(payload, kbaForSave)
                if (kba) pendingKbaData.value = null
                if (result.payload?.c_id) {
                    savedCarId.value = result.payload.c_id
                    // kba_id und KBA-Daten vom Backend übernehmen (prepareKba / resolveKbaWithD2)
                    if (result.payload.kba_id) {
                        car.value.kba_id = result.payload.kba_id
                        if (result.payload.kba && kbaData) {
                            kbaData.value = result.payload.kba
                        }
                    }

                    // Special-KBA separat speichern
                    if (useSpecialKba?.value && kba) {
                        carsStore.saveSpecialKba(result.payload.c_id, kba)
                            .catch(err => console.error('Error saving special KBA:', err))
                    }
                    const resolved = router.resolve({ name: 'car', params: { id: result.payload.c_id } })
                    window.history.replaceState(history.state, '', resolved.href)

                    // Scan-Bilder speichern (asynchron, nicht blockierend)
                    if (pendingScanImages?.value) {
                        const imgs = pendingScanImages.value
                        pendingScanImages.value = null
                        const cId = result.payload.c_id
                        const cLn = car.value.c_ln || ''
                        const hasFieldImages = imgs.images && typeof imgs.images === 'object' && Object.keys(imgs.images).length > 0

                        // Crops + Original in einem Call. scanId weiterreichen,
                        // damit Backend die Crops aus tmp/{scanId} kopieren kann
                        // (Listen-Flow liefert keine field_images / temp_image_id).
                        carsStore.saveScanImages(
                            cId, cLn, null,
                            hasFieldImages ? imgs.images : {},
                            false,
                            imgs.tempImageId || null,
                            imgs.scanId || null
                        ).then(() => {
                            car.value.filename = 'fahrzeuge/' + cId + '/fahrzeugschein'
                        }).catch(err => console.error('Error saving scan images:', err))
                    }
                }
            }
        } catch (e) {
            console.error('Save car error:', e)
            error.value = t('CarEditView.messages.saveError')
        } finally {
            saving.value = false
        }
    }

    // ── Load ──
    async function fetchCar(carId) {
        loading.value = true
        error.value = ''

        try {
            const result = await carsStore.loadCar(carId)
            if (result.payload) {
                // KBA-Daten aus Response extrahieren
                const { kba, ...carFields } = result.payload
                car.value = { ...car.value, ...carFields }
                if (kba && kbaData) {
                    kbaData.value = kba
                }
                // D2 aus KBA-Daten übernehmen (nicht aus cars_lxcars)
                car.value.c_d2 = kba?.d2 || ''

                // Eigentümer laden, falls nicht oder falsch geladen
                const ownerId = result.payload.c_ow
                if (ownerId && oserpData.customer_vendor?.profile?.id !== ownerId) {
                    await oserpData.fetchCustomerOrVendor(ownerId, 'C')
                }
            }
            carsStore.loadCarOrders(carId).then(o => { orders.value = o || [] }).catch(() => {})
        } catch (e) {
            console.error('Load car error:', e)
            error.value = t('CarEditView.messages.loadError')
        } finally {
            loading.value = false
        }
    }

    // ── sendBeacon bei Seitennavigation ──
    function flushPendingChanges() {
        if (isReadonly()) return
        const carId = props.id || savedCarId.value
        if (!carId || !initialLoaded.value || deleted) return
        if (!hasPendingChanges && !saveTimeout) return
        if (hasValidationErrors.value) return
        if (!oserpData.customer_vendor?.profile?.id) return

        if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
        hasPendingChanges = false

        const payload = {
            action: 'updateCar',
            data: { ...car.value, c_id: Number(carId), c_ow: oserpData.customer_vendor.profile.id }
        }
        navigator.sendBeacon('/api/lxcars/', new Blob([JSON.stringify(payload)], { type: 'application/json' }))
    }

    // ── Lifecycle ──
    onMounted(async () => {
        if (isEditMode.value) {
            await fetchCar(props.id)
        }
        await nextTick()
        initialLoaded.value = true
        window.addEventListener('beforeunload', flushPendingChanges)
    })

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', flushPendingChanges)
        if (saveTimeout) clearTimeout(saveTimeout)
        if (validationCleanup) validationCleanup()
        flushPendingChanges()
    })

    // Route-Wechsel (INSERT → EDIT): Daten neu laden
    watch(() => props.id, async (newId) => {
        initialLoaded.value = false
        if (newId) {
            await fetchCar(newId)
        }
        await nextTick()
        initialLoaded.value = true
    })

    return {
        saving, loading, error, savedCarId,
        toggleShield, onFocusIn, onFocusOut, triggerSave, markDeleted
    }
}
