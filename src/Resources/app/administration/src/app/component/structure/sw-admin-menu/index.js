const { Component, State } = Shopware;
import './index.scss';

Component.override('sw-admin-menu', {
    created() {

        this.__unwatchSalesAgentUser = this.$store.watch(
            (state) => state?.session?.currentUser,
            (user, prev) => {
                console.warn('[SalesrepUI] session.currentUser changed', {
                    prevUserId: prev?.id,
                    nextUserId: user?.id,
                    nextEmail: user?.email,
                });

                this.__syncSalesAgentBodyClass();
            },
            { immediate: true }
        );
    },

    beforeDestroy() {

        if (typeof this.__unwatchSalesAgentUser === 'function') {
            this.__unwatchSalesAgentUser();
            this.__unwatchSalesAgentUser = null;
        }
    },

    beforeUnmount() {
        this.beforeDestroy?.();
    },

    methods: {
        __getCurrentUser() {
            const user =
                State.get('session')?.currentUser ||
                this.$store?.state?.session?.currentUser;

            console.warn('[SalesAgentUI] __getCurrentUser()', {
                id: user?.id,
                email: user?.email,
                hasCustomFields: !!user?.customFields,
                rolesType: Array.isArray(user?.aclRoles)
                    ? 'array'
                    : typeof user?.aclRoles,
            });

            return user;
        },

        __isSalesAgentUser(user) {
            const isAgent = !!user?.customFields?.is_salesrep;
            return isAgent;
        },

        __getUserRoles(user) {
            const roles =
                user?.aclRoles ??
                user?.acl_roles ??
                user?.roles ??
                user?.aclRoles?.data ??
                user?.aclRoles?.items;

            const list = Array.isArray(roles) ? roles : [];

            console.warn('[SalesAgentUI] __getUserRoles', {
                rawType: Array.isArray(roles) ? 'array' : typeof roles,
                count: list.length,
                names: list
                    .map((r) => r?.name || r?.label || r?.title || r)
                    .filter(Boolean),
            });

            return list;
        },

        __isSalesAgentRole(role) {
            const name = (role?.name || role?.label || role?.title || '')
                .toString()
                .toLowerCase();

            const match = !!name && name.includes('sales') && name.includes('agent');

            console.warn('[SalesAgentUI] __isSalesAgentRole', {
                roleName: role?.name || role?.label || role?.title || null,
                match,
            });

            return match;
        },

        __shouldApplySalesAgentRestrictions() {
            const user = this.__getCurrentUser();

            if (!this.__isSalesAgentUser(user)) {
                return false;
            }

            const roles = this.__getUserRoles(user);

            if (!roles.length) {
                return true;
            }

            const hasNonSalesAgentRole = roles.some((r) => {
                const hasName = !!(
                    r &&
                    typeof r === 'object' &&
                    (r.name || r.label || r.title)
                );

                if (hasName) {
                    const nonSalesAgent = !this.__isSalesAgentRole(r);
                    console.warn('[SalesAgentUI] role check (named)', {
                        role: r.name || r.label || r.title,
                        nonSalesAgent,
                    });
                    return nonSalesAgent;
                }

                // unknown shape (id string etc.)
                const nonSalesAgent = roles.length > 1;
                console.warn('[SalesAgentUI] role check (unknown shape)', {
                    role: r,
                    nonSalesAgent,
                });
                return nonSalesAgent;
            });

            const restrict = !hasNonSalesAgentRole;

            console.warn('[SalesAgentUI] __shouldApplySalesAgentRestrictions result', {
                hasNonSalesAgentRole,
                restrict,
            });

            return restrict;
        },

        __syncSalesAgentBodyClass() {
            const restrict = this.__shouldApplySalesAgentRestrictions();

            const before = document?.body?.classList.contains('is-salesrep');
            document?.body?.classList.toggle('is-salesrep', restrict);
            const after = document?.body?.classList.contains('is-salesrep');

            console.warn('[SalesAgentUI] body class toggle', {
                restrict,
                before,
                after,
            });
        },
    },
});
