import template from './sales-agent-claim-request-list.html.twig';
import './sales-agent-claim-request-list.scss';
import formattingMixin from '../../../../mixin/formatting.mixin';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sales-agent-claim-request-list', {
    template,
    inject: ['repositoryFactory', 'orderClaimRequestApiService'],
    mixins: [formattingMixin, Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            items: [],
            page: 1,
            limit: 25,
            statusFilter: 'pending',

            columns: [
                { property: 'requestedAt', label: 'Requested at', rawData: true },
                { property: 'orderId', label: 'Order ID', rawData: true },
                { property: 'requestedBy.email', label: 'Requested by', rawData: true },
                { property: 'reason', label: 'Reason', rawData: true },
                { property: 'status', label: 'Status', rawData: true },
                { property: 'decidedAt', label: 'Decided at', rawData: true },
            ],

            decisionModalOpen: false,
            decisionSubmitting: false,
            decisionAction: 'approve',
            decisionItem: null,
            decisionNoteDraft: '',
        };
    },

    computed: {
        repo() {
            return this.repositoryFactory.create('sales_agent_order_claim_request');
        },

        currentUser() {
            return Shopware.State.get('session').currentUser;
        },

        currentUserId() {
            return Shopware.Context.api.userId;
        },

        isSalesAgent() {
            return !!this.currentUser?.customFields?.is_sales_agent;
        },

        liveVersionId() {
            return Shopware.Context.api.liveVersionId;
        },

        statusOptions() {
            return [
                { value: 'pending', label: 'Pending' },
                { value: 'approved', label: 'Approved' },
                { value: 'rejected', label: 'Rejected' },
                { value: 'cancelled', label: 'Cancelled' },
                { value: 'all', label: 'All' },
            ];
        },

        decisionModalTitle() {
            return this.decisionAction === 'approve'
                ? 'Approve claim request'
                : 'Reject claim request';
        },
    },

    created() {
        this.load();
    },

    methods: {
        buildCriteria() {
            const c = new Criteria(this.page, this.limit);
        
            c.addFilter(Criteria.equals('orderVersionId', this.liveVersionId));
        
            if (this.statusFilter !== 'all') {
                c.addFilter(Criteria.equals('status', this.statusFilter));
            }
        
            if (this.isSalesAgent && this.currentUserId) {
                c.addFilter(
                    Criteria.equals('requestedByUserId', this.currentUserId)
                );
            }
        
            c.addSorting(Criteria.sort('requestedAt', 'DESC'));
            c.addAssociation('requestedBy');
            c.addAssociation('decidedBy');

            c.addAssociation('order');
            c.addAssociation('order.stateMachineState');

            c.addFilter(
                Criteria.not('AND', [
                    Criteria.equals('order.stateMachineState.technicalName', 'cancelled'),
                ])
            );

            return c;
        }
        ,

        async load() {
            this.isLoading = true;
            try {
                const result = await this.repo.search(this.buildCriteria(), Shopware.Context.api);
                this.items = result;
            } catch (e) {
                this.createNotificationError({
                    title: 'Load failed',
                    message: e?.message || 'Could not load claim requests',
                });
            } finally {
                this.isLoading = false;
            }
        },

        onStatusChange(val) {
            this.statusFilter = val;
            this.page = 1;
            return this.load();
        },

        canDecide(item) {
            if (this.isSalesAgent) return false;
            return item?.status === 'pending';
        },

        openDecisionModal(item, action) {
            if (!this.canDecide(item)) return;

            this.decisionItem = item;
            this.decisionAction = action;
            this.decisionNoteDraft = '';
            this.decisionModalOpen = true;
        },

        closeDecisionModal() {
            if (this.decisionSubmitting) return;

            this.decisionModalOpen = false;
            this.decisionSubmitting = false;
            this.decisionItem = null;
            this.decisionNoteDraft = '';
        },

        async confirmDecision() {
            if (!this.decisionItem) return;

            this.decisionSubmitting = true;
            try {
                const note = (this.decisionNoteDraft || '').trim() || null;

                if (this.decisionAction === 'approve') {
                    await this.orderClaimRequestApiService.approve(this.decisionItem.id, note);
                    this.createNotificationSuccess({ title: 'Approved', message: 'Claim approved' });
                } else {
                    await this.orderClaimRequestApiService.reject(this.decisionItem.id, note);
                    this.createNotificationSuccess({ title: 'Rejected', message: 'Claim rejected' });
                }

                this.closeDecisionModal();
                await this.load();
            } catch (e) {
                this.createNotificationError({
                    title: 'Decision failed',
                    message: e?.response?.data?.errors?.[0]?.detail || e?.message || 'Decision failed',
                });
            } finally {
                this.decisionSubmitting = false;
            }
        },
    },
});
