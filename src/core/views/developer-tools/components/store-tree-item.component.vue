<!-- src/core/views/system/components/store-tree-item.component.vue -->
<template>
    <div class="tree-item">
        <!-- Array Items -->
        <div v-if="Array.isArray(data)">
            <div
                v-for="(item, index) in data"
                :key="index"
                class="tree-row"
            >
                <div class="tree-line">
                    <v-btn
                        v-if="!isPrimitive(item)"
                        :icon="isExpanded(index) ? 'mdi-chevron-down' : 'mdi-chevron-right'"
                        size="x-small"
                        variant="text"
                        @click="toggleExpand(index)"
                    ></v-btn>
                    <div v-else class="tree-spacer"></div>

                    <span class="tree-key">[{{ index }}]</span>
                    <span class="tree-type">{{ getTypeLabel(item) }}</span>
                    <span v-if="isPrimitive(item)" class="tree-value">{{ formatValue(item) }}</span>

                    <div class="tree-actions">
                        <v-btn
                            icon="mdi-content-copy"
                            size="x-small"
                            variant="text"
                            @click="copyPath(`${path}[${index}]`)"
                            :title="$t('DeveloperTools.store_viewer.copy_path')"
                        ></v-btn>
                        <v-btn
                            icon="mdi-code-json"
                            size="x-small"
                            variant="text"
                            @click="copyValue(`${path}[${index}]`, item)"
                            :title="$t('DeveloperTools.store_viewer.copy_value')"
                        ></v-btn>
                    </div>
                </div>

                <!-- Nested Object/Array -->
                <div v-if="!isPrimitive(item) && isExpanded(index)" class="tree-children">
                    <store-tree-item
                        :data="item"
                        :path="`${path}[${index}]`"
                        :expand-all="expandAll"
                        @copy-path="(p) => $emit('copy-path', p)"
                        @copy-value="(p, v) => $emit('copy-value', p, v)"
                    />
                </div>
            </div>
        </div>

        <!-- Object Properties -->
        <div v-else-if="typeof data === 'object' && data !== null">
            <div
                v-for="(value, key) in data"
                :key="key"
                class="tree-row"
            >
                <div class="tree-line">
                    <v-btn
                        v-if="!isPrimitive(value)"
                        :icon="isExpanded(key) ? 'mdi-chevron-down' : 'mdi-chevron-right'"
                        size="x-small"
                        variant="text"
                        @click="toggleExpand(key)"
                    ></v-btn>
                    <div v-else class="tree-spacer"></div>

                    <span class="tree-key">{{ key }}</span>
                    <span class="tree-type">{{ getTypeLabel(value) }}</span>
                    <span v-if="isPrimitive(value)" class="tree-value">{{ formatValue(value) }}</span>

                    <div class="tree-actions">
                        <v-btn
                            icon="mdi-content-copy"
                            size="x-small"
                            variant="text"
                            @click="copyPath(`${path}.${key}`)"
                            :title="$t('DeveloperTools.store_viewer.copy_path')"
                        ></v-btn>
                        <v-btn
                            icon="mdi-code-json"
                            size="x-small"
                            variant="text"
                            @click="copyValue(`${path}.${key}`, value)"
                            :title="$t('DeveloperTools.store_viewer.copy_value')"
                        ></v-btn>
                    </div>
                </div>

                <!-- Nested Object/Array -->
                <div v-if="!isPrimitive(value) && isExpanded(key)" class="tree-children">
                    <store-tree-item
                        :data="value"
                        :path="`${path}.${key}`"
                        :expand-all="expandAll"
                        @copy-path="(p) => $emit('copy-path', p)"
                        @copy-value="(p, v) => $emit('copy-value', p, v)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'StoreTreeItem'
};
</script>

<script setup>
import { reactive, watch } from 'vue';

/**
 * Props
 */
const props = defineProps({
    data: {
        type: [Object, Array, String, Number, Boolean],
        required: true
    },
    path: {
        type: String,
        default: ''
    },
    expandAll: {
        type: Boolean,
        default: false
    }
});

/**
 * Emits
 */
const emit = defineEmits(['copy-path', 'copy-value']);

