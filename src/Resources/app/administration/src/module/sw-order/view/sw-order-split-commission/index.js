import template from './sw-order-split-commission.html.twig';

const { Component, Store, Mixin, Application } = Shopware;

function clampPercent(v) {
  const n = Number.parseInt(String(v ?? '0'), 10);
  if (Number.isNaN(n)) return 0;
  return Math.max(0, Math.min(100, n));
}

function debounce(fn, wait = 400) {
  let t = null;
  return function (...args) {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), wait);
  };
}

Component.register('sw-order-split-commission', {
  template,

  inject: ['repositoryFactory'],
  mixins: [Mixin.getByName('notification')],

  data() {
    return {
      validating: false,
      emailValid: null,   
      emailReason: null,
    };
  },

  props: {
    order: { type: Object, required: false, default: null },
  },

  computed: {
    cart() { return Store.get('swOrder')?.cart || null; },
    orderEntity() { return this.order || null; },
    entity() { return this.orderEntity || this.cart || null; },

    splitEmail: {
      get() { return this.entity?.customFields?.sales_agent_split_email || ''; },
      set(v) {
        const val = (v || '').trim();
        this.patchEntityCf({ sales_agent_split_email: val });
        this.emailValid = null;
        this.emailReason = null;
        this.debouncedValidateEmail(val);
      }
    },

    splitPercent: {
      get() { return clampPercent(this.entity?.customFields?.sales_agent_split_percent ?? 0); },
      set(v) { this.patchEntityCf({ sales_agent_split_percent: clampPercent(v) }); }
    },
  },

  created() {
    this.debouncedValidateEmail = debounce(this.validateEmail, 500);
  },

  methods: {
    onPercentKeydown(e) {
      const blocked = ['-', '+', 'e', 'E', '.', ','];
      if (blocked.includes(e.key)) e.preventDefault();
    },

    onPercentChange(v) { this.splitPercent = v; },

    async validateEmail(email) {
      const val = (email || '').trim();
      if (!val) {
        this.emailValid = null;
        this.emailReason = null;
        return;
      }

      this.validating = true;

      try {
        const http = Application.getContainer('init')?.httpClient;
        const headers = {
          ...Shopware.Context.api.headers,
          'Content-Type': 'application/json',
        };

        const res = await http.post(
          '/_action/sales-agent/validate-email',
          { email: val },
          { headers }
        );

        const valid = !!res?.data?.valid;
        const reason = res?.data?.reason ?? null;

        this.emailValid = valid;
        this.emailReason = reason;

        if (!valid) {
          this.createNotificationWarning({
            title: 'Split commission',
            message: this.reasonToMessage(reason),
          });
        }
      } catch (e) {
        this.emailValid = false;
        this.emailReason = 'request_failed';
        this.createNotificationError({
          title: 'Split commission',
          message: e?.response?.data?.errors?.[0]?.detail || e?.message || 'Validation failed.',
        });
      } finally {
        this.validating = false;
      }
    },

    reasonToMessage(reason) {
      switch (reason) {
        case 'invalid_format': return 'Email format is invalid.';
        case 'same_email': return 'You cannot split commission with yourself.';
        case 'not_found': return 'No user found with this email.';
        case 'not_sales_agent': return 'This user is not a sales agent.';
        case 'not_authenticated': return 'Not authenticated.';
        default: return 'Email is not valid for split commission.';
      }
    },

    async patchEntityCf(patch) {
      if (this.orderEntity) {
        try {
          const repo = this.repositoryFactory.create('order');
          this.orderEntity.versionId = this.orderEntity.versionId || Shopware.Context.api.liveVersionId;

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

      Store.get('swOrder').setCart({
        ...cart,
        customFields: { ...(cart.customFields || {}), ...patch },
      });
    },
  },
});
