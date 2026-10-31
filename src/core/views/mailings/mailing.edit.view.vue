<!-- src/core/views/mailings/mailing.edit.view.vue -->
<!--
    Rundschreiben bearbeiten: Nachricht, Versandregeln und Empfänger.

    Links die Nachricht (Titel, Betreff für E-Mail, Text mit Bausteinen,
    optional gestaltete E-Mail) mit Vorschau für einen echten Empfänger; rechts
    die Versandregeln: Kanäle in Reihenfolge, WhatsApp-Vorlage für Empfänger
    ohne kostenloses Fenster, Etappen und Zeitfenster, dazu die Planung
    (wer bekommt die Nachricht worüber, was kostet es). Unten die Empfänger.

    Gespeichert wird automatisch (1,5 s nach der letzten Änderung), solange
    das Rundschreiben nicht läuft. Während des Versands zeigt die Seite den
    Fortschritt und stößt fällige Etappen selbst an.
-->
<template>
    <NavbarView />

    <v-container fluid class="pt-2 px-2 px-sm-4 mailing-edit">
        <page-header
            :title="mailing.id ? mailing.title : t('MailingEditView.titleNew')"
            :subtitle="t('MailingEditView.subtitle')"
            :back-to="{ name: 'mailings' }"
            :back-label="t('MailingEditView.backToList')"
        >
            <v-chip size="small" variant="tonal" :color="statusMeta.color" :prepend-icon="statusMeta.icon">{{ t('MailingsView.status.' + mailing.status) }}</v-chip>
            <v-chip v-if="editable" size="small" variant="text" :color="saveState.color" :prepend-icon="saveState.icon" class="text-body-2">{{ saveState.text }}</v-chip>
            <template #actions>
                <template v-if="mailing.status === 'draft' || mailing.status === 'paused'">
                    <v-btn variant="tonal" prepend-icon="mdi-email-fast-outline" :disabled="!mailing.body_text" @click="openTest">{{ t('MailingEditView.test') }}</v-btn>
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-send" :disabled="!canStart" :loading="starting" @click="confirmStart">
                        {{ mailing.status === 'paused' ? t('MailingEditView.resume') : t('MailingEditView.start') }}
                    </v-btn>
                </template>
                <template v-else-if="mailing.status === 'running'">
                    <v-btn variant="tonal" color="warning" prepend-icon="mdi-pause" @click="setStatus('paused')">{{ t('MailingEditView.pause') }}</v-btn>
                </template>
                <template v-else-if="mailing.status === 'cancelled'">
                    <v-btn color="primary" variant="tonal" prepend-icon="mdi-pencil-outline" @click="setStatus('draft')">{{ t('MailingEditView.backToDraft') }}</v-btn>
                </template>
                <template v-else-if="mailing.status === 'done'">
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-content-copy" @click="copy">{{ t('MailingEditView.copyForNew') }}</v-btn>
                </template>
                <v-menu v-if="mailing.id">
                    <template #activator="{ props: menuProps }">
                        <v-btn v-bind="menuProps" variant="tonal" icon="mdi-dots-vertical" :title="t('MailingEditView.more')" />
                    </template>
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-content-copy" :title="t('MailingEditView.copyForNew')" @click="copy" />
                        <v-list-item v-if="mailing.status === 'running' || mailing.status === 'paused'" prepend-icon="mdi-cancel" base-color="error" :title="t('MailingEditView.cancel')" @click="cancelMailing" />
                        <v-list-item v-if="mailing.status === 'paused'" prepend-icon="mdi-pencil-outline" :title="t('MailingEditView.backToDraft')" @click="setStatus('draft')" />
                        <v-list-item v-if="mailing.status === 'done' && failedCount" prepend-icon="mdi-refresh" :title="t('MailingEditView.retryFailed', { n: failedCount })" @click="setStatus('draft')" />
                        <v-divider v-if="mailing.status !== 'running'" />
                        <v-list-item v-if="mailing.status !== 'running'" prepend-icon="mdi-delete-outline" base-color="error" :title="t('MailingEditView.delete')" @click="remove" />
                    </v-list>
                </v-menu>
            </template>
        </page-header>

        <!-- Fortschritt während / nach dem Versand -->
        <v-alert v-if="mailing.status !== 'draft'" :type="progressAlertType" variant="tonal" density="compact" class="mb-3" :icon="statusMeta.icon">
            <div class="d-flex align-center flex-wrap ga-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-center flex-wrap ga-2">
                        <strong>{{ t('MailingEditView.progress', { sent: counts.sent, total: counts.total }) }}</strong>
                        <span v-if="counts.failed" class="text-error">{{ t('MailingsView.failed', { n: counts.failed }) }}</span>
                        <span v-if="counts.skipped">{{ t('MailingsView.skipped', { n: counts.skipped }) }}</span>
                        <span v-if="mailing.status === 'running'">· {{ nextBatchText }}</span>
                        <span v-if="mailing.status === 'running' && !inWindow">· {{ t('MailingEditView.outsideWindow', { from: mailing.send_from?.slice(0, 5), until: mailing.send_until?.slice(0, 5) }) }}</span>
                        <span v-if="mailing.status === 'paused' && mailing.last_error" class="text-error">· {{ t('MailingEditView.pausedByError') }}: {{ mailing.last_error }}</span>
                    </div>
                    <v-progress-linear :model-value="progressPct" :color="counts.failed ? 'warning' : 'success'" height="6" rounded class="mt-2" />
                </div>
                <v-btn v-if="mailing.status === 'running'" size="small" variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="reload">{{ t('MailingEditView.reload') }}</v-btn>
            </div>
        </v-alert>

        <v-row>
            <!-- Nachricht -->
            <v-col cols="12" lg="7">
                <section-card :title="t('MailingEditView.message')" icon="mdi-message-text-outline">
                    <v-row density="compact">
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="mailing.title" :label="t('MailingEditView.titleField')" :hint="t('MailingEditView.titleHint')" persistent-hint :readonly="!editable" :placeholder="t('MailingEditView.titlePlaceholder')" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field ref="subjectField" v-model="mailing.subject" :label="t('MailingEditView.subject')" :hint="t('MailingEditView.subjectHint')" persistent-hint :readonly="!editable" prepend-inner-icon="mdi-email-outline" @focus="insertTarget = 'subject'" />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea
                                ref="bodyField"
                                v-model="mailing.body_text"
                                :label="t('MailingEditView.body')"
                                :placeholder="t('MailingEditView.bodyPlaceholder')"
                                rows="8"
                                auto-grow
                                :readonly="!editable"
                                counter
                                @focus="insertTarget = 'body'"
                            />
                        </v-col>
                    </v-row>
                    <div class="d-flex align-center flex-wrap ga-1 mt-1">
                        <span class="text-caption text-medium-emphasis me-1">
                            <v-icon size="14" class="me-1">mdi-puzzle-outline</v-icon>{{ t('MailingEditView.placeholders') }}
                        </span>
                        <v-chip v-for="ph in placeholders" :key="ph.key" size="small" variant="tonal" :disabled="!editable" :title="t('MailingEditView.placeholderInsert', { value: ph.value })" @click="insertPlaceholder(ph)">
                            {{ ph.label }}
                        </v-chip>
                    </div>
                    <div v-if="usesWhatsAppTemplate" class="text-caption text-medium-emphasis mt-2">
                        <v-icon size="14" class="me-1" color="orange-darken-2">mdi-information-outline</v-icon>{{ t('MailingEditView.templateFlattenHint') }}
                    </div>

                    <v-divider class="my-4" />

                    <v-switch v-model="htmlMode" :label="t('MailingEditView.htmlMode')" color="primary" density="compact" hide-details :readonly="!editable" :disabled="!emailOn" />
                    <div v-if="htmlMode" class="mt-2">
                        <div class="d-flex align-center flex-wrap ga-2 mb-2">
                            <span class="text-caption text-medium-emphasis">{{ t('MailingEditView.htmlHint') }}</span>
                            <v-spacer />
                            <v-btn size="small" variant="text" prepend-icon="mdi-arrow-down-bold-box-outline" :disabled="!editable || !mailing.body_text" @click="textToHtml">{{ t('MailingEditView.htmlFromText') }}</v-btn>
                        </div>
                        <HtmlEditorComponent v-model="mailing.body_html" :min-height="220" :max-height="0" />
                    </div>
                </section-card>

                <!-- Vorschau -->
                <section-card :title="t('MailingEditView.preview')" icon="mdi-eye-outline" class="mt-4">
                    <template #actions>
                        <v-select v-model="previewKey" :items="previewItems" item-title="name" item-value="key" density="compact" variant="outlined" hide-details class="mailing-edit__preview-select" :label="t('MailingEditView.previewFor')" :disabled="!previewItems.length" />
                    </template>
                    <div v-if="!mailing.body_text" class="text-medium-emphasis text-body-2">{{ t('MailingEditView.previewEmpty') }}</div>
                    <template v-else>
                        <div class="d-flex align-center ga-2 mb-2 text-caption text-medium-emphasis">
                            <template v-if="previewRecipient">
                                <v-chip v-if="previewPlan.channel" size="x-small" variant="tonal" :color="CHANNEL_META[previewPlan.channel].color" :prepend-icon="CHANNEL_META[previewPlan.channel].icon">{{ t('MailingRecipients.channel.' + previewPlan.channel) }}</v-chip>
                                <span>{{ previewPlan.target }}</span>
                                <v-chip v-if="previewPlan.email" size="x-small" variant="tonal" :color="CHANNEL_META.email_extra.color" :prepend-icon="CHANNEL_META.email_extra.icon">{{ t('MailingRecipients.channel.email_extra') }}</v-chip>
                                <v-chip v-if="previewPlan.letter" size="x-small" variant="tonal" :color="CHANNEL_META.letter_extra.color" :prepend-icon="CHANNEL_META.letter_extra.icon">{{ t('MailingRecipients.channel.letter_extra') }}</v-chip>
                            </template>
                            <span v-else>{{ t('MailingEditView.previewSample') }}</span>
                        </div>
                        <div v-if="mailing.subject && (!previewPlan.channel || previewPlan.channel === 'email' || previewPlan.email)" class="font-weight-medium mb-2">{{ previewSubject }}</div>
                        <div class="mailing-edit__bubble">{{ previewText }}</div>
                    </template>
                </section-card>
            </v-col>

            <!-- Versandregeln -->
            <v-col cols="12" lg="5">
                <section-card :title="t('MailingEditView.dispatch')" icon="mdi-tune-variant">
                    <div class="text-caption text-medium-emphasis mb-2">{{ t('MailingEditView.channelOrderHint') }}</div>
                    <v-list density="compact" class="mailing-edit__channels pa-0">
                        <v-list-item v-for="(ch, idx) in channelOrder" :key="ch" class="px-2">
                            <template #prepend>
                                <!-- readonly statt disabled: Vuetify zeichnet deaktivierte Schalter mit Knopf links, als wären sie aus -->
                                <v-switch :model-value="channelEnabled[ch]" color="primary" density="compact" hide-details class="me-2" :readonly="!editable" :disabled="!config[ch]" @update:model-value="v => toggleChannel(ch, v)" />
                                <v-icon :color="channelEnabled[ch] && config[ch] ? CHANNEL_META[ch].color : 'grey'" class="me-2">{{ CHANNEL_META[ch].icon }}</v-icon>
                            </template>
                            <v-list-item-title>
                                {{ idx + 1 }}. {{ t('MailingRecipients.channel.' + ch) }}
                                <v-chip v-if="!config[ch]" size="x-small" variant="tonal" color="grey" class="ms-1">{{ t('MailingsView.channelNotConfigured') }}</v-chip>
                                <v-chip v-else-if="ch === 'telegram'" size="x-small" variant="tonal" color="success" class="ms-1">{{ t('MailingEditView.free') }}</v-chip>
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">{{ t('MailingEditView.channelHints.' + ch) }}</v-list-item-subtitle>
                            <template #append>
                                <span class="text-body-2 me-2">{{ ch === 'whatsapp' ? plan.counts.whatsapp + plan.counts.whatsapp_template : plan.counts[ch] }}</span>
                                <v-btn icon="mdi-chevron-up" size="x-small" variant="text" :disabled="!editable || idx === 0" @click="moveChannel(idx, -1)" />
                                <v-btn icon="mdi-chevron-down" size="x-small" variant="text" :disabled="!editable || idx === channelOrder.length - 1" @click="moveChannel(idx, 1)" />
                            </template>
                        </v-list-item>
                    </v-list>

                    <!-- WhatsApp außerhalb des Fensters -->
                    <div v-if="channelEnabled.whatsapp && config.whatsapp" class="mailing-edit__sub mt-3">
                        <v-select
                            v-model="mailing.whatsapp_template_id"
                            :items="templateItems"
                            item-title="title"
                            item-value="id"
                            :label="t('MailingEditView.whatsappOutsideWindow')"
                            :readonly="!editable"
                            :prepend-inner-icon="mailing.whatsapp_template_id ? 'mdi-cash' : 'mdi-cash-off'"
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps" :title="item.title" :subtitle="item.subtitle" />
                            </template>
                        </v-select>
                        <div class="text-caption mt-1" :class="mailing.whatsapp_template_id ? 'text-orange-darken-3' : 'text-medium-emphasis'">
                            <template v-if="mailing.whatsapp_template_id">
                                <v-icon size="14" class="me-1">mdi-alert-outline</v-icon>
                                {{ t('MailingEditView.whatsappTemplateCostHint', { n: plan.counts.whatsapp_template }) }}
                                <strong v-if="templateCost > 0"> {{ t('MailingEditView.whatsappCostEstimate', { amount: formatMoney(plan.counts.whatsapp_template * templateCost) }) }}</strong>
                            </template>
                            <template v-else>
                                {{ t('MailingEditView.whatsappFreeHint', { free: plan.counts.whatsapp, closed: whatsappClosedCount }) }}
                            </template>
                        </div>
                    </div>

                    <!-- E-Mail: zusätzlich, als Rückfall oder gar nicht -->
                    <div class="mailing-edit__sub mt-3">
                        <v-select
                            v-model="mailing.email_mode"
                            :items="emailModeItems"
                            item-title="title"
                            item-value="value"
                            :label="t('MailingEditView.emailMode')"
                            :readonly="!editable"
                            :disabled="!config.email"
                            :prepend-inner-icon="mailing.email_mode === 'off' ? 'mdi-email-off-outline' : 'mdi-email-outline'"
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps" :title="item.title" :subtitle="item.subtitle" />
                            </template>
                        </v-select>
                        <div class="text-caption text-medium-emphasis mt-1">
                            <template v-if="!config.email">{{ t('MailingRecipients.hints.emailOff') }}</template>
                            <template v-else-if="mailing.email_mode === 'off'">{{ t('MailingEditView.emailModeHints.off') }}</template>
                            <template v-else>{{ t('MailingEditView.emailPlanHint', { extra: plan.counts.email_extra, only: plan.counts.email }) }}</template>
                        </div>
                    </div>

                    <!-- E-Brief über den Postdienst (nur wenn eingerichtet) -->
                    <div v-if="config.letter" class="mailing-edit__sub mt-3">
                        <v-select
                            v-model="mailing.letter_mode"
                            :items="letterModeItems"
                            item-title="title"
                            item-value="value"
                            :label="t('MailingEditView.letterMode', { provider: config.letter_provider })"
                            :readonly="!editable"
                            :prepend-inner-icon="mailing.letter_mode === 'off' ? 'mdi-email-newsletter' : 'mdi-cash'"
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps" :title="item.title" :subtitle="item.subtitle" />
                            </template>
                        </v-select>
                        <div class="text-caption mt-1" :class="mailing.letter_mode !== 'off' && letterCount ? 'text-orange-darken-3' : 'text-medium-emphasis'">
                            <template v-if="mailing.letter_mode === 'off'">{{ t('MailingEditView.letterOffHint', { n: plan.none }) }}</template>
                            <template v-else>
                                <v-icon v-if="letterCount" size="14" class="me-1">mdi-alert-outline</v-icon>
                                {{ t('MailingEditView.letterPlanHint', { extra: plan.counts.letter_extra, only: plan.counts.letter }) }}
                                <strong v-if="letterCost > 0 && letterCount"> {{ t('MailingEditView.whatsappCostEstimate', { amount: formatMoney(letterCount * letterCost) }) }}</strong>
                            </template>
                        </div>
                    </div>

                    <v-divider class="my-4" />

                    <!-- Etappen -->
                    <div class="text-caption text-medium-emphasis mb-2">{{ t('MailingEditView.batchHint') }}</div>
                    <v-row density="compact">
                        <v-col cols="6">
                            <v-text-field v-model.number="mailing.batch_size" type="number" min="1" max="100" :label="t('MailingEditView.batchSize')" :readonly="!settingsEditable" prepend-inner-icon="mdi-layers-outline" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model.number="mailing.batch_pause_minutes" type="number" min="0" max="1440" :label="t('MailingEditView.batchPause')" :readonly="!settingsEditable" prepend-inner-icon="mdi-timer-sand" :suffix="t('MailingEditView.minutes')" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="mailing.send_from" type="time" :label="t('MailingEditView.sendFrom')" :readonly="!settingsEditable" prepend-inner-icon="mdi-weather-sunset-up" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="mailing.send_until" type="time" :label="t('MailingEditView.sendUntil')" :readonly="!settingsEditable" prepend-inner-icon="mdi-weather-sunset-down" />
                        </v-col>
                    </v-row>

                    <!-- Planung -->
                    <v-alert :type="plan.planned ? 'success' : 'warning'" variant="tonal" density="compact" class="mt-2" :icon="plan.planned ? 'mdi-account-check-outline' : 'mdi-account-alert-outline'">
                        <div class="font-weight-medium">{{ t('MailingEditView.planSummary', { n: plan.planned, total: recipients.length }) }}</div>
                        <div class="d-flex flex-wrap ga-1 mt-1">
                            <v-chip v-for="ch in ['whatsapp', 'whatsapp_template', 'telegram', 'email', 'email_extra', 'letter', 'letter_extra']" v-show="plan.counts[ch]" :key="ch" size="x-small" variant="tonal" :color="CHANNEL_META[ch].color" :prepend-icon="CHANNEL_META[ch].icon">
                                {{ t('MailingRecipients.channel.' + ch) }} {{ plan.counts[ch] }}
                            </v-chip>
                            <v-chip v-if="plan.none" size="x-small" variant="tonal" color="grey" prepend-icon="mdi-account-off-outline">{{ t('MailingRecipients.channel.none') }} {{ plan.none }}</v-chip>
                            <v-chip v-if="plan.duplicates" size="x-small" variant="tonal" color="grey" prepend-icon="mdi-content-duplicate">{{ t('MailingRecipients.duplicate') }} {{ plan.duplicates }}</v-chip>
                        </div>
                        <div v-if="plan.planned" class="text-caption mt-1">
                            {{ t('MailingEditView.durationEstimate', { duration: durationText }) }}
                            <template v-if="estimatedCost > 0"> · {{ t('MailingEditView.costLabel') }} {{ formatMoney(estimatedCost) }}</template>
                        </div>
                    </v-alert>
                </section-card>
            </v-col>

            <!-- Empfänger -->
            <v-col cols="12">
                <mailing-recipients
                    v-model="recipients"
                    :readonly="!editable"
                    :show-status="mailing.status !== 'draft'"
                    :settings="planSettings"
                    :config="config"
                    :lists="lists"
                    :businesses="businesses"
                    :max="config.max_recipients || 300"
                    :list-id="mailing.mailing_list_id"
                    @lists-changed="onListsChanged"
                    @list-loaded="id => mailing.mailing_list_id = id"
                />
            </v-col>
        </v-row>

        <!-- Start bestätigen -->
        <v-dialog v-model="startDialog" max-width="520">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-send</v-icon>
                    {{ mailing.status === 'paused' ? t('MailingEditView.resume') : t('MailingEditView.start') }}
                </v-card-title>
                <v-card-text>
                    <p class="mb-3">{{ t(mailing.status === 'paused' ? 'MailingEditView.resumeText' : 'MailingEditView.startText', { n: plan.planned }) }}</p>
                    <v-list density="compact" class="pa-0 mb-2">
                        <v-list-item v-for="ch in ['whatsapp', 'whatsapp_template', 'telegram', 'email', 'email_extra', 'letter', 'letter_extra']" v-show="plan.counts[ch]" :key="ch" :prepend-icon="CHANNEL_META[ch].icon" :title="t('MailingRecipients.channel.' + ch)">
                            <template #append>
                                <span class="font-weight-medium">{{ plan.counts[ch] }}</span>
                                <span v-if="ch === 'whatsapp_template' && templateCost > 0" class="text-caption text-orange-darken-3 ms-2">≈ {{ formatMoney(plan.counts[ch] * templateCost) }}</span>
                                <span v-else-if="(ch === 'letter' || ch === 'letter_extra') && letterCost > 0" class="text-caption text-orange-darken-3 ms-2">≈ {{ formatMoney(plan.counts[ch] * letterCost) }}</span>
                                <v-chip v-else-if="!['whatsapp_template', 'letter', 'letter_extra'].includes(ch)" size="x-small" variant="tonal" color="success" class="ms-2">{{ t('MailingEditView.free') }}</v-chip>
                            </template>
                        </v-list-item>
                    </v-list>
                    <div class="text-body-2 text-medium-emphasis">
                        {{ t('MailingEditView.startDetails', { size: mailing.batch_size, pause: mailing.batch_pause_minutes, from: (mailing.send_from || '').slice(0, 5), until: (mailing.send_until || '').slice(0, 5), duration: durationText }) }}
                    </div>
                    <div v-if="plan.none + plan.duplicates" class="text-body-2 text-medium-emphasis mt-2">{{ t('MailingEditView.startSkipped', { n: plan.none + plan.duplicates }) }}</div>
                    <div v-if="!inWindow" class="text-body-2 text-warning mt-2"><v-icon size="16" class="me-1">mdi-clock-alert-outline</v-icon>{{ t('MailingEditView.startOutsideWindow') }}</div>
                </v-card-text>
                <v-card-actions class="px-4 pb-3">
                    <v-spacer />
                    <v-btn variant="text" @click="startDialog = false">{{ t('MailingRecipients.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-send" :loading="starting" @click="start">{{ t('MailingEditView.startNow') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Testnachricht -->
        <v-dialog v-model="testDialog" max-width="460">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('MailingEditView.testTitle') }}</v-card-title>
                <v-card-text>
                    <p class="text-body-2 text-medium-emphasis mb-3">{{ t('MailingEditView.testText') }}</p>
                    <v-btn-toggle v-model="test.channel" mandatory density="compact" variant="outlined" color="primary" divided class="mb-3">
                        <v-btn value="email" size="small" :disabled="!config.email"><v-icon start size="small">mdi-email-outline</v-icon>{{ t('MailingRecipients.channel.email') }}</v-btn>
                        <v-btn value="whatsapp" size="small" :disabled="!config.whatsapp"><v-icon start size="small">mdi-whatsapp</v-icon>{{ t('MailingRecipients.channel.whatsapp') }}</v-btn>
                    </v-btn-toggle>
                    <v-text-field v-model="test.target" :label="test.channel === 'email' ? t('MailingEditView.testEmail') : t('MailingEditView.testPhone')" variant="outlined" density="compact" hide-details autofocus @keyup.enter="sendTest" />
                    <div v-if="test.channel === 'whatsapp'" class="text-caption text-medium-emphasis mt-2">{{ t('MailingEditView.testWhatsappHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="testDialog = false">{{ t('MailingRecipients.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="testSending" :disabled="!test.target" @click="sendTest">{{ t('MailingEditView.testSend') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { apiError } from './composables/apiError.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import MailingRecipients from './components/mailing-recipients.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { CHANNELS, EMAIL_MODES, LETTER_MODES, CHANNEL_META, planAll, planChannel, recipientKey, estimateMinutes, fillPlaceholders } from './composables/mailingPlan.js'

const props = defineProps({ id: { type: [String, Number], default: null } })

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const oserp = oserpStore()

const mailing = ref(emptyMailing())
const recipients = ref([])
const config = ref({})
const templates = ref([])
const lists = ref([])
const businesses = ref([])
const loading = ref(false)
const saving = ref(false)
const starting = ref(false)
const savedAt = ref(null)
const htmlMode = ref(false)
const savedSnapshot = ref('')   // ref, damit „dirty“ nach dem Speichern sofort nachzieht
let saveTimer = null
let pollTimer = null

function emptyMailing() {
    return {
        id: 0, title: '', subject: '', body_text: '', body_html: '',
        channels: ['whatsapp', 'telegram'], email_mode: 'always', letter_mode: 'off', whatsapp_template_id: null,
        batch_size: 20, batch_pause_minutes: 10, send_from: '09:00', send_until: '20:00',
        status: 'draft', mailing_list_id: null, next_batch_at: null, started_at: null, finished_at: null, last_error: null,
    }
}

// ── Status ──
const editable = computed(() => mailing.value.status === 'draft' || mailing.value.status === 'paused')
const settingsEditable = computed(() => editable.value)
const statusMeta = computed(() => ({
    draft:     { icon: 'mdi-pencil-outline', color: 'grey' },
    running:   { icon: 'mdi-send-clock-outline', color: 'primary' },
    paused:    { icon: 'mdi-pause-circle-outline', color: 'warning' },
    done:      { icon: 'mdi-check-circle-outline', color: 'success' },
    cancelled: { icon: 'mdi-cancel', color: 'error' },
}[mailing.value.status] || { icon: 'mdi-help-circle-outline', color: 'grey' }))
const counts = computed(() => {
    const c = { total: recipients.value.length, sent: 0, failed: 0, skipped: 0, pending: 0 }
    for (const r of recipients.value) c[r.status || 'pending'] = (c[r.status || 'pending'] || 0) + 1
    return c
})
const failedCount = computed(() => counts.value.failed)
const progressPct = computed(() => counts.value.total ? Math.round(100 * (counts.value.sent + counts.value.failed + counts.value.skipped) / counts.value.total) : 0)
const progressAlertType = computed(() => ({ running: 'info', paused: 'warning', done: 'success', cancelled: 'error' }[mailing.value.status] || 'info'))
const inWindow = computed(() => {
    const now = new Date()
    const hm = now.getHours() * 60 + now.getMinutes()
    const [fh, fm] = String(mailing.value.send_from || '09:00').split(':').map(Number)
    const [uh, um] = String(mailing.value.send_until || '20:00').split(':').map(Number)
    return hm >= fh * 60 + fm && hm <= uh * 60 + um
})
const nextBatchText = computed(() => {
    if (!mailing.value.next_batch_at) return t('MailingsView.nextBatchNow')
    const at = new Date(mailing.value.next_batch_at)
    if (at <= new Date()) return t('MailingsView.nextBatchNow')
    return t('MailingsView.nextBatchAt', { time: at.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) })
})

// ── Kanäle ──
const channelOrder = ref([...CHANNELS])
const channelEnabled = ref({ whatsapp: true, telegram: true })
function applyChannels(list) {
    const enabled = Array.isArray(list) ? list : CHANNELS
    channelOrder.value = [...enabled.filter(c => CHANNELS.includes(c)), ...CHANNELS.filter(c => !enabled.includes(c))]
    channelEnabled.value = Object.fromEntries(CHANNELS.map(c => [c, enabled.includes(c)]))
}
function syncChannels() {
    mailing.value.channels = channelOrder.value.filter(c => channelEnabled.value[c])
}
function toggleChannel(ch, v) { channelEnabled.value[ch] = !!v; syncChannels() }
function moveChannel(idx, dir) {
    const arr = [...channelOrder.value]
    const [item] = arr.splice(idx, 1)
    arr.splice(idx + dir, 0, item)
    channelOrder.value = arr
    syncChannels()
}
const emailOn = computed(() => !!config.value.email && mailing.value.email_mode !== 'off')
const emailModeItems = computed(() => EMAIL_MODES.map(v => ({ value: v, title: t('MailingEditView.emailModes.' + v), subtitle: t('MailingEditView.emailModeHints.' + v) })))
const letterModeItems = computed(() => LETTER_MODES.map(v => ({ value: v, title: t('MailingEditView.letterModes.' + v), subtitle: t('MailingEditView.letterModeHints.' + v) })))
const letterCost = computed(() => Number(config.value.letter_cost) || 0)
const letterCount = computed(() => plan.value.counts.letter + plan.value.counts.letter_extra)
// Voraussichtliche Kosten: WhatsApp-Vorlagen und Briefe
const estimatedCost = computed(() => plan.value.counts.whatsapp_template * templateCost.value + letterCount.value * letterCost.value)
const planSettings = computed(() => ({ channels: mailing.value.channels, whatsapp_template_id: mailing.value.whatsapp_template_id, email_mode: mailing.value.email_mode, letter_mode: mailing.value.letter_mode }))
// Bei einem pausierten Versand zählt nur, was noch aussteht
const plan = computed(() => planAll(mailing.value.status === 'paused' ? recipients.value.filter(r => r.status !== 'sent') : recipients.value, planSettings.value, config.value))
const usesWhatsAppTemplate = computed(() => !!mailing.value.whatsapp_template_id && plan.value.counts.whatsapp_template > 0)
const whatsappClosedCount = computed(() => recipients.value.filter(r => r.whatsapp_phone && !r.whatsapp_window).length)
const templateCost = computed(() => Number(config.value.whatsapp_template_cost) || 0)
const templateItems = computed(() => [
    { id: null, title: t('MailingEditView.whatsappNoTemplate'), subtitle: t('MailingEditView.whatsappNoTemplateHint') },
    ...templates.value.map(tp => ({ id: tp.id, title: tp.display_name || tp.name, subtitle: (tp.category ? tp.category + ' · ' : '') + (tp.body_text || '').replace(/\s+/g, ' ').slice(0, 90) })),
])
const durationText = computed(() => {
    const min = estimateMinutes(plan.value.planned, mailing.value.batch_size, mailing.value.batch_pause_minutes)
    if (min < 1) return t('MailingEditView.underMinute')
    if (min < 60) return t('MailingEditView.minutesShort', { n: min }, min)
    return t('MailingEditView.hoursShort', { h: Math.floor(min / 60), m: min % 60 })
})
const canStart = computed(() => editable.value && !!mailing.value.title.trim() && !!mailing.value.body_text.trim() && plan.value.planned > 0 && !saving.value)
function formatMoney(v) { return Number(v || 0).toLocaleString(locale.value, { style: 'currency', currency: 'EUR' }) }

// ── Bausteine ──
const insertTarget = ref('body')
const bodyField = ref(null)
const subjectField = ref(null)
const previewRecipient = computed(() => recipients.value.find(r => recipientKey(r) === previewKey.value) || recipients.value[0] || null)
const placeholders = computed(() => {
    const r = previewRecipient.value || { salutation: t('MailingEditView.sampleSalutation'), name: t('MailingEditView.sampleName'), number: '10001' }
    return [
        { key: '{{anrede}}', label: t('MailingEditView.phSalutation'), value: r.salutation },
        { key: '{{name}}', label: t('MailingEditView.phName'), value: r.name },
        { key: '{{kundennummer}}', label: t('MailingEditView.phNumber'), value: r.number },
        { key: '{{firma}}', label: t('MailingEditView.phCompany'), value: config.value.company },
        { key: '{{mitarbeiter}}', label: t('MailingEditView.phEmployee'), value: oserp.session?.logged_in_employee?.name },
    ]
})
function insertPlaceholder(ph) {
    const target = insertTarget.value === 'subject' ? subjectField : bodyField
    const el = target.value?.$el?.querySelector('textarea, input')
    const field = insertTarget.value === 'subject' ? 'subject' : 'body_text'
    const current = mailing.value[field] || ''
    if (!el) { mailing.value[field] = current + ph.key; return }
    const start = el.selectionStart ?? current.length
    const end = el.selectionEnd ?? current.length
    mailing.value[field] = current.slice(0, start) + ph.key + current.slice(end)
    nextTick(() => { el.focus(); el.setSelectionRange(start + ph.key.length, start + ph.key.length) })
}
function textToHtml() {
    const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    mailing.value.body_html = mailing.value.body_text.split(/\n{2,}/).map(p => '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>').join('')
}
watch(htmlMode, (on) => { if (!on) mailing.value.body_html = '' })

// ── Vorschau ──
const previewKey = ref(null)
const previewItems = computed(() => recipients.value.slice(0, 50).map(r => ({ key: recipientKey(r), name: r.number ? `${r.name} (${r.number})` : r.name })))
// Auswahl folgt der Empfängerliste: ohne gültige Wahl wird der erste Empfänger angezeigt
watch(previewItems, items => { if (!items.some(i => i.key === previewKey.value)) previewKey.value = items[0]?.key ?? null }, { immediate: true })
const previewCtx = computed(() => ({ company: config.value.company, employee: oserp.session?.logged_in_employee?.name }))
const previewPlan = computed(() => previewRecipient.value ? planChannel(previewRecipient.value, planSettings.value, config.value) : { channel: null })
const previewText = computed(() => fillPlaceholders(mailing.value.body_text, previewRecipient.value || { salutation: t('MailingEditView.sampleSalutation'), name: t('MailingEditView.sampleName'), number: '10001' }, previewCtx.value))
const previewSubject = computed(() => fillPlaceholders(mailing.value.subject, previewRecipient.value || { salutation: '', name: t('MailingEditView.sampleName'), number: '10001' }, previewCtx.value))

// ── Laden ──
async function load() {
    loading.value = true
    try {
        const params = { action: 'getMailing', id: Number(props.id) || 0, copy_of: Number(route.query.copy_of) || 0 }
        const { data } = await axios.post('/api/mailings/', params)
        if (!data.success) throw new Error(apiError(data, t))
        const p = data.payload
        if (!p.found) {
            toast.error(t('MailingEditView.notFound'))
            router.replace({ name: 'mailings' })
            return
        }
        config.value = p.config || {}
        templates.value = p.templates || []
        lists.value = p.lists || []
        businesses.value = p.businesses || []
        const m = { ...emptyMailing(), ...p.mailing }
        m.send_from = String(m.send_from || '09:00').slice(0, 5)
        m.send_until = String(m.send_until || '20:00').slice(0, 5)
        let recs = p.recipients || []
        if (p.is_copy) {
            m.id = 0; m.status = 'draft'; m.next_batch_at = null; m.started_at = null; m.finished_at = null; m.last_error = null
            m.title = t('MailingEditView.copyTitle', { title: m.title })
            recs = recs.map(r => ({ ...r, channel: null, target: null, status: 'pending', error: null, sent_at: null, batch_no: null }))
        }
        mailing.value = m
        recipients.value = recs
        applyChannels(m.channels)
        htmlMode.value = !!(m.body_html && m.body_html.replace(/<[^>]*>/g, '').trim())
        if (!m.id && route.query.list) await loadListIntoNew(Number(route.query.list))
        savedSnapshot.value = snapshot()
        // Kopie oder Neuanlage aus einem Verteiler: sofort als „ungespeichert“ führen, damit
        // der Datensatz durch das automatische Speichern entsteht, ohne dass erst getippt werden muss
        if (!m.id && (p.is_copy || route.query.list)) savedSnapshot.value = ''
        if (m.status === 'running') startPolling()
    } catch (e) {
        toast.error(e.message)
    } finally {
        loading.value = false
    }
}
async function loadListIntoNew(listId) {
    const { data } = await axios.post('/api/mailings/', { action: 'getMailingList', id: listId })
    if (data.success && data.payload.found) {
        recipients.value = data.payload.members || []
        mailing.value.mailing_list_id = listId
        if (!mailing.value.title) mailing.value.title = data.payload.list?.name || ''
    }
}
async function reload() { await load() }
async function onListsChanged() {
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'getMailing', id: 0 })
        if (data.success) lists.value = data.payload.lists || []
    } catch (e) { /* Liste aktualisiert sich beim nächsten Laden */ }
}

// ── Automatisches Speichern ──
function snapshot() {
    const m = mailing.value
    return JSON.stringify([m.title, m.subject, m.body_text, m.body_html, m.channels, m.whatsapp_template_id, m.batch_size, m.batch_pause_minutes, m.send_from, m.send_until, m.mailing_list_id,
        recipients.value.map(r => recipientKey(r)).sort()])
}
const dirty = computed(() => snapshot() !== savedSnapshot.value)
const saveState = computed(() => {
    if (saving.value) return { icon: 'mdi-sync', color: 'primary', text: t('MailingEditView.saving') }
    if (!mailing.value.title.trim()) return { icon: 'mdi-alert-circle-outline', color: 'warning', text: t('MailingEditView.titleRequired') }
    if (dirty.value) return { icon: 'mdi-pencil-outline', color: 'grey', text: t('MailingEditView.unsaved') }
    if (savedAt.value) return { icon: 'mdi-check', color: 'success', text: t('MailingEditView.savedAt', { time: savedAt.value.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) }) }
    return { icon: 'mdi-content-save-outline', color: 'grey', text: mailing.value.id ? t('MailingEditView.saved') : t('MailingEditView.newHint') }
})
watch(() => snapshot(), () => {
    if (!editable.value || loading.value) return
    clearTimeout(saveTimer)
    saveTimer = setTimeout(() => save(), 1500)
})
async function save() {
    if (!editable.value || !mailing.value.title.trim() || !dirty.value) return false
    saving.value = true
    try {
        const { data } = await axios.post('/api/mailings/', {
            action: 'saveMailing',
            mailing: { ...mailing.value, channels: mailing.value.channels },
            recipients: recipients.value.map(r => ({ cv_src: r.cv_src, cv_id: r.cv_id })),
        })
        if (!data.success) throw new Error(apiError(data, t))
        const wasNew = !mailing.value.id
        mailing.value.id = data.payload.id
        savedSnapshot.value = snapshot()
        savedAt.value = new Date()
        // Beim Verlassen der Seite nicht mehr umlenken, sonst wird die Zielroute (z. B. die Übersicht) verworfen
        if (wasNew && !leaving) router.replace({ name: 'mailing-edit', params: { id: data.payload.id } })
        return true
    } catch (e) {
        toast.error(t('MailingEditView.saveError', { error: e.message }))
        return false
    } finally {
        saving.value = false
    }
}
async function flushSave() {
    clearTimeout(saveTimer)
    if (dirty.value) await save()
    return !dirty.value
}
let leaving = false
onBeforeRouteLeave(async () => { leaving = true; await flushSave(); leaving = false; return true })

// ── Versand ──
const startDialog = ref(false)
function confirmStart() { startDialog.value = true }
async function start() {
    starting.value = true
    try {
        if (!(await flushSave())) throw new Error(t('MailingEditView.startNotSaved'))
        const { data } = await axios.post('/api/mailings/', { action: 'startMailing', id: mailing.value.id })
        if (!data.success) throw new Error(apiError(data, t))
        startDialog.value = false
        const b = data.payload.batch || {}
        toast.success(b.sent ? t('MailingEditView.startedSent', { n: b.sent }) : t('MailingEditView.started'))
        await load()
    } catch (e) {
        toast.error(e.message)
    } finally {
        starting.value = false
    }
}
async function setStatus(status) {
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'setMailingStatus', id: mailing.value.id, status })
        if (!data.success) throw new Error(apiError(data, t))
        await load()
    } catch (e) {
        toast.error(e.message)
    }
}
async function cancelMailing() {
    const res = await alerts.warning(t('MailingEditView.cancelConfirm', { n: counts.value.pending }), '', t('MailingEditView.cancel'), t('MailingRecipients.cancel'))
    if (res.isConfirmed) setStatus('cancelled')
}
function startPolling() {
    stopPolling()
    let n = 0
    pollTimer = setInterval(async () => {
        if (mailing.value.status !== 'running') { stopPolling(); return }
        n++
        if (n % 2 === 0) {
            try { await axios.post('/api/mailings/', { action: 'processMailings', id: mailing.value.id }) } catch (e) { /* nächster Versuch */ }
        }
        await load()
    }, 10000)
}
function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null } }

