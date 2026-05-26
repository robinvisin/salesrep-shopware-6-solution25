import template from './sw-order-create.html.twig';

const { Component, State } = Shopware;

function normalizePercent(v) {
  const n = Number(v ?? 0);
  return Math.max(0, Math.min(100, n));
}

function readSplitFromState() {
  const cart = State.get('swOrder')?.cart || null;
  const cf = cart?.customFields || {};
  return {
    email: (cf.sales_agent_split_email || '').trim(),
    percent: normalizePercent(cf.sales_agent_split_percent),
  };
}

Component.override('sw-order-create', {
  template,
  inject: ['repositoryFactory'],

  data() {
    return {
      __saving: false,
      __lastSplit: { email: '', percent: 0 },
    };
  },

  created() {
    this.resetSplitInCartState();
    this.__lastSplit = { email: '', percent: 0 };
  },

  methods: {
    resetSplitInCartState() {
      const cart = State.get('swOrder')?.cart || null;
      if (!cart) return;

      State.commit('swOrder/setCart', {
        ...cart,
        customFields: {
          ...(cart.customFields || {}),
          sales_agent_split_email: '',
          sales_agent_split_percent: 0,
        },
      });
    },

    async onSaveOrder() {
      if (this.__saving) return;
      this.__saving = true;

      try {
        await this.$nextTick();
        await this.$nextTick();

        const split = readSplitFromState();
        this.__lastSplit = split;

        console.log('[OrderCreate] split captured:', split);

        const result = await this.$super('onSaveOrder');

        const orderId = this.orderId || null;
        if (orderId && (split?.email || split?.percent)) {
          try {
            const repo = this.repositoryFactory.create('order');
            const order = await repo.get(orderId, Shopware.Context.api);

            order.customFields = order.customFields || {};
            order.customFields.sales_agent_split_email = split.email;
            order.customFields.sales_agent_split_percent = split.percent;

            await repo.save(order, Shopware.Context.api);
          } catch (e) {
            console.error('[SplitCommission] failed persisting split on order', e);
          }
        }

        this.resetSplitInCartState();
        this.__lastSplit = { email: '', percent: 0 };

        return result;
      } finally {
        this.__saving = false;
      }
    },
  }
});
