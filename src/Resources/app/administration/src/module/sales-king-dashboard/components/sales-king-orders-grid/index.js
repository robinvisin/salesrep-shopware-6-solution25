import template from './sales-king-orders-grid.html.twig';
import formattingMixin from '../../../../mixin/formatting.mixin';
import '../sales-king-pagination'
import './sales-king-orders-grid.scss'
const {Component} = Shopware;

Component.register('sales-king-orders-grid', {
    template,
    mixins: [
        formattingMixin,
        Shopware.Mixin.getByName('notification')
    ],

    props: {
        orders: {
            type: Array,
            required: true
        },
        columns: {
            type: Array,
            required: true
        },
        count: {
            type: Number,
            default: 0
        },
        page: {
            type: Number,
            default: 10
        },
        limit: {
            type: Number,
            default: 3
        },
        loading: {
            type: Boolean,
            default: false
        }
    },

    data() {
        return {
            selectedOrder: null,
        };
    },

    methods: {
        goToPage(newPage) {
            if (newPage < 1 || newPage > this.totalPages || newPage === this.page) return;
            this.$emit('pageChange', {
                page: newPage,
                limit: this.limit,
            });
        },

        openPricingModal(order) {
            const lineItem = order.lineItems?.[0];
            this.selectedOrder = {
                ...order,
                productName: lineItem?.label || 'Unnamed Item',
                price: lineItem?.price?.unitPrice ?? order.amountTotal ?? 0,
                discount: 0
            };
        },

        handleSavePricing(updatedOrder) {
            this.createNotificationSuccess({
                title: 'Pricing Saved (Stub)',
                message: 'Pricing updated successfully (pending backend).'
            });
            this.selectedOrder = null;
        }
    }

});