// src/core/composables/navigation.cards.js
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'

/**
 * Composable für die Navigation-Menü-Struktur
 * Generiert Menüs basierend auf Features und Berechtigungen
 */
export function useNavigationCards() {
    const oserp = oserpStore()
    const { t } = useI18n()

    const cards = computed(() => {
        const result = []

        // Feature-basiertes Menü: lxcars
        if (oserp.isLxCars()) {
            const lxcarsItems = [
                { title: t('CarView.orderSearch'), to: { name: 'order-search' } },
                { title: t('CarView.newCarFromScan'), to: { name: 'car-new-from-scan' } },
                '-',
                { title: t('CarView.newCar'), to: { name: 'fahrzeug-neu' } },
                { title: t('CarView.manageCars'), to: { name: 'car-list' } }
            ]
            const mechMode = oserp.getClientDefaultValue('lxcars_mechanic_mode', '0')
            if (mechMode === '1' || mechMode === 'true' || mechMode === true) {
                lxcarsItems.push('-')
                lxcarsItems.push({ title: t('MechanicView.title'), to: { name: 'mechanic' } })
            }
            result.push({ title: t('CarView.title'), icon: 'mdi-car', items: lxcarsItems })
        }

        // Stammdaten-Menü
        result.push(
            {
                title: t('MasterDataMenu.title'),
                icon: 'mdi-database',
                items: [
                    { title: t('MasterDataMenu.editCustomer'), to: { name: 'current-customer-edit' } },
                    { title: t('MasterDataMenu.newCustomer'), to: { name: 'customer-new' } },
                    { title: t('MasterDataMenu.manageCustomers'), to: { name: 'customer-vendor' } },
                    { title: t('MasterDataMenu.search'), to: { name: 'search' } },
                    { title: t('MasterDataMenu.manageArticles'), to: { name: 'article-list' } },
                    '-',
                    { title: t('MasterDataMenu.newVendor'), to: { name: 'vendor-new' } },
                    '-',
                    { title: t('FollowUpView.title'), to: { name: 'follow-up' } },
                    { title: t('CalendarView.title'), to: { name: 'calendar' } }
                ]
            }
        )

        // Kontakt-Menü
        const contactItems = [
            // Mitarbeiter-Chat: keine eigene Seite, sondern das Chatfenster rechts
            { title: t('Chat.title'), action: 'chat' },
            '-',
            { title: t('ContactMenu.callHistory'), to: { name: 'call-history' } },
            '-',
            { title: t('ContactMenu.emails'), to: { name: 'emails' } },
            { title: t('ContactMenu.whatsapp'), to: { name: 'whatsapp' } },
            '-',
            { title: t('Tafel.title'), to: { name: 'tafel' } }
        ]
        if (oserp.isLxCars()) {
            contactItems.push('-')
            contactItems.push({ title: t('CarView.huSerienbrief'), to: { name: 'hu-serienbrief' } })
        }
        result.push(
            {
                title: t('ContactMenu.title'),
                icon: 'mdi-message-text',
                items: contactItems
            }
        )

        // Verkauf-Menü
        result.push(
            {
                title: t('SalesMenu.title'),
                icon: 'mdi-cash-register',
                items: [
                    { title: t('SalesMenu.newQuotation'), to: { name: 'quotation-new' } },
                    '-',
                    { title: t('SalesMenu.newOrder'), to: { name: 'order-new' } },
                    '-',
                    { title: t('SalesMenu.newInvoice'), to: { name: 'invoice-new' } },
                    '-',
                    // Beleglisten
                    { title: t('SalesMenu.manageQuotations'), to: { name: 'quotation-list' } },
                    { title: t('SalesMenu.manageOrders'), to: { name: 'order-list' } },
                    { title: t('SalesMenu.manageInvoices'), to: { name: 'invoice-list' } },
                    { title: t('SalesMenu.manageDeliveryOrders'), to: { name: 'delivery-order-list' } },
                    { title: t('SalesMenu.manageCreditNotes'), to: { name: 'credit-note-list' } }
                ]
            }
        )

        // Lager-Menü
        result.push(
            {
                title: t('WarehouseView.title'),
                icon: 'mdi-warehouse',
                items: [
                    { title: t('WarehouseView.tabs.stock'), to: { name: 'warehouse' } },
                    '-',
                    { title: t('WarehouseView.scanner.title'), to: { name: 'warehouse-scanner' } }
                ]
            }
        )

        // Buchhaltung-Menü (ehemals Banking)
        result.push(
            {
                title: t('AccountingView.menu.title'),
                icon: 'mdi-calculator-variant',
                items: [
                    { title: t('AccountingView.menu.overview'), to: { name: 'accounting-overview' } },
                    '-',
                    { title: t('AccountingView.menu.invoiceUpload'), to: { name: 'accounting-invoice-upload' } },
                    { title: t('AccountingView.menu.bookings'), to: { name: 'accounting-bookings' } },
                    { title: t('AccountingView.menu.outgoingMatching'), to: { name: 'accounting-outgoing' } },
                    { title: t('AccountingView.menu.openItems'), to: { name: 'accounting-open-items' } },
                    '-',
                    { title: t('AccountingView.menu.vendors'), to: { name: 'accounting-vendors' } },
                    { title: t('AccountingView.menu.chartOfAccounts'), to: { name: 'accounting-chart-of-accounts' } },
                    '-',
                    { title: t('BankingView.menu.title'), to: { name: 'banking-overview' } },
                    { title: t('KasseView.title'), to: { name: 'kasse' } },
                    '-',
                    { title: t('AccountingView.menu.ustva'), to: { name: 'accounting-ustva' } },
                    { title: t('AccountingView.menu.datevExport'), to: { name: 'accounting-datev-export' } }
                ]
            }
        )

        // Wiki (direkter Link, kein Untermenü)
        result.push(
            {
                title: t('WikiMenu.title'),
                icon: 'mdi-book-open-variant',
                to: { name: 'wiki-list' }
            }
        )

        // Personal-Menü
        result.push(
            {
                title: t('HrMenu.title'),
                icon: 'mdi-account-group',
                items: [
                    { title: t('HrMenu.hub'), to: { name: 'hr' } },
                    '-',
                    { title: t('HrMenu.payroll'), to: { name: 'hr-payroll' } },
                    { title: t('HrMenu.vacation'), to: { name: 'hr-vacation' } }
                ]
            }
        )

        return result
    })

    return {
        cards
    }
}
