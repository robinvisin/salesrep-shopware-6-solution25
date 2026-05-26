Shopware.Component.override('sw-users-permissions-user-detail', {
    data() {
        return {
            __isSalesAgentDetected: false,
            __isCurrentUserSalesAgent: false,
        };
    },

    watch: {
        'user.aclRoles': {
            handler() { this.__syncSalesAgentFlag(); },
            deep: true,
        },
    },

    methods: {
        loadUser() {
            return this.$super('loadUser').then(() => {
                this.__logAclRoles();
                this.__syncSalesAgentFlag();
            });
        },

        __logAclRoles() {
            if (!this.user) {
                return;
            }
        },

        __syncSalesAgentFlag() {
            if (!this.user) return;
            const roles = Array.isArray(this.user.aclRoles) ? this.user.aclRoles : [];
            const norm = s => (s || '').toString().trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-_]/g, '');
            const hasSalesAgent = roles.some(r => norm(r.name) === 'sales-agent' || norm(r.technicalName) === 'sales-agent');

            if (!this.user.customFields || typeof this.user.customFields !== 'object') {
                this.user.customFields = {};
            }

            this.user.customFields.is_sales_agent = !!hasSalesAgent;
            this.__isSalesAgentDetected = !!hasSalesAgent;
        },

        saveUser(context) {
            this.__syncSalesAgentFlag();
            return this.$super('saveUser', context);
        },
    },
});