// State - verwende reactive statt ref für bessere Objekt-Reaktivität
const expanded = reactive({});

// Alle Ebenen auf-/zuklappen wenn expandAll-Prop sich ändert
watch(() => props.expandAll, (val) => {
    if (!props.data || isPrimitive(props.data)) return;
    const keys = Array.isArray(props.data)
        ? props.data.map((_, i) => i)
        : Object.keys(props.data);
    for (const key of keys) {
        const item = Array.isArray(props.data) ? props.data[key] : props.data[key];
        if (!isPrimitive(item)) {
            expanded[key] = val;
        }
    }
});

/**
 * Prüft ob ein Element expandiert ist
 *
 * @param {string|number} key - Key des Elements
 * @returns {boolean}
 */
function isExpanded(key) {
    return expanded[key] === true;
}

/**
 * Togglet den Expand-Status eines Elements
 *
 * @param {string|number} key - Key des Elements
 */
function toggleExpand(key) {
    expanded[key] = !expanded[key];
}

/**
 * Prüft ob ein Wert primitiv ist
 *
 * @param {*} value - Zu prüfender Wert
 * @returns {boolean}
 */
function isPrimitive(value) {
    return value === null ||
           value === undefined ||
           typeof value === 'string' ||
           typeof value === 'number' ||
           typeof value === 'boolean';
}

/**
 * Formatiert einen Wert für die Anzeige
 *
 * @param {*} value - Zu formatierender Wert
 * @returns {string}
 */
function formatValue(value) {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (typeof value === 'string') return `"${value}"`;
    return String(value);
}

/**
 * Gibt das Type-Label zurück
 *
 * @param {*} value - Wert
 * @returns {string}
 */
function getTypeLabel(value) {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (Array.isArray(value)) return `Array[${value.length}]`;
    if (typeof value === 'object') return `Object{${Object.keys(value).length}}`;
    return typeof value;
}

/**
 * Gibt Event zum Kopieren eines Pfads weiter
 *
 * @param {string} path - Pfad
 */
function copyPath(path) {
    emit('copy-path', path);
}

/**
 * Gibt Event zum Kopieren eines Werts weiter
 *
 * @param {string} path - Pfad
 * @param {*} value - Wert
 */
function copyValue(path, value) {
    emit('copy-value', path, value);
}
</script>

<style scoped>
.tree-item {
    width: 100%;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

.tree-row {
    margin: 0;
}

.tree-line {
    display: flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 4px;
    transition: all 0.2s;
    gap: 12px;
    border-bottom: 1px solid #f0f0f0;
}

.tree-line:hover {
    background: linear-gradient(to right, #e3f2fd 0%, #f5f5f5 100%);
    border-bottom: 1px solid #2196f3;
    box-shadow: 0 1px 3px rgba(33, 150, 243, 0.1);
}

.tree-spacer {
    width: 28px;
    flex-shrink: 0;
}

.tree-key {
    font-weight: 700;
    color: #0d47a1;
    min-width: 140px;
    flex-shrink: 0;
    background: linear-gradient(to right, #e3f2fd, transparent);
    padding: 2px 8px;
    border-radius: 3px;
    border-left: 3px solid #2196f3;
}

.tree-type {
    color: #666;
    font-weight: 600;
    min-width: 110px;
    flex-shrink: 0;
    background-color: #fafafa;
    padding: 2px 8px;
    border-radius: 3px;
    border: 1px solid #e0e0e0;
}

.tree-value {
    color: #1b5e20;
    font-weight: 600;
    flex: 1;
    word-break: break-all;
    background-color: #f1f8f4;
    padding: 2px 8px;
    border-radius: 3px;
    border-left: 2px solid #4caf50;
}

.tree-children {
    padding-left: 40px;
    border-left: 2px solid #bbdefb;
    margin-left: 14px;
    position: relative;
}

.tree-children::before {
    content: '';
    position: absolute;
    left: -2px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, #2196f3, transparent);
}

.tree-actions {
    display: flex;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.2s;
    margin-left: auto;
    flex-shrink: 0;
}

.tree-line:hover .tree-actions {
    opacity: 1;
}
</style>