<!-- src/core/views/follow-up/components/follow-up-list.vue -->
<!-- Tabellarische Listenansicht für Wiedervorlagen -->
<template>
    <v-card variant="outlined">
        <!-- Toolbar -->
        <v-toolbar flat color="transparent" density="compact">
            <v-toolbar-title class="text-subtitle-2">
                {{ items.length }} {{ items.length === 1 ? t('FollowUpList.entry') : t('FollowUpList.entries') }}
            </v-toolbar-title>
            <v-spacer />
            <v-btn
                v-if="items.length > 0"
                color="error"
                variant="text"
                prepend-icon="mdi-delete-sweep"
                @click="deleteAllDialog = true"
            >
                {{ t('FollowUpList.deleteAll') }}
            </v-btn>
        </v-toolbar>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="items"
            :items-per-page="15"
            :search="search"
            item-value="id"
            :item-class="getRowClass"
            hover
            class="follow-up-table"
            @click:row="(event, { item }) => $emit('edit', item)"
        >
            <!-- Datum mit Prioritäts-Indikator -->
            <template #item.follow_up_date="{ item }">
                <div class="d-flex align-center">
                    <v-icon
                        size="small"
                        :color="getPriorityColor(item.priority)"
                        class="me-2"
                    >
                        {{ getPriorityIcon(item.priority) }}
                    </v-icon>
                    <span :class="{ 'text-decoration-line-through': item.is_done }">
                        {{ formatDate(item.follow_up_date) }}
                    </span>
                </div>
            </template>

            <!-- Betreff -->
            <template #item.subject="{ item }">
                <div
                    class="font-weight-medium"
                    :class="{ 'text-decoration-line-through text-grey': item.is_done }"
                >
                    {{ item.subject || item.note?.subject || '-' }}
                </div>
                <div
                    v-if="item.body || item.note?.body"
                    class="text-caption text-grey text-truncate"
                    style="max-width: 300px"
                >
                    {{ item.body || item.note?.body }}
                </div>
            </template>

            <!-- Verknüpfung -->
            <template #item.links="{ item }">
                <v-chip
                    v-for="link in item.links"
                    :key="link.id"
                    size="x-small"
                    :color="getLinkColor(link.trans_type)"
                    variant="tonal"
                    class="me-1"
                    @click.stop="navigateToLink(link)"
                    style="cursor: pointer;"
                >
                    <v-icon start size="x-small">
                        {{ getLinkIcon(link.trans_type) }}
                    </v-icon>
                    {{ link.trans_info || link.trans_type }}
                </v-chip>
                <span v-if="!item.links?.length" class="text-grey">-</span>
            </template>

            <!-- Zugewiesen an -->
            <template #item.assigned_employees="{ item }">
                <div class="d-flex">
                    <v-avatar
                        v-for="(emp, idx) in (item.assigned_employees || []).slice(0, 2)"
                        :key="emp.id"
                        size="28"
                        :color="avatarColors[idx % avatarColors.length]"
                        class="avatar-stack"
                        :style="{ marginLeft: idx > 0 ? '-6px' : '0' }"
                        :title="emp.employee_name"
                    >
                        <span class="text-caption text-white">
                            {{ getInitials(emp.employee_name) }}
                        </span>
                    </v-avatar>
                    <span
                        v-if="(item.assigned_employees || []).length > 2"
                        class="text-caption text-grey ms-1 align-self-center"
                    >
                        +{{ item.assigned_employees.length - 2 }}
                    </span>
                </div>
            </template>

            <!-- Status -->
            <template #item.is_done="{ item }">
                <v-chip
                    :color="item.is_done ? 'success' : 'warning'"
                    size="small"
                    variant="tonal"
                >
                    <v-icon start size="small">
                        {{ item.is_done ? 'mdi-check-circle' : 'mdi-clock-outline' }}
                    </v-icon>
                    {{ item.is_done ? t('FollowUpList.done') : t('FollowUpList.open') }}
                </v-chip>
            </template>

            <!-- Aktionen -->
            <template #item.actions="{ item }">
                <div class="d-flex gap-1">
                    <v-btn
                        v-if="!item.is_done"
                        icon
                        size="x-small"
                        variant="text"
                        color="success"
                        :title="t('FollowUpList.markDone')"
                        @click.stop="$emit('done', item)"
                    >
                        <v-icon size="small">mdi-check</v-icon>
                    </v-btn>
                    <v-btn
                        v-else
                        icon
                        size="x-small"
                        variant="text"
                        color="grey"
                        :title="t('FollowUpList.markUndone')"
                        @click.stop="$emit('undone', item)"
                    >
                        <v-icon size="small">mdi-undo</v-icon>
                    </v-btn>
                    <v-btn
                        icon
                        size="x-small"
                        variant="text"
                        color="primary"
                        :title="t('FollowUpList.edit')"
                        @click.stop="$emit('edit', item)"
                    >
                        <v-icon size="small">mdi-pencil</v-icon>
                    </v-btn>
                    <v-btn
                        icon
                        size="x-small"
                        variant="text"
                        color="error"
                        :title="t('FollowUpList.delete')"
                        @click.stop="$emit('delete', item)"
                    >
                        <v-icon size="small">mdi-delete</v-icon>
                    </v-btn>
                </div>
            </template>
        </v-data-table>

        <!-- Bestätigungs-Dialog: Alle löschen -->
        <v-dialog v-model="deleteAllDialog" max-width="500">
            <v-card>
                <v-card-title class="text-h6 bg-error text-white pa-4">
                    <v-icon class="me-2">mdi-alert</v-icon>
                    {{ t('FollowUpList.deleteAllConfirmTitle') }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <p>{{ t('FollowUpList.deleteAllConfirmText', { count: items.length }) }}</p>
                    <v-alert type="warning" variant="tonal" class="mt-3">
                        {{ t('FollowUpList.deleteAllWarning') }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="deleteAllDialog = false"
                    >
                        {{ t('FollowUpList.cancel') }}
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        @click="confirmDeleteAll"
                    >
                        {{ t('FollowUpList.deleteAllConfirm') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script setup>
// src/core/views/follow-up/components/follow-up-list.vue
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useViewHistory } from '@/core/composables/useViewHistory.js';

const { t, d } = useI18n();
const router = useRouter();
const { saveToHistory } = useViewHistory();

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    search: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['edit', 'done', 'undone', 'delete', 'delete-all']);

// State
const deleteAllDialog = ref(false);

// Avatar-Farben
const avatarColors = ['primary', 'secondary', 'success', 'info', 'warning'];

// Tabellenheader
const headers = computed(() => [
    {
        title: t('FollowUpList.headers.date'),
        key: 'follow_up_date',
        width: '140px',
        sortable: true
    },
    {
        title: t('FollowUpList.headers.subject'),
        key: 'subject',
        sortable: true
    },
    {
        title: t('FollowUpList.headers.link'),
        key: 'links',
        width: '180px',
        sortable: false
    },
    {
        title: t('FollowUpList.headers.assignedTo'),
        key: 'assigned_employees',
        width: '120px',
        sortable: false
    },
    {
        title: t('FollowUpList.headers.status'),
        key: 'is_done',
        width: '100px',
        sortable: true
    },
    {
        title: t('FollowUpList.headers.actions'),
        key: 'actions',
        width: '140px',
        sortable: false,
        align: 'end'
    }
]);

// Methods
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (date.toDateString() === today.toDateString()) {
        return t('FollowUpList.today');
    }

    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    if (date.toDateString() === tomorrow.toDateString()) {
        return t('FollowUpList.tomorrow');
    }

    return d(date, 'short');
}

function getPriorityColor(priority) {
    const colors = {
        overdue: 'red-darken-2',
        today: 'amber-darken-3',
        soon: 'orange-darken-2',
        normal: 'green-darken-2'
    };
    return colors[priority] || 'grey';
}

function getPriorityIcon(priority) {
    const icons = {
        overdue: 'mdi-alert-circle',
        today: 'mdi-calendar-today',
        soon: 'mdi-calendar-clock',
        normal: 'mdi-calendar'
    };
    return icons[priority] || 'mdi-calendar';
}

function getLinkColor(transType) {
    const colors = {
        customer: 'blue',
        vendor: 'purple',
        sales_quotation: 'cyan',
        sales_order: 'teal',
        sales_delivery_order: 'green',
        sales_invoice: 'lime',
        purchase_quotation: 'indigo',
        purchase_order: 'deep-purple',
        purchase_delivery_order: 'pink',
        purchase_invoice: 'red'
    };
    return colors[transType] || 'grey';
}

function getLinkIcon(transType) {
    const icons = {
        customer: 'mdi-account',
        vendor: 'mdi-truck',
        sales_quotation: 'mdi-file-document-outline',
        sales_order: 'mdi-cart',
        sales_delivery_order: 'mdi-package-variant',
        sales_invoice: 'mdi-receipt',
        purchase_quotation: 'mdi-file-question-outline',
        purchase_order: 'mdi-cart-arrow-down',
        purchase_delivery_order: 'mdi-package-variant-closed',
        purchase_invoice: 'mdi-receipt-text'
    };
    return icons[transType] || 'mdi-link';
}

const historyTypeMap = {
    customer: 'customer',
    vendor: 'vendor',
    sales_quotation: 'quotation',
    sales_order: 'order',
    sales_invoice: 'invoice',
};

function navigateToLink(link) {
    // Navigation basierend auf trans_type - EXAKT wie in follow-up-card.vue
    const routes = {
        customer: `/kunde/bearbeiten/${link.trans_id}`,
        vendor: `/lieferant/bearbeiten/${link.trans_id}`,
        sales_quotation: `/angebot/bearbeiten/${link.trans_id}`,
        sales_order: `/auftrag/bearbeiten/${link.trans_id}`,
        sales_invoice: `/rechnung/anzeigen/${link.trans_id}`
    };

    if (routes[link.trans_type]) {
        saveToHistory({
            type: historyTypeMap[link.trans_type] || link.trans_type,
            id: link.trans_id,
            title: link.trans_info || '',
            subtitle: '',
            route: routes[link.trans_type]
        });
        router.push(routes[link.trans_type]);
    } else {
        console.warn('Unknown link type:', link.trans_type);
    }
}

function getInitials(name) {
    if (!name) return '?';
    const parts = name.split(' ');
    if (parts.length >= 2) {
        return parts[0][0] + parts[parts.length - 1][0];
    }
    return name.substring(0, 2).toUpperCase();
}

function confirmDeleteAll() {
    emit('delete-all', props.items.map(item => item.id));
    deleteAllDialog.value = false;
}

function getRowClass(item) {
    return item.is_done ? 'bg-grey-lighten-4 opacity-60' : '';
}
</script>

<style scoped>
.follow-up-table :deep(tr:hover) {
    background-color: #f5f5f5 !important;
}

.avatar-stack {
    border: 2px solid white;
}
</style>