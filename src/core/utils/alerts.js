// core/utils/alerts.js
import Swal from 'sweetalert2';
import * as toasts from './toasts.js';
import { ApiError } from '@/core/utils/error.js';
import i18n from '@/i18n';
const { t } = i18n.global;

// config
const closeBtnEnabled = true;
const cancelBtnEnabled = true;
const reverseBtnsEnabled = false;
const btnsStyling = false; // override default SweetAlert2 button styling
const customCss = {
    confirmButton: 'v-btn v-btn--elevated v-theme--light bg-primary v-btn--density-default v-btn--size-default v-btn--variant-elevated',
    cancelButton: 'v-btn v-theme--light text-secondary v-btn--density-default v-btn--size-default v-btn--variant-outlined ml-4',
}

export const info = (text) => {
    return toasts.info(text);
}

export const success = (text) => {
    return toasts.success(text);
}

export const warning = (text, title = '', confirmText = 'OK', cancelText = 'Abbrechen') => {
    return Swal.fire({
        icon: 'warning',
        title: title,
        text: text,
        showCloseButton: closeBtnEnabled,
        showCancelButton: cancelBtnEnabled,
        reverseButtons: reverseBtnsEnabled,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        buttonsStyling: btnsStyling,
        customClass: customCss,
    });
}

export const error = (text, title = '', apiError = false, confirmText = 'OK', cancelText = 'Abbrechen') => {
    let opts = {
        icon: 'error',
        title: title,
        text: text,
        showCloseButton: closeBtnEnabled,
        confirmButtonText: confirmText,
        reverseButtons: reverseBtnsEnabled,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        buttonsStyling: btnsStyling,
        customClass: customCss,
    }

    let footer = '';
    if(apiError && apiError instanceof ApiError) {
        if(apiError.message) {
            footer = `<p>${t('ErrorView.debugInfo')}</p>`;
            footer += `<details><summary>${t('ErrorView.clickToShowDetails')}</summary><pre style="text-align:left; max-height:200px; overflow:auto; margin-top:10px; padding:10px; background:#f5f5f5; border:1px solid #ddd;">${apiError.message}</pre></details>`;
            opts.footer = footer;
        }
    }

    return Swal.fire(opts);
}

export const question = (text, title = '', confirmText = 'OK', cancelText = 'Abbrechen') => {
    return Swal.fire({
        icon: 'question',
        title: title,
        text: text,
        showCloseButton: closeBtnEnabled,
        showCancelButton: cancelBtnEnabled,
        reverseButtons: reverseBtnsEnabled,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        buttonsStyling: btnsStyling,
        customClass: customCss,
    });
}