import { oserpStore } from '@/core/stores/oserp.store'

export function log(...message) {
    if (oserpStore().isDebugMode()) {
        console.info(...message);
    }
}

export function warn(...message) {
    if (oserpStore().isDebugMode()) {
        console.warn(...message);
    }
}

export function error(...message) {
    if (oserpStore().isDebugMode()) {
        console.error(...message);
    }
}

export function debug(...message) {
    if (oserpStore().isDebugMode()) {
        console.debug(...message);
    }
}

export function trace(...message) {
    if (oserpStore().isDebugMode()) {
        console.trace(...message);
    }
}

export function info(...message) {
        console.info(...message);
}