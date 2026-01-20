Shopware.Component.override('sw-users-permissions-user-detail', {
    data() {
        return {
            __isSalesrepDetected: false,
            __isCurrentUserSalesrep: false,
        };
    },

    watch: {
        'user.aclRoles': {
            handler() { this.__syncSalesrepFlag(); },
            deep: true,
        },
    },

    methods: {
        loadUser() {
            return this.$super('loadUser').then(() => {
                this.__logAclRoles();
                this.__syncSalesrepFlag();
            });
        },

        __logAclRoles() {
            if (!this.user) {
                return;
            }
        },

        __syncSalesrepFlag() {
            if (!this.user) return;
            const roles = Array.isArray(this.user.aclRoles) ? this.user.aclRoles : [];
            const norm = s => (s || '').toString().trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-_]/g, '');
            const hasSalesrep = roles.some(r => norm(r.name) === 'salesrep' || norm(r.technicalName) === 'salesrep');

            if (!this.user.customFields || typeof this.user.customFields !== 'object') {
                this.user.customFields = {};
            }

            this.user.customFields.is_salesrep = !!hasSalesrep;
            this.__isSalesrepDetected = !!hasSalesrep;
        },

        saveUser(context) {
            this.__syncSalesrepFlag();
            return this.$super('saveUser', context);
        },
    },
});
