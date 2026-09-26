import { Abandoned, ensureAbandonedStore } from '../../../../state/sales-agent-abandoned.state';

const { Component, Store } = Shopware;

const resetSwOrderStateHard = async () => {
    try {
        // 6.7: swOrder is a Pinia store. resetState, setContextToken and setSalesChannelId
        // are all gone from it; $reset() restores the whole store to its initial state, which
        // is what the field-by-field fallback below was emulating.
        Store.get('swOrder').$reset();
        return;
    } catch (e) {
        Shopware.Utils.debug.warn('sales-agent', 'could not reset swOrder state', e);
    }
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

            const existing = String(Store.get('swOrder')?.customer?.id || '');
            if (existing && existing !== String(customerId)) {
                await resetSwOrderStateHard();
            }

            const customerRepository = this.repositoryFactory.create('customer');

            try {
                const customer = await customerRepository.get(customerId, Shopware.Context.api);

                if (customer) {
                    Store.get('swOrder').setCustomer(customer);
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
