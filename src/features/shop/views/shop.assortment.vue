<!-- src/features/shop/views/shop.assortment.vue -->
<!--
    Sortiment: Gruppen und Kategorien des Shops (dev/shop-sortiment.md).

    Ein Artikel wählt seine Kategorie in der Artikelkarte aus dieser Liste, die
    Gruppe gehört der Kategorie. Beim Vorlagensatz standard entsteht daraus die
    Kategorieübersicht der Webseite: Gruppen in der Reihenfolge hier,
    Kategorien ohne Gruppe unter „Sonstiges“, Topseller oben.

    Lesen: shop_part_edit oder edit_shop_config; ändern nur mit
    edit_shop_config, wie die API (backend/api/shop/assortment.php). Was sich
    auf der Webseite ändert, stoßen Trigger an — die Ansicht muss nichts
    veröffentlichen.

    Jede Aktion ist ein Aufruf; die Antwort trägt die geschriebene Zeile, die
    Liste wird lokal nachgeführt statt neu geladen.
-->
<template>
    <NavbarView />
    <v-container fluid class="sortiment">
        <page-header
            :title="t('ShopView.assortment.title')"
            :subtitle="t('ShopView.assortment.subtitle')"
            :back-to="{ name: 'shop-overview' }"
        >
            <v-chip v-if="laedt" size="small" variant="tonal">{{ t('ShopView.assortment.loading') }}</v-chip>
        </page-header>

        <v-alert v-if="!darfLesen" type="warning" variant="tonal" density="compact">
            {{ t('ShopView.errors.NO_PERMISSION') }}
        </v-alert>

        <template v-else>
            <v-alert type="info" variant="tonal" density="compact" class="sortiment__hinweis">
                {{ t('ShopView.assortment.hint') }}
            </v-alert>

            <v-row density="compact">
                <!-- Gruppen: Reihenfolge = Reihenfolge der Übersicht auf der Webseite -->
                <v-col cols="12" md="4">
                    <section-card :title="t('ShopView.assortment.groups')" icon="mdi-folder-multiple-outline" plain>
                        <template v-if="darfAendern" #actions>
                            <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="gruppeBearbeiten(null)">
                                {{ t('ShopView.assortment.newGroup') }}
                            </v-btn>
                        </template>
                        <v-list density="compact" class="sortiment__liste">
                            <v-list-item
                                v-for="(gruppe, index) in gruppen"
                                :key="gruppe.id"
                                :active="filterGruppe === gruppe.id"
                                @click="filterGruppe = filterGruppe === gruppe.id ? null : gruppe.id"
                            >
                                <v-list-item-title>{{ gruppe.name }}</v-list-item-title>
                                <v-list-item-subtitle>
                                    {{ t('ShopView.assortment.categoryCount', { count: zahlJeGruppe[gruppe.id] || 0 }) }}
                                </v-list-item-subtitle>
                                <template v-if="darfAendern" #append>
                                    <v-btn
                                        icon="mdi-arrow-up" size="x-small" variant="text"
                                        :disabled="index === 0"
                                        :title="t('ShopView.assortment.moveUp')"
                                        @click.stop="verschieben(index, -1)"
                                    />
                                    <v-btn
                                        icon="mdi-arrow-down" size="x-small" variant="text"
                                        :disabled="index === gruppen.length - 1"
                                        :title="t('ShopView.assortment.moveDown')"
                                        @click.stop="verschieben(index, 1)"
                                    />
                                    <v-btn
                                        icon="mdi-pencil-outline" size="x-small" variant="text"
                                        :title="t('ShopView.assortment.edit')"
                                        @click.stop="gruppeBearbeiten(gruppe)"
                                    />
                                    <v-btn
                                        icon="mdi-delete-outline" size="x-small" variant="text"
                                        :title="t('ShopView.assortment.delete')"
                                        @click.stop="loeschenFragen('gruppe', gruppe)"
                                    />
                                </template>
                            </v-list-item>
                            <!-- Restgruppe: Kategorien ohne Gruppe -->
                            <v-list-item
                                :active="filterGruppe === 0"
                                @click="filterGruppe = filterGruppe === 0 ? null : 0"
                            >
                                <v-list-item-title class="sortiment__rest">{{ t('ShopView.assortment.noGroup') }}</v-list-item-title>
                                <v-list-item-subtitle>
                                    {{ t('ShopView.assortment.categoryCount', { count: zahlJeGruppe[0] || 0 }) }}
                                </v-list-item-subtitle>
                            </v-list-item>
                        </v-list>
                    </section-card>
                </v-col>

                <!-- Kategorien -->
                <v-col cols="12" md="8">
                    <section-card :title="t('ShopView.assortment.categories')" icon="mdi-tag-multiple-outline" plain>
                        <template v-if="darfAendern" #actions>
                            <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="kategorieBearbeiten(null)">
                                {{ t('ShopView.assortment.newCategory') }}
                            </v-btn>
                        </template>

                        <!-- Filterzeile im Inhalt, nicht im Kopf: der Kopf-Slot liegt
                             außerhalb der Feld-Vorgaben von section-card (outlined,
                             compact), ein Feld dort sähe anders aus als alle übrigen -->
                        <div class="sortiment__filter">
                            <v-text-field
                                v-model="suche"
                                :label="t('ShopView.assortment.search')"
                                prepend-inner-icon="mdi-magnify"
                                clearable
                                hide-details
                                class="sortiment__suche"
                            />
                        </div>

                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ t('ShopView.assortment.name') }}</th>
                                    <th>{{ t('ShopView.assortment.group') }}</th>
                                    <th class="text-center">{{ t('ShopView.assortment.topseller') }}</th>
                                    <th class="text-end">{{ t('ShopView.assortment.parts') }}</th>
                                    <th v-if="darfAendern" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="kategorie in sichtbareKategorien" :key="kategorie.id">
                                    <td>{{ kategorie.name }}</td>
                                    <td :class="{ sortiment__rest: !kategorie.group_id }">{{ gruppenName(kategorie.group_id) }}</td>
                                    <td class="text-center">
                                        <v-icon v-if="kategorie.topseller" size="small" color="primary">mdi-star</v-icon>
                                    </td>
                                    <td class="text-end" :title="t('ShopView.assortment.partsHint')">
                                        {{ kategorie.offered }} / {{ kategorie.parts }}
                                    </td>
                                    <td v-if="darfAendern" class="text-end text-no-wrap">
                                        <v-btn
                                            icon="mdi-pencil-outline" size="x-small" variant="text"
                                            :title="t('ShopView.assortment.edit')"
                                            @click="kategorieBearbeiten(kategorie)"
                                        />
                                        <v-btn
                                            icon="mdi-delete-outline" size="x-small" variant="text"
                                            :title="t('ShopView.assortment.delete')"
                                            @click="loeschenFragen('kategorie', kategorie)"
                                        />
                                    </td>
                                </tr>
                                <tr v-if="!sichtbareKategorien.length">
                                    <td :colspan="darfAendern ? 5 : 4" class="sortiment__leer">
                                        {{ kategorien.length ? t('ShopView.assortment.noMatch') : t('ShopView.assortment.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </section-card>
                </v-col>
            </v-row>
        </template>

        <!-- Gruppe anlegen oder umbenennen -->
        <v-dialog v-model="gruppeDialog.offen" max-width="480">
            <section-card :title="gruppeDialog.id ? t('ShopView.assortment.editGroup') : t('ShopView.assortment.newGroup')" icon="mdi-folder-outline">
                <v-text-field
                    v-model="gruppeDialog.name"
                    :label="t('ShopView.assortment.name')"
                    :hint="t('ShopView.assortment.groupNameHint')"
                    persistent-hint
                    autofocus
                    @keyup.enter="gruppeSpeichern"
                />
                <div class="sortiment__knoepfe">
                    <v-btn variant="text" @click="gruppeDialog.offen = false">{{ t('ShopView.assortment.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="speichert" :disabled="!gruppeDialog.name.trim()" @click="gruppeSpeichern">
                        {{ t('ShopView.assortment.save') }}
                    </v-btn>
                </div>
            </section-card>
        </v-dialog>

        <!-- Kategorie anlegen oder ändern -->
        <v-dialog v-model="kategorieDialog.offen" max-width="560">
            <section-card :title="kategorieDialog.id ? t('ShopView.assortment.editCategory') : t('ShopView.assortment.newCategory')" icon="mdi-tag-outline">
                <v-row density="compact">
                    <v-col cols="12">
                        <v-text-field
                            v-model="kategorieDialog.name"
                            :label="t('ShopView.assortment.name')"
                            :hint="t('ShopView.assortment.categoryNameHint')"
                            persistent-hint
                            autofocus
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-select
                            v-model="kategorieDialog.group_id"
                            :items="gruppenAuswahl"
                            item-title="name"
                            item-value="id"
                            :label="t('ShopView.assortment.group')"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-checkbox
                            v-model="kategorieDialog.topseller"
                            :label="t('ShopView.assortment.topseller')"
                            :hint="t('ShopView.assortment.topsellerHint')"
                            persistent-hint
                        />
                    </v-col>
                </v-row>
                <!-- Neuer Name = neue Adresse: die Produktseiten werden neu geschrieben -->
                <v-alert
                    v-if="umbenennenMitArtikeln"
                    type="warning" variant="tonal" density="compact" class="sortiment__hinweis"
                >
                    {{ t('ShopView.assortment.renameHint', { count: kategorieDialog.parts }) }}
                </v-alert>
                <div class="sortiment__knoepfe">
                    <v-btn variant="text" @click="kategorieDialog.offen = false">{{ t('ShopView.assortment.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="speichert" :disabled="!kategorieDialog.name.trim()" @click="kategorieSpeichern">
                        {{ t('ShopView.assortment.save') }}
                    </v-btn>
                </div>
            </section-card>
        </v-dialog>

        <!-- Löschen bestätigen -->
        <v-dialog v-model="loeschen.offen" max-width="480">
            <section-card :title="t('ShopView.assortment.delete')" icon="mdi-delete-outline">
                <p>{{ loeschenText }}</p>
                <div class="sortiment__knoepfe">
                    <v-btn variant="text" @click="loeschen.offen = false">{{ t('ShopView.assortment.cancel') }}</v-btn>
                    <v-btn
                        color="error" :loading="speichert"
                        :disabled="loeschen.art === 'kategorie' && loeschen.eintrag?.parts > 0"
                        @click="loeschenAusfuehren"
                    >
                        {{ t('ShopView.assortment.delete') }}
                    </v-btn>
                </div>
            </section-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import * as toasts from '@/core/utils/toasts.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { shopFehler } from '../composables/useShop.js'

const i18n = useI18n()
const { t } = i18n
const oserp = oserpStore()

const darfLesen = computed(() => oserp.checkPermission('shop_part_edit') || oserp.checkPermission('edit_shop_config'))
const darfAendern = computed(() => oserp.checkPermission('edit_shop_config'))

const gruppen = ref([])
const kategorien = ref([])
const laedt = ref(false)
const speichert = ref(false)
const suche = ref('')
/** null = alle, 0 = ohne Gruppe, sonst Kennung der Gruppe */
const filterGruppe = ref(null)

const vergleich = (a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' })

// ── Laden ──

async function aufruf(daten) {
    const antwort = await axios.post('/api/shop/', daten)
    if (!antwort.data?.success) {
        throw antwort.data
    }
    return antwort.data.payload || {}
}

function fehlerZeigen(antwort) {
    toasts.error(shopFehler(antwort?.response?.data ?? antwort, i18n).text)
}

async function laden() {
    laedt.value = true
    try {
        const daten = await aufruf({ action: 'getShopAssortment' })
        gruppen.value = daten.groups || []
        kategorien.value = daten.categories || []
    } catch (e) {
        fehlerZeigen(e)
    } finally {
        laedt.value = false
    }
}

onMounted(() => {
    if (darfLesen.value) laden()
})

// ── Anzeige ──

const gruppenNamen = computed(() => Object.fromEntries(gruppen.value.map(g => [g.id, g.name])))

function gruppenName(id) {
    return id ? (gruppenNamen.value[id] || '') : t('ShopView.assortment.noGroup')
}

const zahlJeGruppe = computed(() => {
    const zahl = {}
    kategorien.value.forEach(k => { zahl[k.group_id || 0] = (zahl[k.group_id || 0] || 0) + 1 })
    return zahl
})

const sichtbareKategorien = computed(() => {
    const begriff = String(suche.value || '').trim().toLocaleLowerCase()
    return kategorien.value
        .filter(k => filterGruppe.value === null || (k.group_id || 0) === filterGruppe.value)
        .filter(k => !begriff || k.name.toLocaleLowerCase().includes(begriff))
        .sort((a, b) => vergleich(a.name, b.name))
})

/** Auswahl im Dialog: die Gruppen und „ohne Gruppe“ */
const gruppenAuswahl = computed(() => [
    { id: null, name: t('ShopView.assortment.noGroup') },
    ...gruppen.value,
])

// ── Gruppen ──

const gruppeDialog = reactive({ offen: false, id: 0, name: '' })

function gruppeBearbeiten(gruppe) {
    Object.assign(gruppeDialog, { offen: true, id: gruppe?.id || 0, name: gruppe?.name || '' })
}

async function gruppeSpeichern() {
    if (!gruppeDialog.name.trim() || speichert.value) return
    speichert.value = true
    try {
        const { group } = await aufruf({ action: 'saveShopCategoryGroup', id: gruppeDialog.id, name: gruppeDialog.name })
        const index = gruppen.value.findIndex(g => g.id === group.id)
        if (index >= 0) gruppen.value.splice(index, 1, group)
        else gruppen.value.push(group)
        gruppeDialog.offen = false
        toasts.success(t('ShopView.assortment.saved'))
    } catch (e) {
        fehlerZeigen(e)
    } finally {
        speichert.value = false
    }
}

async function verschieben(index, richtung) {
    const ziel = index + richtung
    if (ziel < 0 || ziel >= gruppen.value.length) return
    const neu = [...gruppen.value]
    ;[neu[index], neu[ziel]] = [neu[ziel], neu[index]]
    const vorher = gruppen.value
    gruppen.value = neu
    try {
        await aufruf({ action: 'saveShopCategoryGroupOrder', ids: neu.map(g => g.id) })
    } catch (e) {
        gruppen.value = vorher
        fehlerZeigen(e)
    }
}

// ── Kategorien ──

const kategorieDialog = reactive({ offen: false, id: 0, name: '', alterName: '', group_id: null, topseller: false, parts: 0 })

function kategorieBearbeiten(kategorie) {
    Object.assign(kategorieDialog, {
        offen: true,
        id: kategorie?.id || 0,
        name: kategorie?.name || '',
        alterName: kategorie?.name || '',
        // Neue Kategorie: die Gruppe, die gerade gefiltert ist
        group_id: kategorie ? (kategorie.group_id || null) : (filterGruppe.value || null),
        topseller: Boolean(kategorie?.topseller),
        parts: kategorie?.parts || 0,
    })
}

const umbenennenMitArtikeln = computed(() =>
    kategorieDialog.id && kategorieDialog.parts > 0 && kategorieDialog.name.trim() !== kategorieDialog.alterName)

async function kategorieSpeichern() {
    if (!kategorieDialog.name.trim() || speichert.value) return
    speichert.value = true
    try {
        const { category } = await aufruf({
            action: 'saveShopCategory',
            id: kategorieDialog.id,
            name: kategorieDialog.name,
            group_id: kategorieDialog.group_id || 0,
            topseller: kategorieDialog.topseller,
        })
        const index = kategorien.value.findIndex(k => k.id === category.id)
        if (index >= 0) {
            kategorien.value.splice(index, 1, { ...kategorien.value[index], ...category })
        } else {
            kategorien.value.push({ ...category, parts: 0, offered: 0 })
        }
        kategorieDialog.offen = false
        toasts.success(t('ShopView.assortment.saved'))
    } catch (e) {
        fehlerZeigen(e)
    } finally {
        speichert.value = false
    }
}

// ── Löschen ──

const loeschen = reactive({ offen: false, art: '', eintrag: null })

function loeschenFragen(art, eintrag) {
    Object.assign(loeschen, { offen: true, art, eintrag })
}

const loeschenText = computed(() => {
    const eintrag = loeschen.eintrag
    if (!eintrag) return ''
    if (loeschen.art === 'gruppe') {
        return t('ShopView.assortment.deleteGroupConfirm', { name: eintrag.name, count: zahlJeGruppe.value[eintrag.id] || 0 })
    }
    return eintrag.parts > 0
        ? t('ShopView.assortment.categoryInUse', { name: eintrag.name, count: eintrag.parts })
        : t('ShopView.assortment.deleteCategoryConfirm', { name: eintrag.name })
})

async function loeschenAusfuehren() {
    const { art, eintrag } = loeschen
    if (!eintrag || speichert.value) return
    speichert.value = true
    try {
        if (art === 'gruppe') {
            await aufruf({ action: 'deleteShopCategoryGroup', id: eintrag.id })
            gruppen.value = gruppen.value.filter(g => g.id !== eintrag.id)
            // Die Datenbank setzt die Gruppe ihrer Kategorien auf leer
            kategorien.value = kategorien.value.map(k => (k.group_id === eintrag.id ? { ...k, group_id: null } : k))
            if (filterGruppe.value === eintrag.id) filterGruppe.value = null
        } else {
            await aufruf({ action: 'deleteShopCategory', id: eintrag.id })
            kategorien.value = kategorien.value.filter(k => k.id !== eintrag.id)
        }
        loeschen.offen = false
        toasts.success(t('ShopView.assortment.deleted'))
    } catch (e) {
        // Inzwischen Artikel zugeordnet: die Antwort nennt ihre Zahl
        const daten = e?.response?.data ?? e
        if (String(daten?.text || '').startsWith('ASSORTMENT_CATEGORY_IN_USE')) {
            toasts.error(t('ShopView.assortment.categoryInUse', { name: eintrag.name, count: Number(daten.debug || daten.payload || 0) }))
        } else {
            fehlerZeigen(e)
        }
    } finally {
        speichert.value = false
    }
}
</script>

<style scoped>
.sortiment__hinweis {
    margin-bottom: var(--oserp-gap);
}

.sortiment__liste {
    padding: 0;
}

.sortiment__rest {
    color: var(--oserp-text-muted);
    font-style: italic;
}

.sortiment__filter {
    padding: var(--oserp-gap);
    border-bottom: 1px solid var(--oserp-border-soft);
}

.sortiment__suche {
    max-width: 24rem;
}

.sortiment__leer {
    color: var(--oserp-text-muted);
    text-align: center;
    padding: var(--oserp-gap-lg) 0;
}

.sortiment__knoepfe {
    display: flex;
    justify-content: flex-end;
    gap: var(--oserp-gap-sm);
    margin-top: var(--oserp-gap-lg);
}
</style>
