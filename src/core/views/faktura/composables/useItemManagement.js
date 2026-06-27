// Composable: Artikel-CRUD, Dialoge, Suche

import { ref, nextTick } from 'vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

export function useItemManagement({
    fakturaItems, fakturaId, fakturaType, faktura,
    itemsTableRef, calculateItemTotal, calculateTotals, saveAllItems, ensureFakturaExists, flushRouteReplace, oserp, t, router,
    suppressSSEReload
}) {

    // Dialog Refs
    const deleteDialog = ref({
        show: false,
        item: null,
        index: null
    })

    const bulkDeleteDialog = ref({
        show: false,
        items: []
    })

    const deleteFakturaDialog = ref({
        show: false
    })

    const editDialog = ref({
        show: false,
        item: null,
        index: null
    })

    const createDialog = ref({
        show: false,
        searchText: '',
        itemIndex: -1
    })

    // ── Leere Position ──

    function createEmptyItem() {
        return {
            id: null,
            tempId: `temp_${Date.now()}_${Math.random()}`,
            parts_id: null,
            partnumber: '',
            part_type: null,
            classification_id: null,
            description: '',
            longdescription: '',
            qty: 0,
            unit: '',
            sellprice: 0,
            discount: 0,
            position: fakturaItems.value.length + 1,
            buchungsziel: null,
            localArticleList: [],
            localArticleLoading: false
        }
    }

    // ── Neue Zeile hinzufügen ──

    async function addNewItemRow(index) {
        if (index === fakturaItems.value.length - 1) {
            const currentItem = fakturaItems.value[index]

            if (currentItem.parts_id) {
                try {
                    await ensureFakturaExists()
                    const savedItem = await faktura.createFakturaItem(fakturaId.value, currentItem, fakturaType.value)
                    currentItem.id = savedItem.id

                    fakturaItems.value.push(createEmptyItem())
                    calculateTotals()
                    await saveAllItems()
                    flushRouteReplace?.()

                    nextTick(() => {
                        if (itemsTableRef.value) {
                            itemsTableRef.value.focusArticleField(fakturaItems.value.length - 1)
                        }
                    })
                } catch (e) {
                    console.error('Fehler beim Speichern der Position:', e)
                    alerts.error(t('FakturaView.faktura.itemCreateError'))
                }
            }
        }
    }

    // ── Position löschen ──

    function deleteItem(index) {
        const item = fakturaItems.value[index]
        deleteDialog.value = { show: true, item, index }
    }

    async function confirmDeleteItem() {
        const index = deleteDialog.value.index
        const item = deleteDialog.value.item
        deleteDialog.value.show = false

        if (item.id) {
            try {
                await faktura.deleteFakturaItem(item.id, fakturaType.value)
            } catch (e) {
                console.error('Fehler beim Löschen der Position:', e)
                alerts.error(t('FakturaView.faktura.itemDeleteError'))
                return
            }
        }

        fakturaItems.value.splice(index, 1)
        fakturaItems.value.forEach((item, idx) => { item.position = idx + 1 })
        calculateTotals()
        await saveAllItems()
    }

    // ── Mehrere Positionen löschen ──

    function deleteSelectedItems(selectedItemsList) {
        bulkDeleteDialog.value = { show: true, items: selectedItemsList }
    }

    async function confirmBulkDeleteItems() {
        const itemsToDelete = bulkDeleteDialog.value.items
        bulkDeleteDialog.value.show = false

        for (const item of itemsToDelete) {
            if (item.id) {
                try {
                    await faktura.deleteFakturaItem(item.id, fakturaType.value)
                } catch (e) {
                    console.error('Fehler beim Löschen der Position:', e)
                    alerts.error(t('FakturaView.faktura.itemDeleteError'))
                    return
                }
            }
        }

        const deletedKeys = new Set(itemsToDelete.map(i => i.id || i.tempId))
        fakturaItems.value = fakturaItems.value.filter(item => !deletedKeys.has(item.id || item.tempId))
        fakturaItems.value.forEach((item, idx) => { item.position = idx + 1 })
        calculateTotals()
        await saveAllItems()
    }

    // ── Faktura löschen ──

    function deleteFaktura() {
        deleteFakturaDialog.value.show = true
    }

    async function confirmDeleteFaktura() {
        const fakturaID = faktura.data.common?.id
        if (!fakturaID) return

        deleteFakturaDialog.value.show = false

        try {
            await faktura.deleteFaktura(fakturaID, fakturaType.value)
            toasts.success(t('FakturaView.faktura.deleteSuccess'))
            router.back()
        } catch (e) {
            console.error('Fehler beim Löschen des Dokuments:', e)
            let message = t('FakturaView.faktura.deleteError')
            if (e.code === 'NOT_LAST_NUMBER' && e.payload?.currentNumber) {
                message = t('FakturaView.faktura.deleteNotLastNumber', e.payload)
            }
            alerts.error(message)
        }
    }

    // ── Rabatt ──

    function setItemDiscount(item, discount) {
        item.discount = discount
        calculateItemTotal(item)
        calculateTotals()
    }

    function setAllDiscounts(discount) {
        fakturaItems.value.forEach(item => {
            if (item.id !== null) {
                item.discount = discount
                calculateItemTotal(item)
            }
        })
        calculateTotals()
        saveAllItems()
    }

    // ── Artikel bearbeiten ──

    function editArticle(item) {
        const index = fakturaItems.value.findIndex(i => i === item)
        editDialog.value = { show: true, item: { ...item }, index }
    }

    async function onEditItemSave(payload) {
        const { item, updateMasterData } = payload
        const index = editDialog.value.index

        const originalItem = fakturaItems.value[index]
        originalItem.description = item.description
        originalItem.longdescription = item.longdescription
        originalItem.qty = item.qty
        originalItem.sellprice = item.sellprice
        originalItem.discount = item.discount
        originalItem.unit = item.unit

        calculateItemTotal(originalItem)
        calculateTotals()
        editDialog.value.show = false

        try {
            await saveAllItems()

            if (updateMasterData && originalItem.parts_id) {
                await faktura.updatePart({
                    parts_id: originalItem.parts_id,
                    description: item.description,
                    notes: item.longdescription,
                    sellprice: item.sellprice,
                    unit: item.unit
                })
                toasts.success(t('FakturaView.dialogs.editItem.masterDataUpdated'))
            }
        } catch (e) {
            console.error('Fehler beim Speichern:', e)
            alerts.error(t('FakturaView.faktura.itemUpdateError'))
        }
    }

    // ── Artikel erstellen ──

    function createArticle(searchText, item, index) {
        createDialog.value = { show: true, searchText, itemIndex: index }
    }

    async function onCreateArticleSave(payload) {
        const { item, index } = payload

        try {
            const targetItem = fakturaItems.value[index]
            if (!targetItem) {
                console.error('Target item not found at index:', index)
                return
            }

            const partResult = await faktura.createPart({
                description: item.description,
                part_type: item.part_type,
                buchungsgruppen_id: item.buchungsgruppen_id,
                partnumber: item.partnumber || '',
                sellprice: item.sellprice || 0,
                unit: item.unit || 'Stck',
                notes: item.longdescription || ''
            })

            const buchungsgruppe = oserp.session?.company_config?.buchungsgruppen?.find(
                bg => bg.id === item.buchungsgruppen_id
            )

            targetItem.parts_id = partResult.parts_id
            targetItem.partnumber = partResult.partnumber
            targetItem.description = item.description
            targetItem.longdescription = item.longdescription || ''
            targetItem.qty = item.qty || 1
            targetItem.sellprice = item.sellprice || 0
            targetItem.unit = item.unit || 'Stck'
            targetItem.discount = 0
            targetItem.buchungsgruppen_id = item.buchungsgruppen_id
            targetItem.part_type = item.part_type

            if (buchungsgruppe) {
                targetItem.buchungsziel = {
                    income_chart_id: buchungsgruppe.income_accno_id_0 || null,
                    tax_id: null,
                    tax_chart_id: null,
                    rate: 0
                }
            }

            calculateItemTotal(targetItem)
            calculateTotals()

            targetItem.localArticleList = []
            targetItem.localArticleLoading = false

            await ensureFakturaExists()
            const savedItem = await faktura.createFakturaItem(fakturaId.value, targetItem, fakturaType.value)
            if (savedItem && savedItem.id) {
                targetItem.id = savedItem.id
            }

            fakturaItems.value.push(createEmptyItem())
            flushRouteReplace?.()

            nextTick(() => {
                if (itemsTableRef.value) {
                    itemsTableRef.value.focusArticleField(fakturaItems.value.length - 1)
                }
            })

            toasts.success(t('FakturaView.dialogs.createPart.articleCreated'))
        } catch (e) {
            console.error('Fehler beim Anlegen des Artikels:', e)
            alerts.error(t('FakturaView.faktura.itemCreateError'))
        }
    }

    // ── Artikel-Suche ──

    // Race-Condition-Schutz: pro Zeile den zuletzt abgesetzten Suchbegriff merken,
    // damit verspaetete Responses aelterer Requests nicht die Liste ueberschreiben.
    const latestSearchTerm = new Map()

    async function onArticleSearch(searchTerm, index, item) {
        if (!searchTerm || searchTerm.length < 2) {
            latestSearchTerm.set(index, searchTerm || '')
            if (itemsTableRef.value) {
                itemsTableRef.value.updateLocalArticleList(index, [])
            }
            return
        }

        latestSearchTerm.set(index, searchTerm)

        try {
            const parts = await faktura.fetchParts(searchTerm, faktura.data.common.taxzone_id)
            // Nur anwenden, wenn inzwischen kein neuerer Request gestartet wurde
            if (latestSearchTerm.get(index) !== searchTerm) {
                return
            }
            if (itemsTableRef.value) {
                itemsTableRef.value.updateLocalArticleList(index, parts || [])
            }
        } catch (e) {
            if (latestSearchTerm.get(index) !== searchTerm) {
                return
            }
            if (itemsTableRef.value) {
                itemsTableRef.value.updateLocalArticleList(index, [])
            }
        }
    }

    async function onArticleSelect(item, index, selectedArticle) {
        if (!selectedArticle || typeof selectedArticle === 'string') {
            return
        }

        item.description = selectedArticle.description
        fillArticleData(item, selectedArticle)

        // SSE-Reload VOR den async Saves unterdrücken — sonst überschreibt
        // reloadFakturaData() die Items-Liste bevor der Fokus gesetzt wird
        suppressSSEReload?.()

        try {
            await ensureFakturaExists()
            const savedItem = await faktura.createFakturaItem(fakturaId.value, item, fakturaType.value)
            item.id = savedItem.id
            calculateTotals()
            await saveAllItems()
            flushRouteReplace?.()
        } catch (e) {
            alerts.error(t('FakturaView.faktura.itemCreateError'))
        }

        if (index === fakturaItems.value.length - 1) {
            fakturaItems.value.push(createEmptyItem())

            nextTick(() => {
                if (itemsTableRef.value) {
                    itemsTableRef.value.focusArticleField(fakturaItems.value.length - 1)
                }
            })
        }
    }

    // Ersetzt den Artikel einer BESTEHENDEN Position in derselben Zeile.
    // Persistenz über die isolierte Einzel-Funktion (setzt parts_id NUR für
    // diese eine Position; saveAllItems aktualisiert danach Beträge/übrige
    // Felder per Bulk OHNE parts_id → kann keine andere Position beeinflussen).
    async function onArticleReplace(item, index, selectedArticle) {
        if (!selectedArticle || typeof selectedArticle === 'string') return

        item.description = selectedArticle.description
        fillArticleData(item, selectedArticle)

        suppressSSEReload?.()

        try {
            await ensureFakturaExists()
            if (!item.id) {
                const savedItem = await faktura.createFakturaItem(fakturaId.value, item, fakturaType.value)
                item.id = savedItem.id
            } else {
                await faktura.replaceFakturaItemArticle(item.id, item.parts_id, {
                    description: item.description,
                    qty: item.qty,
                    sellprice: item.sellprice,
                    unit: item.unit
                }, fakturaType.value)
            }
            calculateTotals()
            await saveAllItems()
            flushRouteReplace?.()
        } catch (e) {
            alerts.error(t('FakturaView.faktura.itemUpdateError'))
        }
    }

    function fillArticleData(item, selectedArticle) {
        item.parts_id = selectedArticle.id
        item.partnumber = selectedArticle.partnumber
        item.part_type = selectedArticle.part_type
        item.classification_id = selectedArticle.classification_id || null
        item.unit = selectedArticle.unit
        item.sellprice = selectedArticle.sellprice
        item.qty = selectedArticle.qty || 1

        if (typeof selectedArticle.buchungsziel === 'string') {
            try {
                item.buchungsziel = JSON.parse(selectedArticle.buchungsziel)
            } catch (e) {
                item.buchungsziel = null
            }
        } else {
            item.buchungsziel = selectedArticle.buchungsziel
        }

        calculateItemTotal(item)
    }

    return {
        deleteDialog,
        bulkDeleteDialog,
        deleteFakturaDialog,
        editDialog,
        createDialog,
        createEmptyItem,
        addNewItemRow,
        deleteItem,
        confirmDeleteItem,
        deleteSelectedItems,
        confirmBulkDeleteItems,
        deleteFaktura,
        confirmDeleteFaktura,
        setItemDiscount,
        setAllDiscounts,
        editArticle,
        onEditItemSave,
        createArticle,
        onCreateArticleSave,
        onArticleSearch,
        onArticleSelect,
        onArticleReplace,
        fillArticleData
    }
}
