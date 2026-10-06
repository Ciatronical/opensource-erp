<!-- src/core/views/recurring-invoices/components/recurring.config.dialog.vue -->
<!--
    Einrichtung einer wiederkehrenden Abrechnung zu einem Auftrag.

    Links die Einstellungen in der Reihenfolge, in der man darüber nachdenkt:
    Wie oft, wie lange, wieviel, welche Positionen, wie zugestellt. Rechts die
    Vorschau, die bei jeder Änderung aus der Datenbank neu rechnet — mit genau
    den Funktionen, die später auch die Rechnungen erzeugen. Was in der
    Vorschau steht, wird so berechnet. Ein Satz oben fasst die Einstellung
    zusammen; wer ihn liest und nickt, kann speichern.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="1360" scrollable persistent @update:model-value="close">
        <v-card v-if="modelValue" class="recurring-dialog">
            <v-card-title class="d-flex align-center ga-3 py-3">
                <v-icon color="primary">mdi-autorenew</v-icon>
                <div class="flex-grow-1">
                    <div class="text-h6">{{ t('RecurringInvoices.dialog.title') }}</div>
                    <div v-if="order" class="text-body-2 text-medium-emphasis">
                        {{ t('RecurringInvoices.dialog.orderLine', { ordnumber: order.ordnumber, customer: order.customer_name }) }}
                        <span v-if="order.transaction_description"> · {{ order.transaction_description }}</span>
                    </div>
                </div>
                <v-chip v-if="existing" size="small" :color="statusColor(config.status)" variant="flat" label>
                    {{ t('RecurringInvoices.status.' + (config.status || 'active')) }}
                </v-chip>
                <v-btn icon variant="text" @click="close"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-divider />

            <v-card-text class="pa-0">
                <div v-if="loading && !order" class="pa-6">
                    <v-skeleton-loader type="article, article" />
                </div>

                <v-alert v-else-if="loadError" type="error" variant="tonal" class="ma-4">{{ loadError }}</v-alert>

                <div v-else-if="order" class="recurring-dialog__grid">
                    <!-- ── Einstellungen ───────────────────────────────────────── -->
                    <div class="recurring-dialog__form pa-4">
                        <v-alert v-if="!order.customer_id" type="warning" variant="tonal" density="compact" class="mb-4"
                                 :text="t('RecurringInvoices.dialog.noCustomer')" />

                        <!-- Zusammenfassung -->
                        <v-alert variant="tonal" color="primary" density="compact" icon="mdi-text-short" class="mb-4 summary-alert">
                            {{ summarySentence }}
                        </v-alert>

                        <!-- 1. Rhythmus -->
                        <section class="mb-5">
                            <h3 class="section-title"><span class="step">1</span>{{ t('RecurringInvoices.dialog.rhythm') }}</h3>
                            <v-chip-group v-model="preset" mandatory selected-class="text-primary" column class="mb-1">
                                <v-chip v-for="p in presets" :key="p.key" :value="p.key" filter variant="outlined" size="small">
                                    {{ t('RecurringInvoices.rhythm.' + p.key) }}
                                </v-chip>
                                <v-chip value="custom" filter variant="outlined" size="small">{{ t('RecurringInvoices.rhythm.custom') }}</v-chip>
                            </v-chip-group>

                            <v-row v-if="preset === 'custom'" dense class="mb-1">
                                <v-col cols="6" sm="3">
                                    <v-text-field v-model.number="config.interval_count" type="number" min="1" density="compact" variant="outlined" hide-details
                                                  :label="t('RecurringInvoices.dialog.every')" />
                                </v-col>
                                <v-col cols="6" sm="4">
                                    <v-select v-model="config.interval_unit" :items="unitItems" density="compact" variant="outlined" hide-details
                                              :label="t('RecurringInvoices.dialog.unit')" />
                                </v-col>
                            </v-row>

                            <template v-if="config.interval_unit !== 'once'">
                                <v-row dense>
                                    <v-col cols="12" sm="6">
                                        <v-switch v-model="config.align_to_calendar" color="primary" density="compact" hide-details inset
                                                  :disabled="config.interval_unit === 'day'"
                                                  :label="t('RecurringInvoices.dialog.alignToCalendar.' + config.interval_unit)" />
                                        <div class="hint">{{ t('RecurringInvoices.dialog.alignHint') }}</div>
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <v-switch v-model="config.prorate_partial" color="primary" density="compact" hide-details inset
                                                  :label="t('RecurringInvoices.dialog.proratePartial')" />
                                        <div class="hint">{{ t('RecurringInvoices.dialog.prorateHint') }}</div>
                                    </v-col>
                                </v-row>
                                <v-row dense class="mt-1">
                                    <v-col cols="12" sm="7">
                                        <v-btn-toggle v-model="config.billing_timing" mandatory density="compact" color="primary" variant="outlined" divided>
                                            <v-btn value="advance" size="small" class="text-none">
                                                <v-icon start size="small">mdi-ray-start-arrow</v-icon>{{ t('RecurringInvoices.dialog.advance') }}
                                            </v-btn>
                                            <v-btn value="arrears" size="small" class="text-none">
                                                <v-icon start size="small">mdi-ray-end-arrow</v-icon>{{ t('RecurringInvoices.dialog.arrears') }}
                                            </v-btn>
                                        </v-btn-toggle>
                                        <div class="hint">{{ t('RecurringInvoices.dialog.timingHint.' + config.billing_timing) }}</div>
                                    </v-col>
                                    <v-col cols="12" sm="5">
                                        <v-text-field v-model.number="config.billing_offset_days" type="number" density="compact" variant="outlined" hide-details
                                                      :label="t('RecurringInvoices.dialog.offsetDays')" :suffix="t('RecurringInvoices.units.day', 2)" />
                                        <div class="hint">{{ t('RecurringInvoices.dialog.offsetHint') }}</div>
                                    </v-col>
                                </v-row>
                            </template>
                        </section>

                        <!-- 2. Laufzeit -->
                        <section class="mb-5">
                            <h3 class="section-title"><span class="step">2</span>{{ t('RecurringInvoices.dialog.term') }}</h3>
                            <v-row dense>
                                <v-col cols="12" sm="4">
                                    <v-text-field v-model="config.start_date" type="date" density="compact" variant="outlined" hide-details
                                                  :label="t('RecurringInvoices.dialog.startDate')" :error="!config.start_date" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-text-field v-model="config.end_date" type="date" density="compact" variant="outlined" hide-details clearable
                                                  :label="t('RecurringInvoices.dialog.endDate')"
                                                  :placeholder="t('RecurringInvoices.dialog.openEnded')" persistent-placeholder
                                                  :error="!!config.end_date && config.end_date < config.start_date" />
                                </v-col>
                                <v-col cols="12" sm="4" v-if="config.interval_unit !== 'once'">
                                    <v-text-field v-model.number="config.extend_automatically_by" type="number" min="0" density="compact" variant="outlined" hide-details clearable
                                                  :label="t('RecurringInvoices.dialog.extendBy')" :suffix="t('RecurringInvoices.units.month', 2)"
                                                  :disabled="!config.end_date" />
                                </v-col>
                            </v-row>
                            <v-row dense v-if="config.interval_unit !== 'once'" class="mt-1">
                                <v-col cols="12" sm="4">
                                    <v-text-field v-model.number="config.min_term_months" type="number" min="0" density="compact" variant="outlined" hide-details clearable
                                                  :label="t('RecurringInvoices.dialog.minTerm')" :suffix="t('RecurringInvoices.units.month', 2)" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-text-field v-model.number="config.notice_period_months" type="number" min="0" density="compact" variant="outlined" hide-details clearable
                                                  :label="t('RecurringInvoices.dialog.noticePeriod')" :suffix="t('RecurringInvoices.units.month', 2)" />
                                </v-col>
                                <v-col cols="12" sm="4" class="d-flex align-center">
                                    <div class="hint mt-0" v-if="config.end_date && config.extend_automatically_by">
                                        {{ t('RecurringInvoices.dialog.extendHint', { months: config.extend_automatically_by }) }}
                                    </div>
                                    <div class="hint mt-0" v-else-if="!config.end_date">{{ t('RecurringInvoices.dialog.openEndedHint') }}</div>
                                </v-col>
                            </v-row>
                        </section>

                        <!-- 3. Beträge & Preise -->
                        <section class="mb-5">
                            <h3 class="section-title"><span class="step">3</span>{{ t('RecurringInvoices.dialog.prices') }}</h3>
                            <v-row dense>
                                <v-col cols="12" sm="6">
                                    <v-select v-model="config.order_value_periodicity" :items="valuePeriodicityItems" density="compact" variant="outlined" hide-details
                                              :label="t('RecurringInvoices.dialog.orderValueFor')" />
                                    <div class="hint">{{ t('RecurringInvoices.dialog.orderValueHint') }}</div>
                                </v-col>
                                <v-col cols="12" sm="6">
                                    <v-btn-toggle v-model="config.price_mode" mandatory density="compact" color="primary" variant="outlined" divided class="w-100">
                                        <v-btn value="fixed" size="small" class="text-none flex-grow-1">{{ t('RecurringInvoices.dialog.priceFixed') }}</v-btn>
                                        <v-btn value="current" size="small" class="text-none flex-grow-1">{{ t('RecurringInvoices.dialog.priceCurrent') }}</v-btn>
                                    </v-btn-toggle>
                                    <div class="hint">{{ t('RecurringInvoices.dialog.priceModeHint.' + config.price_mode) }}</div>
                                </v-col>
                            </v-row>
                            <v-row dense class="mt-1" v-if="config.interval_unit !== 'once'">
                                <v-col cols="6" sm="3">
                                    <v-text-field v-model="config.price_increase_percent" type="number" step="0.1" density="compact" variant="outlined" hide-details clearable
                                                  :label="t('RecurringInvoices.dialog.priceIncrease')" suffix="%" />
                                </v-col>
                                <v-col cols="6" sm="4">
                                    <v-select v-model="config.price_increase_month" :items="monthItems" density="compact" variant="outlined" hide-details
                                              :disabled="!config.price_increase_percent" :label="t('RecurringInvoices.dialog.priceIncreaseMonth')" />
                                </v-col>
                                <v-col cols="12" sm="5" class="d-flex align-center">
                                    <div class="hint mt-0">{{ t('RecurringInvoices.dialog.priceIncreaseHint') }}</div>
                                </v-col>
                            </v-row>
                        </section>

                        <!-- 4. Positionen -->
                        <section class="mb-5">
                            <h3 class="section-title"><span class="step">4</span>{{ t('RecurringInvoices.dialog.items') }}</h3>
                            <v-alert v-if="!items.length" type="warning" variant="tonal" density="compact" :text="t('RecurringInvoices.dialog.noItems')" />
                            <v-table v-else density="compact" class="items-table">
                                <thead>
                                    <tr>
                                        <th>{{ t('RecurringInvoices.dialog.itemDescription') }}</th>
                                        <th class="text-right">{{ t('RecurringInvoices.dialog.itemTotal') }}</th>
                                        <th class="text-right">{{ t('RecurringInvoices.dialog.itemMode') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="it in items" :key="it.id" :class="{ 'text-disabled': it.recurring_billing_mode === 'never' }">
                                        <td>
                                            <div class="text-body-2">{{ it.description }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                {{ formatQty(it.qty) }} {{ it.unit }} × {{ money(it.sellprice, locale) }}
                                                <span v-if="config.price_mode === 'current' && Number(it.current_price) !== Number(it.sellprice)">
                                                    → {{ money(it.current_price, locale) }}
                                                </span>
                                                <span v-if="it.billed_invnumber"> · {{ t('RecurringInvoices.dialog.itemBilled', { invnumber: it.billed_invnumber }) }}</span>
                                            </div>
                                        </td>
                                        <td class="text-right text-no-wrap">{{ money(it.line_total, locale) }}</td>
                                        <td class="text-right">
                                            <v-btn-toggle v-model="it.recurring_billing_mode" mandatory density="compact" variant="outlined" color="primary" divided>
                                                <v-btn value="always" size="x-small" class="text-none">{{ t('RecurringInvoices.itemMode.always') }}</v-btn>
                                                <v-btn value="once" size="x-small" class="text-none">{{ t('RecurringInvoices.itemMode.once') }}</v-btn>
                                                <v-btn value="never" size="x-small" class="text-none">{{ t('RecurringInvoices.itemMode.never') }}</v-btn>
                                            </v-btn-toggle>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div class="hint">
                                {{ t('RecurringInvoices.dialog.placeholderHint') }}
                                <v-chip v-for="ph in placeholderChips" :key="ph" size="x-small" variant="tonal" class="ma-1 font-mono"
                                        @click="copyPlaceholder(ph)">&lt;%{{ ph }}%&gt;</v-chip>
                            </div>
                        </section>

                        <!-- 5. Zustellung & Buchung -->
                        <section class="mb-5">
                            <h3 class="section-title"><span class="step">5</span>{{ t('RecurringInvoices.dialog.delivery') }}</h3>
                            <v-row dense>
                                <v-col cols="12" sm="6">
                                    <v-switch v-model="config.send_email" color="primary" density="compact" hide-details inset
                                              :disabled="!defaults.email_configured || !!order.postal_invoice"
                                              :label="t('RecurringInvoices.dialog.sendEmail')" />
                                    <div class="hint" v-if="!defaults.email_configured">{{ t('RecurringInvoices.dialog.emailNotConfigured') }}</div>
                                    <div class="hint" v-else-if="order.postal_invoice">{{ t('RecurringInvoices.dialog.postalInvoice') }}</div>
                                </v-col>
                                <v-col cols="12" sm="6">
                                    <v-switch v-model="config.post_to_ledger" color="primary" density="compact" hide-details inset
                                              :label="t('RecurringInvoices.dialog.postToLedger')" />
                                    <v-switch v-model="config.direct_debit" color="primary" density="compact" hide-details inset
                                              :label="t('RecurringInvoices.dialog.directDebit')" />
                                </v-col>
                            </v-row>

                            <v-expand-transition>
                                <div v-if="config.send_email" class="mt-2">
                                    <v-row dense>
                                        <v-col cols="12" sm="6">
                                            <v-select v-model="config.email_recipient_contact_id" :items="contactItems" density="compact" variant="outlined" hide-details clearable
                                                      :label="t('RecurringInvoices.dialog.emailContact')" />
                                        </v-col>
                                        <v-col cols="12" sm="6">
                                            <v-text-field v-model="config.email_recipient_address" density="compact" variant="outlined" hide-details
                                                          :label="t('RecurringInvoices.dialog.emailRecipients')"
                                                          :placeholder="order.invoice_mail || order.customer_email || ''" persistent-placeholder />
                                        </v-col>
                                    </v-row>
                                    <div class="hint">{{ t('RecurringInvoices.dialog.emailRecipientsHint', { mail: order.invoice_mail || order.customer_email || '–' }) }}</div>
                                    <v-row dense class="mt-1">
                                        <v-col cols="12" sm="6">
                                            <v-text-field v-model="config.email_sender" density="compact" variant="outlined" hide-details
                                                          :label="t('RecurringInvoices.dialog.emailSender')" :placeholder="defaults.email_from || ''" persistent-placeholder />
                                        </v-col>
                                        <v-col cols="12" sm="6">
                                            <v-text-field v-model="config.email_subject" density="compact" variant="outlined" hide-details
                                                          :label="t('RecurringInvoices.dialog.emailSubject')" :placeholder="defaults.email_subject || ''" persistent-placeholder />
                                        </v-col>
                                    </v-row>
                                    <div class="mt-2">
                                        <HtmlEditorComponent v-model="config.email_body" :placeholder="defaults.email_body || ''" :min-height="140" />
                                    </div>
                                    <div class="hint">
                                        {{ t('RecurringInvoices.dialog.emailPlaceholderHint') }}
                                        <v-chip v-for="ph in emailPlaceholders" :key="ph" size="x-small" variant="tonal" class="ma-1 font-mono"
                                                @click="copyPlaceholder(ph)">&lt;%{{ ph }}%&gt;</v-chip>
                                    </div>
                                </div>
                            </v-expand-transition>

                            <!-- WhatsApp: Rechnung als Dokument-Template an die Mobilnummer -->
                            <v-row dense class="mt-2">
                                <v-col cols="12" sm="6">
                                    <v-switch v-model="config.send_whatsapp" color="primary" density="compact" hide-details inset
                                              :disabled="!defaults.whatsapp_configured"
                                              :label="t('RecurringInvoices.dialog.sendWhatsapp')" />
                                    <div class="hint" v-if="!defaults.whatsapp_configured">{{ t('RecurringInvoices.dialog.whatsappNotConfigured') }}</div>
                                    <div class="hint" v-else-if="config.send_whatsapp && !phones.length && !config.whatsapp_phone">{{ t('RecurringInvoices.dialog.whatsappNoPhone') }}</div>
                                </v-col>
                            </v-row>
                            <v-expand-transition>
                                <div v-if="config.send_whatsapp" class="mt-2">
                                    <v-row dense>
                                        <v-col cols="12" sm="6">
                                            <v-combobox v-model="config.whatsapp_phone" :items="phoneItems" density="compact" variant="outlined" hide-details clearable
                                                        :label="t('RecurringInvoices.dialog.whatsappPhone')"
                                                        :placeholder="phones[0]?.number || ''" persistent-placeholder />
                                            <div class="hint">{{ t('RecurringInvoices.dialog.whatsappPhoneHint') }}</div>
                                        </v-col>
                                        <v-col cols="12" sm="6">
                                            <v-select v-model="config.whatsapp_template_id" :items="defaults.whatsapp_templates || []" item-title="name" item-value="id"
                                                      density="compact" variant="outlined" hide-details clearable
                                                      :label="t('RecurringInvoices.dialog.whatsappTemplate')"
                                                      :placeholder="defaultWhatsappTemplateName" persistent-placeholder />
                                            <div class="hint">{{ t('RecurringInvoices.dialog.whatsappTemplateHint') }}</div>
                                        </v-col>
                                    </v-row>
                                </div>
                            </v-expand-transition>

                            <v-row dense class="mt-2" v-if="printers.length">
                                <v-col cols="12" sm="4">
                                    <v-switch v-model="config.print" color="primary" density="compact" hide-details inset :label="t('RecurringInvoices.dialog.print')" />
                                </v-col>
                                <v-col cols="8" sm="5" v-if="config.print">
                                    <v-select v-model="config.printer_id" :items="printers" item-title="description" item-value="id" density="compact" variant="outlined" hide-details
                                              :label="t('RecurringInvoices.dialog.printer')" />
                                </v-col>
                                <v-col cols="4" sm="3" v-if="config.print">
                                    <v-text-field v-model.number="config.copies" type="number" min="1" density="compact" variant="outlined" hide-details
                                                  :label="t('RecurringInvoices.dialog.copies')" />
                                </v-col>
                            </v-row>
                        </section>

                        <!-- 6. Schutz & Notizen -->
                        <section class="mb-2">
                            <h3 class="section-title"><span class="step">6</span>{{ t('RecurringInvoices.dialog.safety') }}</h3>
                            <v-row dense>
                                <v-col cols="12" sm="6">
                                    <v-switch :model-value="config.hold_on_overdue_days !== null && config.hold_on_overdue_days !== ''" color="primary" density="compact" hide-details inset
                                              :label="t('RecurringInvoices.dialog.holdOnOverdue')"
                                              @update:model-value="v => config.hold_on_overdue_days = v ? 14 : null" />
                                    <v-text-field v-if="config.hold_on_overdue_days !== null && config.hold_on_overdue_days !== ''" v-model.number="config.hold_on_overdue_days" type="number" min="0"
                                                  density="compact" variant="outlined" hide-details class="mt-2" style="max-width: 220px"
                                                  :label="t('RecurringInvoices.dialog.holdDays')" :suffix="t('RecurringInvoices.units.day', 2)" />
                                    <div class="hint">{{ t('RecurringInvoices.dialog.holdHint') }}</div>
                                </v-col>
                                <v-col cols="12" sm="6">
                                    <v-textarea v-model="config.notes" density="compact" variant="outlined" hide-details rows="3" auto-grow
                                                :label="t('RecurringInvoices.dialog.notes')" />
                                </v-col>
                            </v-row>
                        </section>
                    </div>

                    <!-- ── Vorschau ─────────────────────────────────────────────── -->
                    <aside class="recurring-dialog__preview pa-4">
                        <div class="d-flex align-center mb-2">
                            <v-icon size="small" class="mr-2" color="primary">mdi-eye-outline</v-icon>
                            <span class="text-subtitle-2">{{ t('RecurringInvoices.dialog.preview') }}</span>
                            <v-progress-circular v-if="previewLoading" indeterminate size="14" width="2" class="ml-2" />
                        </div>

                        <div v-if="previewData" class="preview-totals mb-3">
                            <div class="preview-total">
                                <div class="preview-total__label">{{ t('RecurringInvoices.dialog.perPeriod') }}</div>
                                <div class="preview-total__value">{{ money(previewData.period_amount, locale) }}</div>
                            </div>
                            <div class="preview-total" v-if="config.interval_unit !== 'once'">
                                <div class="preview-total__label">{{ t('RecurringInvoices.dialog.perMonth') }}</div>
                                <div class="preview-total__value">{{ money(previewData.monthly_amount, locale) }}</div>
                            </div>
                            <div class="preview-total" v-if="Number(previewData.once_open_total) > 0">
                                <div class="preview-total__label">{{ t('RecurringInvoices.dialog.onceTotal') }}</div>
                                <div class="preview-total__value">{{ money(previewData.once_open_total, locale) }}</div>
                            </div>
                        </div>
                        <div class="text-caption text-medium-emphasis mb-2" v-if="previewData">
                            {{ previewData.taxincluded ? t('RecurringInvoices.dialog.grossAmounts') : t('RecurringInvoices.dialog.netAmounts') }}
                        </div>

                        <v-alert v-if="previewError" type="error" variant="tonal" density="compact" class="mb-2">{{ previewError }}</v-alert>

                        <v-table v-if="previewData?.periods?.length" density="compact" class="preview-table">
                            <thead>
                                <tr>
                                    <th class="text-no-wrap">{{ t('RecurringInvoices.dialog.period') }}</th>
                                    <th class="text-no-wrap">{{ t('RecurringInvoices.dialog.invoiceDate') }}</th>
                                    <th class="text-right text-no-wrap">{{ t('RecurringInvoices.dialog.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in previewData.periods" :key="p.n" :class="{ 'row-created': p.state === 'created', 'row-skipped': p.state === 'skipped' }">
                                    <td class="text-no-wrap">
                                        <span class="text-medium-emphasis mr-1">{{ p.n }}.</span>{{ formatDate(p.period_start, locale) }} – {{ formatDate(p.period_end, locale) }}
                                        <v-chip v-if="p.is_partial" size="x-small" variant="tonal" color="info" class="ml-1">{{ t('RecurringInvoices.dialog.partial', { percent: Math.round(p.factor * 100) }) }}</v-chip>
                                    </td>
                                    <td class="text-no-wrap">
                                        <div>{{ formatDate(p.billing_date, locale) }}</div>
                                        <v-chip v-if="p.state === 'due'" size="x-small" color="error" variant="flat">{{ t('RecurringInvoices.state.due') }}</v-chip>
                                        <v-chip v-else-if="p.state === 'created'" size="x-small" color="success" variant="tonal" prepend-icon="mdi-check"
                                                @click="openInvoice(p.ar_id)">{{ p.invnumber }}</v-chip>
                                        <v-chip v-else-if="p.state === 'skipped'" size="x-small" variant="tonal">{{ t('RecurringInvoices.state.skipped') }}</v-chip>
                                    </td>
                                    <td class="text-right text-no-wrap">
                                        <span v-if="p.state === 'created'">{{ money(p.ar_amount, locale) }}</span>
                                        <span v-else>{{ money(p.amount, locale) }}</span>
                                        <v-icon v-if="p.index_factor && Number(p.index_factor) > 1" size="x-small" color="info" class="ml-1"
                                                :title="t('RecurringInvoices.dialog.indexed', { percent: ((p.index_factor - 1) * 100).toFixed(1) })">mdi-trending-up</v-icon>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                        <div v-else-if="previewData && !previewLoading" class="text-body-2 text-medium-emphasis">{{ t('RecurringInvoices.dialog.noPeriods') }}</div>

                        <div v-if="previewData?.sample" class="sample mt-3">
                            <div class="text-caption text-medium-emphasis mb-1">{{ t('RecurringInvoices.dialog.sampleTitle') }}</div>
                            <div class="sample__text">{{ previewData.sample }}</div>
                        </div>

                        <div v-if="existing && stats" class="mt-4 text-body-2">
                            <v-divider class="mb-2" />
                            <div class="d-flex justify-space-between"><span>{{ t('RecurringInvoices.dialog.statsInvoices') }}</span><strong>{{ stats.invoice_count }}</strong></div>
                            <div class="d-flex justify-space-between"><span>{{ t('RecurringInvoices.dialog.statsInvoiced') }}</span><strong>{{ money(stats.invoiced_total, locale) }}</strong></div>
                            <div class="d-flex justify-space-between" :class="{ 'text-error': Number(stats.open_total) > 0 }">
                                <span>{{ t('RecurringInvoices.dialog.statsOpen') }}</span><strong>{{ money(stats.open_total, locale) }}</strong>
                            </div>
                        </div>
                    </aside>
                </div>
            </v-card-text>

            <v-divider />
            <v-card-actions class="px-4 py-3">
                <v-btn v-if="existing" color="error" variant="text" class="text-none" :disabled="saving" @click="remove">
                    <v-icon start>mdi-delete-outline</v-icon>{{ t('RecurringInvoices.dialog.delete') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" class="text-none" :disabled="saving" @click="close">{{ t('RecurringInvoices.dialog.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" class="text-none" :loading="saving" :disabled="!canSave" @click="save">
                    <v-icon start>mdi-content-save-outline</v-icon>{{ t('RecurringInvoices.dialog.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'
import { formatDate } from '@/core/utils/dateFormatter.js'
import { entityRoute } from '@/core/constants/routes.js'
import { useRecurringInvoices, RHYTHM_PRESETS, PLACEHOLDERS, EMAIL_PLACEHOLDERS, rhythmText, money } from '../composables/useRecurringInvoices.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    oeId: { type: Number, default: null }
})
const emit = defineEmits(['update:modelValue', 'saved', 'deleted'])

const { t, locale } = useI18n()
const router = useRouter()
const api = useRecurringInvoices()

const loading = ref(false)
const loadError = ref('')
const saving = ref(false)
const order = ref(null)
const existing = ref(false)
const items = ref([])
const contacts = ref([])
const printers = ref([])
const phones = ref([])   // Rufnummern des Kunden (Mobil zuerst) fuer den WhatsApp-Versand
const phoneItems = computed(() => phones.value.map(p => ({ value: p.number, title: p.label ? `${p.number} · ${p.label}` : p.number })))
const defaultWhatsappTemplateName = computed(() => {
    const id = Number(defaults.value.whatsapp_template_id || 0)
    return (defaults.value.whatsapp_templates || []).find(x => x.id === id)?.name || ''
})
const defaults = ref({})
const stats = ref(null)
const savedPeriods = ref([])

const presets = RHYTHM_PRESETS
const placeholderChips = PLACEHOLDERS.slice(0, 8)
const emailPlaceholders = EMAIL_PLACEHOLDERS

const emptyConfig = () => ({
    id: null, oe_id: props.oeId, status: 'active', active: true, terminated: false,
    interval_unit: 'month', interval_count: 1, align_to_calendar: true, prorate_partial: true,
    billing_timing: 'advance', billing_offset_days: 0,
    start_date: new Date().toISOString().slice(0, 10), end_date: null, extend_automatically_by: null,
    min_term_months: null, notice_period_months: null, paused_until: null,
    order_value_periodicity: 'p', price_mode: 'fixed', price_increase_percent: null, price_increase_month: null,
    send_email: false, email_recipient_contact_id: null, email_recipient_address: '', email_sender: '', email_subject: '', email_body: '',
    direct_debit: false, post_to_ledger: true, print: false, printer_id: null, copies: 1, ar_chart_id: null,
    send_whatsapp: false, whatsapp_phone: '', whatsapp_template_id: null,
    hold_on_overdue_days: null, notes: ''
})
const config = reactive(emptyConfig())

// Rhythmus-Chip ↔ Einheit/Anzahl
const preset = computed({
    get() {
        const p = presets.find(p => p.unit === config.interval_unit && p.count === Number(config.interval_count))
        return p ? p.key : 'custom'
    },
    set(key) {
        const p = presets.find(p => p.key === key)
        if (p) { config.interval_unit = p.unit; config.interval_count = p.count }
        else if (config.interval_unit === 'once') { config.interval_unit = 'month'; config.interval_count = 2 }
    }
})

const unitItems = computed(() => ['day', 'week', 'month', 'year'].map(u => ({ value: u, title: t(`RecurringInvoices.units.${u}`, 2) })))
const valuePeriodicityItems = computed(() => ['p', 'm', 'q', 'b', 'y', '2', '3', '4', '5'].map(v => ({ value: v, title: t(`RecurringInvoices.valuePeriodicity.${v}`) })))
const monthItems = computed(() => Array.from({ length: 12 }, (_, i) => ({
    value: i + 1,
    title: new Date(2026, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-GB' : 'de-DE', { month: 'long' })
})))
const contactItems = computed(() => contacts.value.map(c => ({ value: c.cp_id, title: c.email ? `${c.name} <${c.email}>` : c.name })))

const canSave = computed(() => !!config.start_date && !!order.value?.customer_id && items.value.length > 0
    && (!config.end_date || config.end_date >= config.start_date))

const summarySentence = computed(() => {
    const parts = [rhythmText(t, config.interval_unit, config.interval_count)]
    if (config.interval_unit !== 'once') {
        if (config.align_to_calendar) parts.push(t('RecurringInvoices.summary.aligned.' + config.interval_unit))
        parts.push(t('RecurringInvoices.summary.' + config.billing_timing))
    }
    let s = parts.join(', ')
    s += ' · ' + t('RecurringInvoices.summary.from', { date: formatDate(config.start_date, locale.value) })
    if (config.end_date) {
        s += ' ' + t('RecurringInvoices.summary.until', { date: formatDate(config.end_date, locale.value) })
        if (config.extend_automatically_by) s += ' ' + t('RecurringInvoices.summary.extends', { months: config.extend_automatically_by })
    } else if (config.interval_unit !== 'once') {
        s += ' ' + t('RecurringInvoices.summary.openEnded')
    }
    if (previewData.value?.periods?.[0]) {
        s += ' · ' + t('RecurringInvoices.summary.firstInvoice', { date: formatDate(previewData.value.periods[0].billing_date, locale.value) })
    }
    return s
})

// ── Laden ──────────────────────────────────────────────────────────────────

async function load() {
    loading.value = true
    loadError.value = ''
    try {
        const r = await api.fetchConfig(props.oeId)
        order.value = r.order
        existing.value = !!r.exists
        items.value = (r.items || []).map(i => ({ ...i }))
        contacts.value = r.contacts || []
        phones.value = r.phones || []
        printers.value = r.printers || []
        defaults.value = r.defaults || {}
        stats.value = r.stats || null
        savedPeriods.value = r.periods || []
        Object.assign(config, emptyConfig(), r.exists ? normalizeConfig(r.config) : { start_date: r.order.transdate || emptyConfig().start_date })
        config.oe_id = r.order.id
        if (r.exists && r.config.email_recipient_contact_id == null && !r.config.email_recipient_address && r.order.cp_id) {
            config.email_recipient_contact_id = r.order.cp_id
        }
        if (!r.exists && r.order.cp_id) config.email_recipient_contact_id = r.order.cp_id
        await refreshPreview()
    } catch (e) {
        loadError.value = e.message
    } finally {
        loading.value = false
    }
}

function normalizeConfig(c) {
    return {
        ...c,
        interval_count: Number(c.interval_count) || 1,
        billing_offset_days: Number(c.billing_offset_days) || 0,
        extend_automatically_by: c.extend_automatically_by ?? null,
        price_increase_percent: c.price_increase_percent != null ? Number(c.price_increase_percent) : null,
        email_recipient_address: c.email_recipient_address || '',
        email_sender: c.email_sender || '',
        email_subject: c.email_subject || '',
        email_body: c.email_body || '',
        copies: Number(c.copies) || 1,
        send_whatsapp: c.send_whatsapp === true || c.send_whatsapp === 't',
        whatsapp_phone: c.whatsapp_phone || '',
        whatsapp_template_id: c.whatsapp_template_id != null ? Number(c.whatsapp_template_id) : null,
        notes: c.notes || ''
    }
}

// ── Vorschau ───────────────────────────────────────────────────────────────

const previewData = ref(null)
const previewLoading = ref(false)
const previewError = ref('')
let previewTimer = null

const sampleText = computed(() => {
    const withPlaceholder = items.value.find(i => /<%|&lt;%/.test(i.description || '') || /<%|&lt;%/.test(i.longdescription || ''))
    if (withPlaceholder) return withPlaceholder.description
    return t('RecurringInvoices.dialog.sampleDefault')
})

async function refreshPreview() {
    if (!config.start_date) return
    previewLoading.value = true
    previewError.value = ''
    try {
        const r = await api.preview({
            oe_id: config.oe_id,
            start_date: config.start_date, end_date: config.end_date || null,
            interval_unit: config.interval_unit, interval_count: config.interval_count,
            align_to_calendar: config.align_to_calendar, prorate_partial: config.prorate_partial,
            billing_timing: config.billing_timing, billing_offset_days: config.billing_offset_days,
            order_value_periodicity: config.order_value_periodicity,
            price_increase_percent: config.price_increase_percent, price_increase_month: config.price_increase_month,
            extend_automatically_by: config.extend_automatically_by, terminated: config.terminated,
            sample_text: sampleText.value, limit: 12,
            items: items.value.map(i => ({ id: i.id, recurring_billing_mode: i.recurring_billing_mode }))
        })
        // Gespeicherte Perioden (erzeugt / übersprungen) in die Vorschau einblenden
        const byStart = new Map(savedPeriods.value.map(p => [p.period_start, p]))
        r.periods = (r.periods || []).map(p => {
            const saved = byStart.get(p.period_start)
            return saved && saved.state !== 'planned' && saved.state !== 'due'
                ? { ...p, state: saved.state, ar_id: saved.ar_id, invnumber: saved.invnumber, ar_amount: saved.ar_amount }
                : p
        })
        previewData.value = r
    } catch (e) {
        previewError.value = e.message
    } finally {
        previewLoading.value = false
    }
}

watch(() => [config.start_date, config.end_date, config.interval_unit, config.interval_count, config.align_to_calendar,
             config.prorate_partial, config.billing_timing, config.billing_offset_days, config.order_value_periodicity,
             config.price_increase_percent, config.price_increase_month, config.extend_automatically_by, config.terminated],
    () => {
        clearTimeout(previewTimer)
        previewTimer = setTimeout(refreshPreview, 250)
    })

// Positionsart ändert die Beträge — die Vorschau rechnet mit der ungespeicherten Art
watch(() => items.value.map(i => i.recurring_billing_mode).join(), () => {
    clearTimeout(previewTimer)
    previewTimer = setTimeout(refreshPreview, 250)
})

// ── Aktionen ───────────────────────────────────────────────────────────────

async function save() {
    saving.value = true
    try {
        const payload = { ...config }
        delete payload.status
        const r = await api.saveConfig(payload, items.value.map(i => ({ id: i.id, recurring_billing_mode: i.recurring_billing_mode })))
        toasts.success(t('RecurringInvoices.dialog.saved'))
        const dueCount = (previewData.value?.periods || []).filter(p => p.state === 'due').length
        emit('saved', { id: r.id, oe_id: config.oe_id, dueCount })
        emit('update:modelValue', false)
        if (dueCount > 0) {
            const answer = await alerts.question(
                t('RecurringInvoices.dialog.dueNowText', { count: dueCount }),
                t('RecurringInvoices.dialog.dueNowTitle'),
                t('RecurringInvoices.dialog.createNow'),
                t('RecurringInvoices.dialog.later'))
            if (answer.isConfirmed) {
                const due = previewData.value.periods.filter(p => p.state === 'due').map(p => ({ config_id: r.id, period_start: p.period_start }))
                const res = await api.createInvoices(due)
                toasts.success(t('RecurringInvoices.created', { count: res.created.length }))
                emit('saved', { id: r.id, oe_id: config.oe_id, dueCount: 0, created: res.created })
            }
        }
    } catch (e) {
        alerts.error(e.message, t('RecurringInvoices.dialog.saveError'))
    } finally {
        saving.value = false
    }
}

async function remove() {
    const answer = await alerts.question(t('RecurringInvoices.dialog.deleteText'), t('RecurringInvoices.dialog.deleteTitle'),
        t('RecurringInvoices.dialog.delete'), t('RecurringInvoices.dialog.cancel'))
    if (!answer.isConfirmed) return
    try {
        await api.deleteConfig(config.id)
        toasts.success(t('RecurringInvoices.dialog.deleted'))
        emit('deleted', { oe_id: config.oe_id })
        emit('update:modelValue', false)
    } catch (e) {
        alerts.error(e.message)
    }
}

function close() {
    emit('update:modelValue', false)
}

function openInvoice(arId) {
    if (arId) router.push(entityRoute('invoice', arId))
}

async function copyPlaceholder(ph) {
    try {
        await navigator.clipboard.writeText(`<%${ph}%>`)
        toasts.info(t('RecurringInvoices.dialog.copied', { placeholder: `<%${ph}%>` }))
    } catch (e) {
        // Zwischenablage nicht verfügbar — der Chip zeigt den Text ohnehin
    }
}

function statusColor(status) {
    return { active: 'success', paused: 'warning', terminated: 'orange', ended: 'grey', inactive: 'grey' }[status] || 'grey'
}

function formatQty(q) {
    return new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'de-DE', { maximumFractionDigits: 2 }).format(Number(q) || 0)
}

watch(() => props.modelValue, (open) => {
    if (open && props.oeId) {
        previewData.value = null
        load()
    }
})
</script>

<style scoped>
.recurring-dialog__grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 460px;
}
.recurring-dialog__preview {
    border-left: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: rgba(var(--v-theme-primary), 0.03);
    position: sticky;
    top: 0;
    align-self: start;
}
@media (max-width: 960px) {
    .recurring-dialog__grid { grid-template-columns: 1fr; }
    .recurring-dialog__preview { border-left: none; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); position: static; }
}
.section-title {
    font-size: 0.95rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}
.step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgb(var(--v-theme-primary));
    color: white;
    font-size: 0.75rem;
    margin-right: 8px;
}
.hint {
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.6);
    margin-top: 4px;
    line-height: 1.35;
}
.summary-alert { font-size: 0.9rem; }
.preview-totals { display: flex; gap: 12px; flex-wrap: wrap; }
.preview-total {
    flex: 1 1 100px;
    padding: 8px 10px;
    border-radius: 8px;
    background: rgba(var(--v-theme-surface), 1);
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.preview-total__label { font-size: 0.7rem; color: rgba(var(--v-theme-on-surface), 0.6); text-transform: uppercase; letter-spacing: 0.03em; }
.preview-total__value { font-size: 1.05rem; font-weight: 600; }
.preview-table :deep(td), .preview-table :deep(th) { font-size: 0.78rem !important; padding: 2px 6px !important; height: auto !important; min-height: 30px; }
.row-created { opacity: 0.75; }
.row-skipped { opacity: 0.5; text-decoration: line-through; }
.sample__text {
    font-size: 0.85rem;
    padding: 8px 10px;
    border-left: 3px solid rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-surface), 1);
    white-space: pre-wrap;
}
.items-table :deep(td) { padding: 4px 8px !important; }
.font-mono { font-family: ui-monospace, monospace; }
</style>
