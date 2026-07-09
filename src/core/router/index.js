// src/router/index.js
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
import i18n from '@/i18n';

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

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        {
            path: '/setup',
            name: 'setup',
            component: SetupView,
            meta: { requiresSetup: true }
        },
        {
            path: i18n.global.t('routes.systemUpdate'),
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
            path: i18n.global.t('routes.customer'),
            name: 'customer-vendor',
            component: StartupView,
            props: { crmView: true },
        },
        {
            path: i18n.global.t('routes.customer') + '/:id(\\d+)',
            name: 'change-customer',
            component: StartupView,
            props: route => ({
                crmView: true,
                id: Number(route.params.id), // optional cast auf Number
            }),
        },
        {
            path: i18n.global.t('routes.mainmenu'),
            name: 'menu',
            component: StartupView,
            props: { crmView: false },
        },
        {
            path: '/login',
            name: 'login',
            component: LoginView,
        },
        {
            path: i18n.global.t('routes.currentCustomerEdit'),
            name: 'current-customer-edit',
            component: CurrentCVeditView,
        },
        {
            path: i18n.global.t('routes.editCustomer') + '/:id(\\d+)',
            name: 'customer-edit',
            component: CustomerEditView,
            props: true,
        },
        {
            path: i18n.global.t('routes.search'),
            name: 'search',
            component: CustomerVendorSearchView,
        },
        {
            path: i18n.global.t('routes.clientConfig'),
            name: 'client-defaults',
            component: ClientDefaultsView,
        },
        {
            path: i18n.global.t('CarView.routes.newCar'),
            name: 'fahrzeug-neu',
            component: CarEditView,
        },
        {
            path: i18n.global.t('CarView.routes.manageCars') + '/:id(\\d+)',
            name: 'car',
            component: CarEditView,
            props: true,
        },
        {
            path: i18n.global.t('routes.developerTools'),
            name: 'developer-tools',
            component: DeveloperToolsView,
        },
        {
            path: i18n.global.t('routes.followUp'),
            name: 'follow-up',
            component: FollowUpView,
        },
        {
            path: i18n.global.t('routes.callHistory'),
            name: 'call-history',
            component: CallHistoryView,
        },
        {
            path: i18n.global.t('routes.calendar'),
            name: 'calendar',
            component: CalendarView,
        },
        {
            path: i18n.global.t('routes.wallDisplay'),
            name: 'wall-display',
            component: WallDisplayView,
        },
        {
            path: i18n.global.t('routes.anschlagtafel'),
            alias: '/tafel',
            name: 'anschlagtafel',
            component: AnschlagtafelView,
        },
        {
            path: '/mechaniker',
            name: 'mechanic',
            component: MechanicView,
        },
        {
            path: '/mechaniker/auftrag/:id(\\d+)',
            name: 'mechanic-order',
            component: MechanicOrderView,
            props: true,
        },
        {
            path: '/mechaniker/fahrzeug/:id(\\d+)',
            name: 'mechanic-car',
            component: CarEditView,
            props: route => ({ id: route.params.id, readonly: true }),
        },
        {
            path: '/emails',
            name: 'emails',
            component: EmailView,
        },
        {
            path: '/whatsapp',
            name: 'whatsapp',
            component: WhatsAppView,
        },
        {
            // Rechnungen
            path: i18n.global.t('routes.manageInvoices') + '/:id(\\d+)',
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
            path: i18n.global.t('routes.manageOrders') + '/:id(\\d+)',
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
            path: i18n.global.t('routes.manageQuotations') + '/:id(\\d+)',
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
            path: i18n.global.t('routes.manageCreditNotes') + '/:id(\\d+)',
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
            path: i18n.global.t('routes.manageDeliveryOrders') + '/:id(\\d+)',
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
            path: i18n.global.t('routes.newCustomer'),
            name: 'customer-new',
            component: CustomerEditView,
            props: () => ({ src: 'C' }),
        },
        {
            path: i18n.global.t('routes.newVendor'),
            name: 'vendor-new',
            component: CustomerEditView,
            props: () => ({ src: 'V' }),
        },
        {
            path: i18n.global.t('routes.manageVendors') + '/:id(\\d+)',
            name: 'change-vendor',
            component: StartupView,
            props: route => ({
                crmView: true,
                id: Number(route.params.id),
                src: 'V',
            }),
        },
        {
            path: i18n.global.t('routes.editVendor') + '/:id(\\d+)',
            name: 'vendor-edit',
            component: CustomerEditView,
            props: route => ({ id: route.params.id, src: 'V' }),
        },
        {
            path: i18n.global.t('routes.manageArticles') + '/:id(\\d+)',
            name: 'article-edit',
            component: ArticleEditView,
            props: true,
        },
        // ── Platzhalter-Routen: Verkauf ──
        {
            path: i18n.global.t('routes.newQuotation'),
            name: 'quotation-new',
            component: FakturaView,
            meta: {
                permission: 'sales_quotation_edit',
                documentType: 'quotation'
            }
        },
        {
            path: i18n.global.t('routes.editQuotation') + '/:id(\\d+)',
            name: 'quotation-edit',
            component: NotFoundView,
            props: true,
        },
        {
            path: i18n.global.t('routes.newOrder'),
            name: 'order-new',
            component: FakturaView,
            meta: {
                permission: 'sales_order_edit',
                documentType: 'order'
            }
        },
        {
            path: i18n.global.t('routes.editOrder') + '/:id(\\d+)',
            name: 'order-edit',
            component: NotFoundView,
            props: true,
        },
        {
            // Gutschriften-Liste
            path: i18n.global.t('routes.manageCreditNotes'),
            name: 'credit-note-list',
            component: OrderSearchView,
            meta: {
                permission: 'invoice_edit',
                documentType: 'credit_note'
            }
        },
        {
            path: i18n.global.t('routes.orderSearch'),
            name: 'order-search',
            component: OrderSearchView,
            meta: {
                permission: 'sales_order_edit'
            }
        },
        {
            path: i18n.global.t('routes.huSerienbrief'),
            name: 'hu-serienbrief',
            component: HuSerienbriefView,
            meta: {
                permission: 'sales_order_edit'
            }
        },
        {
            path: i18n.global.t('routes.newInvoice'),
            name: 'invoice-new',
            component: FakturaView,
            meta: {
                permission: 'invoice_edit',
                documentType: 'invoice'
            }
        },
        {
            path: i18n.global.t('routes.newDeliveryOrder'),
            name: 'delivery-order-new',
            component: FakturaView,
            meta: {
                permission: 'sales_delivery_order_edit',
                documentType: 'delivery_order'
            }
        },
        {
            path: i18n.global.t('routes.viewInvoice') + '/:id(\\d+)',
            name: 'invoice-view',
            component: NotFoundView,
            props: true,
        },
        {
            path: i18n.global.t('routes.manageDeliveryOrders'),
            name: 'delivery-order-list',
            component: NotFoundView,
        },
        // ── Platzhalter-Routen: Kfz (lxcars) ──
        {
            path: i18n.global.t('CarView.routes.newCarFromScan'),
            name: 'car-new-from-scan',
            component: CarScanView,
        },
        {
            path: i18n.global.t('CarView.routes.carRegistration') + '/:id(\\d+)',
            name: 'car-registration',
            component: CarRegView,
            props: true,
        },
        {
            path: i18n.global.t('CarView.routes.manageCars'),
            name: 'car-list',
            component: NotFoundView,
        },
        {
            path: i18n.global.t('CarView.routes.orderSearch'),
            name: 'car-order-search',
            component: NotFoundView,
        },
        // ── Buchhaltung ──
        {
            path: i18n.global.t('AccountingView.routes.accountingOverview'),
            name: 'accounting-overview',
            component: AccountingOverviewView,
        },
        {
            path: i18n.global.t('AccountingView.routes.accountingBookings'),
            name: 'accounting-bookings',
            component: AccountingBookingsView,
        },
        {
            path: i18n.global.t('AccountingView.routes.accountingInvoiceUpload'),
            name: 'accounting-invoice-upload',
            component: AccountingInvoiceUploadView,
        },
        {
            path: i18n.global.t('AccountingView.routes.accountingVendors'),
            name: 'accounting-vendors',
            component: AccountingVendorsView,
        },
        {
            path: i18n.global.t('AccountingView.routes.accountingDatevExport'),
            name: 'accounting-datev-export',
            component: AccountingDatevExportView,
        },
        {
            path: i18n.global.t('AccountingView.routes.accountingOutgoing'),
            name: 'accounting-outgoing',
            component: AccountingOutgoingView,
        },
        {
            path: i18n.global.t('AccountingView.routes.accountingChartOfAccounts'),
            name: 'accounting-chart-of-accounts',
            component: AccountingChartOfAccountsView,
        },
        // ── Banking ── (alle Funktionen in einem Hub zusammengefasst)
        {
            path: i18n.global.t('BankingView.routes.bankingOverview'),
            name: 'banking-overview',
            component: BankingHubView,
        },
        {
            path: i18n.global.t('BankingView.routes.bankingTransfers'),
            name: 'banking-transfers',
            redirect: to => ({ name: 'banking-overview', query: { tab: 'transfers', ...to.query } }),
        },
        {
            path: i18n.global.t('BankingView.routes.bankingReconciliation'),
            name: 'banking-reconciliation',
            redirect: { name: 'banking-overview', query: { tab: 'reconciliation' } },
        },
        {
            path: i18n.global.t('KasseView.routes.kasse'),
            name: 'kasse',
            component: KasseView,
        },
        // ── Kamera / Videoüberwachung ──
        {
            path: i18n.global.t('routes.camera'),
            name: 'camera',
            component: CameraView,
        },
        // ── Wiki ──
        {
            path: i18n.global.t('routes.wiki'),
            name: 'wiki-list',
            component: WikiListView,
        },
        {
            path: i18n.global.t('routes.wikiNew'),
            name: 'wiki-new',
            component: WikiEditView,
        },
        {
            path: i18n.global.t('routes.wikiCategories'),
            name: 'wiki-categories',
            component: WikiCategoriesView,
        },
        {
            path: i18n.global.t('routes.wiki') + '/:id(\\d+)',
            name: 'wiki-read',
            component: WikiReadView,
            props: true,
        },
        {
            path: i18n.global.t('routes.wikiEdit') + '/:id(\\d+)',
            name: 'wiki-edit',
            component: WikiEditView,
            props: true,
        },
        // ── Benutzer ──
        {
            path: i18n.global.t('routes.userConfig'),
            name: 'user-config',
            component: UserConfigView,
        },
        {
            path: i18n.global.t('routes.lxcarsReports'),
            name: 'lxcars-reports',
            component: LxCarsReportsView,
        },
        // ── Dokumentation ──
        {
            path: '/docs/:slug?',
            name: 'docs',
            component: DocsView,
            props: true,
        },
        // ── Öffentliche Seiten ──
        {
            path: '/datenschutz',
            name: 'datenschutz',
            component: DatenschutzView,
            meta: { public: true },
        },
        {
            path: '/datenloeschung',
            name: 'datenloeschung',
            component: DatenloeschungView,
            meta: { public: true },
        },
        // ── HR-Modul ──
        {
            path: i18n.global.t('routes.hr'),
            name: 'hr',
            component: HrHubView,
        },
        {
            path: i18n.global.t('routes.hrPayroll'),
            name: 'hr-payroll',
            redirect: { name: 'hr', query: { tab: 'payroll' } },
        },
        {
            path: i18n.global.t('routes.hrVacation'),
            name: 'hr-vacation',
            redirect: { name: 'hr', query: { tab: 'vacation' } },
        },
        // ── Catch-All ──
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: NotFoundView,
        },
    ],
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

export default router