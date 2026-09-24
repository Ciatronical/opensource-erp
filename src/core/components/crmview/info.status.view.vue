<!-- src/core/components/crmview/info.status.view.vue -->

<template>
    <div class="info-status">
        <!-- Status-Fakten -->
        <div class="d-flex align-center mb-1">
            <v-list-subheader class="text-uppercase font-weight-bold pl-0">{{ t('CrmView.status') }}</v-list-subheader>
            <v-spacer />
            <v-chip
                :color="cvProfile?.obsolete ? 'error' : 'success'"
                :prepend-icon="cvProfile?.obsolete ? 'mdi-account-off' : 'mdi-account-check'"
                variant="tonal"
                size="small"
                label
            >
                {{ cvProfile?.obsolete ? t('CrmView.statusObsolete') : t('CrmView.statusActive') }}
            </v-chip>
        </div>
        <v-row dense>
            <v-col v-for="fact in facts" :key="fact.key" cols="12" sm="6">
                <div class="fact">
                    <v-icon size="small" color="grey-darken-1" class="mr-3">{{ fact.icon }}</v-icon>
                    <div class="min-w-0">
                        <div class="text-caption text-medium-emphasis">{{ fact.label }}</div>
                        <div class="text-body-2 font-weight-medium text-truncate">{{ fact.value }}</div>
                    </div>
                </div>
            </v-col>
        </v-row>

        <v-divider class="my-3" />

        <!-- Interne Bemerkungen -->
        <div class="d-flex align-center mb-1">
            <v-list-subheader class="text-uppercase font-weight-bold pl-0">{{ t('CrmView.internalNotes') }}</v-list-subheader>
            <v-spacer />
            <v-btn
                v-if="hasNotes"
                icon
                size="x-small"
                variant="text"
                color="primary"
                :title="t('CrmView.editNotes')"
                @click="openNotesEdit"
            >
                <v-icon size="small">mdi-pencil</v-icon>
            </v-btn>
        </div>
        <v-sheet v-if="hasNotes" class="note-sheet pa-4" rounded>
            <div class="note-text">{{ notes }}</div>
        </v-sheet>
        <div v-else class="note-empty pa-4 rounded d-flex align-center flex-wrap ga-3">
            <v-icon color="grey">mdi-note-off-outline</v-icon>
            <span class="text-medium-emphasis flex-grow-1">{{ t('CrmView.noNotes') }}</span>
            <v-btn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="mdi-note-plus-outline"
                @click="openNotesEdit"
            >
                {{ t('CrmView.addNote') }}
            </v-btn>
        </div>

        <!-- Herkunft der personenbezogenen Daten -->
        <template v-if="contactOrigin">
            <v-divider class="my-3" />
            <v-list-subheader class="text-uppercase font-weight-bold pl-0">{{ t('CrmView.contactOrigin') }}</v-list-subheader>
            <div class="d-flex align-start">
                <v-icon size="small" color="grey-darken-1" class="mr-3 mt-1">mdi-shield-account-outline</v-icon>
                <div class="text-body-2 note-text">{{ contactOrigin }}</div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import { formatDate } from '@/core/utils/dateFormatter.js'

const { t, locale } = useI18n()
const router = useRouter()
const oserpData = oserpStore()

const cvProfile = computed(() => oserpData.customer_vendor?.profile)
const isVendor = computed(() => cvProfile.value?.src === 'V')

const notes = computed(() => (cvProfile.value?.notes || '').trim())
const hasNotes = computed(() => notes.value.length > 0)
const contactOrigin = computed(() => (cvProfile.value?.contact_origin || '').trim())

/**
 * Sucht einen Eintrag aus einer Stammdatenliste der Session anhand der ID
 */
function lookup(list, id, key) {
    if (id === null || id === undefined) return ''
    const hit = (list || []).find(item => String(item.id) === String(id))
    return hit?.[key] || ''
}

const facts = computed(() => {
    const p = cvProfile.value || {}
    const cfg = oserpData.session?.company_config || {}
    const list = []

    if (!isVendor.value) {
        const business = lookup(cfg.business_types, p.business_id, 'description')
        if (business) list.push({ key: 'business', icon: 'mdi-domain', label: t('CrmView.businessType'), value: business })
    }

    const salesman = lookup(cfg.employees, p.salesman_id, 'name')
    if (salesman) list.push({ key: 'salesman', icon: 'mdi-account-tie', label: t('CrmView.salesman'), value: salesman })

    const language = lookup(cfg.languages, p.language_id, 'description')
    if (language) list.push({ key: 'language', icon: 'mdi-translate', label: t('CrmView.language'), value: language })

    if (!isVendor.value && Number(p.creditlimit) > 0) {
        const currency = lookup(cfg.currencies, p.currency_id, 'name')
        list.push({
            key: 'creditlimit',
            icon: 'mdi-credit-card-outline',
            label: t('CrmView.creditLimit'),
            value: [formatNumber(p.creditlimit, locale.value, 2), currency].filter(Boolean).join(' ')
        })
    }

    if (p.itime) {
        list.push({ key: 'itime', icon: 'mdi-calendar-plus', label: t('CrmView.createdOn'), value: formatDate(p.itime, locale.value) })
    }

    return list
})

/**
 * Springt in den Bearbeiten-Modus direkt zum Feld "Interne Bemerkungen"
 */
function openNotesEdit() {
    const id = cvProfile.value?.id
    if (!id) return
    router.push({
        name: isVendor.value ? 'vendor-edit' : 'customer-edit',
        params: { id },
        query: { tab: 'billing', focus: 'notes' }
    })
}
</script>

<style scoped>
.fact {
    display: flex;
    align-items: center;
    padding: 6px 0;
}

.min-w-0 {
    min-width: 0;
}

/* Bemerkungen als "Haftnotiz" */
.note-sheet {
    background: linear-gradient(135deg, #fff8e1 0%, #fff3c4 100%);
    border: 1px solid #ffe082;
    border-left: 4px solid #ffb300;
}

.v-theme--dark .note-sheet {
    background: rgba(255, 179, 0, 0.12);
    border-color: rgba(255, 179, 0, 0.35);
    border-left-color: #ffb300;
}

.note-text {
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.55;
}

.note-empty {
    border: 1px dashed rgba(0, 0, 0, 0.25);
}

.v-theme--dark .note-empty {
    border-color: rgba(255, 255, 255, 0.25);
}
</style>
