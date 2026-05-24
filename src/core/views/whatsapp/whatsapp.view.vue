<!-- src/core/views/whatsapp/whatsapp.view.vue -->
<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>
        <!-- Titel -->
        <div class="d-flex align-center mb-3">
            <v-icon color="green-darken-2" class="mr-1">mdi-whatsapp</v-icon>
            <h1 class="text-h6 mb-0">{{ t('WhatsAppView.title') }}</h1>
        </div>

        <!-- Nicht konfiguriert -->
        <v-alert v-if="configError" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('WhatsAppView.noConfig') }}
        </v-alert>

        <template v-else>
            <v-row>
                <!-- Linke Spalte: Konversationen -->
                <v-col cols="12" md="4">
                    <v-btn color="green-darken-2" block class="mb-3" @click="showCompose = true">
                        <v-icon start size="small">mdi-message-plus</v-icon>
                        {{ t('WhatsAppView.compose') }}
                    </v-btn>

                    <v-card variant="outlined" elevation="0">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center ga-2">
                            <v-text-field
                                v-model="searchText"
                                :placeholder="t('WhatsAppView.search')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                prepend-inner-icon="mdi-magnify"
                                clearable
                                class="flex-grow-1"
                                @click:clear="searchText = ''"
                            />
                            <v-btn variant="text" size="small" @click="loadConversations" :loading="loading">
                                <v-icon size="small">mdi-refresh</v-icon>
                            </v-btn>
                        </v-card-title>
                        <v-divider />

                        <!-- Loading -->
                        <div v-if="loading && !conversations.length" class="text-center py-8">
                            <v-progress-circular indeterminate size="32" width="3" />
                            <div class="text-body-2 text-medium-emphasis mt-2">{{ t('WhatsAppView.loading') }}</div>
                        </div>

                        <!-- Leere Liste -->
                        <div v-else-if="!conversations.length && !loading" class="text-center text-medium-emphasis py-8">
                            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-chat-outline</v-icon>
                            <div>{{ t('WhatsAppView.noConversations') }}</div>
                        </div>

                        <!-- Konversationsliste -->
                        <v-list v-else density="compact" class="pa-0">
                            <v-list-item
                                v-for="conv in conversations"
                                :key="conv.phone_number"
                                :active="selectedConv?.phone_number === conv.phone_number"
                                @click="selectConversation(conv)"
                                class="conv-list-item"
                            >
                                <template #prepend>
                                    <v-avatar size="36" color="green-lighten-4" class="text-green-darken-2 font-weight-bold text-body-2">
                                        {{ initials(conv.customer_name || conv.contact_name || conv.phone_number) }}
                                    </v-avatar>
                                </template>
                                <v-list-item-title class="text-body-2">
                                    {{ conv.customer_name || conv.contact_name || conv.phone_number }}
                                </v-list-item-title>
                                <v-list-item-subtitle class="text-caption">
                                    <span v-if="conv.last_direction === 'O'" class="text-medium-emphasis">{{ t('WhatsAppView.you') }}: </span>
                                    {{ truncate(conv.last_message, 40) }}
                                </v-list-item-subtitle>
                                <template #append>
                                    <div class="text-right">
                                        <div class="text-caption text-medium-emphasis">{{ formatDate(conv.last_message_time) }}</div>
                                        <v-badge
                                            v-if="conv.unread_count > 0"
                                            :content="conv.unread_count"
                                            color="green-darken-2"
                                            inline
                                        />
                                    </div>
                                </template>
                            </v-list-item>
                        </v-list>
                    </v-card>
                </v-col>

                <!-- Rechte Spalte: Chat -->
                <v-col v-if="selectedConv" cols="12" md="8">
                    <v-card variant="outlined" elevation="0">
                        <!-- Chat Header -->
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-avatar size="32" color="green-lighten-4" class="mr-2 text-green-darken-2 font-weight-bold text-body-2">
                                {{ initials(selectedConv.customer_name || selectedConv.contact_name || selectedConv.phone_number) }}
                            </v-avatar>
                            <div
                                class="flex-grow-1"
                                :class="{ 'cursor-pointer': selectedConv.customer_id }"
                                @click="selectedConv.customer_id && openCustomer(selectedConv)"
                            >
                                <div class="text-subtitle-2" :class="{ 'text-primary': selectedConv.customer_id }">{{ selectedConv.customer_name || selectedConv.contact_name || selectedConv.phone_number }}</div>
                                <div class="text-caption text-medium-emphasis">{{ selectedConv.phone_number }}</div>
                            </div>
                            <v-btn variant="text" size="x-small" icon @click="selectedConv = null; chatMessages = []">
                                <v-icon size="small">mdi-close</v-icon>
                            </v-btn>
                        </v-card-title>
                        <v-divider />

                        <!-- Chat Messages -->
                        <div v-if="loadingChat" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" width="2" />
                        </div>

                        <div v-else ref="chatContainer" class="chat-container">
                            <div v-if="!chatMessages.length" class="text-center text-medium-emphasis py-8">
                                <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-chat-outline</v-icon>
                                <div>{{ t('WhatsAppView.noMessages') }}</div>
                            </div>
                            <template v-else>
                                <template v-for="(msg, idx) in chatMessages" :key="msg.id">
                                    <!-- Datumstrenner -->
                                    <div v-if="showDateSeparator(idx)" class="chat-date-separator">
                                        <span class="chat-date-chip">{{ formatDateLabel(msg.itime) }}</span>
                                    </div>

                                    <div
                                        class="chat-row"
                                        :class="msg.direction === 'O' ? 'chat-row--out' : 'chat-row--in'"
                                    >
                                        <div class="chat-bubble chat-msg-card" :class="msg.direction === 'O' ? 'chat-bubble--out' : 'chat-bubble--in'" style="position: relative;">
                                            <!-- Löschen-Button (per Hover sichtbar) -->
                                            <v-btn
                                                class="chat-msg-delete"
                                                icon
                                                size="x-small"
                                                variant="text"
                                                :title="t('WhatsAppView.deleteMessage')"
                                                @click.stop="confirmDeleteMessage(msg)"
                                            >
                                                <v-icon size="14" color="grey">mdi-delete-outline</v-icon>
                                            </v-btn>
                                            <!-- Kontaktname bei eingehend -->
                                            <div v-if="msg.direction === 'I' && msg.contact_name" class="chat-bubble__sender">
                                                {{ msg.contact_name }}
                                            </div>

                                            <!-- Dokument -->
                                            <div v-if="msg.message_type === 'document'" class="chat-document" :class="{ 'chat-document--clickable': msg.media_url }" @click="downloadDocument(msg)">
                                                <div class="chat-document__icon">
                                                    <v-icon size="28" color="red-darken-1">mdi-file-pdf-box</v-icon>
                                                </div>
                                                <div class="chat-document__info">
                                                    <div class="chat-document__name">{{ msg.message_text || 'Dokument' }}</div>
                                                    <div class="chat-document__type">PDF</div>
                                                </div>
                                                <v-btn v-if="msg.media_url && selectedConv?.customer_id" icon size="x-small" variant="text" :title="t('WhatsAppView.saveToFolder')" :loading="savingMedia[msg.id]" @click.stop="saveMediaToFolder(msg)">
                                                    <v-icon size="18" color="primary">mdi-folder-download</v-icon>
                                                </v-btn>
                                                <v-icon v-if="msg.media_url" size="20" color="grey" class="ms-auto">mdi-download</v-icon>
                                                <v-progress-circular v-if="mediaLoading[msg.id]" indeterminate size="18" width="2" class="ms-auto" />
                                            </div>
                                            <div v-if="msg.message_type === 'document' && msg.media_caption" class="text-body-2 mt-1" style="white-space: pre-wrap;">{{ msg.media_caption }}</div>

                                            <!-- Bild -->
                                            <div v-else-if="msg.message_type === 'image'" class="chat-image">
                                                <template v-if="mediaCache[msg.id]">
                                                    <div class="chat-image__wrapper">
                                                        <img
                                                            :src="mediaCache[msg.id]"
                                                            class="chat-image__img"
                                                            @click="openImageViewer(mediaCache[msg.id], msg.media_caption)"
                                                        />
                                                        <v-btn
                                                            class="chat-image__download"
                                                            size="x-small"
                                                            variant="tonal"
                                                            icon
                                                            @click.stop="downloadMedia(msg)"
                                                        >
                                                            <v-icon size="16">mdi-download</v-icon>
                                                        </v-btn>
                                                        <v-btn
                                                            v-if="selectedConv?.customer_id"
                                                            class="chat-image__save"
                                                            size="x-small"
                                                            variant="tonal"
                                                            icon
                                                            :loading="savingMedia[msg.id]"
                                                            :title="t('WhatsAppView.saveToFolder')"
                                                            @click.stop="saveMediaToFolder(msg)"
                                                        >
                                                            <v-icon size="16">mdi-folder-download</v-icon>
                                                        </v-btn>
                                                    </div>
                                                </template>
                                                <div v-else-if="msg.media_url" class="chat-image__placeholder">
                                                    <v-progress-circular v-if="mediaLoading[msg.id]" indeterminate size="28" width="2" color="grey" />
                                                    <v-icon v-else size="40" color="grey-lighten-1">mdi-image-broken-variant</v-icon>
                                                </div>
                                                <div v-else class="chat-image__placeholder">
                                                    <v-icon size="40" color="grey">mdi-image</v-icon>
                                                </div>
                                                <div v-if="msg.media_caption" class="chat-image__caption">{{ msg.media_caption }}</div>
                                            </div>

                                            <!-- Audio / Sprachnachricht -->
                                            <div v-else-if="msg.message_type === 'audio' || msg.message_type === 'voice'" class="chat-audio">
                                                <audio v-if="mediaCache[msg.id]" :src="mediaCache[msg.id]" controls preload="none" style="max-width: 260px; height: 36px;" />
                                                <template v-else>
                                                    <v-progress-circular v-if="mediaLoading[msg.id]" indeterminate size="20" width="2" class="mr-1" />
                                                    <v-icon v-else size="20" class="mr-1">mdi-microphone</v-icon>
                                                    <span class="text-caption">{{ t('WhatsAppView.voiceMessage') }}</span>
                                                </template>
                                            </div>

                                            <!-- Video -->
                                            <div v-else-if="msg.message_type === 'video'" class="chat-image">
                                                <template v-if="mediaCache[msg.id]">
                                                    <div class="chat-image__wrapper">
                                                        <video
                                                            :src="mediaCache[msg.id]"
                                                            controls
                                                            preload="metadata"
                                                            style="max-width: 260px; max-height: 260px; border-radius: 6px; display: block;"
                                                        />
                                                        <v-btn
                                                            class="chat-image__download"
                                                            size="x-small"
                                                            variant="tonal"
                                                            icon
                                                            @click.stop="downloadMedia(msg)"
                                                        >
                                                            <v-icon size="16">mdi-download</v-icon>
                                                        </v-btn>
                                                        <v-btn
                                                            v-if="selectedConv?.customer_id"
                                                            class="chat-image__save"
                                                            size="x-small"
                                                            variant="tonal"
                                                            icon
                                                            :loading="savingMedia[msg.id]"
                                                            :title="t('WhatsAppView.saveToFolder')"
                                                            @click.stop="saveMediaToFolder(msg)"
                                                        >
                                                            <v-icon size="16">mdi-folder-download</v-icon>
                                                        </v-btn>
                                                    </div>
                                                </template>
                                                <div v-else-if="msg.media_url" class="chat-image__placeholder">
                                                    <v-progress-circular v-if="mediaLoading[msg.id]" indeterminate size="28" width="2" color="grey" />
                                                    <v-icon v-else size="40" color="grey-lighten-1">mdi-video-outline</v-icon>
                                                </div>
                                                <div v-if="msg.media_caption" class="chat-image__caption">{{ msg.media_caption }}</div>
                                            </div>

                                            <!-- Sticker -->
                                            <div v-else-if="msg.message_type === 'sticker'" class="chat-image">
                                                <template v-if="mediaCache[msg.id]">
                                                    <img :src="mediaCache[msg.id]" style="max-width: 150px; max-height: 150px;" />
                                                </template>
                                                <div v-else-if="msg.media_url" class="d-flex align-center">
                                                    <v-progress-circular v-if="mediaLoading[msg.id]" indeterminate size="20" width="2" class="mr-1" />
                                                    <v-icon v-else size="20" class="mr-1">mdi-sticker-emoji</v-icon>
                                                    <span class="text-caption">Sticker</span>
                                                </div>
                                            </div>

                                            <!-- Standort -->
                                            <div v-else-if="msg.message_type === 'location'" class="chat-location">
                                                <div style="width: 260px; border-radius: 6px; overflow: hidden; border: 1px solid rgba(0,0,0,0.1);">
                                                    <iframe :src="buildMapEmbedUrl(msg.message_text)" style="width: 260px; height: 160px; border: none; display: block; pointer-events: none;" loading="lazy"></iframe>
                                                    <a :href="'https://maps.google.com/?q=' + parseLocationCoords(msg.message_text)" target="_blank" rel="noopener" class="d-flex align-center pa-2 text-decoration-none" style="background: rgba(0,0,0,0.03);">
                                                        <v-icon size="18" color="red" class="mr-1">mdi-map-marker</v-icon>
                                                        <span class="text-caption text-medium-emphasis flex-grow-1">{{ parseLocationCoords(msg.message_text) }}</span>
                                                        <v-icon size="14" color="grey">mdi-open-in-new</v-icon>
                                                    </a>
                                                </div>
                                            </div>

                                            <!-- Kontakt -->
                                            <div v-else-if="msg.message_type === 'contacts'" class="chat-contacts">
                                                <template v-if="parseContacts(msg.message_text).length">
                                                    <div
                                                        v-for="(contact, ci) in parseContacts(msg.message_text)"
                                                        :key="ci"
                                                        class="chat-contact-card"
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
                                                                {{ t('WhatsAppView.createCustomer') }}
                                                            </v-btn>
                                                            <v-btn size="x-small" variant="tonal" color="secondary" prepend-icon="mdi-account-plus" @click="createFromContact(contact, 'V')">
                                                                {{ t('WhatsAppView.createVendor') }}
                                                            </v-btn>
                                                        </div>
                                                    </div>
                                                </template>
                                                <div v-else class="d-flex align-center">
                                                    <v-icon size="20" color="green-darken-2" class="mr-1">mdi-account-circle</v-icon>
                                                    <span class="text-body-2">{{ msg.message_text || '[Kontakt]' }}</span>
                                                </div>
                                            </div>

                                            <!-- Text (Standard) -->
                                            <div v-else class="chat-bubble__text">
                                                <div v-html="linkifyText(msg.message_text)"></div>
                                                <!-- Dokument-Anhang bei Template-Nachrichten -->
                                                <div v-if="msg.media_url && msg.media_mime_type === 'application/pdf'" class="chat-document mt-2" :class="{ 'chat-document--clickable': msg.media_url }" @click="downloadDocument(msg)">
                                                    <div class="chat-document__icon">
                                                        <v-icon size="28" color="red-darken-1">mdi-file-pdf-box</v-icon>
                                                    </div>
                                                    <div class="chat-document__info">
                                                        <div class="chat-document__name">PDF</div>
                                                    </div>
                                                    <v-btn v-if="selectedConv?.customer_id" icon size="x-small" variant="text" :title="t('WhatsAppView.saveToFolder')" :loading="savingMedia[msg.id]" @click.stop="saveMediaToFolder(msg)">
                                                        <v-icon size="18" color="primary">mdi-folder-download</v-icon>
                                                    </v-btn>
                                                    <v-icon size="20" color="grey" class="ms-auto">mdi-download</v-icon>
                                                    <v-progress-circular v-if="mediaLoading[msg.id]" indeterminate size="18" width="2" class="ms-auto" />
                                                </div>
                                            </div>

                                            <!-- Zeitstempel + Status + Weiterleiten -->
                                            <div class="chat-bubble__meta">
                                                <v-btn
                                                    class="chat-bubble__forward"
                                                    icon="mdi-share"
                                                    size="x-small"
                                                    variant="text"
                                                    density="compact"
                                                    @click.stop="openForwardDialog(msg)"
                                                />
                                                <span>{{ formatTime(msg.itime) }}</span>
                                                <v-icon
                                                    v-if="msg.direction === 'O'"
                                                    size="14"
                                                    class="chat-bubble__status"
                                                    :class="{
                                                        'text-blue': msg.status === 'read',
                                                        'text-red': msg.status === 'failed',
                                                        'text-grey': msg.status !== 'read' && msg.status !== 'failed'
                                                    }"
                                                >
                                                    {{ statusIcon(msg.status) }}
                                                </v-icon>
                                            </div>

                                            <!-- Fehlermeldung -->
                                            <div v-if="msg.status === 'failed' && msg.error_message" class="chat-bubble__error">
                                                <v-icon size="12" color="red" class="mr-1">mdi-alert-circle</v-icon>
                                                {{ msg.error_message }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </template>
                        </div>

                        <!-- Anhang-Vorschau -->
                        <div v-if="attachedFile" class="px-3 pt-2">
                            <v-chip
                                color="green"
                                variant="tonal"
                                closable
                                prepend-icon="mdi-paperclip"
                                @click:close="attachedFile = null"
                            >
                                {{ attachedFile.name }}
                            </v-chip>
                        </div>

                        <!-- Nachricht senden -->
                        <v-divider />
                        <div class="pa-3">
                            <div class="d-flex align-end ga-2">
                                <v-menu location="top start">
                                    <template #activator="{ props }">
                                        <v-btn
                                            v-bind="props"
                                            icon="mdi-paperclip"
                                            variant="text"
                                            size="large"
                                        />
                                    </template>
                                    <v-list density="compact">
                                        <v-list-item
                                            prepend-icon="mdi-upload"
                                            :title="t('WhatsAppView.attachFile')"
                                            @click="triggerFileUpload"
                                        />
                                        <v-list-item
                                            v-if="selectedConv?.customer_id"
                                            prepend-icon="mdi-folder-open"
                                            :title="t('WhatsAppView.attachFromDocs')"
                                            @click="openDocumentPicker"
                                        />
                                    </v-list>
                                </v-menu>
                                <input
                                    ref="fileInputRef"
                                    type="file"
                                    style="display: none"
                                    accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mp3,.ogg"
                                    @change="onFileSelected"
                                />
                                <!-- Emoji-Picker -->
                                <v-menu v-model="showEmojiPicker" :close-on-content-click="false" location="top start">
                                    <template #activator="{ props: emojiProps }">
                                        <v-btn
                                            v-bind="emojiProps"
                                            icon="mdi-emoticon-happy-outline"
                                            variant="text"
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
                                    v-model="replyMessage"
                                    :placeholder="t('WhatsAppView.message')"
                                    variant="outlined"
                                    density="compact"
                                    rows="2"
                                    auto-grow
                                    hide-details
                                    @keydown.enter.exact.prevent="doSendReply"
                                />
                                <v-btn
                                    color="green-darken-2"
                                    :loading="sending"
                                    :disabled="!replyMessage.trim() && !attachedFile"
                                    @click="doSendReply"
                                    icon="mdi-send"
                                    size="large"
                                />
                            </div>
                        </div>

                        <!-- Kundendokumente-Dialog -->
                        <v-dialog v-model="showDocPicker" max-width="500">
                            <v-card>
                                <v-card-title class="bg-primary d-flex align-center">
                                    <v-icon class="mr-2">mdi-folder-open</v-icon>
                                    {{ t('WhatsAppView.customerDocs') }}
                                    <v-spacer />
                                    <v-btn icon="mdi-close" variant="text" size="small" @click="showDocPicker = false" />
                                </v-card-title>
                                <v-card-text class="pa-0">
                                    <v-list v-if="customerFiles.length" density="compact">
                                        <v-list-item
                                            v-for="file in customerFiles"
                                            :key="file.path"
                                            :prepend-icon="file.basename.endsWith('.pdf') ? 'mdi-file-pdf-box' : 'mdi-file-image'"
                                            :title="file.basename"
                                            @click="selectCustomerFile(file)"
                                        />
                                    </v-list>
                                    <div v-else-if="docPickerLoading" class="d-flex justify-center pa-6">
                                        <v-progress-circular indeterminate />
                                    </div>
                                    <div v-else class="text-center pa-6 text-grey">
                                        {{ t('WhatsAppView.noDocuments') }}
                                    </div>
                                </v-card-text>
                            </v-card>
                        </v-dialog>
                    </v-card>
                </v-col>

                <!-- Kein Chat ausgewählt -->
                <v-col v-else cols="12" md="8">
                    <v-card variant="outlined" elevation="0" class="d-flex align-center justify-center" style="min-height: 400px;">
                        <div class="text-center text-medium-emphasis">
                            <v-icon size="80" color="grey-lighten-2" class="mb-3">mdi-whatsapp</v-icon>
                            <div class="text-h6 text-grey-lighten-1">{{ t('WhatsAppView.title') }}</div>
                        </div>
                    </v-card>
                </v-col>
            </v-row>
        </template>

        <!-- Bild-Betrachter Dialog -->
        <v-dialog v-model="showImageViewer" max-width="900">
            <v-card>
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-image</v-icon>
                    <span class="text-subtitle-1">{{ t('WhatsAppView.image') }}</span>
                    <v-spacer />
                    <v-btn variant="text" size="small" @click="downloadViewerImage" :title="t('WhatsAppView.download')">
                        <v-icon size="small">mdi-download</v-icon>
                    </v-btn>
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
                    <span class="text-subtitle-1">{{ t('WhatsAppView.saveToFolder') }}</span>
                    <v-spacer />
                    <v-btn variant="text" size="x-small" icon @click="showSaveDialog = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-3">
                    <!-- Speicherort -->
                    <div class="text-caption font-weight-medium mb-1">{{ t('WhatsAppView.saveLocation') }}</div>
                    <div class="d-flex align-center mb-3 pa-2 rounded" style="background: rgba(0,0,0,0.04);">
                        <v-icon size="small" class="mr-1" color="amber-darken-2">mdi-folder-open</v-icon>
                        <span class="text-body-2">/{{ saveCurrentPath || t('WhatsAppView.rootFolder') }}</span>
                    </div>

                    <!-- Ordner-Navigation (optional in Unterordner wechseln) -->
                    <div v-if="saveFolders.length || saveCurrentPath" class="text-caption font-weight-medium mb-1">{{ t('WhatsAppView.changeFolder') }}</div>
                    <v-card v-if="saveFolders.length || saveCurrentPath" variant="outlined" class="mb-3" style="max-height: 200px; overflow-y: auto;">
                        <v-list density="compact" class="pa-0">
                            <v-list-item
                                v-if="saveCurrentPath"
                                @click="navigateSaveFolder('..')"
                                prepend-icon="mdi-arrow-up"
                                title=".."
                                class="text-medium-emphasis"
                            />
                            <v-list-item
                                v-for="folder in saveFolders"
                                :key="folder"
                                @click="navigateSaveFolder(folder)"
                                prepend-icon="mdi-folder"
                                :title="folder"
                            />
                        </v-list>
                        <div v-if="saveFolderLoading" class="d-flex justify-center pa-3">
                            <v-progress-circular indeterminate size="24" width="2" />
                        </div>
                    </v-card>

                    <!-- Dateiname -->
                    <v-text-field
                        v-model="saveFilename"
                        :label="t('WhatsAppView.saveFilename')"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-3 py-2">
                    <v-spacer />
                    <v-btn variant="text" @click="showSaveDialog = false">{{ t('WhatsAppView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="savingToFolder" :disabled="!saveFilename.trim()" @click="doSaveToFolder">
                        <v-icon start size="small">mdi-content-save</v-icon>
                        {{ t('WhatsAppView.saveBtn') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Compose Dialog -->
        <v-dialog v-model="showCompose" max-width="500" persistent>
            <v-card>
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small" color="green-darken-2">mdi-message-plus</v-icon>
                    <span class="text-subtitle-1">{{ t('WhatsAppView.compose') }}</span>
                    <v-spacer />
                    <v-btn variant="text" size="x-small" icon @click="showCompose = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-3">
                    <!-- Kundensuche -->
                    <v-autocomplete
                        v-model="composeCustomer"
                        v-model:search="customerSearch"
                        :items="customerResults"
                        :loading="customerLoading"
                        :placeholder="t('WhatsAppView.searchCustomer')"
                        :label="t('WhatsAppView.customer')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                        item-value="id"
                        item-title="name"
                        return-object
                        no-filter
                        clearable
                        hide-no-data
                        prepend-inner-icon="mdi-account-search"
                        @update:search="onCustomerSearch"
                        @update:model-value="onCustomerSelect"
                    >
                        <template #item="{ item, props: itemProps }">
                            <v-list-item v-bind="itemProps">
                                <template #subtitle>
                                    <span>{{ item.raw.number }}</span>
                                    <span class="ml-2 text-green-darken-2">{{ item.raw.phones.join(', ') }}</span>
                                </template>
                            </v-list-item>
                        </template>
                    </v-autocomplete>

                    <!-- Telefonnummer-Auswahl -->
                    <v-select
                        v-if="composePhones.length > 1"
                        v-model="composeData.to"
                        :items="composePhones"
                        :label="t('WhatsAppView.selectPhone')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                    />
                    <v-text-field
                        v-else
                        v-model="composeData.to"
                        :label="t('WhatsAppView.to')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                        prepend-inner-icon="mdi-phone"
                    />

                    <v-textarea
                        v-model="composeData.message"
                        :label="t('WhatsAppView.message')"
                        variant="outlined"
                        density="compact"
                        rows="5"
                        auto-grow
                        @keydown.enter.exact.prevent="doSendCompose"
                    />

                </v-card-text>
                <v-divider />
                <v-card-actions class="px-3 py-2">
                    <v-spacer />
                    <v-btn variant="text" @click="showCompose = false">
                        {{ t('WhatsAppView.cancel') }}
                    </v-btn>
                    <v-btn color="green-darken-2" :loading="composeSending" :disabled="!composeData.to || !composeData.message.trim()" @click="doSendCompose">
                        <v-icon start size="small">mdi-send</v-icon>
                        {{ t('WhatsAppView.send') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Weiterleiten-Dialog -->
        <v-dialog v-model="showForwardDialog" max-width="420">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon start>mdi-share</v-icon>
                    {{ t('WhatsAppView.forwardTitle') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showForwardDialog = false" />
                </v-card-title>
                <v-divider />

                <!-- Vorschau der weitergeleiteten Nachricht -->
                <div v-if="forwardMessage" class="pa-3 bg-grey-lighten-4">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('WhatsAppView.forwardPreview') }}</div>
                    <div class="text-body-2" style="white-space: pre-wrap; max-height: 80px; overflow: hidden;">
                        {{ forwardMessage.message_text || '[Medium]' }}
                    </div>
                </div>

                <v-divider />
                <v-text-field
                    v-model="forwardSearch"
                    :placeholder="t('WhatsAppView.forwardSearchPlaceholder')"
                    prepend-inner-icon="mdi-magnify"
                    variant="solo-filled"
                    density="compact"
                    flat
                    hide-details
                    class="mx-3 mt-3"
                />
                <v-card-text class="pa-0" style="max-height: 350px; overflow-y: auto;">
                    <v-list density="compact">
                        <v-list-item
                            v-for="conv in filteredForwardConversations()"
                            :key="conv.phone_number"
                            :disabled="forwardSending"
                            @click="doForward(conv)"
                        >
                            <template #prepend>
                                <v-avatar size="36" color="green-lighten-4">
                                    <span class="text-caption font-weight-bold text-green-darken-3">{{ initials(conv.contact_name || conv.customer_name || conv.phone_number) }}</span>
                                </v-avatar>
                            </template>
                            <v-list-item-title class="text-body-2 font-weight-medium">
                                {{ conv.customer_name || conv.contact_name || conv.phone_number }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                {{ conv.phone_number }}
                            </v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Löschen-Bestätigungsdialog -->
        <v-dialog v-model="showDeleteConfirm" max-width="400">
            <v-card>
                <v-card-title class="d-flex align-center">
                    {{ t('WhatsAppView.deleteMessage') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showDeleteConfirm = false" />
                </v-card-title>
                <v-card-text>{{ t('WhatsAppView.deleteConfirm') }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showDeleteConfirm = false">{{ t('WhatsAppView.cancel') }}</v-btn>
                    <v-btn color="red" variant="flat" :loading="deleting" @click="doDeleteMessage">{{ t('WhatsAppView.deleteMessage') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script>
import { ref, reactive, onMounted, onUnmounted, nextTick, watch } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'
import { waMediaCache } from '@/core/utils/whatsappMediaCache.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'

// Gemeinsamer Cache fuer beide WhatsApp-Views (Standalone + Kunden-Tab)
const mediaCache = waMediaCache

export default {
    name: 'WhatsAppView',
    components: { NavbarView, EmojiPicker },

    setup() {
        const { t } = useI18n()
        const router = useRouter()
        const route = useRoute()
        const oserp = oserpStore()

        const conversations = ref([])
        const loading = ref(false)
        const configError = ref(false)
        const searchText = ref('')
        let searchDebounceTimer = null
        watch(searchText, () => {
            if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
            searchDebounceTimer = setTimeout(loadConversations, 300)
        })

        const selectedConv = ref(null)
        const chatMessages = ref([])
        const loadingChat = ref(false)
        const replyMessage = ref('')
        const sending = ref(false)
        const chatContainer = ref(null)
        const attachedFile = ref(null) // { name, base64, mimeType }
        const fileInputRef = ref(null)
        const showDocPicker = ref(false)
        const customerFiles = ref([])
        const docPickerLoading = ref(false)

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
                    chatMessages.value = chatMessages.value.filter(m => m.id !== deleteTarget.value.id)
                    toast.success(t('WhatsAppView.deleteSuccess'))
                } else {
                    toast.error(resp.data.text || t('WhatsAppView.deleteError'))
                }
            } catch {
                toast.error(t('WhatsAppView.deleteError'))
            } finally {
                deleting.value = false
                showDeleteConfirm.value = false
                deleteTarget.value = null
            }
        }

        const showForwardDialog = ref(false)
        const forwardMessage = ref(null)
        const forwardSearch = ref('')
        const forwardSending = ref(false)

        const showCompose = ref(false)
        const composeData = ref({ to: '', message: '' })
        const composeSending = ref(false)
        const composePhones = ref([])
        const composeCustomer = ref(null)
        const customerSearch = ref('')
        const customerResults = ref([])
        const customerLoading = ref(false)
        let customerSearchTimeout = null
        let eventSource = null

        // Loading-Status (lokal pro Instanz), Cache ist global
        const mediaLoading = reactive({})

        // Speichern-Dialog
        const showSaveDialog = ref(false)
        const saveCurrentPath = ref('')
        const saveFolders = ref([])
        const saveFilename = ref('')
        const saveFolderLoading = ref(false)
        const savingToFolder = ref(false)
        const savingMedia = reactive({}) // Loading-Indikator auf Buttons
        let saveMediaMsg = null // Nachricht die gespeichert werden soll

        // Bild-Betrachter
        const showImageViewer = ref(false)
        const viewerImageSrc = ref('')
        const viewerCaption = ref('')

        // Config prüfen
        function checkConfig() {
            const token = oserp.getClientDefaultValue('whatsapp_access_token', '')
            const phoneId = oserp.getClientDefaultValue('whatsapp_phone_number_id', '')
            configError.value = !(token && phoneId)
            return !configError.value
        }

        async function loadConversations() {
            loading.value = true
            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'getWhatsAppConversations',
                    search: searchText.value,
                    limit: 100
                })
                if (resp.data.success) {
                    conversations.value = resp.data.payload?.conversations || []
                }
            } catch {
                // WhatsApp nicht verfügbar
            } finally {
                loading.value = false
            }
        }

        async function selectConversation(conv) {
            selectedConv.value = conv
            loadingChat.value = true
            chatMessages.value = []
            replyMessage.value = ''

            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'getWhatsAppChat',
                    phone_number: conv.phone_number,
                    limit: 200
                })
                if (resp.data.success) {
                    chatMessages.value = resp.data.payload?.messages || []
                }

                // Ungelesene Nachrichten als gelesen markieren
                if (conv.unread_count > 0) {
                    axios.post('/api/whatsapp/', {
                        action: 'markWhatsAppRead',
                        phone_number: conv.phone_number
                    })
                    conv.unread_count = 0
                }
            } catch {
                // Fehler beim Laden
            } finally {
                loadingChat.value = false
                await nextTick()
                scrollToBottom()
                loadAllMedia()
            }
        }

        async function doSendReply() {
            const text = replyMessage.value.trim()
            const file = attachedFile.value
            if ((!text && !file) || !selectedConv.value) return

            sending.value = true
            try {
                let success = false

                // Dokument senden (mit oder ohne Text)
                if (file) {
                    const resp = await axios.post('/api/whatsapp/', {
                        action: 'sendWhatsAppChatDocument',
                        to: selectedConv.value.phone_number,
                        message: text,
                        customer_id: selectedConv.value.customer_id || 0,
                        document_base64: file.base64,
                        filename: file.name
                    })
                    success = resp.data.success
                    if (!success) {
                        const detail = resp.data.text || ''
                        toast.error(detail || t('WhatsAppView.sendError'))
                    }
                    // Nach loadChat() wird das Medium per loadAllMedia() geladen und im globalen Cache gespeichert
                } else {
                    // Nur Text
                    const resp = await axios.post('/api/whatsapp/', {
                        action: 'sendWhatsAppMessage',
                        to: selectedConv.value.phone_number,
                        message: text,
                        customer_id: selectedConv.value.customer_id || 0,
                        customer_name: selectedConv.value.customer_name || selectedConv.value.contact_name || ''
                    })
                    success = resp.data.success
                    if (!success) toast.error(t('WhatsAppView.sendError'))
                }

                if (success) {
                    replyMessage.value = ''
                    attachedFile.value = null
                    toast.success(t('WhatsAppView.sendSuccess'))
                    await selectConversation(selectedConv.value)
                }
            } catch (err) {
                console.error('WhatsApp doSendReply error:', err)
                toast.error(t('WhatsAppView.sendError'))
            } finally {
                sending.value = false
            }
        }

        // ===== Dateianhang =====

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

        async function openDocumentPicker() {
            if (!selectedConv.value?.customer_id) return
            showDocPicker.value = true
            docPickerLoading.value = true
            try {
                const resp = await axios.post('/api/customer_vendor/', {
                    action: 'vfIndex',
                    cv_id: selectedConv.value.customer_id,
                    src: 'C'
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
                    cv_id: selectedConv.value.customer_id,
                    src: 'C',
                    path: file.path
                }, { responseType: 'arraybuffer' })
                const base64 = btoa(new Uint8Array(resp.data).reduce((data, byte) => data + String.fromCharCode(byte), ''))
                attachedFile.value = {
                    name: file.basename,
                    base64: base64,
                    mimeType: file.basename.endsWith('.pdf') ? 'application/pdf' : 'image/jpeg'
                }
            } catch {
                toast.error(t('WhatsAppView.downloadError'))
            }
        }

        async function doSendCompose() {
            const to = composeData.value.to.trim()
            const message = composeData.value.message.trim()
            if (!to || !message) return

            composeSending.value = true
            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'sendWhatsAppMessage',
                    to: to,
                    message: message,
                    customer_id: composeCustomer.value?.id || 0,
                    customer_name: composeCustomer.value?.name || ''
                })
                if (resp.data.success) {
                    showCompose.value = false
                    composeData.value = { to: '', message: '' }
                    composeCustomer.value = null
                    composePhones.value = []
                    toast.success(t('WhatsAppView.sendSuccess'))
                    await loadConversations()
                } else {
                    toast.error(t('WhatsAppView.sendError'))
                }
            } catch (err) {
                console.error('WhatsApp doSendCompose error:', err)
                toast.error(t('WhatsAppView.sendError'))
            } finally {
                composeSending.value = false
            }
        }

        function onCustomerSearch(val) {
            if (customerSearchTimeout) clearTimeout(customerSearchTimeout)
            if (!val || val.length < 2) {
                customerResults.value = []
                return
            }
            customerLoading.value = true
            customerSearchTimeout = setTimeout(async () => {
                try {
                    const resp = await axios.post('/api/whatsapp/', {
                        action: 'searchCvPhones',
                        query: val
                    })
                    if (resp.data.success) {
                        customerResults.value = resp.data.payload || []
                    }
                } catch {
                    customerResults.value = []
                } finally {
                    customerLoading.value = false
                }
            }, 300)
        }

        function onCustomerSelect(item) {
            if (!item) {
                composePhones.value = []
                return
            }
            composePhones.value = item.phones || []
            if (composePhones.value.length === 1) {
                composeData.value.to = composePhones.value[0]
            } else if (composePhones.value.length > 1) {
                composeData.value.to = composePhones.value[0]
            }
        }

        function openCustomer(conv) {
            if (conv.customer_id) {
                const routeName = conv.src === 'V' ? 'change-vendor' : 'change-customer'
                router.push({ name: routeName, params: { id: conv.customer_id }, query: { tab: 'whatsapp' } })
            }
        }

        function scrollToBottom() {
            const doScroll = () => {
                if (chatContainer.value) {
                    chatContainer.value.scrollTop = chatContainer.value.scrollHeight
                }
            }
            doScroll()
            // Nochmal nach kurzem Delay (Bilder/Medien koennen Hoehe aendern)
            setTimeout(doScroll, 150)
            setTimeout(doScroll, 500)
        }

        // Alle Bilder im Chat laden
        function loadAllMedia() {
            chatMessages.value
                .filter(msg => ['image', 'audio', 'voice', 'video', 'sticker'].includes(msg.message_type) && msg.media_url && !mediaCache[msg.id])
                .forEach(msg => loadMedia(msg))
        }

        async function loadMedia(msg) {
            const msgId = msg.id
            if (!msg.media_url || mediaCache[msgId] || mediaLoading[msgId]) return

            mediaLoading[msgId] = true
            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'getWhatsAppMedia',
                    media_id: msg.media_url
                })
                if (resp.data.success && resp.data.payload?.data) {
                    const mimeType = resp.data.payload.mime_type || 'image/jpeg'
                    mediaCache[msgId] = `data:${mimeType};base64,${resp.data.payload.data}`
                }
            } catch {
                // Fehler beim Laden des Mediums
            } finally {
                delete mediaLoading[msgId]
            }
        }

        function openImageViewer(src, caption) {
            viewerImageSrc.value = src
            viewerCaption.value = caption || ''
            showImageViewer.value = true
        }

        async function downloadDocument(msg) {
            const msgId = msg.id
            if (!msg.media_url) return
            if (mediaLoading[msgId]) return

            // Bereits im Cache?
            if (mediaCache[msgId]) {
                triggerDownload(mediaCache[msgId], msg.message_text || 'Dokument.pdf')
                return
            }

            mediaLoading[msgId] = true
            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'getWhatsAppMedia',
                    media_id: msg.media_url
                })
                if (resp.data.success && resp.data.payload?.data) {
                    const mimeType = resp.data.payload.mime_type || 'application/pdf'
                    const dataUrl = `data:${mimeType};base64,${resp.data.payload.data}`
                    mediaCache[msgId] = dataUrl
                    triggerDownload(dataUrl, msg.message_text || 'Dokument.pdf')
                } else {
                    toast.error(t('WhatsAppView.downloadError'))
                }
            } catch {
                toast.error(t('WhatsAppView.downloadError'))
            } finally {
                delete mediaLoading[msgId]
            }
        }

        function triggerDownload(dataUrl, filename) {
            const link = document.createElement('a')
            link.href = dataUrl
            link.download = filename
            document.body.appendChild(link)
            link.click()
            document.body.removeChild(link)
        }

        function downloadMedia(msg) {
            const dataUrl = mediaCache[msg.id]
            if (!dataUrl) return

            const link = document.createElement('a')
            link.href = dataUrl
            const ext = (msg.media_mime_type || 'image/jpeg').split('/').pop() || 'jpg'
            link.download = msg.media_caption || `whatsapp_${msg.id}.${ext}`
            document.body.appendChild(link)
            link.click()
            document.body.removeChild(link)
        }

        function downloadViewerImage() {
            if (!viewerImageSrc.value) return
            const link = document.createElement('a')
            link.href = viewerImageSrc.value
            link.download = viewerCaption.value || 'whatsapp_image.jpg'
            document.body.appendChild(link)
            link.click()
            document.body.removeChild(link)
        }

        function deriveSaveFilename(msg) {
            // 1. Lokaler Pfad? Dateiname extrahieren (z.B. "customers/123/whatsapp/2024-01-01_image.jpg")
            const mediaUrl = msg.media_url || ''
            if (mediaUrl.includes('/')) {
                const basename = mediaUrl.split('/').pop()
                if (basename && basename.includes('.')) return basename
            }
            // 2. message_text nur nutzen wenn es wie ein Dateiname aussieht (hat Extension, kein [ ])
            const text = msg.message_text || ''
            if (text && text.includes('.') && !text.startsWith('[')) return text
            // 3. Caption verwenden wenn vorhanden
            const caption = msg.media_caption || ''
            if (caption && caption.includes('.')) return caption
            // 4. Fallback: aus Typ + Media-ID generieren
            const extMap = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'application/pdf': 'pdf', 'audio/ogg': 'ogg', 'video/mp4': 'mp4' }
            const ext = extMap[msg.media_mime_type] || (msg.media_mime_type || '').split('/').pop() || 'bin'
            const id = mediaUrl.replace(/[^a-zA-Z0-9]/g, '').slice(-10) || Date.now()
            return `whatsapp_${id}.${ext}`
        }

        async function saveMediaToFolder(msg) {
            if (!msg.media_url || !selectedConv.value?.customer_id) return
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
                    customer_id: selectedConv.value.customer_id,
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
                    customer_id: selectedConv.value.customer_id,
                    filename: saveFilename.value.trim(),
                    path: saveCurrentPath.value
                })
                if (resp.data.success) {
                    toast.success(t('WhatsAppView.saveToFolderSuccess', { filename: resp.data.payload?.filename || saveFilename.value }))
                    showSaveDialog.value = false
                } else {
                    toast.error(resp.data.text || t('WhatsAppView.saveToFolderError'))
                }
            } catch {
                toast.error(t('WhatsAppView.saveToFolderError'))
            } finally {
                savingToFolder.value = false
            }
        }

        function parseContacts(text) {
            try { return JSON.parse(text) } catch { return [] }
        }

        function createFromContact(contact, src) {
            const query = {}
            if (contact.name) query.prefill_name = contact.name
            if (contact.phones?.length) query.prefill_phone = contact.phones[0]
            if (contact.emails?.length) query.prefill_email = contact.emails[0]
            router.push({ name: src === 'V' ? 'vendor-new' : 'customer-new', query })
        }

        // Emoji einfuegen
        function onEmojiSelect(emoji) {
            replyMessage.value += emoji.i
            showEmojiPicker.value = false
        }

        // Weiterleiten
        function openForwardDialog(msg) {
            forwardMessage.value = msg
            forwardSearch.value = ''
            showForwardDialog.value = true
        }

        const forwardConversations = ref([])

        function filteredForwardConversations() {
            if (!forwardSearch.value.trim()) return conversations.value
            const q = forwardSearch.value.toLowerCase()
            return conversations.value.filter(c =>
                (c.contact_name || '').toLowerCase().includes(q) ||
                (c.customer_name || '').toLowerCase().includes(q) ||
                (c.phone_number || '').includes(q)
            )
        }

        async function doForward(targetConv) {
            if (!forwardMessage.value || forwardSending.value) return
            forwardSending.value = true
            try {
                const msg = forwardMessage.value
                let success = false

                if (msg.message_type === 'location') {
                    // Standort als native Location-Message weiterleiten
                    const coords = parseLocationCoords(msg.message_text)
                    const [lat, lon] = coords.split(',').map(Number)
                    const resp = await axios.post('/api/whatsapp/', {
                        action: 'sendWhatsAppLocation',
                        to: targetConv.phone_number,
                        latitude: lat,
                        longitude: lon,
                        customer_id: targetConv.customer_id || 0
                    })
                    success = resp.data.success
                } else if (msg.media_url && (msg.message_type === 'image' || msg.message_type === 'document' || msg.message_type === 'audio' || msg.message_type === 'voice' || msg.message_type === 'video')) {
                    // Mediendatei weiterleiten: erst abrufen, dann senden
                    const mediaResp = await axios.post('/api/whatsapp/', {
                        action: 'getWhatsAppMedia',
                        media_id: msg.media_url
                    })
                    if (mediaResp.data.success && mediaResp.data.payload?.data) {
                        const mimeType = mediaResp.data.payload.mime_type || msg.media_mime_type || 'application/octet-stream'
                        const extMap = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'audio/ogg': 'ogg', 'audio/mpeg': 'mp3', 'video/mp4': 'mp4', 'application/pdf': 'pdf' }
                        const ext = extMap[mimeType] || mimeType.split('/').pop() || 'bin'
                        const filename = `weiterleitung_${msg.id}.${ext}`
                        const resp = await axios.post('/api/whatsapp/', {
                            action: 'sendWhatsAppChatDocument',
                            to: targetConv.phone_number,
                            message: msg.media_caption || '',
                            customer_id: targetConv.customer_id || 0,
                            document_base64: mediaResp.data.payload.data,
                            filename: filename
                        })
                        success = resp.data.success
                    }
                } else {
                    // Textnachricht weiterleiten
                    const resp = await axios.post('/api/whatsapp/', {
                        action: 'sendWhatsAppMessage',
                        to: targetConv.phone_number,
                        message: msg.message_text || '',
                        customer_id: targetConv.customer_id || 0,
                        customer_name: targetConv.customer_name || targetConv.contact_name || ''
                    })
                    success = resp.data.success
                }

                if (success) {
                    toast.success(t('WhatsAppView.forwardSuccess'))
                    showForwardDialog.value = false
                    forwardMessage.value = null
                } else {
                    toast.error(t('WhatsAppView.forwardError'))
                }
            } catch {
                toast.error(t('WhatsAppView.forwardError'))
            } finally {
                forwardSending.value = false
            }
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

        function formatDate(dateStr) {
            if (!dateStr) return ''
            try {
                const d = new Date(dateStr)
                if (isNaN(d.getTime())) return dateStr
                const now = new Date()
                if (d.toDateString() === now.toDateString()) {
                    return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
                }
                return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })
            } catch {
                return dateStr
            }
        }

        function formatDateTime(isoStr) {
            if (!isoStr) return ''
            const d = new Date(isoStr)
            return d.toLocaleString('de-DE', {
                day: '2-digit', month: '2-digit',
                hour: '2-digit', minute: '2-digit'
            })
        }

        function formatTime(isoStr) {
            if (!isoStr) return ''
            const d = new Date(isoStr)
            return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
        }

        function formatDateLabel(isoStr) {
            if (!isoStr) return ''
            const d = new Date(isoStr)
            const now = new Date()
            const yesterday = new Date(now)
            yesterday.setDate(yesterday.getDate() - 1)

            if (d.toDateString() === now.toDateString()) return t('WhatsAppView.today')
            if (d.toDateString() === yesterday.toDateString()) return t('WhatsAppView.yesterday')
            return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        }

        function showDateSeparator(idx) {
            if (idx === 0) return true
            const prev = new Date(chatMessages.value[idx - 1].itime)
            const curr = new Date(chatMessages.value[idx].itime)
            return prev.toDateString() !== curr.toDateString()
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

        function truncate(str, len) {
            if (!str) return ''
            return str.length > len ? str.substring(0, len) + '...' : str
        }

        function initials(name) {
            if (!name) return '?'
            const parts = name.trim().split(/\s+/)
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
            }
            return name.substring(0, 2).toUpperCase()
        }

        // Telefonnummer normalisieren (nur Ziffern, fuer den Vergleich mit gespeicherten Nummern)
        function normalizePhoneDigits(phone) {
            return (phone || '').replace(/\D/g, '')
        }

        onMounted(async () => {
            if (checkConfig()) {
                await loadConversations()

                // Auto-Select per Query-Parameter (z.B. aus phone-action-bar oder Anrufliste)
                const phoneFromQuery = route.query.phone
                if (phoneFromQuery) {
                    const wanted = normalizePhoneDigits(phoneFromQuery)
                    const match = conversations.value.find(c => normalizePhoneDigits(c.phone_number) === wanted)
                    if (match) {
                        selectConversation(match)
                    } else {
                        // Keine bestehende Konversation -> Compose-Dialog mit vorbelegter Nummer
                        composeData.value = { to: phoneFromQuery, message: '' }
                        showCompose.value = true
                    }
                } else if (conversations.value.length && !selectedConv.value) {
                    // Default: neueste Konversation auswaehlen
                    selectConversation(conversations.value[0])
                }

                // SSE fuer Echtzeit-Updates bei eingehenden Nachrichten
                eventSource = new EventSource('/sse/events')
                eventSource.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data)
                        if (data.message_type === undefined) return // Kein WhatsApp-Event
                    } catch {
                        return
                    }
                    // Konversationsliste aktualisieren
                    loadConversations()
                    // Aktiven Chat aktualisieren, falls offen
                    if (selectedConv.value) {
                        selectConversation(selectedConv.value)
                    }
                }
                eventSource.onerror = () => { /* SSE-Fehler still ignorieren */ }
            }
        })

        onUnmounted(() => {
            if (eventSource) {
                eventSource.close()
                eventSource = null
            }
        })

        return {
            t,
            conversations, loading, configError, searchText,
            selectedConv, chatMessages, loadingChat,
            replyMessage, sending, chatContainer,
            showCompose, composeData, composeSending, composePhones,
            composeCustomer, customerSearch, customerResults, customerLoading,
            attachedFile, fileInputRef, showDocPicker, customerFiles, docPickerLoading,
            triggerFileUpload, onFileSelected, openDocumentPicker, selectCustomerFile,
            mediaCache, mediaLoading, savingMedia,
            showSaveDialog, saveCurrentPath, saveFolders, saveFilename, saveFolderLoading, savingToFolder,
            showImageViewer, viewerImageSrc, viewerCaption,
            showEmojiPicker, onEmojiSelect,
            showDeleteConfirm, deleting, confirmDeleteMessage, doDeleteMessage,
            showForwardDialog, forwardMessage, forwardSearch, forwardSending,
            openForwardDialog, filteredForwardConversations, doForward,
            loadConversations, selectConversation,
            doSendReply, doSendCompose,
            onCustomerSearch, onCustomerSelect,
            openCustomer,
            openImageViewer, downloadDocument, downloadMedia, downloadViewerImage,
            saveMediaToFolder, navigateSaveFolder, doSaveToFolder,
            formatDate, formatDateTime, formatTime, formatDateLabel,
            showDateSeparator, statusIcon, truncate, initials,
            linkifyText, parseLocationCoords, buildMapEmbedUrl, parseContacts, createFromContact
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
    background-color: #f5f5f5;
}

