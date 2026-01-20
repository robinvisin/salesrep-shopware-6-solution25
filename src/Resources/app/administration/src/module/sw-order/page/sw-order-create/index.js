import template from './sw-order-create.html.twig';

const { Component, State, Application } = Shopware;

const FLAG = '__splitOrderIdInterceptorInstalled';

function normalizePercent(v) {
  const n = Number(v ?? 0);
  return Math.max(0, Math.min(100, n));
}

function readSplitFromState() {
  const cart = State.get('swOrder')?.cart || null;
  const cf = cart?.customFields || {};
  return {
    email: (cf.salesrep_split_email || '').trim(),
    percent: normalizePercent(cf.salesrep_split_percent),
  };
}

function extractOrderIdFromAnyResponse(resp) {
  // common shapes
  return (
    resp?.data?.id ||
    resp?.data?.orderId ||
    resp?.data?.order?.id ||
    resp?.data?.data?.id ||
    resp?.data?.data?.orderId ||
    null
  );
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
    this.installCreateOrderResponseHook();
  },

  methods: {
    installCreateOrderResponseHook() {
      const init = Application.getContainer('init');
      const http = init?.httpClient;
      if (!http?.interceptors?.response) return;

      if (http[FLAG]) return;
      http[FLAG] = true;

      http.interceptors.response.use(async (resp) => {
        const url = String(resp?.config?.url || '');
        const method = String(resp?.config?.method || '').toLowerCase();

        // We only care about the actual "create order" request.
        // This match is intentionally broad; tighten it once you see the real URL in logs.
        const looksLikeCreate =
          method === 'post' &&
          (url.includes('/_action/order') || url.includes('/order') || url.includes('create'));

        if (!looksLikeCreate) return resp;

        const orderId = extractOrderIdFromAnyResponse(resp);
        if (!orderId) return resp;

        const split = this.__lastSplit;
        if (!split?.email && !split?.percent) return resp;

        try {
          const repo = this.repositoryFactory.create('order');
          const order = await repo.get(orderId, Shopware.Context.api);

          order.customFields = order.customFields || {};
          order.customFields.salesrep_split_email = split.email;
          order.customFields.salesrep_split_percent = split.percent;

          await repo.save(order, Shopware.Context.api);

          console.log('[SplitCommission] persisted split on order (from response):', {
            orderId,
            ...split,
          });
        } catch (e) {
          console.error('[SplitCommission] failed persisting split on order', e);
        }

        return resp;
      }, (err) => Promise.reject(err));
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

        return await this.$super('onSaveOrder');
      } finally {
        this.__saving = false;
      }
    },
  }
});
