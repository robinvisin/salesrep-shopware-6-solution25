import template from './abandoned-cart-list.html.twig';
import FormatUtilsMixin from '../../mixin/format-utils.mixin';
import '../../component';
import { Abandoned, ensureAbandonedStore } from '../../../../state/salesrep-abandoned.state';

const { Component, Mixin, Data: { Criteria } } = Shopware;

Component.register('abandoned-cart-list', {
  template,
  inject: ['repositoryFactory', 'orderService', 'abandonedCartReminderService'],
  mixins: [Mixin.getByName('notification'), FormatUtilsMixin],

  data() {
    return {
      isLoading: false,
      term: '',
      page: 1,
      limit: 10,
      total: 0,
      carts: [],
      currentModal: null,
      modalPayload: null,

      repository: null,
      customerRepository: null,

      columns: [
        { property: 'fullName', label: this.$tc('abandoned-cart-admin.columnName'), sortable: true },
        { property: 'email', label: this.$tc('abandoned-cart-admin.columnEmail'), sortable: true },
        { property: 'customerId', label: this.$tc('abandoned-cart-admin.coulmnCustomerId'), sortable: true },
        { property: 'price', label: this.$tc('abandoned-cart-admin.columnPrice'), sortable: true },
        { property: 'lineItemCount', label: this.$tc('abandoned-cart-admin.columnLineItemCount'), sortable: true },
        { property: 'created_at', label: this.$tc('abandoned-cart-admin.columnCreatedAt'), sortable: true },
        { property: 'actions', label: this.$tc('abandoned-cart-admin.columnActions'), sortable: false },
      ],
    };
  },

  created() {
    ensureAbandonedStore();
    this.repository = this.repositoryFactory.create('salesrep_abandoned_cart');
    this.customerRepository = this.repositoryFactory.create('customer');
    this.loadCarts();
  },

  methods: {
    createCriteria() {
      const criteria = new Criteria(this.page, this.limit);
      if (this.term && this.term.trim()) criteria.setTerm(this.term.trim());
      return criteria;
    },

    async fetchCustomerEmails(customerIds) {
      const ids = Array.from(new Set((customerIds || []).filter(Boolean)));
      if (!ids.length) return {};

      const criteria = new Criteria(1, ids.length);
      criteria.addFilter(Criteria.equalsAny('id', ids));

      const customers = await this.customerRepository.search(criteria, Shopware.Context.api);
      const map = {};
      customers.forEach((c) => { map[c.id] = c.email || ''; });
      return map;
    },

    async loadCarts() {
      this.isLoading = true;

      try {
        const criteria = this.createCriteria();
        const term = (this.term || '').trim();

        if (term) {
          // Keep your existing OR filtering approach
          const orFilters = [
            Criteria.contains('firstName', term),
            Criteria.contains('lastName', term),
            Criteria.contains('cartToken', term),
            Criteria.contains('customerId', term),
          ];
          criteria.addFilter(Criteria.multi('OR', orFilters));
        }

        const result = await this.repository.search(criteria, Shopware.Context.api);

        const customerIds = result.map(i => i.customerId).filter(Boolean);
        const emailByCustomerId = await this.fetchCustomerEmails(customerIds);

        this.carts = result.map((item) => {
          const raw = item.lineItems;
          const lineItems = Array.isArray(raw)
            ? raw
            : raw && typeof raw === 'object'
              ? Object.values(raw)
              : [];

          return {
            id: item.id,
            fullName: `${item.firstName ?? ''} ${item.lastName ?? ''}`.trim(),
            email: emailByCustomerId[item.customerId] ?? '',
            token: item.cartToken,
            customerId: item.customerId,
            price: item.price,
            lineItemCount: lineItems.length,
            created_at: item.createdAt ? this.formatDate(item.createdAt) : '',
            lineItems,
            shippingCost: item.shippingCost ?? 0,
          };
        });

        this.total = result.total ?? 0;
      } catch (e) {
        console.error('Error fetching abandoned carts:', e);
        this.createNotificationError({
          title: this.$tc('global.default.error'),
          message: e?.message ?? 'Failed to load carts.',
        });
      } finally {
        this.isLoading = false;
      }
    },

    onSearch(term) {
      this.term = term ?? '';
      this.page = 1;
      this.loadCarts();
    },

    onPageChange(newPageData) {
      if (typeof newPageData === 'object') {
        if (newPageData.page > 0) this.page = parseInt(newPageData.page, 10);
        if (newPageData.limit > 0 && newPageData.limit <= 100) this.limit = parseInt(newPageData.limit, 10);
      } else if (typeof newPageData === 'number' && newPageData > 0) {
        this.page = parseInt(newPageData, 10);
      } else {
        return;
      }
      this.loadCarts();
    },

    openModal(type, payload = null) {
      this.currentModal = type;
      this.modalPayload = payload;
    },

    closeModal() {
      this.currentModal = null;
      this.modalPayload = null;
    },

    confirmCreateOrder() {
      if (this.modalPayload) this.createOrder(this.modalPayload);
      this.closeModal();
    },

    async confirmSendReminder(reminderPayload) {
      const cart = this.modalPayload;
      this.closeModal();
      if (!cart) return;
      await this.sendReminder(cart, reminderPayload);
    },

    confirmDeleteCart() {
      if (this.modalPayload) this.deleteCart(this.modalPayload.id);
      this.closeModal();
    },

    async sendReminder(cart, reminderPayload = {}) {
      if (!cart?.id) {
        this.createNotificationError({ title: 'Missing cart', message: 'Cart ID is missing.' });
        return;
      }
      if (!cart?.customerId) {
        this.createNotificationError({ title: 'Missing customer', message: 'This cart has no customer.' });
        return;
      }

      try {
        this.isLoading = true;

        await this.abandonedCartReminderService.sendReminder({
          cartId: cart.id,
          customerId: cart.customerId,
          note: reminderPayload?.note || '',
          includeItems: reminderPayload?.includeItems !== false,
        });

        this.createNotificationSuccess({
          title: this.$tc('abandoned-cart-admin.reminderSentTitle'),
          message: this.$tc('abandoned-cart-admin.reminderSentMessage'),
        });
      } catch (e) {
        console.error('Error sending reminder:', e);
        this.createNotificationError({
          title: this.$tc('abandoned-cart-admin.reminderFailedTitle'),
          message: e?.response?.data?.errors?.[0]?.detail ?? e?.message ?? 'Failed to send reminder.',
        });
      } finally {
        this.isLoading = false;
      }
    },

    async createOrder(cart) {
      if (!cart?.customerId) {
        this.createNotificationError({ title: 'Missing customer', message: 'This cart has no customer.' });
        return;
      }

      try {
        await Shopware.State.dispatch('swOrder/resetState');
      } catch (e) {
        try { Shopware.State.commit('swOrder/setCustomer', null); } catch {}
        try { Shopware.State.commit('swOrder/setCart', null); } catch {}
        try { Shopware.State.commit('swOrder/setCartLineItems', []); } catch {}
        try { Shopware.State.commit('swOrder/setContextToken', null); } catch {}
        try { Shopware.State.commit('swOrder/setSalesChannelId', null); } catch {}
      }

      Abandoned.disableClear();
      Abandoned.set({
        customerId: cart.customerId,
        lineItems: cart.lineItems ?? [],
        shippingCost: cart.shippingCost ?? 0,
        token: cart.token ?? null,
        handedOverAt: new Date().toISOString(),
      });

      this.$router.push({
        name: 'sw.order.create.initial',
        query: { customerId: cart.customerId, _ab_ts: Date.now() },
      }).catch(() => {});
    },

    async deleteCart(cartId) {
      this.isLoading = true;
      try {
        if (!cartId) throw new Error('Cart ID is required to delete the cart.');
        await this.repository.delete(cartId, Shopware.Context.api);

        this.carts = this.carts.filter((c) => c.id !== cartId);

        this.createNotificationSuccess({
          title: 'Cart Deleted',
          message: 'The abandoned cart was successfully deleted.',
        });
      } catch (e) {
        console.error('Error deleting cart:', e);
        this.createNotificationError({
          title: 'Deletion Failed',
          message: `An error occurred while deleting the cart: ${e?.message ?? 'Unknown error'}`,
        });
      } finally {
        this.isLoading = false;
      }
    },
  },
});
