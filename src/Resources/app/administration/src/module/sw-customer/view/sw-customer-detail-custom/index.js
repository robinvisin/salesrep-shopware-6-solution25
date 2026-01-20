import template from './sw-customer-detail-custom.html.twig';
import '../../../salesrep-abandoned-cart/component';
import FormatUtilsMixin from '../../../../mixin/formatting.mixin'
import { Abandoned, ensureAbandonedStore } from '../../../../state/salesrep-abandoned.state';
const { Component, Mixin } = Shopware;

Component.register('sw-customer-detail-custom', {
    template,

    inject: ['repositoryFactory', 'orderService'],

    mixins: [Mixin.getByName('notification'), FormatUtilsMixin],

    data() {
        return {
            isLoading: false,
            repository: null,
            carts: [],
            total: 0,
            page: 1,
            limit: 10,
            currentModal: null,
            modalPayload: null,
            columns: [
                { property: 'fullName', label: this.$tc('abandoned-cart-admin.columnName'), sortable: true },
                { property: 'price', label: this.$tc('abandoned-cart-admin.columnPrice'), sortable: true },
                { property: 'lineItemCount', label: this.$tc('abandoned-cart-admin.columnLineItemCount'), sortable: true },
                { property: 'created_at', label: this.$tc('abandoned-cart-admin.columnCreatedAt'), sortable: true },
                { property: 'actions', label: this.$tc('abandoned-cart-admin.columnActions'), sortable: false },
            ],
        };
    },

    created() {
        this.repository = this.repositoryFactory.create('salesrep_abandoned_cart');
        this.loadCarts();
    },

    methods: {
        openModal(type, payload = null) {
            this.currentModal = type;
            this.modalPayload = payload;
        },

        closeModal() {
            this.currentModal = null;
            this.modalPayload = null;
        },

        confirmCreateOrder() {
            if (this.modalPayload) {
                this.createOrder(this.modalPayload);
            }
            this.closeModal();
        },

        confirmDeleteCart() {
            if (this.modalPayload?.id) {
                this.deleteCart(this.modalPayload.id);
            }
            this.closeModal();
        },

        formatDate(dateString) {
            if (!dateString) return '';
            return new Intl.DateTimeFormat('en-US', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            }).format(new Date(dateString));
        },

        async loadCarts() {
            this.isLoading = true;
            try {
                const criteria = this.createCriteria();
                const result = await this.repository.search(criteria, Shopware.Context.api);
                this.carts = result.map(item => ({
                    id: item.id,
                    fullName: `${item.firstName} ${item.lastName}`,
                    token: item.cartToken,
                    customer_id: item.customerId,
                    price: item.price,
                    lineItemCount: item.lineItems.length,
                    created_at: this.formatDate(item.createdAt),
                    lineItems: item.lineItems,
                    shippingCost: item.shippingCost || 0
                }));
                this.total = result.total || 0;
            } catch (e) {
                console.error(e);
            } finally {
                this.isLoading = false;
            }
        },

        onPageChange(newPageData) {
            if (typeof newPageData === 'object') {
                this.page = parseInt(newPageData.page || this.page, 10);
                this.limit = parseInt(newPageData.limit || this.limit, 10);
            } else if (typeof newPageData === 'number') {
                this.page = parseInt(newPageData, 10);
            }

            this.loadCarts();
        },

        createCriteria() {
            const criteria = new Shopware.Data.Criteria(this.page, this.limit)
                .addSorting(Shopware.Data.Criteria.sort('createdAt', 'DESC'));

            const customerId = this.$route.params.id;
            if (customerId) {
                criteria.addFilter(Shopware.Data.Criteria.equals('customerId', customerId));
            }

            return criteria;
        },

        async createOrder(cart) {
            if (!cart?.customer_id) {
                this.createNotificationError({ title: 'Missing customer', message: 'This cart has no customer.' });
                return;
            }

            Abandoned.disableClear();

            Abandoned.set({
                customerId: cart.customer_id,
                lineItems: cart.lineItems ?? [],
                shippingCost: cart.shippingCost ?? 0,
                token: cart.token ?? null,
                handedOverAt: new Date().toISOString(),
            });

            this.$router.push({
                name: 'sw.order.create.initial',
                query: { customerId: cart.customer_id },
            });
        },

        async deleteCart(cartId) {
            try {
                await this.repository.delete(cartId, Shopware.Context.api);
                this.carts = this.carts.filter(cart => cart.id !== cartId);
                this.createNotificationSuccess({
                    title: 'Deleted',
                    message: 'Cart was deleted successfully.',
                });
            } catch (error) {
                this.createNotificationError({
                    title: 'Delete Failed',
                    message: error.message,
                });
            }
        }
    }
});
