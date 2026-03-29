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
            result.push({ title: t('CarView.title'), items: lxcarsItems })
        }

        // Stammdaten-Menü
        result.push(
            {
                title: t('MasterDataMenu.title'),
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
                items: contactItems
            }
        )

        // Verkauf-Menü
        result.push(
            {
                title: t('SalesMenu.title'),
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

        // Wiki-Menü
        result.push(
            {
                title: t('WikiMenu.title'),
                items: [
                    { title: t('WikiMenu.newPage'), to: '/wiki/neu' },
                    { title: t('WikiMenu.allPages'), to: '/wiki' },
                    '-',
                    { title: t('WikiMenu.categories'), to: '/wiki/kategorien' }
                ]
            }
        )

        return result
    })

    return {
        cards
    }
}
