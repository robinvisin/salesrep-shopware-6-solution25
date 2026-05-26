import template from './sw-order-list.html.twig';

const {Criteria} = Shopware.Data;

Shopware.Component.override('sw-order-list', {
    template,
    inject: ['repositoryFactory'],
    data() {
        return {
            salesAgentEmailMap: {},
        };
    },
    computed: {
        userRepository() {
            return this.repositoryFactory.create('user');
        },
    },
    watch: {
        orders: {
            immediate: true,
            deep: true,
            handler() {
                this.loadSalesAgentEmails();
            }
        },
    },
    methods: {
        async loadSalesAgentEmails() {

            const userIds = this.orders.map(order => order?.customFields?.created_by_sales_agent_id).filter(Boolean)
            if (!userIds.length) {
                return;
            }

            try {
                const criteria = new Criteria(1, userIds.length);
                criteria.setIds(userIds);

                const result = await this.userRepository.search(criteria, Shopware.Context.api);

                const map = {};
                result.forEach(user => {
                    map[user.id] = user.email;
                });

                this.salesAgentEmailMap = map;
            } catch (error) {
                console.error('Could not load sales agent users', error);
                this.salesAgentEmailMap = {};
            }
        },

        getSalesAgentEmail(order) {
            const userId = order?.customFields?.created_by_sales_agent_id;

            if (!userId) {
                return '-';
            }

            return this.salesAgentEmailMap[userId] || userId;
        }
    }

});