.conv-list-item {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

/* ============================================
   CHAT CONTAINER - WhatsApp-style
   ============================================ */
.chat-container {
    max-height: 500px;
    overflow-y: auto;
    background-color: #efeae2;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23d4cfc6' fill-opacity='0.25'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    padding: 16px 12px;
}

/* Datumstrenner */
.chat-date-separator {
    text-align: center;
    margin: 12px 0;
}

.chat-date-chip {
    display: inline-block;
    background: rgba(225, 218, 208, 0.9);
    color: #54656f;
    font-size: 12px;
    font-weight: 500;
    padding: 4px 12px;
    border-radius: 8px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.08);
}

/* Chat-Zeile */
.chat-row {
    display: flex;
    margin-bottom: 3px;
}

.chat-row--out {
    justify-content: flex-end;
}

.chat-row--in {
    justify-content: flex-start;
}

/* Chat-Bubble */
.chat-bubble {
    max-width: 65%;
    min-width: 100px;
    padding: 6px 8px 4px;
    border-radius: 8px;
    position: relative;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
    word-wrap: break-word;
}

.chat-bubble--out {
    background-color: #d9fdd3;
    border-top-right-radius: 0;
}

.chat-bubble--in {
    background-color: #ffffff;
    border-top-left-radius: 0;
}

