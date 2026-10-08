// src/features/shop/components/shopOrderStatus.js
//
// Bestell- und Lieferstatus der Shop-Bestellungen (dev/shop-bestellstatus.md):
// Werte wie die CHECK-Bedingungen in ar_status_shop, dazu die Farben der
// Chips. Genutzt von der Liste der Bestellungen und der Karte in der
// Rechnungsansicht (shop-order-status.chip.vue).

const shopOrderStatus = {
    order: {
        werte: ['open', 'processing', 'completed', 'cancelled'],
        farben: { processing: 'info', completed: 'success', cancelled: 'error' },
    },
    delivery: {
        werte: ['open', 'partially_shipped', 'shipped', 'partially_returned', 'returned', 'cancelled'],
        farben: {
            partially_shipped: 'info', shipped: 'success',
            partially_returned: 'warning', returned: 'warning', cancelled: 'error',
        },
    },
}

export default shopOrderStatus
