<!-- src/core/views/about/about.view.vue -->
<!--
    Über OSERP: Entstehung des Projekts, Grundsätze, Dank, Team und die
    Einladung zum Mitmachen. Reine Leseseite in Lesebreite, ohne Datenabruf.

    Aufbau, Namen und Links stehen in about.data.json, die Texte in locales/.
    Beides geht über den Feature-Katalog auch an die Website
    (backend/api/docs/docs.php, docs/website-schnittstelle.md) — dort ist die
    Seite für Suchmaschinen, OSERP selbst ist noindex (index.html).

    Öffentlich (Link auf der Loginseite): angemeldet mit Menü, sonst mit
    Rückweg zur Anmeldung. Die Route ist public, deshalb stellt die Seite eine
    bestehende Sitzung nach einem Neuladen selbst wieder her.
-->
<template>
    <NavbarView v-if="logged_in" />
    <v-container fluid class="pt-2 px-2 px-sm-4">
        <div class="about">
            <page-header
                :title="t('AboutView.title')"
                :subtitle="t('AboutView.subtitle')"
                :back-to="logged_in ? null : { name: 'login' }"
            />

            <section-card :title="t('AboutView.history.title')" icon="mdi-history" quiet>
                <p v-for="key in about_data.history" :key="key" class="about__text">
                    {{ t(`AboutView.history.${key}`) }}
                </p>
            </section-card>

            <section-card :title="t('AboutView.values.title')" icon="mdi-compass-outline" quiet>
                <div class="about__values">
                    <div v-for="value in about_data.values" :key="value.key" class="about__value">
                        <v-icon size="small" color="primary" class="about__value-icon">{{ value.icon }}</v-icon>
                        <div>
                            <div class="about__value-title">{{ t(`AboutView.values.${value.key}.title`) }}</div>
                            <div class="about__muted">{{ t(`AboutView.values.${value.key}.text`) }}</div>
                        </div>
                    </div>
                </div>
            </section-card>

            <section-card :title="t('AboutView.thanks.title')" icon="mdi-hand-heart-outline" quiet>
                <!-- Abschnitte aus about.data.json; extension: nur mit dieser Erweiterung (Fahrzeugschein-Scanner nur mit LxCars) -->
                <template v-for="block in thanks" :key="block.text">
                    <p class="about__text">
                        {{ t(`AboutView.thanks.${block.text}`) }}
                        <a v-if="block.link" :href="block.link" target="_blank" rel="noopener">{{ linkLabel(block.link) }}</a>
                    </p>
                    <div v-if="block.people" class="about__people">
                        <v-chip v-for="person in block.people" :key="person" :prepend-icon="block.icon" variant="tonal" color="primary">
                            {{ person }}
                        </v-chip>
                    </div>
                </template>
            </section-card>

            <section-card :title="t('AboutView.team.title')" icon="mdi-account-group-outline" quiet>
                <p class="about__text">{{ t('AboutView.team.text') }}</p>
                <div class="about__people">
                    <v-chip v-for="person in about_data.team" :key="person" prepend-icon="mdi-account-outline" variant="outlined">
                        {{ person }}
                    </v-chip>
                </div>
            </section-card>

            <section-card :title="t('AboutView.contribute.title')" icon="mdi-source-branch" quiet>
                <p class="about__text">{{ t('AboutView.contribute.intro') }}</p>
                <div class="about__values">
                    <div v-for="way in about_data.contribution_ways" :key="way.key" class="about__value">
                        <v-icon size="small" color="primary" class="about__value-icon">{{ way.icon }}</v-icon>
                        <div>
                            <div class="about__value-title">{{ t(`AboutView.contribute.ways.${way.key}.title`) }}</div>
                            <div class="about__muted">{{ t(`AboutView.contribute.ways.${way.key}.text`) }}</div>
                        </div>
                    </div>
                </div>
                <p class="about__text">{{ t('AboutView.contribute.howto') }}</p>
                <!-- Symbol im Inhalt statt prepend-icon: die globale Regel für schmale Bildschirme
                     würde sonst den Text ausblenden, und ein Globus allein sagt nicht „Website“ -->
                <div class="about__links">
                    <v-btn :href="about_data.links.github" target="_blank" rel="noopener" variant="tonal" color="primary">
                        <v-icon start>mdi-github</v-icon>
                        {{ t('AboutView.contribute.github') }}
                    </v-btn>
                    <v-btn :href="about_data.links.website" target="_blank" rel="noopener" variant="tonal">
                        <v-icon start>mdi-web</v-icon>
                        {{ t('AboutView.contribute.website') }}
                    </v-btn>
                    <v-btn :href="about_data.links.demo" target="_blank" rel="noopener" variant="tonal">
                        <v-icon start>mdi-play-circle-outline</v-icon>
                        {{ t('AboutView.contribute.demo') }}
                    </v-btn>
                    <v-btn v-if="logged_in" :to="{ name: 'docs' }" variant="tonal">
                        <v-icon start>mdi-book-open-variant</v-icon>
                        {{ t('AboutView.contribute.docs') }}
                    </v-btn>
                </div>
                <v-alert variant="tonal" color="primary" icon="mdi-heart-outline" density="compact" class="about__closing">
                    {{ t('AboutView.contribute.closing') }}
                </v-alert>
            </section-card>
        </div>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store'
import { AuthStatus } from '@/core/constants/auth.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import about_data from './about.data.json'

const { t } = useI18n()
const oserp = oserpStore()

const logged_in = ref(oserp.session.user !== '' && oserp.session.client !== '')

onMounted(async () => {
    if (!logged_in.value) {
        logged_in.value = (await oserp.isAuthenticated()) === AuthStatus.AUTHENTICATED
    }
})

// Dankesabschnitte, die an eine Erweiterung gebunden sind, nur zeigen, wenn sie aktiv ist
const thanks = computed(() => about_data.thanks.filter(block => !block.extension || oserp.isExtensionEnabled(block.extension)))

/** Linktext aus der Adresse: https://www.beispiel.de → beispiel.de */
function linkLabel(url) {
    return new URL(url).host.replace(/^www\./, '')
}
</script>

<style scoped>
.about {
    max-width: var(--oserp-content-max);
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap-lg);
    padding-bottom: var(--oserp-gap-xl);
}

.about__text {
    margin: 0 0 var(--oserp-gap) 0;
    line-height: 1.7;
}

.about__text a {
    color: rgb(var(--v-theme-primary));
}

.about__text:last-child {
    margin-bottom: 0;
}

.about__muted {
    color: var(--oserp-text-muted);
    line-height: 1.6;
}

.about__values {
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap);
    margin-bottom: var(--oserp-gap);
}

.about__value {
    display: flex;
    align-items: flex-start;
    gap: var(--oserp-gap);
}

.about__value-icon {
    margin-top: 2px;
}

.about__value-title {
    font-weight: var(--oserp-section-title-weight);
}

.about__people,
.about__links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    margin-bottom: var(--oserp-gap);
}

.about__closing {
    margin-top: var(--oserp-gap-sm);
}
</style>
