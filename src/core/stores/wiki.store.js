// src/core/stores/wiki.store.js

import { defineStore } from 'pinia'
import axios from 'axios'
import { ApiError } from '@/core/utils/error.js'

export const wikiStore = defineStore('wikiStore', () => {

    async function fetchPages(filter = {}) {
        const response = await axios.post('/api/wiki/', {
            action: 'getWikiPages',
            ...filter
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload.result.pages
    }

    async function fetchPage(id) {
        const response = await axios.post('/api/wiki/', {
            action: 'getWikiPage',
            id
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload.result.page
    }

    async function savePage(data) {
        const response = await axios.post('/api/wiki/', {
            action: 'saveWikiPage',
            ...data
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload
    }

    async function deletePage(id) {
        const response = await axios.post('/api/wiki/', {
            action: 'deleteWikiPage',
            id
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data
    }

    async function fetchCategories() {
        const response = await axios.post('/api/wiki/', {
            action: 'getWikiCategories'
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload.result.categories
    }

    async function saveCategory(data) {
        const response = await axios.post('/api/wiki/', {
            action: 'saveWikiCategory',
            ...data
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload
    }

    async function updateCategorySortOrder(categories) {
        const response = await axios.post('/api/wiki/', {
            action: 'updateCategorySortOrder',
            categories
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data
    }

    async function deleteCategory(id) {
        const response = await axios.post('/api/wiki/', {
            action: 'deleteWikiCategory',
            id
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data
    }

    async function fetchRevisions(pageId) {
        const response = await axios.post('/api/wiki/', {
            action: 'getWikiRevisions',
            page_id: pageId
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload.result.revisions
    }

    async function restoreRevision(id, employee_id) {
        const response = await axios.post('/api/wiki/', {
            action: 'restoreWikiRevision',
            id,
            employee_id
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload
    }

    async function fetchPagesByKba(hsn, tsn) {
        const response = await axios.post('/api/wiki/', {
            action: 'getWikiPagesByKba',
            hsn,
            tsn
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload.result.pages
    }

    return {
        fetchPages,
        fetchPage,
        savePage,
        deletePage,
        fetchCategories,
        saveCategory,
        updateCategorySortOrder,
        deleteCategory,
        fetchRevisions,
        restoreRevision,
        fetchPagesByKba
    }
})
