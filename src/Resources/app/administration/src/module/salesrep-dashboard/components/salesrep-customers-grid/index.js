import template from './salesrep-customers-grid.html.twig';
import '../salesrep-pagination'

const {Component} = Shopware;

Component.register('salesrep-customers-grid', {
    template,

    props: {
        customers: {
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
        loading: {
            type: Boolean,
            default: false
        },
        page: {
            type: Number,
            default: 10
        },
        limit: {
            type: Number,
            default: 3
        },
    },
    methods: {
        goToPage(newPage) {
            if (newPage < 1 || newPage > this.totalPages || newPage === this.page) return;
            this.$emit('pageChange', {
                page: newPage,
                limit: this.limit,
            });
        },
    }
});
