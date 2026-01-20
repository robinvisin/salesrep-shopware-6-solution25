import template from './sw-order-detail-split-commission.html.twig';

const { Component } = Shopware;

Component.register('sw-order-detail-split-commission', {
    template,

    computed: {
        order() {
            return this.$parent?.order || null;
        }
    },

});


