// src/core/stores/invoice.store.js

import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { ApiError } from '@/core/utils/error.js';

export const invoiceStore = defineStore('invoiceStore', () => {
    const data = ref(false);

    async function fetchInvoiceData(invoiceID) {
        const response = await axios.post('/api/invoice/', { action: 'getInvoiceData', invoiceID: invoiceID });
        if (response.data.success) {
            data.value = response.data.payload.main;
            console.log(response.data.payload);
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error fetching clients: ' + response.data.text);
        }
    }

    async function fetchParts(term, taxzone) {
        const response = await axios.post('/api/parts/', { action: 'findParts', term: term, taxzone: taxzone });
        if (response.data.success) {
            return response.data.payload.parts;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error fetching parts: ' + response.data.text);
        }
    }

    return {
        fetchInvoiceData,
        fetchParts,
        data
    };
});