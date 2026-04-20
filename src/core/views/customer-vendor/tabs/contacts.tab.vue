<!-- src/core/views/customer-vendor/tabs/contacts.tab.vue -->
<template>
  <v-row class="pa-2 pa-sm-3">
    <v-col cols="12">
      <v-card variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 d-flex flex-column flex-sm-row justify-space-between align-start align-sm-center bg-grey-lighten-4 ga-2">
          <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.contacts.contactsTitle') }}</h4>
          <div class="d-flex ga-2 flex-wrap">
            <v-btn
              color="primary"
              size="small"
              variant="tonal"
              :loading="scanning"
              :disabled="scanning"
              prepend-icon="mdi-creation"
              @click="openBusinessCardPicker"
            >
              {{ t('CustomerVendorEditView.businessCard.button') }}
            </v-btn>
            <v-btn color="primary" size="small" @click="addContact" prepend-icon="mdi-plus">
              {{ t('CustomerVendorEditView.contacts.addButton') }}
            </v-btn>
          </div>
          <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
            style="display:none"
            @change="onBusinessCardSelected"
          />
        </v-card-title>
        <v-divider />
        <v-card-text class="py-2 px-2 px-sm-3">
          <v-alert v-if="localData.length === 0" type="info" density="compact" class="mb-0">
            {{ t('CustomerVendorEditView.contacts.noContacts') }}
          </v-alert>
          <v-expansion-panels v-else variant="accordion" v-model="expandedPanels">
            <v-expansion-panel
              v-for="(contact, index) in localData"
              :key="contact.cp_id || `new-contact-\${index}`"
              :value="index"
            >
              <v-expansion-panel-title>
                <div class="d-flex align-center w-100 flex-wrap ga-1">
                  <v-icon class="mr-2">mdi-account</v-icon>
                  <span class="font-weight-medium">{{ getContactName(contact) }}</span>
                  <v-spacer />
                  <span class="text-caption text-medium-emphasis mr-2 d-none d-sm-inline">
                    {{ contact.cp_email || '' }}
                  </span>
                </div>
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <contact-form v-model="localData[index]" @remove="removeContact(index)" />
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top">
    {{ snackbar.text }}
    <template #actions>
      <v-btn icon="mdi-close" variant="text" @click="snackbar.show = false" />
    </template>
  </v-snackbar>
</template>

<script>
// src/core/views/customer-vendor/tabs/contacts.tab.vue
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as alerts from '@/core/utils/alerts.js'
import ContactForm from '../forms/contact.form.vue'

