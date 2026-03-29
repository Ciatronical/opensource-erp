<!-- src/core/views/customer-vendor/tabs/contacts.tab.vue -->
<template>
  <v-row class="pa-2 pa-sm-3">
    <v-col cols="12">
      <v-card variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 d-flex flex-column flex-sm-row justify-space-between align-start align-sm-center bg-grey-lighten-4 ga-2">
          <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.contacts.contactsTitle') }}</h4>
          <v-btn color="primary" size="small" @click="addContact" prepend-icon="mdi-plus">
            {{ t('CustomerVendorEditView.contacts.addButton') }}
          </v-btn>
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

        return { localData, expandedPanels, getContactName, addContact, removeContact, t }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 { background-color: #f5f5f5; }
</style>