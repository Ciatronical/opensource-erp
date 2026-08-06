// src/router/index.js
import { watch } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'

// Statisch: Nur Login, Setup, Startup, NotFound (werden sofort gebraucht)
import LoginView from '@/core/views/login/login.view.vue'
import SetupView from '@/core/views/setup/setup.view.vue'
import StartupView from '@/core/views/startup/startup.view.vue'
import NotFoundView from '@/core/views/notfound/notfound.view.vue'

// Lazy-Loaded: Alles andere wird erst bei Navigation geladen
const UpdateView = () => import('@/core/views/update/update.view.vue')
const DocsView = () => import('@/core/views/docs/docs.view.vue')
const CustomerEditView = () => import('@/core/views/customer-vendor/cv.edit.view.vue')
const CurrentCVeditView = () => import('@/core/views/customer-vendor/edit_current.view.vue')
const ClientDefaultsView = () => import('@/core/views/config/client-defaults.view.vue')
const CustomerVendorSearchView = () => import('@/core/views/search/search.view.vue')
const DeveloperToolsView = () => import('@/core/views/developer-tools/developer-tools.view.vue')
const FakturaView = () => import('@/core/views/faktura/faktura.view.vue')
const FollowUpView = () => import('@/core/views/follow-up/follow-up.view.vue')
const CallHistoryView = () => import('@/core/views/call-history/call-history.view.vue')
const CalendarView = () => import('@/core/views/calendar/calendar.view.vue')
const TasksView = () => import('@/core/views/tasks/tasks.view.vue')
const EmailView = () => import('@/core/views/email/email.view.vue')
const WhatsAppView = () => import('@/core/views/whatsapp/whatsapp.view.vue')
const DatenschutzView = () => import('@/core/views/datenschutz/datenschutz.view.vue')
const DatenloeschungView = () => import('@/core/views/datenschutz/datenloeschung.view.vue')
const ArticleEditView = () => import('@/core/views/article/article.edit.view.vue')
const WikiListView = () => import('@/core/views/wiki/wiki.list.view.vue')
const WikiEditView = () => import('@/core/views/wiki/wiki.edit.view.vue')
const WikiReadView = () => import('@/core/views/wiki/wiki.read.view.vue')
const WikiCategoriesView = () => import('@/core/views/wiki/wiki.categories.view.vue')
const OrderSearchView = () => import('@/core/views/order-search/order-search.view.vue')
const UserConfigView = () => import('@/core/views/user-config/user-config.view.vue')
const WallDisplayView = () => import('@/core/views/wall-display/wall-display.view.vue')
const AnschlagtafelView = () => import('@/core/views/anschlagtafel/anschlagtafel.view.vue')
const TafelView = () => import('@/core/views/tafel/tafel.view.vue')
const CameraView = () => import('@/core/views/camera/camera.view.vue')

const HuSerienbriefView = () => {
    const oserp = oserpStore()
    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/hu-serienbrief/hu-serienbrief.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}

const MechanicView = () => {
    const oserp = oserpStore()
    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/mechanic/mechanic.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}

const MechanicOrderView = () => {
    const oserp = oserpStore()
    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/mechanic/mechanic-order.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}
import { oserpStore } from '@/core/stores/oserp.store.js'
import { AuthStatus } from '@/core/constants/auth.js';
import * as alerts from '@/core/utils/alerts.js';
import i18n, { getStoredLocale, storeLocale } from '@/i18n';

// ───────────────────────── Sprachabhängige URLs ─────────────────────────
//
// Die Routen-Tabelle wird für genau eine Sprache gebaut: deren Pfade sind die
// kanonischen URLs, die in der Adresszeile stehen. Die Pfade aller übrigen
// Sprachen hängen als `alias` an derselben Route. Dadurch bleibt jede URL in
// jeder Sprache gültig — Lesezeichen überleben den Sprachwechsel und ein Link,
// den ein Benutzer mit deutscher Oberfläche verschickt, funktioniert auch beim
// Empfänger mit englischer.
//
// Sprachen mit eigener URL-Schreibweise. Die übrigen Oberflächensprachen
// spiegeln derzeit die deutschen Pfade und werden im nächsten Schritt ergänzt.
export const ROUTE_LOCALES = ['de', 'en'];