export default {
    name: 'ContactsTab',
    components: { ContactForm },
    props: {
        modelValue: { type: Array, required: true },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const expandedPanels = ref([])
        const fileInput = ref(null)
        const scanning = ref(false)
        const snackbar = ref({ show: false, color: 'success', text: '' })
        const localData = computed({
            get: () => props.modelValue,
            set: (value) => emit('update:modelValue', value)
        })

        const getContactName = (contact) => {
            const parts = []
            if (contact.cp_title) parts.push(contact.cp_title)
            if (contact.cp_givenname) parts.push(contact.cp_givenname)
            if (contact.cp_name) parts.push(contact.cp_name)
            return parts.length > 0 ? parts.join(' ') : t('CustomerVendorEditView.contacts.newContact')
        }

        const addContact = () => {
            // Schritt 1: Klappe alle Panels ein
            expandedPanels.value = []

            // Schritt 2: Füge neuen Kontakt hinzu
            const newContact = {
                cp_id: null,
                cp_cv_id: null,
                cp_title: '',
                cp_givenname: '',
                cp_name: '',
                cp_email: '',
                cp_phone1: '',
                cp_phone2: '',
                cp_mobile1: '',
                cp_mobile2: '',
                cp_fax: '',
                cp_privatphone: '',
                cp_privatemail: '',
                cp_abteilung: '',
                cp_position: '',
                cp_birthday: '',
                cp_gender: '',
                cp_street: '',
                cp_zipcode: '',
                cp_city: '',
            }
            localData.value = [...localData.value, newContact]

            // Schritt 3: Klappe nach kurzer Verzögerung den neuen Kontakt auf
            // (Vuetify braucht einen Moment, um das neue Panel zu rendern)
            setTimeout(() => {
                expandedPanels.value = [localData.value.length - 1]
            }, 50)
        }

        const removeContact = async (index) => {
            // Zeige Bestätigungsdialog mit SweetAlert2
            const result = await alerts.warning(
                t('CustomerVendorEditView.contacts.confirmRemove'),
                '',
                t('CustomerVendorEditView.contacts.removeButton'),
                'Abbrechen'
            )

            if (result.isConfirmed) {
                const contactToRemove = localData.value[index]

                // Wenn der Ansprechpartner bereits in der Datenbank existiert (cp_id vorhanden),
                // lösche ihn sofort aus der Datenbank
                if (contactToRemove.cp_id) {
                    try {
                        await axios.post('/api/customer_vendor/', {
                            action: 'deleteContact',
                            cp_id: contactToRemove.cp_id
                        })
                    } catch (error) {
                        console.error('Fehler beim Löschen des Ansprechpartners:', error)
                        await alerts.error(
                            t('CustomerVendorEditView.messages.deleteContactError'),
                            t('CustomerVendorEditView.messages.deleteContactErrorTitle')
                        )
                        return
                    }
                }

                // Entferne aus dem lokalen Array
                const updated = [...localData.value]
                updated.splice(index, 1)
                localData.value = updated
            }
        }

        function readFileAsBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader()
                reader.onload = () => {
                    const result = reader.result || ''
                    const idx = String(result).indexOf(',')
                    resolve(idx >= 0 ? String(result).slice(idx + 1) : String(result))
                }
                reader.onerror = () => reject(reader.error)
                reader.readAsDataURL(file)
            })
        }

        function openBusinessCardPicker() {
            if (scanning.value) return
            fileInput.value?.click()
        }

        async function onBusinessCardSelected(event) {
            const file = event.target.files?.[0]
            if (!file) return
            event.target.value = ''

            if (file.size > 10 * 1024 * 1024) {
                snackbar.value = { show: true, color: 'error', text: t('CustomerVendorEditView.businessCard.tooLarge') }
                return
            }

            scanning.value = true
            try {
                const fileBase64 = await readFileAsBase64(file)
                const response = await axios.post('/api/customer_vendor/', {
                    action: 'scanBusinessCard',
                    file_base64: fileBase64,
                    mime_type: file.type || 'image/jpeg'
                })
                if (!response.data?.success) {
                    throw new Error(response.data?.text || 'Scan fehlgeschlagen')
                }
                const extracted = response.data.payload?.extracted || {}
                const confidence = Number(extracted.confidence ?? 0)
                if (confidence <= 0) {
                    snackbar.value = {
                        show: true, color: 'warning',
                        text: t('CustomerVendorEditView.businessCard.lowConfidence')
                    }
                    return
                }

                // phone_numbers nach Label auf die passenden Kontakt-Felder verteilen
                const phoneFields = { phone2: '', mobile1: '', mobile2: '', fax: '', privatphone: '' }
                const phoneNumbers = Array.isArray(extracted.phone_numbers) ? extracted.phone_numbers : []
                for (const pn of phoneNumbers) {
                    const label = (pn?.label || '').toLowerCase()
                    const number = (pn?.number || '').trim()
                    if (!number) continue
                    if (label.includes('mobil') || label.includes('mobile') || label.includes('handy')) {
                        if (!phoneFields.mobile1) phoneFields.mobile1 = number
                        else if (!phoneFields.mobile2) phoneFields.mobile2 = number
                    } else if (label.includes('fax')) {
                        phoneFields.fax = number
                    } else if (label.includes('priv')) {
                        phoneFields.privatphone = number
                    } else {
                        if (!phoneFields.phone2) phoneFields.phone2 = number
                    }
                }

                // Fallback: Vor-/Nachname aus contact-Feld ableiten falls KI sie nicht getrennt hat
                let givenname = (extracted.contact_firstname || '').trim()
                let lastname  = (extracted.contact_lastname  || '').trim()
                const title   = (extracted.contact_title     || '').trim()
                if (!givenname && !lastname && extracted.contact) {
                    const cleaned = String(extracted.contact)
                        .replace(/(Dr\.|Prof\.|Prof\. Dr\.|Dipl\.-\S+)/gi, '')
                        .trim()
                    const parts = cleaned.split(/\s+/)
                    if (parts.length >= 2) {
                        lastname = parts.pop()
                        givenname = parts.join(' ')
                    } else if (parts.length === 1) {
                        lastname = parts[0]
                    }
                }

                const newContact = {
                    cp_id: null,
                    cp_cv_id: null,
                    cp_title: title,
                    cp_givenname: givenname,
                    cp_name: lastname,
                    cp_gender: (extracted.contact_gender || '').trim(),
                    cp_position: (extracted.contact_position || '').trim(),
                    cp_abteilung: (extracted.department_1 || '').trim(),
                    cp_email: (extracted.email || '').trim(),
                    cp_privatemail: '',
                    cp_phone1: (extracted.phone || '').trim(),
                    cp_phone2: phoneFields.phone2,
                    cp_mobile1: phoneFields.mobile1,
                    cp_mobile2: phoneFields.mobile2,
                    cp_fax: phoneFields.fax,
                    cp_privatphone: phoneFields.privatphone,
                    cp_birthday: '',
                    cp_street: (extracted.street || '').trim(),
                    cp_zipcode: (extracted.zipcode || '').trim(),
                    cp_city: (extracted.city || '').trim(),
                }

                expandedPanels.value = []
                localData.value = [...localData.value, newContact]
                setTimeout(() => {
                    expandedPanels.value = [localData.value.length - 1]
                }, 50)

                snackbar.value = {
                    show: true, color: 'success',
                    text: t('CustomerVendorEditView.businessCard.success', { confidence: Math.round(confidence * 100) })
                }
            } catch (e) {
                snackbar.value = {
                    show: true, color: 'error',
                    text: (e?.response?.data?.text || e?.message || t('CustomerVendorEditView.businessCard.error'))
                }
            } finally {
                scanning.value = false
            }
        }

        return {
            localData, expandedPanels, getContactName, addContact, removeContact,
            fileInput, scanning, snackbar,
            openBusinessCardPicker, onBusinessCardSelected,
            t,
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 { background-color: #f5f5f5; }
</style>