// ── Test ──
const testDialog = ref(false)
const testSending = ref(false)
const test = ref({ channel: 'email', target: '' })
function openTest() {
    test.value.channel = config.value.email ? 'email' : 'whatsapp'
    testDialog.value = true
}
async function sendTest() {
    testSending.value = true
    try {
        const { data } = await axios.post('/api/mailings/', {
            action: 'sendMailingTest', channel: test.value.channel, target: test.value.target,
            subject: mailing.value.subject, body_text: mailing.value.body_text, body_html: htmlMode.value ? mailing.value.body_html : '',
            whatsapp_template_id: mailing.value.whatsapp_template_id,
        })
        if (!data.success) throw new Error(apiError(data, t))
        testDialog.value = false
        toast.success(t('MailingEditView.testSent'))
    } catch (e) {
        toast.error(e.message)
    } finally {
        testSending.value = false
    }
}

// ── Sonstiges ──
function copy() { router.push({ name: 'mailing-new', query: { copy_of: String(mailing.value.id) } }) }
async function remove() {
    const res = await alerts.warning(t('MailingsView.deleteConfirm', { title: mailing.value.title }), '', t('MailingsView.delete'), t('MailingRecipients.cancel'))
    if (!res.isConfirmed) return
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'deleteMailing', id: mailing.value.id })
        if (!data.success) throw new Error(apiError(data, t))
        savedSnapshot.value = snapshot()
        router.push({ name: 'mailings' })
    } catch (e) {
        toast.error(e.message)
    }
}

onMounted(load)
onBeforeUnmount(() => { stopPolling(); clearTimeout(saveTimer) })
</script>

<style scoped>
.mailing-edit__channels :deep(.v-list-item) {
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius-sm);
    margin-bottom: var(--oserp-gap-xs);
}
.mailing-edit__sub {
    padding: var(--oserp-gap) var(--oserp-gap);
    background: var(--oserp-surface-soft);
    border-radius: var(--oserp-radius-sm);
}
.mailing-edit__bubble {
    display: inline-block;
    max-width: 100%;
    white-space: pre-wrap;
    word-break: break-word;
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    background: var(--oserp-surface-soft);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
    border-top-left-radius: var(--oserp-gap-xs);
}
.mailing-edit__preview-select {
    min-width: 220px;
}
</style>
