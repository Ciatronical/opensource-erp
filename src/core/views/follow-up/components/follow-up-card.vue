<!-- src/core/views/follow-up/components/follow-up-card.vue -->
<!-- Post-It Style Karte für Wiedervorlagen -->
<template>
    <v-card
        class="follow-up-card mb-2"
        :class="[
            `bg-${color}`,
            { 'follow-up-card-done': done }
        ]"
        :elevation="hover ? 4 : 1"
        @mouseenter="hover = true"
        @mouseleave="hover = false"
        @click="$emit('click')"
    >
        <!-- Farbstreifen oben (Post-It Effekt) -->
        <div
            class="card-stripe"
            :class="stripeColor"
        />

        <v-card-text class="pa-3 pb-1">
            <!-- Header: Datum & Aktionen -->
            <div class="d-flex justify-space-between align-start mb-2">
                <div class="d-flex align-center">
                    <v-icon
                        size="small"
                        :color="priorityColor"
                        class="me-1"
                    >
                        {{ priorityIcon }}
                    </v-icon>
                    <span
                        class="text-caption font-weight-medium"
                        :class="`text-${priorityColor}`"
                    >
                        {{ formattedDate }}
                    </span>
                </div>

                <!-- Quick Actions (erscheinen bei Hover) -->
                <div
                    class="card-actions"
                    :class="{ 'opacity-100': hover }"
                    @click.stop
                >
                    <v-btn
                        v-if="!done"
                        icon
                        size="x-small"
                        variant="text"
                        color="success"
                        :title="t('FollowUpCard.markDone')"
                        @click="$emit('done')"
                    >
                        <v-icon size="small">mdi-check</v-icon>
                    </v-btn>
                    <v-btn
                        v-else
                        icon
                        size="x-small"
                        variant="text"
                        color="grey"
                        :title="t('FollowUpCard.markUndone')"
                        @click="$emit('undone')"
                    >
                        <v-icon size="small">mdi-undo</v-icon>
                    </v-btn>
                    <v-btn
                        icon
                        size="x-small"
                        variant="text"
                        color="error"
                        :title="t('FollowUpCard.delete')"
                        @click="$emit('delete')"
                    >
                        <v-icon size="small">mdi-delete</v-icon>
                    </v-btn>
                </div>
            </div>

            <!-- Subject -->
            <h4
                class="text-body-2 font-weight-bold mb-1 line-clamp-2"
                :class="{ 'text-decoration-line-through': done }"
            >
                {{ followUp.subject || followUp.note?.subject || t('FollowUpCard.noSubject') }}
            </h4>

            <!-- Body Preview -->
            <p
                v-if="followUp.body || followUp.note?.body"
                class="text-caption text-grey-darken-1 mb-2 line-clamp-3"
                :class="{ 'text-decoration-line-through': done }"
            >
                {{ followUp.body || followUp.note?.body }}
            </p>

            <!-- Verknüpfung -->
            <div
                v-if="followUp.links && followUp.links.length > 0"
                class="mb-2"
            >
                <v-chip
                    v-for="link in followUp.links"
                    :key="link.id"
                    size="x-small"
                    :color="getLinkColor(link.trans_type)"
                    variant="tonal"
                    class="me-1 mb-1"
                    @click.stop="navigateToLink(link)"
                >
                    <v-icon start size="x-small">
                        {{ getLinkIcon(link.trans_type) }}
                    </v-icon>
                    {{ link.trans_info || link.trans_type }}
                </v-chip>
            </div>
        </v-card-text>

        <!-- Footer: Zugewiesene Mitarbeiter -->
        <v-card-actions
            v-if="followUp.assigned_employees?.length > 0"
            class="pt-0 px-3 pb-2"
        >
            <v-avatar
                v-for="(emp, idx) in followUp.assigned_employees.slice(0, 3)"
                :key="emp.id"
                size="24"
                :color="avatarColors[idx % avatarColors.length]"
                class="avatar-stack"
                :style="{ marginLeft: idx > 0 ? '-8px' : '0' }"
                :title="emp.employee_name"
            >
                <span class="text-caption text-white font-weight-medium">
                    {{ getInitials(emp.employee_name) }}
                </span>
            </v-avatar>
            <span
                v-if="followUp.assigned_employees.length > 3"
                class="text-caption text-grey ms-1"
            >
                +{{ followUp.assigned_employees.length - 3 }}
            </span>
        </v-card-actions>

        <!-- Done-Overlay -->
        <div v-if="done" class="done-overlay">
            <v-icon size="64" color="success">mdi-check-circle</v-icon>
        </div>
    </v-card>
