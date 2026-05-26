import template from './sw-order-create-notes.html.twig';

const { Component, Mixin, State } = Shopware;

Component.register('sw-order-create-notes', {
    template,
    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],

    props: {
        orderId: {
            type: String,
            required: false,
            default: null
        }
    },

    data() {
        return {
            order: null,
            note: '',
            doNotShipUntil: null,
            isLoading: false,
        };
    },

    computed: {
        isCreatePage() {
            return !this.orderId;
        },

        cart() {
            return State.get('swOrder')?.cart || null;
        },

        customer() {
            return State.get('swOrder')?.customer || null;
        },

        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        today() {
            const d = new Date();
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 10);
        },

        datePickerConfig() {
            return {
                allowInput: false,
                minDate: this.today,
                dateFormat: 'Y-m-d',
            };
        }
    },

    async created() {
        if (this.isCreatePage) {
            this.loadFromCart();
        } else {
            await this.loadOrder();
        }
    },

    watch: {
        note(newVal) {
            if (this.isCreatePage) {
                const payload = this.buildPayload({
                    infoplus_salesAgentOrderNotes: newVal,
                    infoplus_salesAgentDoNotShipUntil: this.doNotShipUntil
                });
                this.writeToCart(payload);
                this.pushToBackend(payload);
            }
        },

        doNotShipUntil(newVal) {
            const picked = this.toDateString(newVal);

            if (picked && picked < this.today) {
                this.doNotShipUntil = this.today;
            }

            if (this.isCreatePage) {
                const payload = this.buildPayload({
                    infoplus_salesAgentOrderNotes: this.note,
                    infoplus_salesAgentDoNotShipUntil: this.doNotShipUntil
                });
                this.writeToCart(payload);
                this.pushToBackend(payload);
            }
        }
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

        buildPayload(raw) {
            let date = raw.infoplus_salesAgentDoNotShipUntil
                ? this.toDateString(raw.infoplus_salesAgentDoNotShipUntil)
                : null;

            if (date && date < this.today) {
                date = this.today;
            }

            return {
                infoplus_salesAgentOrderNotes: raw.infoplus_salesAgentOrderNotes || '',
                infoplus_salesAgentDoNotShipUntil: date
            };
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
            } finally {
                this.isLoading = false;
            }
        },

        loadFromCart() {
            const cart = this.cart;

            if (!cart) {
                this.note = '';
                this.doNotShipUntil = null;
                return;
            }

            const cf = cart.customFields || {};

            this.note = cf.infoplus_salesAgentOrderNotes || '';

            const savedDate = cf.infoplus_salesAgentDoNotShipUntil || null;

            if (savedDate) {
                const d = this.toDateString(savedDate);
                this.doNotShipUntil = d < this.today ? this.today : d;
            } else {
                this.doNotShipUntil = null;
            }
        },

        writeToCart(value) {
            const currentCart = this.cart;
            if (!currentCart) return;

            const cart = { ...currentCart };
            cart.customFields = cart.customFields || {};

            cart.customFields.infoplus_salesAgentOrderNotes = value.infoplus_salesAgentOrderNotes;
            cart.customFields.infoplus_salesAgentDoNotShipUntil = value.infoplus_salesAgentDoNotShipUntil;

            if (cart.customFields.sales_agent) {
                delete cart.customFields.sales_agent;
            }

            State.commit('swOrder/setCart', cart);
        },

        async pushToBackend(payload) {
            const salesChannelId =
                this.customer?.salesChannelId ||
                this.salesChannelContext?.salesChannelId ||
                '';

            const contextToken =
                this.cart?.token ||
                this.salesChannelContext?.token ||
                '';

            if (!salesChannelId || !contextToken) return;

            const cartStoreService = Shopware.Service('cartStoreService');
            const headers = {
                ...cartStoreService.getBasicHeaders(),
                'sw-context-token': contextToken
            };

            const url = `_proxy/store-api/${salesChannelId}/sales-agent/cart/note`;

            await cartStoreService.httpClient.post(
                url,
                payload,
                { headers }
            );
        },

        async saveNoteDetail() {
            if (this.isCreatePage) return;

            this.isLoading = true;

            try {
                const order = await this.orderRepository.get(this.orderId, Shopware.Context.api);
                order.customFields = order.customFields || {};

                const payload = this.buildPayload({
                    infoplus_salesAgentOrderNotes: this.note,
                    infoplus_salesAgentDoNotShipUntil: this.doNotShipUntil
                });

                order.customFields.infoplus_salesAgentOrderNotes = payload.infoplus_salesAgentOrderNotes;
                order.customFields.infoplus_salesAgentDoNotShipUntil = payload.infoplus_salesAgentDoNotShipUntil;

                if (order.customFields.sales_agent) {
                    delete order.customFields.sales_agent;
                }

                await this.orderRepository.save(order, Shopware.Context.api);

                this.createNotificationSuccess({
                    title: this.$tc('sw-order.detail.titleSaveSuccess'),
                    message: this.$tc('Notes saved on order.')
                });
            } catch (e) {
                this.createNotificationError({
                    title: this.$tc('sw-order.detail.titleSaveError'),
                    message: this.$tc('Failed to save notes on order.')
                });
            } finally {
                this.isLoading = false;
            }
        },

        saveNote() {
            if (this.isCreatePage) {
                this.createNotificationSuccess({
                    title: 'Sales agent',
                    message: 'Note updated in cart.'
                });
            } else {
                this.saveNoteDetail();
            }
        }
    }
});
