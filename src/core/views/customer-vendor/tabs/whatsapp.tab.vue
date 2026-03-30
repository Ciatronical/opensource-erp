<!-- src/core/views/customer-vendor/tabs/whatsapp.tab.vue -->

<template>
  <v-row class="pa-2 pa-sm-3 whatsapp-tab">
    <v-col cols="12">
      <!-- Kein WhatsApp konfiguriert -->
      <v-alert v-if="configError" type="warning" variant="tonal" density="compact" class="mb-3">
        <v-icon start>mdi-cog-outline</v-icon>
        {{ t('CustomerVendorEditView.whatsapp.noConfig') }}
      </v-alert>

      <!-- Keine Telefonnummern -->
      <v-alert v-else-if="!phoneNumbers.length" type="info" variant="tonal" density="compact" class="mb-3">
        <v-icon start>mdi-phone-off</v-icon>
        {{ t('CustomerVendorEditView.whatsapp.noPhoneNumbers') }}
      </v-alert>

      <template v-else>
        <!-- Telefonnummer-Auswahl (wenn mehrere vorhanden) -->
        <v-select
          v-if="phoneNumbers.length > 1"
          v-model="selectedPhone"
          :items="phoneNumbers"
          :label="t('CustomerVendorEditView.whatsapp.selectPhone')"
          variant="outlined"
          density="compact"
          hide-details
          class="mb-3"
          style="max-width: 300px"
        />

        <!-- Chat-Verlauf -->
        <v-card variant="outlined" class="mb-3">
          <v-card-text ref="chatContainer" class="pa-3" style="max-height: 500px; overflow-y: auto;">
            <div v-if="loading" class="text-center py-4">
              <v-progress-circular indeterminate size="32" width="3" />
            </div>
            <div v-else-if="!messages.length" class="text-center text-medium-emphasis py-8">
              <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-whatsapp</v-icon>
              <div>{{ t('CustomerVendorEditView.whatsapp.noMessages') }}</div>
            </div>
            <template v-else>
              <div
                v-for="msg in messages"
                :key="msg.id"
                class="d-flex mb-2"
                :class="msg.direction === 'O' ? 'justify-end' : 'justify-start'"
              >
                <v-card
                  :color="msg.direction === 'O' ? 'green-lighten-4' : 'grey-lighten-3'"
                  class="pa-2 rounded-lg chat-msg-card"
                  style="max-width: 70%; min-width: 120px; position: relative;"
                  flat
                >
                  <!-- Löschen-Button (per Hover sichtbar) -->
                  <v-btn
                    class="chat-msg-delete"
                    icon
                    size="x-small"
                    variant="text"
                    :title="t('CustomerVendorEditView.whatsapp.deleteMessage')"
                    @click.stop="confirmDeleteMessage(msg)"
                  >
                    <v-icon size="14" color="grey">mdi-delete-outline</v-icon>
                  </v-btn>
                  <!-- Kontaktname bei eingehend -->
                  <div v-if="msg.direction === 'I' && msg.contact_name" class="text-caption font-weight-bold text-green-darken-3 mb-1">
                    {{ msg.contact_name }}
                  </div>
                  <!-- Audio -->
                  <div v-if="msg.message_type === 'audio' || msg.message_type === 'voice'" class="my-1">
                    <audio v-if="audioCache[msg.id]" :src="audioCache[msg.id]" controls preload="none" style="max-width: 260px; height: 36px;" />
                    <div v-else class="d-flex align-center">
                      <v-progress-circular v-if="audioLoading[msg.id]" indeterminate size="20" width="2" class="mr-1" />
                      <v-icon v-else size="20" class="mr-1">mdi-microphone</v-icon>
                      <span class="text-caption">{{ t('CustomerVendorEditView.whatsapp.voiceMessage') }}</span>
                    </div>
                  </div>
                  <!-- Kontakt -->
                  <div v-if="msg.message_type === 'contacts'" class="my-1">
                    <template v-if="parseContacts(msg.message_text).length">
                      <div
                        v-for="(contact, ci) in parseContacts(msg.message_text)"
                        :key="ci"
                        class="pa-2 rounded mb-1"
                        style="background: rgba(0,0,0,0.04);"
                      >
                        <div class="d-flex align-center mb-1">
                          <v-icon size="20" color="green-darken-2" class="mr-2">mdi-account-circle</v-icon>
                          <span class="font-weight-medium text-body-2">{{ contact.name }}</span>
                        </div>
                        <div v-if="contact.org" class="text-caption text-medium-emphasis ms-7">{{ contact.org }}</div>
                        <div v-for="(phone, pi) in contact.phones" :key="'p'+pi" class="d-flex align-center ms-7 mt-1">
                          <v-icon size="14" class="mr-1" color="grey">mdi-phone</v-icon>
                          <span class="text-caption">{{ phone }}</span>
                        </div>
                        <div v-for="(email, ei) in contact.emails" :key="'e'+ei" class="d-flex align-center ms-7 mt-1">
                          <v-icon size="14" class="mr-1" color="grey">mdi-email-outline</v-icon>
                          <span class="text-caption">{{ email }}</span>
                        </div>
                        <div class="d-flex ga-2 mt-2">
                          <v-btn size="x-small" variant="tonal" color="primary" prepend-icon="mdi-account-plus" @click="createFromContact(contact, 'C')">
                            {{ t('CustomerVendorEditView.whatsapp.createCustomer') }}
                          </v-btn>
                          <v-btn size="x-small" variant="tonal" color="secondary" prepend-icon="mdi-account-plus" @click="createFromContact(contact, 'V')">
                            {{ t('CustomerVendorEditView.whatsapp.createVendor') }}
                          </v-btn>
                        </div>
                      </div>
                    </template>
                    <div v-else class="d-flex align-center">
                      <v-icon size="20" color="green-darken-2" class="mr-1">mdi-account-circle</v-icon>
                      <span class="text-body-2">{{ msg.message_text || '[Kontakt]' }}</span>
                    </div>
                  </div>
                  <!-- Bild -->
                  <div v-if="msg.message_type === 'image'" class="my-1">
                    <div v-if="imageCache[msg.id]" style="position: relative; display: inline-block;">
                      <img :src="imageCache[msg.id]" style="max-width: 260px; max-height: 260px; border-radius: 6px; cursor: pointer; display: block;" @click="openImageViewer(imageCache[msg.id], msg.media_caption)" />
                      <div class="d-flex ga-1" style="position: absolute; top: 4px; right: 4px;">
                        <v-btn v-if="cvId" size="x-small" variant="tonal" icon :title="t('CustomerVendorEditView.whatsapp.saveToFolder')" :loading="savingMedia[msg.id]" style="background: rgba(255,255,255,0.85);" @click.stop="saveMediaToFolder(msg)">
                          <v-icon size="16" color="primary">mdi-folder-download</v-icon>
                        </v-btn>
                        <v-btn size="x-small" variant="tonal" icon style="background: rgba(255,255,255,0.85);" @click.stop="downloadImage(msg)">
                          <v-icon size="16">mdi-download</v-icon>
                        </v-btn>
                      </div>
                    </div>
                    <div v-else class="d-flex align-center justify-center" style="width: 200px; height: 140px; background: rgba(0,0,0,0.04); border-radius: 6px;">
                      <v-progress-circular v-if="msg.media_url && imageLoading[msg.id]" indeterminate size="28" width="2" color="grey" />
                      <div v-else class="text-center">
                        <v-icon size="40" color="grey-lighten-1">mdi-image</v-icon>
                        <div class="text-caption text-medium-emphasis">{{ msg.media_caption || msg.message_text || '' }}</div>
                      </div>
                    </div>
                  </div>
                  <!-- Video -->
                  <div v-if="msg.message_type === 'video'" class="my-1">
                    <div v-if="imageCache[msg.id]" style="position: relative; display: inline-block;">
                      <video :src="imageCache[msg.id]" controls preload="metadata" style="max-width: 260px; max-height: 260px; border-radius: 6px; display: block;" />
                      <div class="d-flex ga-1" style="position: absolute; top: 4px; right: 4px;">
                        <v-btn v-if="cvId" size="x-small" variant="tonal" icon :title="t('CustomerVendorEditView.whatsapp.saveToFolder')" :loading="savingMedia[msg.id]" style="background: rgba(255,255,255,0.85);" @click.stop="saveMediaToFolder(msg)">
                          <v-icon size="16" color="primary">mdi-folder-download</v-icon>
                        </v-btn>
                        <v-btn size="x-small" variant="tonal" icon style="background: rgba(255,255,255,0.85);" @click.stop="downloadImage(msg)">
                          <v-icon size="16">mdi-download</v-icon>
                        </v-btn>
                      </div>
                    </div>
                    <div v-else class="d-flex align-center justify-center" style="width: 200px; height: 140px; background: rgba(0,0,0,0.04); border-radius: 6px;">
                      <v-progress-circular v-if="msg.media_url && imageLoading[msg.id]" indeterminate size="28" width="2" color="grey" />
                      <div v-else class="text-center">
                        <v-icon size="40" color="grey-lighten-1">mdi-video-outline</v-icon>
                        <div class="text-caption text-medium-emphasis">{{ msg.media_caption || '' }}</div>
                      </div>
                    </div>
                    <div v-if="msg.media_caption" class="text-body-2 mt-1" style="white-space: pre-wrap;">{{ msg.media_caption }}</div>
                  </div>
                  <!-- Sticker -->
                  <div v-if="msg.message_type === 'sticker'" class="my-1">
                    <img v-if="imageCache[msg.id]" :src="imageCache[msg.id]" style="max-width: 150px; max-height: 150px;" />
                    <div v-else class="d-flex align-center">
                      <v-progress-circular v-if="msg.media_url && imageLoading[msg.id]" indeterminate size="20" width="2" class="mr-1" />
                      <v-icon v-else size="20" class="mr-1">mdi-sticker-emoji</v-icon>
                      <span class="text-caption">Sticker</span>
                    </div>
                  </div>
                  <!-- Dokument -->
                  <div v-if="msg.message_type === 'document'" class="my-1">
                    <div class="d-flex align-center pa-2 rounded" style="background: rgba(0,0,0,0.06); cursor: pointer;" @click="downloadDocument(msg)">
                      <v-icon size="24" color="red-darken-1" class="mr-2">mdi-file-pdf-box</v-icon>
                      <span class="text-caption flex-grow-1">{{ msg.message_text || 'Dokument' }}</span>
                      <v-btn v-if="cvId" icon size="x-small" variant="text" :title="t('CustomerVendorEditView.whatsapp.saveToFolder')" :loading="savingMedia[msg.id]" @click.stop="saveMediaToFolder(msg)">
                        <v-icon size="16" color="primary">mdi-folder-download</v-icon>
                      </v-btn>
                      <v-icon size="18" color="grey">mdi-download</v-icon>
                    </div>
                    <div v-if="msg.media_caption" class="text-body-2 mt-1" style="white-space: pre-wrap;">{{ msg.media_caption }}</div>
                  </div>
                  <!-- Standort -->
                  <div v-if="msg.message_type === 'location'" class="my-1">
                    <div style="width: 260px; border-radius: 6px; overflow: hidden; border: 1px solid rgba(0,0,0,0.1);">
                      <iframe :src="buildMapEmbedUrl(msg.message_text)" style="width: 260px; height: 160px; border: none; display: block; pointer-events: none;" loading="lazy"></iframe>
                      <a :href="'https://maps.google.com/?q=' + parseLocationCoords(msg.message_text)" target="_blank" rel="noopener" class="d-flex align-center pa-2 text-decoration-none" style="background: rgba(0,0,0,0.03);">
                        <v-icon size="18" color="red" class="mr-1">mdi-map-marker</v-icon>
                        <span class="text-caption text-medium-emphasis flex-grow-1">{{ parseLocationCoords(msg.message_text) }}</span>
                        <v-icon size="14" color="grey">mdi-open-in-new</v-icon>
                      </a>
                    </div>
                  </div>
                  <!-- Text (Fallback) -->
                  <div v-if="!['audio','voice','contacts','image','video','sticker','document','location'].includes(msg.message_type)" class="text-body-2" style="white-space: pre-wrap;" v-html="linkifyText(msg.message_text)"></div>
                  <!-- PDF-Anhang bei Text-/Template-Nachrichten -->
                  <div v-if="!['document','image'].includes(msg.message_type) && msg.media_url && msg.media_mime_type === 'application/pdf'" class="d-flex align-center mt-2 pa-2 rounded" style="background: rgba(0,0,0,0.06); cursor: pointer;" @click="downloadDocument(msg)">
                    <v-icon size="24" color="red-darken-1" class="mr-2">mdi-file-pdf-box</v-icon>
                    <span class="text-caption flex-grow-1">PDF</span>
                    <v-btn v-if="cvId" icon size="x-small" variant="text" :title="t('CustomerVendorEditView.whatsapp.saveToFolder')" :loading="savingMedia[msg.id]" @click.stop="saveMediaToFolder(msg)">
                      <v-icon size="16" color="primary">mdi-folder-download</v-icon>
                    </v-btn>
                    <v-icon size="18" color="grey">mdi-download</v-icon>
                  </div>
                  <div class="text-caption text-medium-emphasis text-right mt-1">
                    {{ formatDate(msg.itime) }}
                    <v-icon
                      v-if="msg.direction === 'O'"
                      size="12"
                      class="ms-1"
                      :color="msg.status === 'read' ? 'blue' : (msg.status === 'failed' ? 'red' : 'grey')"
                    >
                      {{ statusIcon(msg.status) }}
                    </v-icon>
                  </div>
                </v-card>
              </div>
            </template>
          </v-card-text>
        </v-card>

        <!-- Anhang-Vorschau -->
        <v-chip
          v-if="attachedFile"
          closable
          color="primary"
          variant="tonal"
          size="small"
          class="mb-2"
          @click:close="attachedFile = null"
        >
          <v-icon start size="small">mdi-paperclip</v-icon>
          {{ attachedFile.name }}
        </v-chip>

        <!-- Nachricht senden -->
        <div class="d-flex align-end ga-2">
          <!-- Büroklammer-Menü -->
          <v-menu location="top start">
            <template #activator="{ props: menuProps }">
              <v-btn
                v-bind="menuProps"
                icon="mdi-paperclip"
                variant="text"
                color="grey-darken-1"
                size="large"
              />
            </template>
            <v-list density="compact">
              <v-list-item prepend-icon="mdi-upload" @click="triggerFileUpload">
                {{ t('CustomerVendorEditView.whatsapp.uploadFile') }}
              </v-list-item>
              <v-list-item prepend-icon="mdi-folder-open" @click="openDocumentPicker">
                {{ t('CustomerVendorEditView.whatsapp.attachFromDocs') }}
              </v-list-item>
            </v-list>
          </v-menu>

          <input
            ref="fileInputRef"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mp3,.ogg"
            style="display: none"
            @change="onFileSelected"
          />

          <!-- Emoji-Picker -->
          <v-menu v-model="showEmojiPicker" :close-on-content-click="false" location="top start">
            <template #activator="{ props: emojiProps }">
              <v-btn
                v-bind="emojiProps"
                icon="mdi-emoticon-happy-outline"
                variant="text"
                color="grey-darken-1"
                size="large"
              />
            </template>
            <EmojiPicker
              :native="true"
              :disable-skin-tones="true"
              :display-recent="true"
              theme="light"
              @select="onEmojiSelect"
            />
          </v-menu>

          <v-textarea
            v-model="newMessage"
            :label="t('CustomerVendorEditView.whatsapp.typeMessage')"
            variant="outlined"
            density="compact"
            rows="2"
            auto-grow
            hide-details
            class="flex-grow-1"
            @keydown.enter.exact.prevent="doSend"
          />
          <v-btn
            color="green-darken-2"
            :loading="sending"
            :disabled="!newMessage.trim() && !attachedFile"
            @click="doSend"
            icon="mdi-send"
            size="large"
          />
        </div>
      </template>

      <!-- Bild-Betrachter Dialog -->
      <v-dialog v-model="showImageViewer" max-width="900">
        <v-card>
          <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
            <v-icon class="mr-2" size="small">mdi-image</v-icon>
            <span class="text-subtitle-1">{{ t('CustomerVendorEditView.whatsapp.image') }}</span>
            <v-spacer />
            <v-btn variant="text" size="x-small" icon @click="showImageViewer = false">
              <v-icon>mdi-close</v-icon>
            </v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="pa-0 text-center" style="background: #1a1a1a;">
            <img :src="viewerImageSrc" style="max-width: 100%; max-height: 80vh; object-fit: contain;" />
          </v-card-text>
          <template v-if="viewerCaption">
            <v-divider />
            <v-card-text class="py-2 text-body-2">{{ viewerCaption }}</v-card-text>
          </template>
        </v-card>
      </v-dialog>

      <!-- Speichern-Dialog -->
      <v-dialog v-model="showSaveDialog" max-width="500">
        <v-card>
          <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
            <v-icon class="mr-2" size="small" color="primary">mdi-folder-download</v-icon>
            <span class="text-subtitle-1">{{ t('CustomerVendorEditView.whatsapp.saveToFolder') }}</span>
            <v-spacer />
            <v-btn variant="text" size="x-small" icon @click="showSaveDialog = false">
              <v-icon>mdi-close</v-icon>
            </v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="py-3">
            <div class="text-caption font-weight-medium mb-1">{{ t('CustomerVendorEditView.whatsapp.saveLocation') }}</div>
            <div class="d-flex align-center mb-3 pa-2 rounded" style="background: rgba(0,0,0,0.04);">
              <v-icon size="small" class="mr-1" color="amber-darken-2">mdi-folder-open</v-icon>
              <span class="text-body-2">/{{ saveCurrentPath || t('CustomerVendorEditView.whatsapp.rootFolder') }}</span>
            </div>
            <div v-if="saveFolders.length || saveCurrentPath" class="text-caption font-weight-medium mb-1">{{ t('CustomerVendorEditView.whatsapp.changeFolder') }}</div>
            <v-card v-if="saveFolders.length || saveCurrentPath" variant="outlined" class="mb-3" style="max-height: 200px; overflow-y: auto;">
              <v-list density="compact" class="pa-0">
                <v-list-item v-if="saveCurrentPath" @click="navigateSaveFolder('..')" prepend-icon="mdi-arrow-up" title=".." class="text-medium-emphasis" />
                <v-list-item v-for="folder in saveFolders" :key="folder" @click="navigateSaveFolder(folder)" prepend-icon="mdi-folder" :title="folder" />
              </v-list>
              <div v-if="saveFolderLoading" class="d-flex justify-center pa-3">
                <v-progress-circular indeterminate size="24" width="2" />
              </div>
            </v-card>
            <v-text-field v-model="saveFilename" :label="t('CustomerVendorEditView.whatsapp.saveFilename')" variant="outlined" density="compact" hide-details />
          </v-card-text>
          <v-divider />
          <v-card-actions class="px-3 py-2">
            <v-spacer />
            <v-btn variant="text" @click="showSaveDialog = false">{{ t('CustomerVendorEditView.whatsapp.cancel') }}</v-btn>
            <v-btn color="primary" :loading="savingToFolder" :disabled="!saveFilename.trim()" @click="doSaveToFolder">
              <v-icon start size="small">mdi-content-save</v-icon>
              {{ t('CustomerVendorEditView.whatsapp.saveBtn') }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- Kundendokumente-Dialog -->
      <v-dialog v-model="showDocPicker" max-width="500">
        <v-card>
          <v-card-title class="d-flex align-center">
            <v-icon start>mdi-folder-open</v-icon>
            {{ t('CustomerVendorEditView.whatsapp.selectDocument') }}
            <v-spacer />
            <v-btn icon="mdi-close" size="x-small" variant="text" @click="showDocPicker = false" />
          </v-card-title>
          <v-divider />
          <v-card-text class="pa-0" style="max-height: 400px; overflow-y: auto;">
            <div v-if="docPickerLoading" class="text-center py-6">
              <v-progress-circular indeterminate size="32" width="3" />
            </div>
            <v-list v-else-if="customerFiles.length" density="compact">
              <v-list-item
                v-for="file in customerFiles"
                :key="file.path"
                :prepend-icon="file.basename?.endsWith('.pdf') ? 'mdi-file-pdf-box' : 'mdi-file-image'"
                @click="selectCustomerFile(file)"
              >
                <v-list-item-title>{{ file.basename }}</v-list-item-title>
              </v-list-item>
            </v-list>
            <div v-else class="text-center text-medium-emphasis py-6">
              {{ t('CustomerVendorEditView.whatsapp.noDocuments') }}
            </div>
          </v-card-text>
        </v-card>
      </v-dialog>

      <!-- Löschen-Bestätigungsdialog -->
      <v-dialog v-model="showDeleteConfirm" max-width="400">
        <v-card>
          <v-card-title class="d-flex align-center">
            {{ t('CustomerVendorEditView.whatsapp.deleteMessage') }}
            <v-spacer />
            <v-btn icon="mdi-close" size="x-small" variant="text" @click="showDeleteConfirm = false" />
          </v-card-title>
          <v-card-text>{{ t('CustomerVendorEditView.whatsapp.deleteConfirm') }}</v-card-text>
          <v-card-actions>
            <v-spacer />
            <v-btn variant="text" @click="showDeleteConfirm = false">{{ t('CustomerVendorEditView.whatsapp.cancel') }}</v-btn>
            <v-btn color="red" variant="flat" :loading="deleting" @click="doDeleteMessage">{{ t('CustomerVendorEditView.whatsapp.deleteMessage') }}</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
    </v-col>
  </v-row>
</template>

<script>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'
import { waMediaCache } from '@/core/utils/whatsappMediaCache.js'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'

// Gemeinsamer Cache fuer beide WhatsApp-Views (Standalone + Kunden-Tab)
const audioCache = waMediaCache
const imageCache = waMediaCache

export default {
  name: 'WhatsappTab',
  components: { EmojiPicker },
  props: {
    cvId: { type: [Number, String], required: true },
    src: { type: String, default: 'C' },
    phoneNumbers: { type: Array, default: () => [] },
    customerName: { type: String, default: '' }
  },
  setup(props) {
    const { t, locale } = useI18n()
    const router = useRouter()
    const route = useRoute()
    const oserp = oserpStore()

    const messages = ref([])
    const loading = ref(false)
    const sending = ref(false)
    const newMessage = ref('')
    const chatContainer = ref(null)
    const selectedPhone = ref('')
    const configError = ref(false)

    // Audio-/Bild-Loading-Status (lokal pro Instanz)
    const audioLoading = reactive({})
    const imageLoading = reactive({})

    // Bild-Betrachter
    const showImageViewer = ref(false)
    const viewerImageSrc = ref('')
    const viewerCaption = ref('')

    // Emoji-Picker
    const showEmojiPicker = ref(false)

    // Nachricht löschen
    const showDeleteConfirm = ref(false)
    const deleting = ref(false)
    const deleteTarget = ref(null)

    function confirmDeleteMessage(msg) {
      deleteTarget.value = msg
      showDeleteConfirm.value = true
    }

    async function doDeleteMessage() {
      if (!deleteTarget.value) return
      deleting.value = true
      try {
        const resp = await axios.post('/api/whatsapp/', {
          action: 'deleteWhatsAppMessage',
          message_id: deleteTarget.value.id
        })
        if (resp.data.success) {
          messages.value = messages.value.filter(m => m.id !== deleteTarget.value.id)
          toast.success(t('CustomerVendorEditView.whatsapp.deleteSuccess'))
        } else {
          toast.error(resp.data.text || t('CustomerVendorEditView.whatsapp.deleteError'))
        }
      } catch {
        toast.error(t('CustomerVendorEditView.whatsapp.deleteError'))
      } finally {
        deleting.value = false
        showDeleteConfirm.value = false
        deleteTarget.value = null
      }
    }

    // Speichern-Dialog
    const savingMedia = reactive({})
    const showSaveDialog = ref(false)
    const saveCurrentPath = ref('')
    const saveFolders = ref([])
    const saveFilename = ref('')
    const saveFolderLoading = ref(false)
    const savingToFolder = ref(false)
    let saveMediaMsg = null

    // Dateianhang
    const attachedFile = ref(null)
    const fileInputRef = ref(null)
    const showDocPicker = ref(false)
    const docPickerLoading = ref(false)
    const customerFiles = ref([])

    // SSE
    let eventSource = null

    // Config prüfen
    const hasConfig = computed(() => {
      const token = oserp.getClientDefaultValue('whatsapp_access_token', '')
      const phoneId = oserp.getClientDefaultValue('whatsapp_phone_number_id', '')
      return !!(token && phoneId)
    })

    function focusMessageInput() {
      nextTick(() => {
        const ta = document.querySelector('.whatsapp-tab textarea')
        if (ta) ta.focus()
      })
    }

    onMounted(() => {
      configError.value = !hasConfig.value
      if (props.phoneNumbers.length > 0) {
        // Telefonnummer aus Query vorauswählen (von PhoneActionBar-Klick)
        const queryPhone = route.query.whatsappPhone
        if (queryPhone && props.phoneNumbers.includes(queryPhone)) {
          selectedPhone.value = queryPhone
        } else {
          selectedPhone.value = props.phoneNumbers[0]
        }
        // Query-Parameter aufräumen
        if (queryPhone) {
          const { whatsappPhone, ...rest } = route.query
          router.replace({ query: rest })
        }
      }
      if (hasConfig.value && props.phoneNumbers.length > 0) {
        fetchMessages().then(() => focusMessageInput())

        // SSE für Echtzeit-Updates
        eventSource = new EventSource('/sse/events')
        eventSource.onmessage = (event) => {
          try {
            const data = JSON.parse(event.data)
            if (data.message_type === undefined) return
          } catch {
            return
          }
          fetchMessages()
        }
        eventSource.onerror = () => { /* still ignorieren */ }
      }
    })

    onUnmounted(() => {
      if (eventSource) {
        eventSource.close()
        eventSource = null
      }
    })

    // Bei Kunde/Lieferant-Wechsel neu laden
    watch(() => props.cvId, () => {
      if (props.phoneNumbers.length > 0) {
        selectedPhone.value = props.phoneNumbers[0]
      }
      messages.value = []
      if (hasConfig.value && props.phoneNumbers.length > 0) {
        fetchMessages()
      }
    })

    // Bei Telefonnummern-Wechsel (z.B. anderer Kunde mit anderen Nummern)
    watch(() => props.phoneNumbers, (newPhones) => {
      if (newPhones.length > 0) {
        selectedPhone.value = newPhones[0]
      }
      messages.value = []
      if (hasConfig.value && newPhones.length > 0) {
        fetchMessages()
      }
    })

    // Bei manueller Telefonnummer-Auswahl neu laden
    watch(selectedPhone, () => {
      if (hasConfig.value) fetchMessages()
    })

    async function fetchMessages() {
      loading.value = true
      try {
        const response = await axios.post('/api/whatsapp/', {
          action: 'getWhatsAppMessages',
          customer_id: props.cvId,
          phone_numbers: props.phoneNumbers,
          limit: 100
        })
        if (response.data.success) {
          messages.value = response.data.payload?.messages || []
          await nextTick()
          scrollToBottom()
          loadAudioMessages()
          loadImageMessages()
        }
      } catch {
        // WhatsApp nicht verfügbar
      } finally {
        loading.value = false
      }
    }

    function loadAudioMessages() {
      messages.value
        .filter(msg => (msg.message_type === 'audio' || msg.message_type === 'voice') && msg.media_url && !audioCache[msg.id])
        .forEach(msg => loadAudio(msg))
    }

    async function loadAudio(msg) {
      if (!msg.media_url || audioCache[msg.id] || audioLoading[msg.id]) return
      audioLoading[msg.id] = true
      try {
        const resp = await axios.post('/api/whatsapp/', {
          action: 'getWhatsAppMedia',
          media_id: msg.media_url
        })
        if (resp.data.success && resp.data.payload?.data) {
          const mimeType = resp.data.payload.mime_type || 'audio/ogg'
          audioCache[msg.id] = `data:${mimeType};base64,${resp.data.payload.data}`
        }
      } catch {
        // Audio-Abruf fehlgeschlagen
      } finally {
        delete audioLoading[msg.id]
      }
    }

    function loadImageMessages() {
      messages.value
        .filter(msg => ['image', 'video', 'sticker'].includes(msg.message_type) && msg.media_url && !imageCache[msg.id])
        .forEach(msg => loadImage(msg))
    }

    async function loadImage(msg) {
      if (!msg.media_url || imageCache[msg.id] || imageLoading[msg.id]) return
      imageLoading[msg.id] = true
      try {
        const resp = await axios.post('/api/whatsapp/', {
          action: 'getWhatsAppMedia',
          media_id: msg.media_url
        })
        if (resp.data.success && resp.data.payload?.data) {
          const mimeType = resp.data.payload.mime_type || 'image/jpeg'
          imageCache[msg.id] = `data:${mimeType};base64,${resp.data.payload.data}`
        }
      } catch {
        // Bild-Abruf fehlgeschlagen
      } finally {
        delete imageLoading[msg.id]
      }
    }

    function openImageViewer(src, caption) {
      viewerImageSrc.value = src
      viewerCaption.value = caption || ''
      showImageViewer.value = true
    }

    function downloadImage(msg) {
      const dataUrl = imageCache[msg.id]
      if (!dataUrl) return
      const link = document.createElement('a')
      link.href = dataUrl
      const ext = (msg.media_mime_type || 'image/jpeg').split('/').pop() || 'jpg'
      link.download = msg.media_caption || `whatsapp_${msg.id}.${ext}`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
    }

    async function doSend() {
      if (!selectedPhone.value) return

      const text = newMessage.value.trim()
      const file = attachedFile.value
      if (!text && !file) return

      sending.value = true
      try {
        let success = false

        if (file) {
          // Dokument senden
          const resp = await axios.post('/api/whatsapp/', {
            action: 'sendWhatsAppChatDocument',
            to: selectedPhone.value,
            message: text,
            customer_id: props.cvId,
            document_base64: file.base64,
            filename: file.name
          })
          success = resp.data.success
          if (!success) {
            toast.error(resp.data.text || t('CustomerVendorEditView.whatsapp.sendError'))
          }
          // Nach fetchMessages() wird das Bild per loadImageMessages() geladen und im globalen Cache gespeichert
        } else {
          // Nur Text
          const resp = await axios.post('/api/whatsapp/', {
            action: 'sendWhatsAppMessage',
            to: selectedPhone.value,
            message: text,
            customer_id: props.cvId,
            customer_name: props.customerName
          })
          success = resp.data.success
          if (!success) {
            toast.error(resp.data.text || t('CustomerVendorEditView.whatsapp.sendError'))
          }
        }

        if (success) {
          newMessage.value = ''
          attachedFile.value = null
          toast.success(t('CustomerVendorEditView.whatsapp.sendSuccess'))
          await fetchMessages()
        }
      } catch (err) {
        console.error('WhatsApp doSend error:', err)
        toast.error(t('CustomerVendorEditView.whatsapp.sendError'))
      } finally {
        sending.value = false
      }
    }

    // Datei-Upload
    function triggerFileUpload() {
      fileInputRef.value?.click()
    }

    function onFileSelected(event) {
      const file = event.target.files?.[0]
      if (!file) return
      const reader = new FileReader()
      reader.onload = () => {
        attachedFile.value = {
          name: file.name,
          base64: reader.result.split(',')[1],
          mimeType: file.type
        }
      }
      reader.readAsDataURL(file)
      event.target.value = ''
    }

    // Kundendokumente
    async function openDocumentPicker() {
      showDocPicker.value = true
      docPickerLoading.value = true
      try {
        const resp = await axios.post('/api/customer_vendor/', {
          action: 'vfIndex',
          cv_id: props.cvId,
          src: props.src
        })
        if (resp.data.success) {
          const entries = resp.data.payload?.files || resp.data.payload || []
          customerFiles.value = entries.filter(f => f.type === 'file')
        } else {
          customerFiles.value = []
        }
      } catch {
        customerFiles.value = []
      } finally {
        docPickerLoading.value = false
      }
    }

    async function selectCustomerFile(file) {
      showDocPicker.value = false
      try {
        const resp = await axios.post('/api/customer_vendor/', {
          action: 'vfDownload',
          cv_id: props.cvId,
          src: props.src,
          path: file.path
        }, { responseType: 'arraybuffer' })
        const base64 = btoa(new Uint8Array(resp.data).reduce((data, byte) => data + String.fromCharCode(byte), ''))
        attachedFile.value = {
          name: file.basename,
          base64: base64,
          mimeType: file.basename.endsWith('.pdf') ? 'application/pdf' : 'image/jpeg'
        }
      } catch {
        toast.error(t('CustomerVendorEditView.whatsapp.downloadError'))
      }
    }

    function deriveSaveFilename(msg) {
      const mediaUrl = msg.media_url || ''
      if (mediaUrl.includes('/')) {
        const basename = mediaUrl.split('/').pop()
        if (basename && basename.includes('.')) return basename
      }
      const text = msg.message_text || ''
      if (text && text.includes('.') && !text.startsWith('[')) return text
      const caption = msg.media_caption || ''
      if (caption && caption.includes('.')) return caption
      const extMap = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'application/pdf': 'pdf', 'audio/ogg': 'ogg', 'video/mp4': 'mp4' }
      const ext = extMap[msg.media_mime_type] || (msg.media_mime_type || '').split('/').pop() || 'bin'
      const id = mediaUrl.replace(/[^a-zA-Z0-9]/g, '').slice(-10) || Date.now()
      return `whatsapp_${id}.${ext}`
    }

    // Medium im Kundenordner speichern
    function saveMediaToFolder(msg) {
      if (!msg.media_url || !props.cvId) return
      saveMediaMsg = msg
      saveCurrentPath.value = ''
      saveFilename.value = deriveSaveFilename(msg)
      showSaveDialog.value = true
      loadSaveFolders('')
    }

    async function loadSaveFolders(path) {
      saveFolderLoading.value = true
      try {
        const resp = await axios.post('/api/whatsapp/', {
          action: 'getWhatsAppMediaSaveFolders',
          customer_id: props.cvId,
          path
        })
        if (resp.data.success) {
          saveFolders.value = resp.data.payload?.folders || []
          saveCurrentPath.value = resp.data.payload?.path || ''
        }
      } catch {
        saveFolders.value = []
      } finally {
        saveFolderLoading.value = false
      }
    }

    function navigateSaveFolder(folder) {
      if (folder === '..') {
        const parts = saveCurrentPath.value.split('/').filter(Boolean)
        parts.pop()
        loadSaveFolders(parts.join('/'))
      } else {
        const newPath = saveCurrentPath.value ? saveCurrentPath.value + '/' + folder : folder
        loadSaveFolders(newPath)
      }
    }

    async function doSaveToFolder() {
      if (!saveMediaMsg || !saveFilename.value.trim()) return
      savingToFolder.value = true
      try {
        const resp = await axios.post('/api/whatsapp/', {
          action: 'saveWhatsAppMediaToFolder',
          media_id: saveMediaMsg.media_url,
          customer_id: props.cvId,
          filename: saveFilename.value.trim(),
          path: saveCurrentPath.value
        })
        if (resp.data.success) {
          toast.success(t('CustomerVendorEditView.whatsapp.saveToFolderSuccess', { filename: resp.data.payload?.filename || saveFilename.value }))
          showSaveDialog.value = false
        } else {
          toast.error(resp.data.text || t('CustomerVendorEditView.whatsapp.saveToFolderError'))
        }
      } catch {
        toast.error(t('CustomerVendorEditView.whatsapp.saveToFolderError'))
      } finally {
        savingToFolder.value = false
      }
    }

    // Dokument-Download aus Chat
    async function downloadDocument(msg) {
      const mediaId = msg.media_url
      if (!mediaId) return
      try {
        const resp = await axios.post('/api/whatsapp/', {
          action: 'getWhatsAppMedia',
          media_id: mediaId
        })
        if (resp.data.success && resp.data.payload?.data) {
          const mimeType = resp.data.payload.mime_type || msg.media_mime_type || 'application/pdf'
          const dataUrl = `data:${mimeType};base64,${resp.data.payload.data}`
          const link = document.createElement('a')
          link.href = dataUrl
          link.download = msg.message_text || 'dokument.pdf'
          document.body.appendChild(link)
          link.click()
          document.body.removeChild(link)
        } else {
          toast.error(t('CustomerVendorEditView.whatsapp.downloadError'))
        }
      } catch {
        toast.error(t('CustomerVendorEditView.whatsapp.downloadError'))
      }
    }

    function scrollToBottom() {
      if (chatContainer.value?.$el) {
        const el = chatContainer.value.$el
        el.scrollTop = el.scrollHeight
      } else if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight
      }
    }

    function parseContacts(text) {
      try { return JSON.parse(text) } catch { return [] }
    }

    function onEmojiSelect(emoji) {
      newMessage.value += emoji.i
      showEmojiPicker.value = false
    }

    function createFromContact(contact, src) {
      const query = {}
      if (contact.name) query.prefill_name = contact.name
      if (contact.phones?.length) query.prefill_phone = contact.phones[0]
      if (contact.emails?.length) query.prefill_email = contact.emails[0]
      router.push({ name: src === 'V' ? 'vendor-new' : 'customer-new', query })
    }

    function parseLocationCoords(text) {
      if (!text) return '0,0'
      const match = text.match(/([-\d.]+),\s*([-\d.]+)/)
      return match ? match[1] + ',' + match[2] : '0,0'
    }

    function buildMapEmbedUrl(text) {
      const coords = parseLocationCoords(text)
      const [lat, lon] = coords.split(',').map(Number)
      const d = 0.004
      return `https://www.openstreetmap.org/export/embed.html?bbox=${lon-d},${lat-d},${lon+d},${lat+d}&layer=mapnik&marker=${lat},${lon}`
    }

    function linkifyText(text) {
      if (!text) return ''
      const escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
      return escaped.replace(
        /(https?:\/\/[^\s<]+)/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer" style="color: #1565c0; text-decoration: underline; word-break: break-all;">$1</a>'
      )
    }

    function formatDate(isoStr) {
      if (!isoStr) return ''
      const d = new Date(isoStr)
      const loc = locale.value === 'de' ? 'de-DE' : 'en-US'
      return d.toLocaleString(loc, {
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit'
      })
    }

    function statusIcon(status) {
      switch (status) {
        case 'sent': return 'mdi-check'
        case 'delivered': return 'mdi-check-all'
        case 'read': return 'mdi-check-all'
        case 'failed': return 'mdi-alert-circle'
        default: return 'mdi-clock-outline'
      }
    }

    return {
      t, messages, loading, sending, newMessage,
      chatContainer, selectedPhone, configError,
      attachedFile, fileInputRef, showDocPicker, docPickerLoading, customerFiles,
      audioCache, audioLoading, savingMedia,
      imageCache, imageLoading,
      showImageViewer, viewerImageSrc, viewerCaption,
      showSaveDialog, saveCurrentPath, saveFolders, saveFilename, saveFolderLoading, savingToFolder,
      showEmojiPicker, onEmojiSelect,
      showDeleteConfirm, deleting, confirmDeleteMessage, doDeleteMessage,
      doSend, fetchMessages, formatDate, statusIcon, linkifyText, parseLocationCoords, buildMapEmbedUrl, parseContacts, createFromContact,
      triggerFileUpload, onFileSelected, openDocumentPicker, selectCustomerFile,
      downloadDocument, downloadImage, saveMediaToFolder, navigateSaveFolder, doSaveToFolder, openImageViewer
    }
  }
}
</script>

<style scoped>
.chat-msg-card { padding-right: 28px !important; }
.chat-msg-card .chat-msg-delete { position: absolute; top: 2px; right: 2px; opacity: 0; transition: opacity 0.15s; z-index: 1; }
.chat-msg-card:hover .chat-msg-delete { opacity: 1; }
</style>
