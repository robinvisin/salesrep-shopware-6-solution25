import template from './sw-order-detail.html';
const { Criteria } = Shopware.Data;

Shopware.Component.override('sw-order-detail', {
    template,
    inject: ['repositoryFactory'],
    data() {
        return {
            createdBySalesAgentEmail: null,
        };
    },
    computed: {
        userRepository() {
            return this.repositoryFactory.create('user');
        },
        orderRepository() {
            return this.repositoryFactory.create('order');
        },
    },
    watch: {
        order: {
            immediate: true,
            deep: true,
            handler() {
                this.loadSalesAgentEmail();
            }
        },
    },
    methods: {
        async loadSalesAgentEmail() {
            const criteria = new Criteria();

            const firstOrderVersion = await this.orderRepository.get(this.order.id, Shopware.Context.api, criteria)
            const userId = firstOrderVersion?.customFields?.created_by_sales_agent_id;

            if (!userId) {
                this.createdBySalesAgentEmail = null;
                return;
            }

            try {
                const user = await this.userRepository.get(userId, Shopware.Context.api, criteria);
                this.createdBySalesAgentEmail = user?.email ?? null;
            } catch (e) {
                console.error('Could not load sales agent user', e);
                this.createdBySalesAgentEmail = null;
            }
        }
    }

});