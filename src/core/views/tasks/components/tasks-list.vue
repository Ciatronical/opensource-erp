<!-- src/core/views/tasks/components/tasks-list.vue -->
<!-- Tabellarische Listenansicht für Wiedervorlagen -->
<template>
    <v-card variant="outlined">
        <v-data-table
            :headers="headers"
            :items="items"
            :items-per-page="15"
            :search="search"
            hover
            class="tasks-table"
        >
            <!-- Datum mit Prioritäts-Indikator -->
            <template #item.task_date="{ item }">
                <div class="d-flex align-center">
                    <v-icon
                        size="small"
                        :color="getPriorityColor(item.priority)"
                        class="me-2"
                    >
                        {{ getPriorityIcon(item.priority) }}
                    </v-icon>
                    <span :class="{ 'text-decoration-line-through': item.is_done }">
                        {{ formatDate(item.task_date) }}
                    </span>
                </div>
            </template>

            <!-- Betreff -->
            <template #item.note.subject="{ item }">
                <div
                    class="font-weight-medium"
                    :class="{ 'text-decoration-line-through text-grey': item.is_done }"
                >
                    {{ item.note?.subject || '-' }}
                </div>
                <div
                    v-if="item.note?.body"
                    class="text-caption text-grey text-truncate"
                    style="max-width: 300px"
                >
                    {{ item.note.body }}
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
                    {{ item.is_done ? t('TasksList.done') : t('TasksList.open') }}
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
                        :title="t('TasksList.markDone')"
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
                        :title="t('TasksList.markUndone')"
                        @click.stop="$emit('undone', item)"
                    >
                        <v-icon size="small">mdi-undo</v-icon>
                    </v-btn>
                    <v-btn
                        icon
                        size="x-small"
                        variant="text"
                        color="primary"
                        :title="t('TasksList.edit')"
                        @click.stop="$emit('edit', item)"
                    >
                        <v-icon size="small">mdi-pencil</v-icon>
                    </v-btn>
                    <v-btn
                        icon
                        size="x-small"
                        variant="text"
                        color="error"
                        :title="t('TasksList.delete')"
                        @click.stop="$emit('delete', item)"
                    >
                        <v-icon size="small">mdi-delete</v-icon>
                    </v-btn>
                </div>
            </template>

            <!-- Zeilen-Styling für erledigte Items -->
            <template #item="{ item, columns, internalItem }">
                <tr
                    :class="{ 'bg-grey-lighten-4 opacity-60': item.is_done }"
                    @click="$emit('edit', item)"
                    style="cursor: pointer"
                >
                    <td
                        v-for="column in columns"
                        :key="column.key"
                    >
                        <slot
                            :name="`item.${column.key}`"
                            :item="item"
                        >
                            {{ internalItem.columns[column.key] }}
                        </slot>
                    </td>
                </tr>
            </template>
        </v-data-table>
    </v-card>
</template>

<script setup>
// src/core/views/tasks/components/tasks-list.vue
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, d } = useI18n();

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

defineEmits(['edit', 'done', 'undone', 'delete']);

// Avatar-Farben
const avatarColors = ['primary', 'secondary', 'success', 'info', 'warning'];

// Tabellenheader
const headers = computed(() => [
    {
        title: t('TasksList.headers.date'),
        key: 'task_date',
        width: '140px',
        sortable: true
    },
    {
        title: t('TasksList.headers.subject'),
        key: 'note.subject',
        sortable: true
    },
    {
        title: t('TasksList.headers.link'),
        key: 'links',
        width: '180px',
        sortable: false
    },
    {
        title: t('TasksList.headers.assignedTo'),
        key: 'assigned_employees',
        width: '120px',
        sortable: false
    },
    {
        title: t('TasksList.headers.status'),
        key: 'is_done',
        width: '100px',
        sortable: true
    },
    {
        title: t('TasksList.headers.actions'),
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
        return t('TasksList.today');
    }

    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    if (date.toDateString() === tomorrow.toDateString()) {
        return t('TasksList.tomorrow');
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

function getInitials(name) {
    if (!name) return '?';
    const parts = name.split(' ');
    if (parts.length >= 2) {
        return parts[0][0] + parts[parts.length - 1][0];
    }
    return name.substring(0, 2).toUpperCase();
}
</script>

<style scoped>
.tasks-table :deep(tr:hover) {
    background-color: #f5f5f5 !important;
}

.avatar-stack {
    border: 2px solid white;
}
</style>
