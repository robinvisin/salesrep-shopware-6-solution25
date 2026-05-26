import template from './line-items-modal.html.twig';
import FormatUtilsMixin from '../../mixin/format-utils.mixin'
const { Component, Mixin } = Shopware;

Component.register('line-items-modal', {
    template,
    mixins: [Mixin.getByName("notification"), FormatUtilsMixin],
    props: {
        visible: {
            type: Boolean,
            required: true
        },
        items: {
            type: Array,
            required: true
        }
    },

    methods: {
        close() {
            this.$emit('close');
        },
    },

    data() {
        return {
            columns: [
                { property: "label", label: this.$tc("abandoned-cart-admin.lineItemColumnProduct"), sortable: false },
                { property: "quantity", label: this.$tc("abandoned-cart-admin.lineItemColumnQuantity"), sortable: false },
                { property: "unitPrice", label: this.$tc("abandoned-cart-admin.lineItemColumnUnitPrice"), sortable: false },
            ]
        };
    }
});