/**
 * Ordnet einer Oberflächensprache die URL-Schreibweise zu
 *
 * Sprachen ohne eigene Pfad-Vokabeln spiegeln die deutschen Pfade — genau das
 * steht auch in ihren Locale-Dateien. Ohne diese Zuordnung bliebe beim Wechsel
 * von Englisch auf z. B. Polnisch die englische URL stehen.
 *
 * @param {string} locale - Oberflächensprache
 * @return {string} Sprache, aus der die Pfade gelesen werden
 */
function routeLocaleFor(locale) {
    return ROUTE_LOCALES.includes(locale) ? locale : 'de';
}

// Sprache, für die die aktuelle Routen-Tabelle gebaut ist
let routeLocale = routeLocaleFor(getStoredLocale());

/**
 * Liest einen Pfad-Baustein aus dem Sprachkatalog
 *
 * @param {string} key - i18n-Schlüssel, z. B. 'routes.customer'
 * @param {string} locale - Sprache, aus der gelesen wird
 * @return {string|null} Pfad oder null, wenn die Sprache den Schlüssel nicht kennt
 */
function pathFor(key, locale) {
    // te() statt t(): sonst liefert der Fallback den englischen Pfad und
    // erzeugt einen Alias, den es in dieser Sprache gar nicht gibt.
    if (!i18n.global.te(key, locale)) return null;
    const value = i18n.global.t(key, {}, { locale });
    return typeof value === 'string' && value.startsWith('/') ? value : null;
}

/**
 * Baut `path` (aktuelle Sprache) und `alias` (alle übrigen Sprachen)
 * für einen Routen-Schlüssel.
 *
 * @param {string} key - i18n-Schlüssel des Pfads
 * @param {string} [suffix] - Parameter-Anhang, z. B. '/:id(\\d+)'
 * @param {string[]} [extraAliases] - zusätzliche Altpfade, die gültig bleiben sollen
 * @return {Object} { path } bzw. { path, alias }
 */
function routePath(key, suffix = '', extraAliases = []) {
    const canonical = pathFor(key, routeLocale);
    if (!canonical) {
        throw new Error(`Routen-Schlüssel fehlt: ${key} (${routeLocale})`);
    }
    const path = canonical + suffix;
    const alias = [];
    for (const locale of ROUTE_LOCALES) {
        if (locale === routeLocale) continue;
        const other = pathFor(key, locale);
        if (other && other + suffix !== path) alias.push(other + suffix);
    }
    for (const extra of extraAliases) {
        if (extra !== path) alias.push(extra);
    }
    const unique = [...new Set(alias)];
    return unique.length ? { path, alias: unique } : { path };
}

const CarEditView = () => {
    const oserp = oserpStore()

    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/car/car.edit.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}

const CarScanView = () => {
    const oserp = oserpStore()

    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/car/car.scan.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}

const CarRegView = () => {
    const oserp = oserpStore()

    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/car/carreg.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}

const LxCarsReportsView = () => {
    const oserp = oserpStore()
    if (oserp.isLxCars()) {
        return import('@/features/lxcars/views/reports/reports.view.vue')
    } else {
        return import('@/core/views/notfound/notfound.view.vue')
    }
}

// Banking
const BankingHubView = () => import('@/features/banking/views/banking.hub.vue')
const KasseView = () => import('@/features/banking/views/banking.kasse.vue')

// HR-Modul
const HrHubView = () => import('@/core/views/hr/hr.hub.vue')

// Buchhaltung
const AccountingOverviewView = () => import('@/features/accounting/views/accounting.overview.vue')
const AccountingBookingsView = () => import('@/features/accounting/views/accounting.bookings.vue')
const AccountingInvoiceUploadView = () => import('@/features/accounting/views/accounting.invoice-upload.vue')
const AccountingVendorsView = () => import('@/features/accounting/views/accounting.vendors.vue')
const AccountingDatevExportView = () => import('@/features/accounting/views/accounting.datev-export.vue')
const AccountingOutgoingView = () => import('@/features/accounting/views/accounting.outgoing.vue')
const AccountingChartOfAccountsView = () => import('@/features/accounting/views/accounting.chart-of-accounts.vue')
const AccountingOpenItemsView = () => import('@/features/accounting/views/accounting.open-items.vue')

/**
 * Baut die vollständige Routen-Tabelle für die aktuell gesetzte `routeLocale`
 *
 * @return {Array} Routen-Definitionen für vue-router
 */
