<!-- src/core/views/letters/letters.view.vue -->
<!--
    Briefliste (Kontakt → Briefe): alle Briefe an Kunden und Lieferanten mit
    Volltextsuche, Filtern nach Empfängerart, Versandstatus und Zeitraum.
    Ein Klick auf die Zeile öffnet den Brief im Editor.
-->
<template>
    <NavbarView />

    <v-container fluid class="pa-2 pa-md-4">
        <v-row class="mb-2" align="center">
            <v-col cols="12" md="6">
                <h1 class="text-h5 text-md-h4 d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-email-edit-outline</v-icon>
                    {{ t('LettersView.title') }}
                </h1>
            </v-col>
            <v-col cols="12" md="6" class="d-flex justify-md-end">
                <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" :to="{ name: 'letter-new' }">
                    {{ t('LettersView.newLetter') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row class="mb-2" dense>
            <v-col cols="12" sm="6" md="4">
                <v-text-field
                    v-model="search"
                    :label="t('LettersView.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    @update:model-value="onFilterChange"
                />
            </v-col>
            <v-col cols="12" sm="6" md="2">
                <v-select
                    v-model="src"
                    :label="t('LettersView.recipientType')"
                    :items="srcItems"
                    variant="outlined"
                    density="compact"
                    hide-details
                    @update:model-value="onFilterChange"
                />
            </v-col>
            <v-col cols="6" sm="6" md="2">
                <v-text-field v-model="dateFrom" :label="t('LettersView.dateFrom')" type="date" variant="outlined" density="compact" hide-details clearable @update:model-value="onFilterChange" />
            </v-col>
            <v-col cols="6" sm="6" md="2">
                <v-text-field v-model="dateTo" :label="t('LettersView.dateTo')" type="date" variant="outlined" density="compact" hide-details clearable @update:model-value="onFilterChange" />
            </v-col>
            <v-col cols="12" md="2" class="d-flex align-center">
                <v-btn-toggle v-model="status" density="compact" variant="outlined" color="primary" divided mandatory @update:model-value="onFilterChange">
                    <v-btn value="" size="small">{{ t('LettersView.all') }} {{ counts.all }}</v-btn>
                    <v-btn value="draft" size="small" :title="t('LettersView.statusDraft')"><v-icon start size="small">mdi-pencil-outline</v-icon>{{ counts.draft }}</v-btn>
                    <v-btn value="sent" size="small" :title="t('LettersView.statusSent')"><v-icon start size="small">mdi-send-check-outline</v-icon>{{ counts.sent }}</v-btn>
                </v-btn-toggle>
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12">
                <v-card variant="outlined" rounded="lg">
                    <v-card-text class="pa-0" style="overflow-x: auto;">
                        <v-data-table-server
                            :headers="headers"
                            :items="letters"
                            :items-length="total"
                            :items-per-page="itemsPerPage"
                            :items-per-page-options="[25, 50, 100, 200]"
                            :page="page"
                            :loading="loading"
                            density="compact"
                            :no-data-text="t('LettersView.noData')"
                            hover
                            class="zebra-table letters-table"
                            @update:page="onPageChange"
                            @update:items-per-page="onItemsPerPageChange"
                            @click:row="(_, { item }) => openLetter(item)"
                        >
                            <template #item.letternumber="{ item }">
                                <span class="font-weight-medium text-no-wrap">{{ item.letternumber }}</span>
                            </template>
                            <template #item.date="{ item }">
                                <span class="text-no-wrap">{{ formatDate(item.date) }}</span>
                            </template>
                            <template #item.cv_name="{ item }">
                                <div class="d-flex align-center">
                                    <v-icon size="small" class="me-1" :color="item.src === 'V' ? 'orange-darken-2' : 'primary'">
                                        {{ item.src === 'V' ? 'mdi-truck-outline' : 'mdi-account-outline' }}
                                    </v-icon>
                                    <a href="#" class="text-decoration-none" @click.prevent.stop="openCustomer(item)">{{ item.cv_name }}</a>
                                </div>
                                <div v-if="item.contact_name" class="text-caption text-medium-emphasis">{{ item.contact_name }}</div>
                            </template>
                            <template #item.subject="{ item }">
                                <div class="text-truncate" :title="item.subject">{{ item.subject || t('LettersView.noSubject') }}</div>
                                <div v-if="item.reference" class="text-caption text-medium-emphasis text-truncate">{{ t('LettersView.reference') }}: {{ item.reference }}</div>
                            </template>
                            <template #item.sent_log="{ item }">
                                <div class="d-flex flex-wrap ga-1">
                                    <v-chip v-if="!item.sent_log?.length" size="x-small" variant="tonal" color="grey" prepend-icon="mdi-pencil-outline">
                                        {{ t('LettersView.statusDraft') }}
                                    </v-chip>
                                    <v-chip v-for="ch in sentChannels(item)" :key="ch.channel" size="x-small" variant="tonal" :color="ch.color" :prepend-icon="ch.icon" :title="formatWhen(ch.sent_at)">
                                        {{ t('LettersView.channel.' + ch.channel) }}<span v-if="ch.count > 1">&nbsp;×{{ ch.count }}</span>
                                    </v-chip>
                                </div>
                            </template>
                            <template #item.actions="{ item }">
                                <div class="d-inline-flex align-center">
                                    <v-btn icon size="x-small" variant="text" color="primary" :title="t('LettersView.edit')" @click.stop="openLetter(item)">
                                        <v-icon size="small">mdi-pencil</v-icon>
                                    </v-btn>
                                    <v-btn icon size="x-small" variant="text" color="error" :title="t('LettersView.pdf')" @click.stop="openPdf(item)">
                                        <v-icon size="small">mdi-file-pdf-box</v-icon>
                                    </v-btn>
                                    <v-btn icon size="x-small" variant="text" :title="t('LettersView.copy')" @click.stop="copyLetter(item)">
                                        <v-icon size="small">mdi-content-copy</v-icon>
                                    </v-btn>
                                </div>
                            </template>
                        </v-data-table-server>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, onActivated } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as toast from '@/core/utils/toasts.js'
import { openBase64Pdf } from '@/core/utils/download.js'

const { t, locale } = useI18n()
const router = useRouter()

const letters = ref([])
const total = ref(0)
const counts = ref({ all: 0, draft: 0, sent: 0 })
const loading = ref(false)
const page = ref(1)
const itemsPerPage = ref(50)

const search = ref('')
const src = ref('')
const status = ref('')
const dateFrom = ref('')
const dateTo = ref('')

const srcItems = computed(() => [
    { value: '', title: t('LettersView.all') },
    { value: 'C', title: t('LettersView.customers') },
    { value: 'V', title: t('LettersView.vendors') },
])

const headers = computed(() => [
    { title: t('LettersView.number'), key: 'letternumber', sortable: false, nowrap: true },
    { title: t('LettersView.date'), key: 'date', sortable: false, nowrap: true },
    { title: t('LettersView.recipient'), key: 'cv_name', sortable: false },
    { title: t('LettersView.subject'), key: 'subject', sortable: false, cellProps: { class: 'letters-subject' } },
    { title: t('LettersView.dispatch'), key: 'sent_log', sortable: false },
    { title: t('LettersView.employee'), key: 'employee_name', sortable: false, nowrap: true },
    { title: '', key: 'actions', sortable: false, align: 'end', width: '110px' },
])

const channelMeta = {
    email: { icon: 'mdi-email', color: 'info' },
    post:  { icon: 'mdi-mailbox-up-outline', color: 'deep-purple' },
}
function sentChannels(item) {
    const map = new Map()
    for (const e of item.sent_log || []) {
        const cur = map.get(e.channel)
        if (cur) cur.count++
        else map.set(e.channel, { ...e, ...(channelMeta[e.channel] || { icon: 'mdi-send', color: 'grey' }), count: 1 })
    }
    return [...map.values()]
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString(locale.value)
}
function formatWhen(v) {
    if (!v) return ''
    return new Date(v).toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

let searchTimer = null
function onFilterChange() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { page.value = 1; load() }, 250)
}
function onPageChange(p) { page.value = p; load() }
function onItemsPerPageChange(n) { itemsPerPage.value = n; page.value = 1; load() }

