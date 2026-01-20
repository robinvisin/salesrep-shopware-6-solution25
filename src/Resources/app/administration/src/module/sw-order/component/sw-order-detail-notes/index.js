import template from './sw-order-detail-notes.html.twig';

const { Component } = Shopware;

Component.register('sw-order-detail-notes', {
    template,

    props: {
        orderId: {
            type: String,
            required: true
        }
    },

    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        }
    },

    created() {
        this.loadOrder();
    },

    methods: {
        async loadOrder() {
            this.order = await this.orderRepository.get(this.orderId, Shopware.Context.api);
        }
    }
});