function buildRoutes() {
    return [
        {
            ...routePath('routes.setup'),
            name: 'setup',
            component: SetupView,
            meta: { requiresSetup: true }
        },
        {
            ...routePath('routes.systemUpdate'),
            name: 'system-update',
            component: UpdateView,
        },
        {
            path: '/',
            name: 'startup',
            component: StartupView,
            // crmView dynamisch aus dem Store, Redirect bei speziellen Startansichten
            beforeEnter: () => {
                const oserp = oserpStore()
                const startupView = oserp.getStartupViewConfig()
                if (startupView === 'wall-display') return { name: 'wall-display' }
                if (startupView === 'anschlagtafel') return { name: 'anschlagtafel' }
                if (startupView === 'mechanic') return { name: 'mechanic' }
                return true
            },
            props: () => {
                const oserp = oserpStore()
                const startupView = oserp.getStartupViewConfig()
                return {
                    crmView: startupView === 'customer-vendor'
                }
            },
        },
        {
            ...routePath('routes.customer'),
            name: 'customer-vendor',
            component: StartupView,
            props: { crmView: true },
        },
        {
            ...routePath('routes.customer', '/:id(\\d+)'),
            name: 'change-customer',
            component: StartupView,
            props: route => ({
                crmView: true,
                id: Number(route.params.id), // optional cast auf Number
            }),
        },
        {
            ...routePath('routes.mainmenu'),
            name: 'menu',
            component: StartupView,
            props: { crmView: false },
        },
        {
            ...routePath('routes.login'),
            name: 'login',
            component: LoginView,
        },
        {
            ...routePath('routes.currentCustomerEdit'),
            name: 'current-customer-edit',
            component: CurrentCVeditView,
        },
        {
            ...routePath('routes.editCustomer', '/:id(\\d+)'),
            name: 'customer-edit',
            component: CustomerEditView,
            props: true,
        },
        {
            ...routePath('routes.search'),
            name: 'search',
            component: CustomerVendorSearchView,
        },
        {
            ...routePath('routes.clientConfig'),
            name: 'client-defaults',
            component: ClientDefaultsView,
        },
        {
            // Alte URL (/system/mandantenkonfiguration) auf neue umleiten,
            // damit bestehende Lesezeichen weiter funktionieren.
            path: '/system/mandantenkonfiguration',
            redirect: to => ({ name: 'client-defaults', query: to.query }),
        },
        {
            ...routePath('CarView.routes.newCar'),
            name: 'fahrzeug-neu',
            component: CarEditView,
        },
        {
            ...routePath('CarView.routes.manageCars', '/:id(\\d+)'),
            name: 'car',
            component: CarEditView,
            props: true,
        },
        {
            ...routePath('routes.developerTools'),
            name: 'developer-tools',
            component: DeveloperToolsView,
        },
        {
            ...routePath('routes.followUp'),
            name: 'follow-up',
            component: FollowUpView,
        },
        {
            ...routePath('routes.callHistory'),
            name: 'call-history',
            component: CallHistoryView,
        },
        {
            ...routePath('routes.calendar'),
            name: 'calendar',
            component: CalendarView,
        },
        {
            ...routePath('routes.tasks'),
            name: 'tasks',
            component: TasksView,
        },
        {
            ...routePath('routes.wallDisplay'),
            name: 'wall-display',
            component: WallDisplayView,
        },
        {
            // '/tafel' war die urspruengliche URL und bleibt gueltig
            ...routePath('routes.anschlagtafel', '', ['/tafel']),
            name: 'anschlagtafel',
            component: AnschlagtafelView,
        },
        {
            // PC-Variante der Anschlagtafel: Eintraege hinzufuegen/loeschen
            ...routePath('routes.tafel'),
            name: 'tafel',
            component: TafelView,
        },
        {
            ...routePath('routes.mechanic'),
            name: 'mechanic',
            component: MechanicView,
        },
        {
            ...routePath('routes.mechanicOrder', '/:id(\\d+)'),
            name: 'mechanic-order',
            component: MechanicOrderView,
            props: true,
        },
        {
            ...routePath('routes.mechanicCar', '/:id(\\d+)'),
            name: 'mechanic-car',
            component: CarEditView,
            props: route => ({ id: route.params.id, readonly: true }),
        },
        {
            ...routePath('routes.emails'),
            name: 'emails',
            component: EmailView,
        },
        {
            ...routePath('routes.whatsapp'),
            name: 'whatsapp',
            component: WhatsAppView,
        },
        {
            // Rechnungen
            ...routePath('routes.manageInvoices', '/:id(\\d+)'),
            name: 'faktura-invoice-view',
            component: FakturaView,
            props: true,
            meta: {
                permission: 'invoice_edit',
                documentType: 'invoice'
            }
        },
        {
            // Aufträge
            ...routePath('routes.manageOrders', '/:id(\\d+)'),
            name: 'faktura-order-view',
            component: FakturaView,
            props: true,
            meta: {
                permission: 'sales_order_edit',
                documentType: 'order'
            }
        },
        {
            // Angebote
            ...routePath('routes.manageQuotations', '/:id(\\d+)'),
            name: 'faktura-quotation-view',
            component: FakturaView,
            props: true,
            meta: {
                permission: 'sales_quotation_edit',
                documentType: 'quotation'
            }
        },
        {
            // Gutschriften
            ...routePath('routes.manageCreditNotes', '/:id(\\d+)'),
            name: 'faktura-credit-note-view',
            component: FakturaView,
            props: true,
            meta: {
                permission: 'invoice_edit',
                documentType: 'credit_note'
            }
        },
        {
            // Lieferscheine
            ...routePath('routes.manageDeliveryOrders', '/:id(\\d+)'),
            name: 'faktura-delivery-order-view',
            component: FakturaView,
            props: true,
            meta: {
                permission: 'sales_delivery_order_edit',
                documentType: 'delivery_order'
            }
        },
        // ── Stammdaten: Kunden/Lieferanten ──
        {
            ...routePath('routes.newCustomer'),
            name: 'customer-new',
            component: CustomerEditView,
            props: () => ({ src: 'C' }),
        },
        {
            ...routePath('routes.newVendor'),
            name: 'vendor-new',
            component: CustomerEditView,
            props: () => ({ src: 'V' }),
        },
        {
            ...routePath('routes.manageVendors', '/:id(\\d+)'),
            name: 'change-vendor',
            component: StartupView,
            props: route => ({
                crmView: true,
                id: Number(route.params.id),
                src: 'V',
            }),
        },
        {
            ...routePath('routes.editVendor', '/:id(\\d+)'),
            name: 'vendor-edit',
            component: CustomerEditView,
            props: route => ({ id: route.params.id, src: 'V' }),
        },
        {
            ...routePath('routes.manageArticles', '/:id(\\d+)'),
            name: 'article-edit',
            component: ArticleEditView,
            props: true,
        },
        // ── Platzhalter-Routen: Verkauf ──
        {
            ...routePath('routes.newQuotation'),
            name: 'quotation-new',
            component: FakturaView,
            meta: {
                permission: 'sales_quotation_edit',
                documentType: 'quotation'
            }
        },
        {
            ...routePath('routes.editQuotation', '/:id(\\d+)'),
            name: 'quotation-edit',
            component: NotFoundView,
            props: true,
        },
        {
            ...routePath('routes.newOrder'),
            name: 'order-new',
            component: FakturaView,
            meta: {
                permission: 'sales_order_edit',
                documentType: 'order'
            }
        },
        {
            ...routePath('routes.editOrder', '/:id(\\d+)'),
            name: 'order-edit',
            component: NotFoundView,
            props: true,
        },
        {
            // Gutschriften-Liste
            ...routePath('routes.manageCreditNotes'),
            name: 'credit-note-list',
            component: OrderSearchView,
            meta: {
                permission: 'invoice_edit',
                documentType: 'credit_note'
            }
        },
        {
            ...routePath('routes.orderSearch'),
            name: 'order-search',
            component: OrderSearchView,
            meta: {
                permission: 'sales_order_edit'
            }
        },
        {
            ...routePath('routes.huSerienbrief'),
            name: 'hu-serienbrief',
            component: HuSerienbriefView,
            meta: {
                permission: 'sales_order_edit'
            }
        },
        {
            ...routePath('routes.newInvoice'),
            name: 'invoice-new',
            component: FakturaView,
            meta: {
                permission: 'invoice_edit',
                documentType: 'invoice'
            }
        },
        {
            ...routePath('routes.newDeliveryOrder'),
            name: 'delivery-order-new',
            component: FakturaView,
            meta: {
                permission: 'sales_delivery_order_edit',
                documentType: 'delivery_order'
            }
        },
        {
            // Altpfad /rechnung/anzeigen/:id — zeigte auf NotFound. Leitet jetzt
            // auf die echte Rechnungsansicht um, damit Lesezeichen und
            // Wiedervorlage-Verknuepfungen funktionieren.
            ...routePath('routes.viewInvoice', '/:id(\\d+)'),
            name: 'invoice-view',
            redirect: to => ({ name: 'faktura-invoice-view', params: { id: to.params.id }, query: to.query }),
        },
        {
            ...routePath('routes.manageDeliveryOrders'),
            name: 'delivery-order-list',
            component: NotFoundView,
        },
        // ── Platzhalter-Routen: Kfz (lxcars) ──
        {
            ...routePath('CarView.routes.newCarFromScan'),
            name: 'car-new-from-scan',
            component: CarScanView,
        },
        {
            ...routePath('CarView.routes.carRegistration', '/:id(\\d+)'),
            name: 'car-registration',
            component: CarRegView,
            props: true,
        },
        {
            ...routePath('CarView.routes.manageCars'),
            name: 'car-list',
            component: NotFoundView,
        },
        {
            ...routePath('CarView.routes.orderSearch'),
            name: 'car-order-search',
            component: NotFoundView,
        },
        // ── Buchhaltung ──
        {
            ...routePath('AccountingView.routes.accountingOverview'),
            name: 'accounting-overview',
            component: AccountingOverviewView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingBookings'),
            name: 'accounting-bookings',
            component: AccountingBookingsView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingInvoiceUpload'),
            name: 'accounting-invoice-upload',
            component: AccountingInvoiceUploadView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingVendors'),
            name: 'accounting-vendors',
            component: AccountingVendorsView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingDatevExport'),
            name: 'accounting-datev-export',
            component: AccountingDatevExportView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingOutgoing'),
            name: 'accounting-outgoing',
            component: AccountingOutgoingView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingChartOfAccounts'),
            name: 'accounting-chart-of-accounts',
            component: AccountingChartOfAccountsView,
            meta: { hideCustomerBar: true },
        },
        {
            ...routePath('AccountingView.routes.accountingOpenItems'),
            name: 'accounting-open-items',
            component: AccountingOpenItemsView,
            meta: { hideCustomerBar: true },
        },
        // ── Banking ── (alle Funktionen in einem Hub zusammengefasst)
        {
            ...routePath('BankingView.routes.bankingOverview'),
            name: 'banking-overview',
            component: BankingHubView,
        },
        {
            ...routePath('BankingView.routes.bankingTransfers'),
            name: 'banking-transfers',
            redirect: to => ({ name: 'banking-overview', query: { tab: 'transfers', ...to.query } }),
        },
        {
            ...routePath('BankingView.routes.bankingReconciliation'),
            name: 'banking-reconciliation',
            redirect: { name: 'banking-overview', query: { tab: 'reconciliation' } },
        },
        {
            ...routePath('KasseView.routes.kasse'),
            name: 'kasse',
            component: KasseView,
        },
        // ── Kamera / Videoüberwachung ──
        {
            ...routePath('routes.camera'),
            name: 'camera',
            component: CameraView,
        },
        // ── Wiki ──
        {
            ...routePath('routes.wiki'),
            name: 'wiki-list',
            component: WikiListView,
        },
        {
            ...routePath('routes.wikiNew'),
            name: 'wiki-new',
            component: WikiEditView,
        },
        {
            ...routePath('routes.wikiCategories'),
            name: 'wiki-categories',
            component: WikiCategoriesView,
        },
        {
            ...routePath('routes.wiki', '/:id(\\d+)'),
            name: 'wiki-read',
            component: WikiReadView,
            props: true,
        },
        {
            ...routePath('routes.wikiEdit', '/:id(\\d+)'),
            name: 'wiki-edit',
            component: WikiEditView,
            props: true,
        },
        // ── Benutzer ──
        {
            ...routePath('routes.userConfig'),
            name: 'user-config',
            component: UserConfigView,
        },
        {
            ...routePath('routes.lxcarsReports'),
            name: 'lxcars-reports',
            component: LxCarsReportsView,
        },
        // ── Dokumentation ──
        {
            ...routePath('routes.docs', '/:slug?'),
            name: 'docs',
            component: DocsView,
            props: true,
        },
        // ── Öffentliche Seiten ──
        {
            ...routePath('routes.privacy'),
            name: 'datenschutz',
            component: DatenschutzView,
            meta: { public: true },
        },
        {
            ...routePath('routes.dataDeletion'),
            name: 'datenloeschung',
            component: DatenloeschungView,
            meta: { public: true },
        },
        // ── HR-Modul ──
        {
            ...routePath('routes.hr'),
            name: 'hr',
            component: HrHubView,
        },
        {
            ...routePath('routes.hrPayroll'),
            name: 'hr-payroll',
            redirect: { name: 'hr', query: { tab: 'payroll' } },
        },
        {
            ...routePath('routes.hrVacation'),
            name: 'hr-vacation',
            redirect: { name: 'hr', query: { tab: 'vacation' } },
        },
        // ── Catch-All ──
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: NotFoundView,
        },
    ];
}

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: buildRoutes(),
})

