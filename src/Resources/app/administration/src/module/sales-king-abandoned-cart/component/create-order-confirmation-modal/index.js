import template from './create-order-confirmation-modal.html.twig';

const { Component } = Shopware;

Component.register('create-order-confirmation-modal', {
    template,

    props: {
        visible: {
            type: Boolean,
            required: true
        }
    },

    methods: {
        close() {
            this.$emit('close');
        },
        confirm() {
            this.$emit('confirm');
        }
    }
});