.chat-bubble__sender {
    font-size: 12px;
    font-weight: 600;
    color: #1fa855;
    margin-bottom: 2px;
}

.chat-bubble__text {
    font-size: 14px;
    line-height: 1.4;
    color: #111b21;
    white-space: pre-wrap;
}

/* Zeitstempel + Status */
.chat-bubble__meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
    margin-top: 2px;
    font-size: 11px;
    color: #667781;
    line-height: 1;
}

.chat-bubble__status {
    margin-left: 1px;
}

/* Fehlermeldung */
.chat-bubble__error {
    display: flex;
    align-items: center;
    font-size: 11px;
    color: #d32f2f;
    margin-top: 4px;
    padding-top: 4px;
    border-top: 1px solid rgba(211, 47, 47, 0.2);
}

/* Dokument-Darstellung */
.chat-document {
    display: flex;
    align-items: center;
    background: rgba(0, 0, 0, 0.04);
    border-radius: 6px;
    padding: 8px 10px;
    margin-bottom: 2px;
    gap: 10px;
}

.chat-document__icon {
    flex-shrink: 0;
}

.chat-document__info {
    overflow: hidden;
}

.chat-document__name {
    font-size: 13px;
    font-weight: 500;
    color: #111b21;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-document__type {
    font-size: 11px;
    color: #667781;
    text-transform: uppercase;
}