async function load() {
    loading.value = true
    try {
        const { data } = await axios.post('/api/letters/', {
            action: 'getLetters',
            search: search.value || '',
            src: src.value || '',
            status: status.value || '',
            date_from: dateFrom.value || '',
            date_to: dateTo.value || '',
            limit: itemsPerPage.value,
            offset: (page.value - 1) * itemsPerPage.value,
        })
        if (!data.success) throw new Error(data.text)
        const r = data.payload.results
        letters.value = r.letters || []
        total.value = Number(r.total || 0)
        counts.value = r.counts || { all: 0, draft: 0, sent: 0 }
    } catch (e) {
        toast.error(t('LettersView.loadError', { error: e.message }))
    } finally {
        loading.value = false
    }
}

function openLetter(item) {
    router.push({ name: 'letter-edit', params: { id: item.id } })
}
function copyLetter(item) {
    router.push({ name: 'letter-new', query: { copy_of: String(item.id) } })
}
function openCustomer(item) {
    router.push({ name: item.src === 'V' ? 'change-vendor' : 'change-customer', params: { id: item.cv_id } })
}
async function openPdf(item) {
    try {
        const { data } = await axios.post('/api/letters/', { action: 'getLetterPdf', id: item.id })
        if (!data.success) throw new Error(data.text)
        openBase64Pdf(data.payload.results.pdf, data.payload.results.filename)
    } catch (e) {
        toast.error(e.message)
    }
}

onMounted(load)
onActivated(load)
</script>

<style scoped>
/* Betreffspalte bekommt den Restplatz und kürzt, statt die Tabelle zu sprengen */
.letters-table :deep(.letters-subject) {
    max-width: 0;
    width: 100%;
}
.letters-table :deep(tbody tr) {
    cursor: pointer;
}
</style>