// Navigations-Guards
router.beforeEach(async (to) => {
    // Öffentliche Seiten ohne Authentifizierung
    if (to.meta?.public) return true;

    const setupRouteName = 'setup';
    const loginRouteName = 'login';
    const startupRouteName = 'startup';

    // Normale Authentifizierungs-Guards
    const oserpData = oserpStore();
    const authStatus = await oserpData.isAuthenticated();

    // Status-basierte Routing-Entscheidungen
    switch (authStatus) {
        case AuthStatus.SETUP_REQUIRED:
            if (to.name !== setupRouteName) {
                return { name: setupRouteName };
            }
            return true;

        case AuthStatus.AUTHENTICATED:
            // Nicht zur Setup-, Update- oder Login-Seite wenn authentifiziert
            if (to.name === setupRouteName || to.name === loginRouteName) {
                return { name: startupRouteName };
            }

            // Faktura-View Berechtigungen prüfen
            if (to.meta && to.meta.permission) {
                console.info(`Überprüfe Berechtigung: ${to.meta.permission}`);
                try {
                    oserpData.permit(to.meta.permission);
                }
                catch (error) {
                    console.warn(`Zugriff verweigert:`, error.message);
                    alerts.error(i18n.global.t('FakturaView.alerts.warning_no_permission'))
                    return { name: startupRouteName };
                }
            }
            return true;

        case AuthStatus.NOT_AUTHENTICATED:
        default:
            // Zur Login-Seite wenn nicht authentifiziert
            if (to.name !== loginRouteName) {
                return { name: loginRouteName, query: { redirect: to.fullPath } };
            }
            return true;
    }
});

