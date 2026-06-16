<!-- src/core/views/faktura/cards/payment.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card mb-3">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-cash-multiple</v-icon>
            {{ t('FakturaView.faktura.payment') }}
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body pa-0">
            <v-table density="compact" class="payment-table">
                <thead>
                    <tr>
                        <th>{{ t('FakturaView.faktura.paymentDate') }}</th>
                        <th>{{ t('FakturaView.faktura.paymentSource') }}</th>
                        <th>{{ t('FakturaView.faktura.paymentMemo') }}</th>
                        <th>{{ t('FakturaView.faktura.paymentAccount') }}</th>
                        <th v-if="showExchangeRate">{{ t('FakturaView.faktura.paymentExchangeRate') }}</th>
                        <th class="text-right">{{ t('FakturaView.faktura.paymentAmount') }}</th>
                        <th class="text-center">{{ t('FakturaView.faktura.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(payment, index) in payments" :key="getPaymentKey(payment, index)">
                        <td>
                            <v-text-field
                                v-model="payment.transdate"
                                type="date"
                                :disabled="isBankBooked(payment) && !isBelegUnlocked(payment, index)"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @keydown.enter="onEnterKey(index)"
                                @blur="onPaymentChange"
                            />
                        </td>
                        <td>
                            <v-text-field
                                :ref="el => { if (el) sourceRefs[index] = el }"
                                v-model="payment.source"
                                :readonly="!isBelegUnlocked(payment, index)"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @keydown.enter="onEnterKey(index)"
                                @blur="onPaymentChange"
                            >
                                <template #append-inner>
                                    <v-icon
                                        size="small"
                                        :icon="isBelegUnlocked(payment, index) ? 'mdi-lock-open-variant' : 'mdi-lock'"
                                        :color="lockColor(payment, index)"
                                        style="cursor:pointer"
                                        :title="lockTitle(payment, index)"
                                        @click.stop="toggleBeleg(payment, index)"
                                    />
                                </template>
                            </v-text-field>
                        </td>
                        <td>
                            <v-text-field
                                v-model="payment.memo"
                                :disabled="isBankBooked(payment) && !isBelegUnlocked(payment, index)"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @keydown.enter="onEnterKey(index)"
                                @blur="onPaymentChange"
                            />
                        </td>
                        <td>
                            <v-autocomplete
                                :ref="el => { if (el) accountRefs[index] = el }"
                                v-model="payment.chart_id"
                                :disabled="isBankBooked(payment) && !isBelegUnlocked(payment, index)"
                                :items="paymentAccList"
                                item-title="label"
                                item-value="id"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @keydown.enter="onAccountEnter(payment, index, $event)"
                                @update:model-value="onAccountSelect(index)"
                            >
                                <template #item="{ props, item }">
                                    <v-list-item
                                        v-bind="props"
                                        :title="item.raw.label"
                                        :subtitle="item.raw.accno"
                                    />
                                </template>
                            </v-autocomplete>
                        </td>
                        <td v-if="showExchangeRate">
                            <v-text-field
                                :model-value="getFormattedExchangeRate(payment, index)"
                                :disabled="isBankBooked(payment) && !isBelegUnlocked(payment, index)"
                                @update:model-value="onExchangeRateInput(index, $event)"
                                @blur="onExchangeRateBlur(index)"
                                @keydown.enter="onExchangeRateBlur(index); onEnterKey(index)"
                                @focus="$event.target.select()"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                class="text-right-input"
                            />
                        </td>
                        <td>
                            <v-text-field
                                :ref="el => { if (el) amountRefs[index] = el }"
                                :disabled="isBankBooked(payment) && !isBelegUnlocked(payment, index)"
                                :model-value="getFormattedAmount(payment, index)"
                                @update:model-value="onAmountInput(index, $event)"
                                @blur="onAmountBlur(index)"
                                @keydown.enter.prevent="onAmountEnter(index)"
                                @focus="$event.target.select()"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                class="text-right-input"
                            />
                        </td>
                        <td class="text-center">
                            <div class="d-flex align-center justify-center ga-1">
                                <v-btn
                                    v-if="isLastRow(index) && remainingAmount > 0 && payment.chart_id"
                                    icon="mdi-cash-check"
                                    size="x-small"
                                    variant="tonal"
                                    color="success"
                                    :title="t('FakturaView.faktura.applyRemainingAmount', { amount: formatNumber(remainingAmount, currentLocale, 2) })"
                                    @click="applyRemainingAmount(index)"
                                />
                                <v-btn
                                    v-if="canDeletePayment(payment, index)"
                                    icon="mdi-delete"
                                    size="x-small"
                                    variant="text"
                                    color="error"
                                    :title="t('FakturaView.dialogs.deletePayment.title')"
                                    @click="openDeleteDialog(index)"
                                />
                                <v-btn
                                    v-if="isBankBooked(payment)"
                                    icon="mdi-bank-check"
                                    size="x-small"
                                    variant="text"
                                    color="info"
                                    :title="t('FakturaView.faktura.bankBookedGoto')"
                                    @click="goToBankTransaction(payment)"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="payment-table__footer">
                        <td :colspan="showExchangeRate ? 5 : 4" class="text-right font-weight-bold">
                            {{ t('FakturaView.faktura.remainingAmount') }}:
                        </td>
                        <td class="text-right font-weight-bold" :class="remainingAmount > 0 ? 'text-error' : 'text-success'">
                            {{ formatNumber(remainingAmount, currentLocale, 2) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </v-table>
        </v-card-text>
    </v-card>

    <!-- Delete Payment Dialog -->
    <v-dialog v-model="deleteDialog.show" max-width="400" @keydown.esc="deleteDialog.show = false">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-error text-white">
                <v-icon class="mr-2">mdi-delete-alert</v-icon>
                {{ t('FakturaView.dialogs.deletePayment.title') }}
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    density="compact"
                    size="small"
                    @click="deleteDialog.show = false"
                />
            </v-card-title>
            <v-card-text class="pt-4 pb-2">
                <p>{{ t('FakturaView.dialogs.deletePayment.text') }}</p>
                <p v-if="deleteDialog.payment" class="mt-2 font-weight-medium">
                    {{ formatNumber(Math.abs(deleteDialog.payment.amount || 0), currentLocale, 2) }}
                    <span v-if="deleteDialog.payment.transdate" class="text-medium-emphasis">
                        ({{ deleteDialog.payment.transdate }})
                    </span>
                </p>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="deleteDialog.show = false"
                >
                    {{ t('FakturaView.dialogs.deletePayment.cancel') }}
                </v-btn>
                <v-btn
                    color="error"
                    variant="elevated"
                    prepend-icon="mdi-delete"
                    @click="confirmDeletePayment"
                >
                    {{ t('FakturaView.dialogs.deletePayment.confirm') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
// src/core/views/faktura/cards/payment.section.card.vue

import { defineComponent, ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { formatNumber, parseNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'

export default defineComponent({
    name: 'PaymentSectionCard',
    props: {
        /**
         * Array mit Zahlungen
         */
        modelValue: {
            type: Array,
            required: true
        },
        /**
         * Liste der verfügbaren Zahlungskonten
         */
        paymentAccList: {
            type: Array,
            default: () => []
        },
        /**
         * Bruttobetrag der Rechnung
         */
        grossAmount: {
            type: Number,
            default: 0
        },
        /**
         * Zeigt Wechselkurs-Spalte an (Fremdwährung)
         */
        showExchangeRate: {
            type: Boolean,
            default: false
        },
        /**
         * Rechnungsnummer — wird beim Sprung ins Bankmodul als Suchfilter vorbelegt
         */
        invnumber: {
            type: String,
            default: ''
        }
    },
    emits: ['update:modelValue', 'save'],
    setup(props, { emit }) {
        const { t, locale } = useI18n()
        const router = useRouter()

        const currentLocale = computed(() => locale.value || 'de-DE')

        // Lokale Kopie der Zahlungen
        const payments = ref([])

        // Refs für Eingabefelder
        const accountRefs = ref({})
        const amountRefs = ref({})
        const sourceRefs = ref({})

        // Beleg-Feld: standardmäßig gesperrt (automatische Belegnummer).
        // Pro Zahlung (über getPaymentKey) merken, ob es manuell entsperrt wurde.
        const belegUnlockedKeys = ref({})

        // Eingabe-Cache für formatierte Zahlenfelder
        const editingAmounts = ref({})
        const editingExchangeRates = ref({})

        // Flag um blur während Focus-Wechsel zu unterdrücken
        const isNavigating = ref(false)

        // Delete Dialog State
        const deleteDialog = ref({
            show: false,
            payment: null,
            index: -1
        })

        // Flag um zu erkennen ob Änderung von innen kommt
        const isInternalChange = ref(false)

        /**
         * Generiert einen eindeutigen Key für eine Zahlung
         */
        function getPaymentKey(payment, index) {
            return payment.id || `new-${index}`
        }

        /**
         * Prüft ob die Zeile die letzte leere Zeile ist
         */
        function isLastEmptyRow(index) {
            if (index !== payments.value.length - 1) {
                return false
            }
            const payment = payments.value[index]
            return !payment.id && Math.abs(payment.amount || 0) === 0
        }

        /**
         * Prüft ob die Zeile die letzte Zeile ist
         */
        function isLastRow(index) {
            return index === payments.value.length - 1
        }

        /**
         * Prüft ob eine Zahlung aus einer noch gebuchten Bankzuordnung stammt.
         * Solche Zahlungen dürfen hier nicht gelöscht/umgebucht werden — der Storno
         * läuft über das Bankmodul, damit auch der Bankumsatz konsistent bleibt.
         */
        function isBankBooked(payment) {
            return payment.bank_booked === true
        }

        /**
         * Ist das Beleg-Feld dieser Zahlung manuell entsperrt? (Standard: gesperrt)
         */
        function isBelegUnlocked(payment, index) {
            return !!belegUnlockedKeys.value[getPaymentKey(payment, index)]
        }

        /**
         * Farbe des Schloss-Icons: entsperrt = primary, bank-gebucht & gesperrt = info,
         * sonst dezent.
         */
        function lockColor(payment, index) {
            if (isBelegUnlocked(payment, index)) return 'primary'
            return isBankBooked(payment) ? 'info' : 'medium-emphasis'
        }

        /**
         * Tooltip des Schloss-Icons je nach Zustand (bank-gebucht vs. normal).
         */
        function lockTitle(payment, index) {
            const unlocked = isBelegUnlocked(payment, index)
            if (isBankBooked(payment)) {
                return unlocked
                    ? t('FakturaView.faktura.bankBookedUnlocked')
                    : t('FakturaView.faktura.bankBookedEdit')
            }
            return unlocked
                ? t('FakturaView.faktura.belegLock')
                : t('FakturaView.faktura.belegEdit')
        }

        /**
         * Schloss umschalten — Editierschutz der Zahlungszeile.
         * Öffnen = Felder bearbeitbar (und Beleg-Feld fokussieren); Schließen = sperren.
         *
         * Bei bank-gebuchten Zahlungen erlaubt das Entsperren die direkte Bearbeitung des
         * bereits gebuchten Satzes. Das Backend aktualisiert dann beide acc_trans-Beine
         * in-place (Flag payment.bank_edit). Der Bankumsatz selbst bleibt unangetastet —
         * Betrag/Konto-Änderungen können also von der Bankabstimmung abweichen, daher die
         * Warnung.
         */
        function toggleBeleg(payment, index) {
            const key = getPaymentKey(payment, index)
            const nowUnlocked = !belegUnlockedKeys.value[key]
            belegUnlockedKeys.value = { ...belegUnlockedKeys.value, [key]: nowUnlocked }
            if (isBankBooked(payment)) {
                payment.bank_edit = nowUnlocked
                if (nowUnlocked) {
                    toasts.warning(t('FakturaView.faktura.bankBookedEditWarning'))
                }
            }
            if (nowUnlocked) {
                focusSourceField(index)
            }
        }

        /**
         * Automatische fortlaufende Belegnummer EINMALIG vom Server holen, sobald
         * ein Zahlungskonto gewählt wurde und das Beleg-Feld noch leer ist. Ein
         * bereits vorhandener Wert (automatisch oder manuell) wird nie überschrieben.
         *
         * @param {number} index Index der Zahlung
         */
        async function fetchBelegnummer(index) {
            const payment = payments.value[index]
            if (!payment || !payment.chart_id || isBankBooked(payment)) {
                return
            }
            // Nur ein leeres Feld befüllen — vorhandene Nummer bleibt unangetastet
            if (String(payment.source || '').trim() !== '') {
                return
            }
            try {
                const res = await axios.post('/api/banking/', {
                    action: 'getNextBelegnummer',
                    chart_id: payment.chart_id,
                    transdate: payment.transdate,
                })
                if (res.data?.success) {
                    payment.source = res.data.payload.belegnummer
                    onPaymentChange()
                }
            } catch (e) {
                // Belegnummer ist eine Komfortfunktion — Fehler still ignorieren
            }
        }

        /**
         * Springt ins Bankmodul und filtert dort auf genau diesen Bankumsatz,
         * damit die Buchung dort eingesehen/storniert werden kann.
         */
        function goToBankTransaction(payment) {
            if (!payment.bank_transaction_id) {
                return
            }
            router.push({
                name: 'banking-overview',
                query: {
                    tab: 'transactions',
                    account: payment.bank_account_id,
                    q: props.invnumber || ''
                }
            })
        }

        /**
         * Prüft ob eine Zahlung gelöscht werden kann
         * Löschbar wenn: Betrag > 0 ODER gespeicherte Zahlung (mit ID).
         * Nicht löschbar: bank-gebuchte Zahlungen (Storno nur über das Bankmodul).
         */
        function canDeletePayment(payment, index) {
            // Leere Eingabezeile ohne ID nicht löschbar
            if (!payment.id && Math.abs(payment.amount || 0) === 0) {
                return false
            }
            // Bank-gebuchte Zahlung — nur über das Bankmodul stornierbar
            if (isBankBooked(payment)) {
                return false
            }
            return true
        }

        /**
         * Berechnet den offenen Fehlbetrag
         */
        const remainingAmount = computed(() => {
            const gross = props.grossAmount || 0
            const paidAmount = payments.value.reduce((sum, payment, index) => {
                if (isLastEmptyRow(index) && Math.abs(payment.amount || 0) === 0) {
                    return sum
                }
                return sum + Math.abs(payment.amount || 0)
            }, 0)
            return Math.round((gross - paidAmount) * 100) / 100
        })

        /**
         * Erstellt eine leere Zahlungszeile
         */
        function createEmptyPayment() {
            return {
                id: null,
                transdate: new Date().toISOString().split('T')[0],
                source: '',
                memo: '',
                chart_id: null,
                amount: 0,
                exchangerate: 1
            }
        }

        /**
         * Stellt sicher, dass eine leere Zeile am Ende existiert (nur wenn noch offener Betrag)
         */
        function ensureEmptyRow() {
            const hasEmptyRow = payments.value.length > 0 && isLastEmptyRow(payments.value.length - 1)

            // Keine neue leere Zeile wenn bereits eine existiert
            if (hasEmptyRow) {
                return
            }

            // Neue leere Zeile nur wenn offener Betrag > 0 oder keine Zahlungen vorhanden
            if (payments.value.length === 0 || remainingAmount.value > 0) {
                payments.value.push(createEmptyPayment())
            }
        }

        // Initialisierung und Watch - nur auf externe Änderungen reagieren
        watch(() => props.modelValue, (newValue) => {
            // Bei internen Änderungen nicht überschreiben, aber leere Zeile sicherstellen
            if (isInternalChange.value) {
                isInternalChange.value = false
                ensureEmptyRow()
                return
            }
            payments.value = [...newValue]
            // Frische (extern geladene) Daten: alle Entsperrungen zurücksetzen, damit
            // bank-gebuchte Zeilen nach einem Speichern/Neuladen wieder gesperrt sind.
            belegUnlockedKeys.value = {}
            ensureEmptyRow()
        }, { immediate: true })

        // Watch auf grossAmount - wenn sich der Bruttobetrag ändert, leere Zeile prüfen
        watch(() => props.grossAmount, () => {
            ensureEmptyRow()
        })

        /**
         * Gibt den formatierten Betrag zurück
         */
        function getFormattedAmount(payment, index) {
            const key = getPaymentKey(payment, index)
            if (editingAmounts.value[key] !== undefined) {
                return editingAmounts.value[key]
            }
            return formatNumber(Math.abs(payment.amount || 0), currentLocale.value, 2)
        }

        /**
         * Speichert Eingabewert während der Bearbeitung
         */
        function onAmountInput(index, value) {
            const key = getPaymentKey(payments.value[index], index)
            editingAmounts.value[key] = value
        }

        /**
         * Parst und speichert den Betrag beim Verlassen des Feldes
         * Zeigt Fehlermeldung wenn kein Konto ausgewählt
         */
        function onAmountBlur(index) {
            // Während Navigation nicht verarbeiten
            if (isNavigating.value) {
                return
            }

            const payment = payments.value[index]
            const key = getPaymentKey(payment, index)
            const rawValue = editingAmounts.value[key]

            if (rawValue !== undefined) {
                const parsed = parseNumber(rawValue, currentLocale.value)
                if (parsed !== null && Number.isFinite(parsed) && Math.abs(parsed) > 0) {
                    // Kein Konto ausgewählt?
                    if (!payment.chart_id) {
                        toasts.warning(t('FakturaView.faktura.paymentNoAccount'))
                        // Eingabe bleibt im editingAmounts stehen
                        return
                    }
                    payment.amount = -Math.abs(parsed)
                }
                delete editingAmounts.value[key]
            }

            onPaymentChange()
        }

        /**
         * Handler für Enter im Betragsfeld
         * Zeigt Fehlermeldung wenn kein Konto ausgewählt
         */
        function onAmountEnter(index) {
            const payment = payments.value[index]
            const key = getPaymentKey(payment, index)
            const rawValue = editingAmounts.value[key]

            const isLast = index === payments.value.length - 1
            const hasChartId = !!payment.chart_id

            // Betrag parsen
            let parsedAmount = 0
            if (rawValue !== undefined) {
                const parsed = parseNumber(rawValue, currentLocale.value)
                if (parsed !== null && Number.isFinite(parsed)) {
                    parsedAmount = Math.abs(parsed)
                }
            }

            // Kein Konto ausgewählt aber Betrag eingegeben?
            if (!hasChartId && parsedAmount > 0) {
                toasts.warning(t('FakturaView.faktura.paymentNoAccount'))
                focusAccountField(index)
                return
            }

            // Betrag übernehmen
            if (parsedAmount > 0) {
                payment.amount = -parsedAmount
            }
            delete editingAmounts.value[key]

            isNavigating.value = true

            // Neue Zeile hinzufügen wenn Bedingungen erfüllt
            const shouldAddRow = isLast && parsedAmount > 0 && hasChartId && remainingAmount.value > 0

            if (shouldAddRow) {
                payments.value.push(createEmptyPayment())
            }

            onPaymentChange()

            if (shouldAddRow) {
                focusSourceField(payments.value.length - 1)
            }

            setTimeout(() => {
                isNavigating.value = false
            }, 200)
        }

        /**
         * Gibt den formatierten Wechselkurs zurück
         */
        function getFormattedExchangeRate(payment, index) {
            const key = getPaymentKey(payment, index)
            if (editingExchangeRates.value[key] !== undefined) {
                return editingExchangeRates.value[key]
            }
            return formatNumber(payment.exchangerate || 1, currentLocale.value, 5)
        }

        /**
         * Speichert Wechselkurs-Eingabewert während der Bearbeitung
         */
        function onExchangeRateInput(index, value) {
            const key = getPaymentKey(payments.value[index], index)
            editingExchangeRates.value[key] = value
        }

        /**
         * Parst und speichert den Wechselkurs beim Verlassen des Feldes
         */
        function onExchangeRateBlur(index) {
            const payment = payments.value[index]
            const key = getPaymentKey(payment, index)
            const rawValue = editingExchangeRates.value[key]

            if (rawValue !== undefined) {
                const parsed = parseNumber(rawValue, currentLocale.value)
                if (parsed !== null && Number.isFinite(parsed) && parsed > 0) {
                    payment.exchangerate = parsed
                }
                delete editingExchangeRates.value[key]
            }

            onPaymentChange()
        }

        /**
         * Setzt den Fehlbetrag als Zahlungsbetrag
         * Addiert zum vorhandenen Betrag wenn bereits einer gesetzt ist
         */
        function applyRemainingAmount(index) {
            const payment = payments.value[index]
            // Nur wenn Konto ausgewählt und offener Betrag > 0
            if (payment.chart_id && remainingAmount.value > 0) {
                // Aktuellen Betrag + Restbetrag
                const currentAmount = Math.abs(payment.amount || 0)
                payment.amount = -(currentAmount + remainingAmount.value)

                if (!payment.transdate) {
                    payment.transdate = new Date().toISOString().split('T')[0]
                }
                const key = getPaymentKey(payment, index)
                delete editingAmounts.value[key]
                onPaymentChange()
            }
        }

        /**
         * Öffnet den Lösch-Dialog
         */
        function openDeleteDialog(index) {
            deleteDialog.value = {
                show: true,
                payment: payments.value[index],
                index: index
            }
        }

        /**
         * Bestätigt das Löschen einer Zahlung
         */
        function confirmDeletePayment() {
            const index = deleteDialog.value.index
            deleteDialog.value.show = false
            payments.value.splice(index, 1)
            ensureEmptyRow()
            onPaymentChange(true)
        }

        /**
         * Handler für Enter-Taste
         * Fügt neue Zeile hinzu und setzt Focus auf Beleg-Feld
         */
        function onEnterKey(index) {
            const payment = payments.value[index]
            const isLast = index === payments.value.length - 1
            const hasAmount = Math.abs(payment.amount || 0) > 0
            const hasChartId = !!payment.chart_id

            // Neue Zeile nur wenn Bedingungen erfüllt
            if (isLast && hasAmount && hasChartId && remainingAmount.value > 0) {
                payments.value.push(createEmptyPayment())
                onPaymentChange()
                focusSourceField(payments.value.length - 1)
            }
        }

        /**
         * Handler für Enter-Taste im Zahlungskonto-Autocomplete
         * Wählt automatisch das einzige Konto aus wenn nur eines gefiltert ist
         * und setzt den Focus auf das Betragsfeld
         *
         * @param {Object} payment - Das Payment-Objekt
         * @param {number} index - Index der Zahlung
         * @param {KeyboardEvent} event - Das Keyboard-Event
         */
        function onAccountEnter(payment, index, event) {
            const autocomplete = accountRefs.value[index]

            // Wenn bereits ein Konto ausgewählt ist, zum Betragsfeld springen
            if (payment.chart_id) {
                event.preventDefault()
                event.stopPropagation()
                focusAmountField(index)
                return
            }

            if (!autocomplete) {
                return
            }

            // Hole den Suchtext aus dem Autocomplete
            const searchText = autocomplete.search || ''

            // Wenn kein Suchtext, nichts tun (Vuetify handled das)
            if (!searchText) {
                return
            }

            // Filtere die Liste basierend auf dem Suchtext
            const searchLower = searchText.toLowerCase()
            const filteredItems = props.paymentAccList.filter(item =>
                item.label?.toLowerCase().includes(searchLower) ||
                item.accno?.toLowerCase().includes(searchLower)
            )

            // Wenn genau ein Ergebnis, dieses auswählen und zum Betrag springen
            if (filteredItems.length === 1) {
                event.preventDefault()
                event.stopPropagation()
                payment.chart_id = filteredItems[0].id
                onPaymentChange()
                fetchBelegnummer(index)
                focusAmountField(index)
            }
            // Bei mehreren Ergebnissen: Vuetify macht das Menu-Handling
        }

        /**
         * Setzt den Focus auf das Betragsfeld
         *
         * @param {number} index - Index der Zahlung
         */
        function focusAmountField(index) {
            nextTick(() => {
                const amountField = amountRefs.value[index]
                if (amountField) {
                    const input = amountField.$el?.querySelector('input')
                    if (input) {
                        input.focus()
                        input.select()
                    }
                }
            })
        }

        /**
         * Setzt den Focus auf das Kontofeld
         *
         * @param {number} index - Index der Zahlung
         */
        function focusAccountField(index) {
            nextTick(() => {
                const accountField = accountRefs.value[index]
                if (accountField) {
                    const input = accountField.$el?.querySelector('input')
                    if (input) {
                        input.focus()
                    }
                }
            })
        }

        /**
         * Setzt den Focus auf das Source-Feld (Beleg)
         *
         * @param {number} index - Index der Zahlung
         */
        function focusSourceField(index) {
            nextTick(() => {
                nextTick(() => {
                    setTimeout(() => {
                        const input = sourceRefs.value[index]?.$el?.querySelector('input')
                        if (input) {
                            input.focus()
                        }
                    }, 100)
                })
            })
        }

        /**
         * Handler für Kontoauswahl - setzt Focus auf Betragsfeld
         *
         * @param {number} index - Index der Zahlung
         */
        function onAccountSelect(index) {
            onPaymentChange()
            fetchBelegnummer(index)
            focusAmountField(index)
        }

        /**
         * Emittiert Änderungen an Parent
         * Speichert nur wenn alle Zahlungen mit Betrag auch ein Konto haben
         */
        function onPaymentChange(forceSave = false) {
            ensureEmptyRow()

            // Flag setzen damit der Watcher nicht überschreibt
            isInternalChange.value = true
            emit('update:modelValue', payments.value)

            // Nur speichern wenn alle Zahlungen mit Betrag auch ein Konto haben
            const allValid = payments.value.every(payment => {
                const hasAmount = Math.abs(payment.amount || 0) > 0
                return !hasAmount || (hasAmount && payment.chart_id)
            })

            // Nur speichern wenn tatsächlich Zahlungen mit Betrag vorhanden sind,
            // oder explizit erzwungen (z.B. nach Löschen). Verhindert, dass ein
            // Blur auf leere Felder die bestehenden acc_trans-Einträge löscht.
            const hasActualPayments = payments.value.some(p => Math.abs(p.amount || 0) > 0)

            if (allValid && (hasActualPayments || forceSave === true)) {
                emit('save')
            }
        }

        return {
            t,
            currentLocale,
            payments,
            accountRefs,
            amountRefs,
            sourceRefs,
            remainingAmount,
            deleteDialog,
            showExchangeRate: computed(() => props.showExchangeRate),
            paymentAccList: computed(() => props.paymentAccList),
            formatNumber,
            getPaymentKey,
            isLastRow,
            canDeletePayment,
            isBankBooked,
            isBelegUnlocked,
            lockColor,
            lockTitle,
            toggleBeleg,
            goToBankTransaction,
            getFormattedAmount,
            onAmountInput,
            onAmountBlur,
            onAmountEnter,
            getFormattedExchangeRate,
            onExchangeRateInput,
            onExchangeRateBlur,
            applyRemainingAmount,
            openDeleteDialog,
            confirmDeletePayment,
            onEnterKey,
            onAccountEnter,
            onAccountSelect,
            onPaymentChange
        }
    }
})
</script>

<style scoped>
/* ============================================
   PAYMENT SECTION CARD
   ============================================ */

.faktura-card {
    border-radius: 8px;
    overflow: hidden;
}

.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #f5f5f5;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}

.faktura-card__body {
    padding: 0 !important;
}

/* Payment Table Styling */
.payment-table :deep(thead th) {
    padding: 12px 8px !important;
    font-weight: 600;
    font-size: 13px;
    color: #555;
    background-color: #fafafa;
    border-bottom: 1px solid #e0e0e0;
    white-space: nowrap;
}

.payment-table :deep(tbody td) {
    padding: 8px 6px !important;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.payment-table :deep(tbody tr:nth-child(even)) {
    background-color: #fafafa;
}

.payment-table :deep(tbody tr:hover) {
    background-color: #f0f7ff;
}

.payment-table__footer td {
    padding: 12px 8px !important;
    background-color: #f5f5f5;
    border-top: 2px solid #e0e0e0;
}

/* Rechtsbündige Zahlenfelder */
.text-right-input :deep(input) {
    text-align: right;
}

/* Vuetify Input optimieren */
.payment-table :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
}

.payment-table :deep(.v-input__details) {
    display: none !important;
}

.payment-table :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 10px;
    --v-field-padding-end: 10px;
}
</style>