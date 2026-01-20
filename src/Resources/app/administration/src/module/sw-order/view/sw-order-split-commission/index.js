import template from './sw-order-split-commission.html.twig';

const { Component, State, Mixin } = Shopware;

function clampPercent(v) {
    const n = Number.parseInt(String(v ?? '0'), 10);
    if (Number.isNaN(n)) return 0;
    return Math.max(0, Math.min(100, n));
}

Component.register('sw-order-split-commission', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    props: {
        order: {
            type: Object,
            required: false,
            default: null,
        },
    },

    computed: {
        cart() {
            return State.get('swOrder')?.cart || null;
        },

        orderEntity() {
            return this.order || null;
        },

        entity() {
            return this.orderEntity || this.cart || null;
        },

        splitEmail: {
            get() {
                return this.entity?.customFields?.salesrep_split_email || '';
            },
            set(v) {
                const val = (v || '').trim();
                this.patchEntityCf({ salesrep_split_email: val });
            }
        },

        splitPercent: {
            get() {
                return clampPercent(this.entity?.customFields?.salesrep_split_percent ?? 0);
            },
            set(v) {
                const n = clampPercent(v);
                this.patchEntityCf({ salesrep_split_percent: n });
            }
        },
    },

    methods: {
        onPercentKeydown(e) {
            const blocked = ['-', '+', 'e', 'E', '.', ','];
            if (blocked.includes(e.key)) {
                e.preventDefault();
            }
        },

        onPercentChange(v) {
            this.splitPercent = v;
        },

        async patchEntityCf(patch) {
            if (this.orderEntity) {
                try {
                    const repo = this.repositoryFactory.create('order');

                    this.orderEntity.versionId =
                        this.orderEntity.versionId || Shopware.Context.api.liveVersionId;

                    this.orderEntity.customFields = {
                        ...(this.orderEntity.customFields || {}),
                        ...patch,
                    };

                    await repo.save(this.orderEntity, Shopware.Context.api);
                } catch (e) {
                    console.error('Split commission save failed', e);
                    this.createNotificationError({
                        title: 'Split commission',
                        message: e?.message || 'Failed to save split commission.',
                    });
                }
                return;
            }

            const cart = this.cart;
            if (!cart) return;

            State.commit('swOrder/setCart', {
                ...cart,
                customFields: {
                    ...(cart.customFields || {}),
                    ...patch,
                },
            });
        },
    },
});
