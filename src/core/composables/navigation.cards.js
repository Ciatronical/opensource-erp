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
                { title: t('CarView.orderSearch'), to: t('routes.orderSearch') },
                { title: t('CarView.newCarFromScan'), to: t('CarView.routes.newCarFromScan') },
                '-',
                { title: t('CarView.newCar'), to: t('CarView.routes.newCar') },
                { title: t('CarView.manageCars'), to: t('CarView.routes.manageCars') }
            ]
            const mechMode = oserp.getClientDefaultValue('lxcars_mechanic_mode', '0')
            if (mechMode === '1' || mechMode === 'true' || mechMode === true) {
                lxcarsItems.push('-')
                lxcarsItems.push({ title: t('MechanicView.title'), to: '/mechaniker' })
            }
            result.push({ title: t('CarView.title'), icon: 'mdi-car', items: lxcarsItems })
        }

        // Stammdaten-Menü
        result.push(
            {
                title: t('MasterDataMenu.title'),
                icon: 'mdi-database',
                items: [
                    { title: t('MasterDataMenu.editCustomer'), to: t('routes.editCustomer') },
                    { title: t('MasterDataMenu.newCustomer'), to: t('routes.newCustomer') },
                    { title: t('MasterDataMenu.manageCustomers'), to: t('routes.manageCustomers') },
                    { title: t('MasterDataMenu.search'), to: t('routes.search') },
                    '-',
                    { title: t('MasterDataMenu.newVendor'), to: t('routes.newVendor') },
                    '-',
                    { title: t('FollowUpView.title'), to: t('routes.followUp') },
                    { title: t('CalendarView.title'), to: t('routes.calendar') }
                ]
            }
        )

        // Kontakt-Menü
        const contactItems = [
            { title: t('ContactMenu.callHistory'), to: t('routes.callHistory') },
            '-',
            { title: t('ContactMenu.emails'), to: '/emails' },
            { title: t('ContactMenu.whatsapp'), to: '/whatsapp' }
        ]
        if (oserp.isLxCars()) {
            contactItems.push('-')
            contactItems.push({ title: t('CarView.huSerienbrief'), to: t('routes.huSerienbrief') })
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
                    { title: t('SalesMenu.newQuotation'), to: t('routes.newQuotation') },
                    '-',
                    { title: t('SalesMenu.newOrder'), to: t('routes.newOrder') },
                    '-',
                    { title: t('SalesMenu.newInvoice'), to: t('routes.newInvoice') },
                    '-',
                    { title: t('SalesMenu.manageCreditNotes'), to: t('routes.manageCreditNotes') }
                ]
            }
        )

        // Buchhaltung-Menü (ehemals Banking)
        result.push(
            {
                title: t('AccountingView.menu.title'),
                icon: 'mdi-calculator-variant',
                items: [
                    { title: t('AccountingView.menu.overview'), to: t('AccountingView.routes.accountingOverview') },
                    '-',
                    { title: t('AccountingView.menu.invoiceUpload'), to: t('AccountingView.routes.accountingInvoiceUpload') },
                    { title: t('AccountingView.menu.bookings'), to: t('AccountingView.routes.accountingBookings') },
                    { title: t('AccountingView.menu.outgoingMatching'), to: t('AccountingView.routes.accountingOutgoing') },
                    '-',
                    { title: t('AccountingView.menu.vendors'), to: t('AccountingView.routes.accountingVendors') },
                    { title: t('AccountingView.menu.chartOfAccounts'), to: t('AccountingView.routes.accountingChartOfAccounts') },
                    '-',
                    { title: t('BankingView.menu.title'), to: t('BankingView.routes.bankingOverview') },
                    { title: t('KasseView.title'), to: t('KasseView.routes.kasse') },
                    '-',
                    { title: t('AccountingView.menu.datevExport'), to: t('AccountingView.routes.accountingDatevExport') }
                ]
            }
        )

        // Wiki-Menü
        result.push(
            {
                title: t('WikiMenu.title'),
                icon: 'mdi-book-open-variant',
                items: [
                    { title: t('WikiMenu.newPage'), to: '/wiki/neu' },
                    { title: t('WikiMenu.allPages'), to: '/wiki' },
                    '-',
                    { title: t('WikiMenu.categories'), to: '/wiki/kategorien' }
                ]
            }
        )

        // Personal-Menü
        result.push(
            {
                title: t('HrMenu.title'),
                icon: 'mdi-account-group',
                items: [
                    { title: t('HrMenu.hub'), to: t('routes.hr') },
                    '-',
                    { title: t('HrMenu.payroll'), to: t('routes.hrPayroll') },
                    { title: t('HrMenu.vacation'), to: t('routes.hrVacation') }
                ]
            }
        )

        return result
    })

    return {
        cards
    }
}