.chat-document--clickable {
    cursor: pointer;
    transition: background 0.15s;
}

.chat-document--clickable:hover {
    background: rgba(0, 0, 0, 0.08);
}

/* Bild-Darstellung */
.chat-image {
    margin-bottom: 2px;
}

.chat-image__wrapper {
    position: relative;
    display: inline-block;
}

.chat-image__img {
    max-width: 280px;
    max-height: 280px;
    border-radius: 6px;
    cursor: pointer;
    display: block;
    object-fit: cover;
}

.chat-image__img:hover {
    opacity: 0.92;
}

.chat-image__download {
    position: absolute;
    top: 6px;
    right: 6px;
    opacity: 0;
    transition: opacity 0.15s;
    background: rgba(255, 255, 255, 0.85) !important;
}

.chat-image__wrapper:hover .chat-image__download,
.chat-image__wrapper:hover .chat-image__save {
    opacity: 1;
}

.chat-image__save {
    position: absolute;
    top: 6px;
    right: 36px;
    opacity: 0;
    transition: opacity 0.15s;
    background: rgba(255, 255, 255, 0.85) !important;
}

.chat-image__placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 200px;
    height: 140px;
    background: rgba(0, 0, 0, 0.04);
    border-radius: 6px;
}

.chat-image__caption {
    font-size: 13px;
    color: #111b21;
    margin-top: 4px;
}

/* Audio/Video/Sticker */
.chat-audio {
    display: flex;
    align-items: center;
    padding: 4px 0;
    color: #667781;
}

/* Weiterleiten-Button */
.chat-bubble__forward {
    opacity: 0;
    transition: opacity 0.15s;
    margin-right: 4px;
}
.chat-bubble:hover .chat-bubble__forward {
    opacity: 0.6;
}
.chat-bubble__forward:hover {
    opacity: 1 !important;
}

/* Kontakt-Karte */
.chat-contact-card {
    background: rgba(0,0,0,0.04);
    border-radius: 8px;
    padding: 8px 10px;
    margin: 2px 0;
}
.chat-contact-card + .chat-contact-card { margin-top: 6px; }
.chat-contact-card a { color: #1565c0; }
.chat-msg-card { padding-right: 28px !important; }
.chat-msg-card .chat-msg-delete { position: absolute; top: 2px; right: 2px; opacity: 0; transition: opacity 0.15s; z-index: 1; }
.chat-msg-card:hover .chat-msg-delete { opacity: 1; }
</style>
