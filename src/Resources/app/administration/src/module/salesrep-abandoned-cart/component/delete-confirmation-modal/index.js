import template from './delete-confirmation-modal.html.twig';

const { Component } = Shopware;

Component.register('delete-confirmation-modal', {
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
