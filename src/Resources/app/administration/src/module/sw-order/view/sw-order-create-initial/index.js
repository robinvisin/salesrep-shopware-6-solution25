import { Abandoned, ensureAbandonedStore } from '../../../../state/sales-agent-abandoned.state';

const { Component, State } = Shopware;

const resetSwOrderStateHard = async () => {
    try {
        await State.dispatch('swOrder/resetState');
        return;
    } catch (e) {}

    try { State.commit('swOrder/setCustomer', null); } catch {}
    try { State.commit('swOrder/setCart', null); } catch {}
    try { State.commit('swOrder/setCartLineItems', []); } catch {}
    try { State.commit('swOrder/setContextToken', null); } catch {}
    try { State.commit('swOrder/setSalesChannelId', null); } catch {}
};

Component.override('sw-order-create-initial', {
    inject: ['repositoryFactory'],

    created() {
        ensureAbandonedStore();
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            const customerId = this.$route?.query?.customerId;

            if (!customerId) {
                return;
            }

            const existing = String(State.get('swOrder')?.customer?.id || '');
            if (existing && existing !== String(customerId)) {
                await resetSwOrderStateHard();
            }

            const customerRepository = this.repositoryFactory.create('customer');

            try {
                const customer = await customerRepository.get(customerId, Shopware.Context.api);

                if (customer) {
                    State.commit('swOrder/setCustomer', customer);
                } else {
                    console.warn('[Order Create Initial] Customer not found.');
                }
            } catch (e) {
                console.error('[Order Create Initial] Failed to load customer:', e);
            }
        },

        async __salesAgentCleanupOnCancel() {
            try {
                Abandoned.enableClear();
                Abandoned.clear();
            } catch (e) {}

            await resetSwOrderStateHard();
        },

        async onCancel() {
            await this.__salesAgentCleanupOnCancel();
            return this.$super('onCancel');
        },

        async onClose() {
            await this.__salesAgentCleanupOnCancel();
            return this.$super('onClose');
        },
    },

    async beforeRouteLeave(to, from, next) {
        const goingToOrderCreate = String(to?.name || '').startsWith('sw.order.create');

        if (!goingToOrderCreate) {
            await this.__salesAgentCleanupOnCancel();
        }

        next();
    },
});