</template>

<script setup>
// src/core/views/follow-up/components/follow-up-card.vue
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useViewHistory } from '@/core/composables/useViewHistory.js';

const { t, d } = useI18n();
const router = useRouter();
const { saveToHistory } = useViewHistory();

const props = defineProps({
    followUp: {
        type: Object,
        required: true
    },
    color: {
        type: String,
        default: 'amber-lighten-5'
    },
    done: {
        type: Boolean,
        default: false
    }
});

defineEmits(['click', 'done', 'undone', 'delete']);

// State
const hover = ref(false);

// Avatar-Farben für Mitarbeiter
const avatarColors = ['primary', 'secondary', 'success', 'info', 'warning'];

// Computed
const stripeColor = computed(() => {
    switch (props.followUp.priority) {
        case 'overdue': return 'bg-red';
        case 'today': return 'bg-amber';
        case 'soon': return 'bg-orange';
        default: return 'bg-green';
    }
});

const priorityColor = computed(() => {
    switch (props.followUp.priority) {
        case 'overdue': return 'red-darken-2';
        case 'today': return 'amber-darken-3';
        case 'soon': return 'orange-darken-2';
        default: return 'green-darken-2';
    }
});

const priorityIcon = computed(() => {
    switch (props.followUp.priority) {
        case 'overdue': return 'mdi-alert-circle';
        case 'today': return 'mdi-calendar-today';
        case 'soon': return 'mdi-calendar-clock';
        default: return 'mdi-calendar';
    }
});

const formattedDate = computed(() => {
    const date = new Date(props.followUp.follow_up_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);

    if (date.toDateString() === today.toDateString()) {
        return t('FollowUpCard.today');
    }
    if (date.toDateString() === tomorrow.toDateString()) {
        return t('FollowUpCard.tomorrow');
    }

    // Relative Zeit für nahe Daten
    const diffDays = Math.ceil((date - today) / (1000 * 60 * 60 * 24));
    if (diffDays < 0) {
        return t('FollowUpCard.daysAgo', { days: Math.abs(diffDays) });
    }
    if (diffDays <= 7) {
        return t('FollowUpCard.inDays', { days: diffDays });
    }

    // Volles Datum
    return d(date, 'short');
});

// Methods
function getInitials(name) {
    if (!name) return '?';
    const parts = name.split(' ');
    if (parts.length >= 2) {
        return parts[0][0] + parts[parts.length - 1][0];
    }
    return name.substring(0, 2).toUpperCase();
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
    // Navigation basierend auf trans_type mit named routes
    const routeMap = {
        customer: { name: 'customer-edit', params: { id: link.trans_id } },
        vendor: { name: 'vendor-edit', params: { id: link.trans_id } },
        sales_quotation: { name: 'quotation-edit', params: { id: link.trans_id } },
        sales_order: { name: 'order-edit', params: { id: link.trans_id } },
        sales_invoice: { name: 'invoice-view', params: { invoiceID: link.trans_id } }
    };

    if (routeMap[link.trans_type]) {
        saveToHistory({
            type: historyTypeMap[link.trans_type] || link.trans_type,
            id: link.trans_id,
            title: link.trans_info || '',
            subtitle: '',
            route: routeMap[link.trans_type]
        });
        router.push(routeMap[link.trans_type]);
    } else {
        console.warn('Unknown link type:', link.trans_type);
    }
}
</script>

<style scoped>
.follow-up-card {
    position: relative;
    cursor: pointer;
    transition: all 0.2s ease;
    overflow: hidden;
    border-radius: 4px !important;
}

.follow-up-card:hover {
    transform: translateY(-2px) rotate(0.5deg);
}

.card-stripe {
    height: 4px;
    width: 100%;
}

.card-actions {
    opacity: 0;
    transition: opacity 0.2s ease;
}

.card-actions.opacity-100 {
    opacity: 1;
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.avatar-stack {
    border: 2px solid white;
}

.follow-up-card-done {
    opacity: 0.7;
}

.done-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.7);
    pointer-events: none;
}

/* Post-It Papier-Effekt */
.follow-up-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    border-width: 0 16px 16px 0;
    border-style: solid;
    border-color: rgba(0, 0, 0, 0.05) rgba(255, 255, 255, 0.5);
}
</style>