router.onError((error, to) => {
    // Dynamische Import-Fehler nach neuem Build: direkt zum Ziel hart navigieren.
    // NICHT window.location.reload() — das lädt die aktuelle (alte) Seite neu, da die
    // Navigation zum Ziel wegen des fehlgeschlagenen Imports nie übernommen wurde.
    // Stattdessen den Browser direkt auf die Zielroute schicken, damit der erste Klick
    // sofort die richtige Seite mit den frischen Chunks lädt.
    if (error.message && (
        error.message.includes('dynamically imported module') ||
        error.message.includes('Failed to fetch') ||
        error.message.includes('Loading chunk') ||
        error.message.includes('Loading CSS chunk')
    )) {
        console.warn('Neuer Build erkannt — Seite wird neu geladen...');
        window.location.assign(to?.fullPath || window.location.href);
        return;
    }
    console.error(`Ein Navigationsfehler ist aufgetreten (${error.code}): `, error.message);
});

/**
 * Schaltet die URL-Sprache um: Routen-Tabelle neu registrieren und die
 * aktuelle Adresse in der neuen Schreibweise ersetzen.
 *
 * Die Route-Namen bleiben dabei unverändert — deshalb behält die laufende
 * Ansicht ihren Zustand und alle Verlinkungen im Code funktionieren weiter.
 *
 * @param {string} locale - Zielsprache
 * @return {Promise<void>}
 */
export async function applyRouteLocale(locale) {
    const target = routeLocaleFor(locale);
    if (target === routeLocale) return;

    routeLocale = target;

    const current = router.currentRoute.value;

    // Namen vorher einsammeln: removeRoute() entfernt auch die Alias-Einträge,
    // sodass sich getRoutes() waehrend des Durchlaufs veraendern wuerde.
    const names = [...new Set(router.getRoutes().map(r => r.name).filter(Boolean))];
    for (const name of names) router.removeRoute(name);
    for (const definition of buildRoutes()) router.addRoute(definition);

    // Adresszeile auf die neue Schreibweise bringen. Ohne Namen (Direktaufruf
    // einer unbekannten URL) gibt es nichts umzuschreiben.
    if (current.name && current.name !== 'not-found') {
        await router.replace({
            name: current.name,
            params: current.params,
            query: current.query,
            hash: current.hash,
        });
    }
}

// Auf jeden Sprachwechsel reagieren — egal, wer ihn ausloest (Benutzer-
// einstellung, Session, Firmenwechsel).
watch(() => i18n.global.locale.value, (locale) => {
    storeLocale(locale);
    applyRouteLocale(locale);
});

export default router