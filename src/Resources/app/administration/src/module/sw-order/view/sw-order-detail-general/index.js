import template from './sw-order-detail-general.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-order-detail-general', {
  template,

  inject: ['repositoryFactory', 'orderClaimRequestApiService'],

  mixins: [Mixin.getByName('notification')],

  data() {
    return {
      claimReqLoading: false,
      claimReqSubmitting: false,

      claimReqStatus: 'none', // none | pending | approved
      claimReqId: '',
      claimReqRequestedAt: '',
      claimReqReason: '',

      claimReqApprovedUserId: '',

      claimReqReasonInput: '',
    };
  },

  computed: {
    orderId() {
      return this.$route.params.id;
    },

    currentUser() {
      // Works in admin
      return Shopware.State.get('session')?.currentUser || null;
    },

    isSalesAgentUser() {
      const cf = this.currentUser?.customFields || {};
      const v =
        cf.is_salesrep ??
        cf.salesrep ??
        false;

      return v === true || v === 1 || v === '1';
    },

    claimReqRepo() {
      return this.repositoryFactory.create('salesrep_order_claim_request');
    },
  },

  watch: {
    orderId: {
      immediate: true,
      handler() {
        this.loadClaimRequestStatus();
      }
    },
    order: {
      immediate: true,
      handler(order) {
        // if approved claim is already on order custom fields, reflect it immediately
        const cf = order?.customFields || {};
        const claimedUserId = (cf.salesrep_claimed_user_id || '').toString();
        const claimedAt = (cf.salesrep_claimed_at || '').toString();

        if (claimedUserId && claimedAt) {
          this.claimReqStatus = 'approved';
          this.claimReqApprovedUserId = claimedUserId;
        }
      }
    }
  },

  methods: {
    async loadClaimRequestStatus() {
      if (!this.orderId || !this.isSalesAgentUser) return;

      this.claimReqLoading = true;
      try {
        // 1) if order already has approved claim, we’re done
        const cf = this.order?.customFields || {};
        const claimedUserId = (cf.salesrep_claimed_user_id || '').toString();
        const claimedAt = (cf.salesrep_claimed_at || '').toString();
        if (claimedUserId && claimedAt) {
          this.claimReqStatus = 'approved';
          this.claimReqApprovedUserId = claimedUserId;
          return;
        }

        // 2) check if there is a pending request for this order
        const c = new Criteria(1, 1);
        c.addFilter(Criteria.equals('orderId', this.orderId));
        c.addFilter(Criteria.equals('orderVersionId', Shopware.Context.api.liveVersionId));
        c.addFilter(Criteria.equals('status', 'pending'));
        c.addSorting(Criteria.sort('requestedAt', 'DESC'));

        const res = await this.claimReqRepo.search(c, Shopware.Context.api);

        const req = res?.first?.() || (res && res.length ? res[0] : null);
        if (req) {
          this.claimReqStatus = 'pending';
          this.claimReqId = req.id;
          this.claimReqRequestedAt = req.requestedAt || '';
          this.claimReqReason = req.reason || '';
        } else {
          this.claimReqStatus = 'none';
          this.claimReqId = '';
          this.claimReqRequestedAt = '';
          this.claimReqReason = '';
        }
      } catch (e) {
        this.createNotificationError({
          title: 'Claim request',
          message: e?.message || 'Failed to load claim request status.'
        });
      } finally {
        this.claimReqLoading = false;
      }
    },

    async submitClaimRequest() {
      if (!this.orderId) return;

      this.claimReqSubmitting = true;
      try {
        const reason = (this.claimReqReasonInput || '').trim() || null;

        const resp = await this.orderClaimRequestApiService.request(this.orderId, reason);
        const requestId =
          resp?.data?.requestId ||
          resp?.data?.data?.requestId ||
          '';

        this.createNotificationSuccess({
          title: 'Claim request',
          message: 'Request submitted for admin approval.'
        });

        this.claimReqReasonInput = '';
        await this.loadClaimRequestStatus();
      } catch (e) {
        const detail = e?.response?.data?.errors?.[0]?.detail;
        this.createNotificationError({
          title: 'Claim request failed',
          message: detail || e?.message || 'Could not submit claim request.'
        });
      } finally {
        this.claimReqSubmitting = false;
      }
    }
  }
});
