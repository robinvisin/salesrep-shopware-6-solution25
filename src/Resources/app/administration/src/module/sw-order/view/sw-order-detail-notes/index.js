import template from './sw-order-detail-notes.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-order-detail-notes', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        orderId: {
            type: String,
            required: true
        }
    },

    data() {
        return {
            order: null,
            note: '',
            doNotShipUntil: null,
            isLoading: false,
            isSaving: false,
        };
    },

    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        today() {
            return new Date().toISOString().slice(0, 10);
        },
    },

    watch: {
        doNotShipUntil(newVal) {
            if (!newVal) return;

            const picked = this.toDateString(newVal);
            const today = this.today;

            if (picked < today) {
                this.$nextTick(() => {
                    this.doNotShipUntil = today;
                });
            }
        }
    },

    async created() {
        this.loadOrder();
    },

    methods: {
        toDateString(val) {
            if (!val) return null;

            if (val instanceof Date) return val.toISOString().slice(0, 10);

            if (typeof val === 'string') return val.slice(0, 10);

            try {
                return new Date(val).toISOString().slice(0, 10);
            } catch {
                return null;
            }
        },

        async loadOrder() {
            this.isLoading = true;

            try {
                const order = await this.orderRepository.get(this.orderId, Shopware.Context.api);
                this.order = order;

                const cf = order.customFields || {};

                this.note = cf.infoplus_salesAgentOrderNotes || '';

                const savedDate = cf.infoplus_salesAgentDoNotShipUntil || null;

                if (savedDate) {
                    const d = this.toDateString(savedDate);
                    this.doNotShipUntil = d < this.today ? this.today : d;
                } else {
                    this.doNotShipUntil = null;
                }
            } catch (e) {
                this.createNotificationError({
                    title: 'Order',
                    message: 'Failed to load sales agent notes.'
                });
            } finally {
                this.isLoading = false;
            }
        },

        async saveNotes() {
            if (!this.order) return;

            this.isSaving = true;

            try {
                const order = await this.orderRepository.get(this.orderId, Shopware.Context.api);
                order.customFields = order.customFields || {};

                let shipDate = this.doNotShipUntil
                    ? this.toDateString(this.doNotShipUntil)
                    : null;

                if (shipDate && shipDate < this.today) {
                    shipDate = this.today;
                }

                order.customFields.infoplus_salesAgentOrderNotes = this.note;
                order.customFields.infoplus_salesAgentDoNotShipUntil = shipDate || null;

                if (order.customFields.sales_agent) {
                    delete order.customFields.sales_agent;
                }

                await this.orderRepository.save(order, Shopware.Context.api);

                this.createNotificationSuccess({
                    title: 'Sales agent',
                    message: 'Notes saved on order.'
                });

            } catch (e) {
                this.createNotificationError({
                    title: 'Sales agent',
                    message: 'Failed to save notes on order.'
                });

            } finally {
                this.isSaving = false;
            }
        },
    }
});
