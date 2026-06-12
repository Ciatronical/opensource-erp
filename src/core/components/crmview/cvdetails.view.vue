<!-- src/components/crmview/cvdetails.view.vue -->

<template>
    <v-sheet elevation="0" class="d-flex">
        <v-tabs v-model="cvContactTab" direction="vertical" color="primary" density="compact">
            <v-tab value="contact">
                <v-icon start>mdi-account-box</v-icon>
                {{ t('CrmView.contactData') }}
            </v-tab>
            <v-tab value="billing_addresses">
                <v-icon start>mdi-map-marker</v-icon>
                {{ t('CrmView.billingAddresses') }}
            </v-tab>
            <v-tab value="contacts">
                <v-icon start>mdi-account-group</v-icon>
                {{ t('CrmView.contactPersons') }}
            </v-tab>
            <v-tab value="custom_vars">
                <v-icon start>mdi-variable</v-icon>
                {{ t('CrmView.variables') }}
            </v-tab>
            <v-tab v-if="cvProfile?.id" value="files">
                <v-icon start>mdi-folder-open</v-icon>
                {{ t('CrmView.files') }}
            </v-tab>
            <v-tab v-if="cvProfile?.id" value="emails">
                <v-icon start>mdi-email-outline</v-icon>
                {{ t('CrmView.emails') }}
            </v-tab>
            <v-tab v-if="cvProfile?.id" value="whatsapp">
                <v-icon start>mdi-whatsapp</v-icon>
                {{ t('CrmView.whatsapp') }}
            </v-tab>
        </v-tabs>

        <v-divider vertical />

        <v-window v-model="cvContactTab" class="flex-grow-1" :transition="false" :reverse-transition="false">
            <!-- Kontaktdaten -->
            <v-window-item value="contact">
                <div class="pa-4">
                    <CrmContactView />
                </div>
            </v-window-item>

            <!-- Zusätzliche Rechnungsadressen -->
            <v-window-item value="billing_addresses">
                <v-expansion-panels variant="accordion">
                    <v-expansion-panel
                        v-for="address in billing_addresses"
                        :key="address.id"
                    >
                        <v-expansion-panel-title>
                            <div class="d-flex align-center">
                                <v-icon class="mr-2">mdi-map-marker</v-icon>
                                <div>
                                    <strong>{{ address.name }}</strong>
                                    <span v-if="address.contact" class="ml-2 text-medium-emphasis">
                                        ({{ address.contact }})
                                    </span>
                                    <span v-if="address.phone" class="ml-4 text-medium-emphasis">
                                        <v-icon size="small">mdi-phone</v-icon>
                                        {{ formatPhone(address.phone) }}
                                    </span>
                                </div>
                            </div>
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-list density="compact">
                                <v-list-item v-if="address.street">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-road</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.street }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.street') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.zipcode || address.city">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-city</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.zipcode }} {{ address.city }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.zipCity') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.country">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-flag</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.country }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.country') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.department_1">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-office-building</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.department_1 }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.department1') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.department_2">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-office-building-outline</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.department_2 }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.department2') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.email">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-email</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.email }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.email') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.fax">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-fax</v-icon>
                                    </template>
                                    <v-list-item-title>{{ formatPhone(address.fax) }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.fax') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="address.gln">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-barcode</v-icon>
                                    </template>
                                    <v-list-item-title>{{ address.gln }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.gln') }}</v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
                <v-alert v-if="!billing_addresses?.length" type="info" variant="tonal" class="ma-4">
                    {{ t('CrmView.noBillingAddresses') }}
                </v-alert>
            </v-window-item>

            <!-- Ansprechpersonen -->
            <v-window-item value="contacts">
                <v-expansion-panels v-model="expandedContactPanel" variant="accordion">
                    <v-expansion-panel
                        v-for="contact in contacts"
                        :key="contact.cp_id"
                    >
                        <v-expansion-panel-title>
                            <div class="d-flex align-center">
                                <v-icon class="mr-2">mdi-account</v-icon>
                                <div>
                                    <strong>{{ contact.cp_givenname }} {{ contact.cp_name }}</strong>
                                    <span v-if="contact.cp_phone1" class="ml-4">
                                        <PhoneActionBar
                                            :phone="contact.cp_phone1"
                                            :name="(contact.cp_givenname || '') + ' ' + (contact.cp_name || '')"
                                            @whatsapp="switchToWhatsApp"
                                        />
                                    </span>
                                </div>
                            </div>
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-list density="compact">
                                <v-list-item v-if="contact.cp_title">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-card-account-details</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_title }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.salutation') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_position">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-briefcase</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_position }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.position') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_abteilung">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-office-building</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_abteilung }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.department') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_project">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-folder</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_project }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.project') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-divider v-if="contact.cp_street || contact.cp_zipcode || contact.cp_city" class="my-2"></v-divider>
                                <v-list-item v-if="contact.cp_street">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-road</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_street }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.street') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_zipcode || contact.cp_city">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-city</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_zipcode }} {{ contact.cp_city }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.zipCity') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-divider class="my-2"></v-divider>
                                <v-list-item v-if="contact.cp_phone2">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-phone</v-icon>
                                    </template>
                                    <v-list-item-title>
                                        <PhoneActionBar
                                            :phone="contact.cp_phone2"
                                            :name="contactFullName(contact)"
                                            @whatsapp="switchToWhatsApp"
                                        />
                                    </v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.phone2') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_mobile1">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-cellphone</v-icon>
                                    </template>
                                    <v-list-item-title>
                                        <PhoneActionBar
                                            :phone="contact.cp_mobile1"
                                            :name="contactFullName(contact)"
                                            @whatsapp="switchToWhatsApp"
                                        />
                                    </v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.mobile1') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_mobile2">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-cellphone</v-icon>
                                    </template>
                                    <v-list-item-title>
                                        <PhoneActionBar
                                            :phone="contact.cp_mobile2"
                                            :name="contactFullName(contact)"
                                            @whatsapp="switchToWhatsApp"
                                        />
                                    </v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.mobile2') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_fax">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-fax</v-icon>
                                    </template>
                                    <v-list-item-title>{{ formatPhone(contact.cp_fax) }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.fax') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_satphone">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-satellite-uplink</v-icon>
                                    </template>
                                    <v-list-item-title>{{ formatPhone(contact.cp_satphone) }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.satPhone') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_satfax">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-satellite-uplink</v-icon>
                                    </template>
                                    <v-list-item-title>{{ formatPhone(contact.cp_satfax) }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.satFax') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-divider class="my-2"></v-divider>
                                <v-list-item v-if="contact.cp_email">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-email</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_email }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.email') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_privatemail">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-email-outline</v-icon>
                                    </template>
                                    <v-list-item-title>{{ contact.cp_privatemail }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.privateEmail') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_privatphone">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-phone-outline</v-icon>
                                    </template>
                                    <v-list-item-title>
                                        <PhoneActionBar
                                            :phone="contact.cp_privatphone"
                                            :name="contactFullName(contact)"
                                            @whatsapp="switchToWhatsApp"
                                        />
                                    </v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.privatePhone') }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item v-if="contact.cp_birthday">
                                    <template v-slot:prepend>
                                        <v-icon>mdi-cake-variant</v-icon>
                                    </template>
                                    <v-list-item-title>{{ formatDate(contact.cp_birthday) }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ t('CrmView.birthday') }}</v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
                <v-alert v-if="!contacts?.length" type="info" variant="tonal" class="ma-4">
                    {{ t('CrmView.noContactPersons') }}
                </v-alert>
            </v-window-item>

            <!-- Variablen -->
            <v-window-item value="custom_vars">
                <v-data-table
                    :headers="customVarHeaders"
                    :items="custom_vars"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="t('CrmView.noVariables')"
                >
                </v-data-table>
            </v-window-item>

            <!-- Dokumente -->
            <v-window-item v-if="cvProfile?.id" value="files">
                <files-tab
                    :cv-id="cvProfile.id"
                    :src="cvProfile.src || 'C'"
                    :cv-name="cvProfile.name || ''"
                />
            </v-window-item>

            <!-- E-Mails -->
            <v-window-item v-if="cvProfile?.id" value="emails">
                <emails-tab
                    :cv-id="cvProfile.id"
                    :src="cvProfile.src || 'C'"
                    :email-addresses="cvEmailAddresses"
                />
            </v-window-item>

            <!-- WhatsApp -->
            <v-window-item v-if="cvProfile?.id" value="whatsapp">
                <whatsapp-tab
                    :cv-id="cvProfile.id"
                    :src="cvProfile.src || 'C'"
                    :phone-numbers="cvPhoneNumbers"
                    :customer-name="cvProfile.name || ''"
                />
            </v-window-item>
        </v-window>
    </v-sheet>
</template>

<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import CrmContactView from '@/core/components/crmview/contact.view.vue'
import PhoneActionBar from '@/core/components/phone-action-bar.vue'
const FilesTab = defineAsyncComponent(() => import('@/core/views/customer-vendor/tabs/files.tab.vue'))
import EmailsTab from '@/core/views/customer-vendor/tabs/emails.tab.vue'
import WhatsappTab from '@/core/views/customer-vendor/tabs/whatsapp.tab.vue'
import { formatPhone } from '@/core/utils/phoneFormat.js'

const { t } = useI18n();
const oserpData = oserpStore();
const billing_addresses = computed(() => oserpData.customer_vendor?.billing_addresses || []);
const contacts = computed(() => oserpData.customer_vendor?.contacts || []);
const custom_vars = computed(() =>
    (oserpData.customer_vendor?.custom_vars || []).filter(
        v => v.value !== null && v.value !== '' && v.value !== undefined
    )
);
const cvProfile = computed(() => oserpData.customer_vendor?.profile);

const route = useRoute()
const cvContactTab = ref(route.query.tab || 'contact');
const expandedContactPanel = ref(undefined);

watch(() => route.query.tab, (newTab) => {
    if (newTab) cvContactTab.value = newTab;
});

// Expansion-Panel der gesuchten Ansprechperson aufklappen
watch([contacts, () => route.query.cpId], ([contactList, cpId]) => {
    if (!cpId || !contactList?.length) return;
    const idx = contactList.findIndex(c => String(c.cp_id) === String(cpId));
    if (idx >= 0) expandedContactPanel.value = idx;
}, { immediate: true });

const cvPhoneNumbers = computed(() => {
    const nums = []
    const addNum = (num) => {
        const trimmed = (num || '').trim()
        if (trimmed && !nums.includes(trimmed)) nums.push(trimmed)
    }
    addNum(cvProfile.value?.phone)
    contacts.value.forEach(c => {
        addNum(c.cp_phone1)
        addNum(c.cp_phone2)
        addNum(c.cp_mobile1)
        addNum(c.cp_mobile2)
    })
    return nums
});

const cvEmailAddresses = computed(() => {
    const addrs = []
    const profileEmail = cvProfile.value?.email
    if (profileEmail) {
        profileEmail.split(/[;,]/).forEach(e => {
            const trimmed = e.trim()
            if (trimmed) addrs.push(trimmed)
        })
    }
    contacts.value.forEach(c => {
        if (c.cp_email) {
            c.cp_email.split(/[;,]/).forEach(e => {
                const trimmed = e.trim()
                if (trimmed && !addrs.includes(trimmed)) addrs.push(trimmed)
            })
        }
    })
    return addrs
});

const customVarHeaders = [
    { title: t('CrmView.description'), key: 'description', sortable: true },
    { title: t('CrmView.value'), key: 'value', sortable: true }
];

function switchToWhatsApp() {
    cvContactTab.value = 'whatsapp'
}

const contactFullName = (contact) => {
    return ((contact.cp_givenname || '') + ' ' + (contact.cp_name || '')).trim();
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('de-DE');
};
</script>
