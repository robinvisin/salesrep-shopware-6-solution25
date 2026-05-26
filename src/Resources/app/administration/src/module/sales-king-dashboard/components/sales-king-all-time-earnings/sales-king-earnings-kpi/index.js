import template from './sales-king-earnings-kpi.html.twig';
const { Component } = Shopware;

Component.register('sales-king-earnings-kpi', {
    template,

    props: {
        value: { type: Number, required: true },
        sales: { type: Number, default: 0 },
        currency: { type: String, default: 'USD' },
        title: { type: String, default: 'Lifetime commission' },
        isLoading: { type: Boolean, default: false }
    },

    computed: {
        formattedCommission() {
            return this.fmtMoney(this.value);
        },
        formattedSales() {
            return this.fmtMoney(this.sales);
        }
    },

    methods: {
        fmtMoney(v) {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: this.currency
            }).format(Number(v || 0));
        }
    }
});
