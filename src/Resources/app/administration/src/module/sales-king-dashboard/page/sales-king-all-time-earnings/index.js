import template from './sales-king-all-time-earnings.html.twig';
import '../../components/sales-king-all-time-earnings/index';

const { Component, Mixin } = Shopware;

Component.register('sales-king-all-time-earnings', {
    template,
    inject: ['salesKingAgentService'],
    mixins: [Mixin.getByName('notification')],

    data() {
        const nowY = new Date().getUTCFullYear();
        return {
            isLoading: false,
            lifetimeCommission: 0,
            lifetimeSales: 0,
            years: Array.from({ length: 6 }, (_, i) => nowY - i),
            agentConfig: null,
            adminAgentsLoading: false,
            adminAgents: [],
            adminAgentColumns: [
                { property: 'username', label: 'Username' },
                { property: 'email', label: 'Email' },
                { property: 'lifetimeOrders', label: 'Orders' },
                { property: 'lifetimeSales', label: 'Sales (All-time)' },
                { property: 'lifetimeCommission', label: 'Commission (All-time)' },
                { property: 'export', label: 'Export', type: 'string' }
            ],
        };
    },

   computed: {
        currentAdminUser() {
            return Shopware.Store.get('session')?.currentUser || null;
        },
        isAdminUser() {
            return !!this.currentAdminUser?.admin;
        },

        formattedCommission() {
            return this.salesKingAgentService.formatCurrency(this.lifetimeCommission);
        },
        formattedSales() {
            return this.salesKingAgentService.formatCurrency(this.lifetimeSales);
        }
    },

    async created() {
        await this.loadLifetime();
        if (this.isAdminUser) {
            await this.loadAdminLifetimeAgents();
            return;
        }
    },

 
    methods: {
        formatCurrency(v) {
            return this.salesKingAgentService.formatCurrency(v);
        },

        async loadAdminLifetimeAgents() {
            this.adminAgentsLoading = true;
            try {
                this.adminAgents = await this.salesKingAgentService.fetchAgentsWithLifetimeTotals();
            } catch (e) {
                this.createNotificationError({
                    title: 'Sales King',
                    message: e?.message || 'Failed to load lifetime earnings for agents.'
                });
            } finally {
                this.adminAgentsLoading = false;
            }
        },
        resolveCustomerName(o) {
            if (o?.fullName && typeof o.fullName === 'string' && o.fullName.trim()) {
                return o.fullName.trim();
            }
            const oc = o?.orderCustomer || o?.order?.orderCustomer || o?.commissionOrder?.orderCustomer || null;
            const cust = oc?.customer || o?.orderCustomer?.customer || o?.order?.orderCustomer?.customer || null;
            const billing = o?.billingAddress || o?.order?.billingAddress || cust?.defaultBillingAddress || null;

            const first = oc?.firstName ?? cust?.firstName ?? billing?.firstName ?? '';
            const last  = oc?.lastName ?? cust?.lastName ?? billing?.lastName ?? '';
            const email = oc?.email ?? cust?.email ?? o?.customerEmail ?? o?.order?.orderCustomer?.email ?? '';

            const name = `${first} ${last}`.trim();
            if (name) return name;
            if (email) return email;
            return 'Unknown';
        },

        shouldCountRecord(o) {
            const orderState = (
                o?.orderState ||
                o?.order?.stateMachineState?.technicalName ||
                o?.state ||
                ''
            )?.toLowerCase();

            const transactionState = (
                o?.transactionState ||
                o?.order?.transactions?.[0]?.stateMachineState?.technicalName ||
                ''
            )?.toLowerCase();

            const negativeOrReturn =
                (o?.isReturn === true) ||
                (o?.type && String(o.type).toLowerCase().includes('return')) ||
                (o?.isCredit === true) ||
                (o?.documentType && String(o.documentType).toLowerCase().includes('credit'));

            if (negativeOrReturn) return false;

            const okOrderStates = new Set(['completed', 'done', 'fulfilled', 'in_progress']);
            const okTxnStates   = new Set(['paid', 'captured', 'authorized', 'partially_paid']);

            const orderEligible = okOrderStates.has(orderState) || orderState === '';
            const txnEligible   = okTxnStates.has(transactionState) || transactionState === '';

            return orderEligible || txnEligible;
        },

        normalizeGross(o, { includeShipping = false } = {}) {
            const price = o?.order?.price || o?.price || null;

            let gross =
                (price?.totalPrice != null ? Number(price.totalPrice) : null) ??
                Number(o?.amountTotal ?? o?.gross ?? o?.amountNet ?? o?.amount ?? 0);

            if (!Number.isFinite(gross)) gross = 0;

            if (!includeShipping) {
                const shippingTotal =
                    (o?.order?.shippingTotal != null ? Number(o.order.shippingTotal) : null) ??
                    (price?.calculatedTaxes ? 0 : null);
                if (Number.isFinite(shippingTotal) && shippingTotal > 0) {
                    gross -= shippingTotal;
                }
            }

            const cf =
                Number(o?.currencyFactor ??
                       o?.order?.currencyFactor ??
                       price?.rawTotal?._currencyFactor ??
                       1);
            if (Number.isFinite(cf) && cf > 0 && cf !== 1) {
                gross = gross * cf;
            }

            if (gross > -0.005 && gross < 0.005) gross = 0;

            return gross;
        },

        calcCommission(gross, o, commissionPct) {
            if (o?.commission != null && Number.isFinite(Number(o.commission))) {
                return Number(o.commission);
            }
            return this.salesKingAgentService.calculateCommission(gross, commissionPct);
        },

        async loadLifetime() {
            this.isLoading = true;
            try {
                const userId = this.salesKingAgentService.getCurrentUserId();
                if (!userId) return;

                const cfg = await this.salesKingAgentService.fetchAgentConfig(userId);
                const commissionPct = Number(cfg?.commission ?? 0);

                const records = await this.salesKingAgentService.fetchCombinedCommissions(userId, {
                    start: '1970-01-01T00:00:00Z',
                    end: new Date().toISOString()
                });

                const seen = new Set();
                let lifetimeSales = 0;
                let lifetimeCommission = 0;

                for (const rec of records) {
                    if (!this.shouldCountRecord(rec)) continue;

                    const orderId =
                        rec?.orderId ||
                        rec?.order?.id ||
                        null;

                    const orderNumber =
                        rec?.orderNumber ||
                        rec?.order?.orderNumber ||
                        null;

                    const dateKey = (rec?.orderDateTime || rec?.orderDate || rec?.createdAt || '').slice(0, 10);

                    const key = orderId || (orderNumber ? `${orderNumber}#${dateKey}` : null) || JSON.stringify(rec).slice(0, 80);
                    if (seen.has(key)) continue;
                    seen.add(key);

                    const gross = this.normalizeGross(rec, { includeShipping: false });
                    if (gross <= 0) continue;

                    const commission = this.calcCommission(gross, rec, commissionPct);

                    lifetimeSales += gross;
                    lifetimeCommission += commission;
                }

                this.lifetimeSales = Number(lifetimeSales.toFixed(2));
                this.lifetimeCommission = Number(lifetimeCommission.toFixed(2));
            } catch (error) {
                this.createNotificationError({
                    title: 'Sales Agent',
                    message: 'Failed to load lifetime earnings.'
                });
            } finally {
                this.isLoading = false;
            }
        },

        async downloadCsv() {
            try {
                const userId = this.salesKingAgentService.getCurrentUserId();
                if (!userId) return;

                const cfg = await this.salesKingAgentService.fetchAgentConfig(userId);
                const commissionPct = Number(cfg?.commission ?? 0);

                const records = await this.salesKingAgentService.fetchCombinedCommissions(userId, {
                    start: '1970-01-01T00:00:00Z',
                    end: new Date().toISOString()
                });

                const rows = [['Order Number', 'Date', 'Customer', 'Gross ($)', 'Commission %', 'Commission ($)']];
                const seen = new Set();
                let totalSales = 0;
                let totalCommission = 0;

                const q = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;

                for (const rec of records) {
                    if (!this.shouldCountRecord(rec)) continue;

                    const orderId =
                        rec?.orderId ||
                        rec?.order?.id ||
                        null;

                    const orderNumber =
                        rec?.orderNumber ||
                        rec?.order?.orderNumber ||
                        '';

                    const dateISO = rec.orderDateTime || rec.orderDate || rec.createdAt || '';
                    const date = dateISO ? new Date(dateISO).toISOString().split('T')[0] : '';

                    const key = orderId || (orderNumber ? `${orderNumber}#${date}` : null) || JSON.stringify(rec).slice(0, 80);
                    if (seen.has(key)) continue;
                    seen.add(key);

                    const gross = this.normalizeGross(rec, { includeShipping: false });
                    if (gross <= 0) continue;

                    const commission = this.calcCommission(gross, rec, commissionPct);
                    totalSales += gross;
                    totalCommission += commission;

                    const customerName = this.resolveCustomerName(rec);

                    rows.push([
                        q(orderNumber),
                        q(date),
                        q(customerName),
                        q(gross.toFixed(2)),
                        q(commissionPct.toFixed(2)),
                        q(commission.toFixed(2))
                    ]);
                }

                rows.push([]);
                rows.push([
                    q('TOTAL SALES ($)'), q(totalSales.toFixed(2)),
                    '', '', q('TOTAL COMMISSION ($)'), q(totalCommission.toFixed(2))
                ]);

                const csvContent = rows.map(r => r.join(',')).join('\n');
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'all_time_earnings.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            } catch (error) {
                this.createNotificationError({
                    title: 'Sales Agent',
                    message: 'Failed to download earnings CSV.'
                });
            }
        },
        async downloadAgentCsv(agent) {
            try {
              const agentId = agent?.userId;
              if (!agentId) return;
          
              const cfg = await this.salesKingAgentService.fetchAgentConfig(agentId);
              const commissionPct = Number(cfg?.commission ?? 0);
          
              const records = await this.salesKingAgentService.fetchCombinedCommissions(agentId, {
                start: '1970-01-01T00:00:00Z',
                end: new Date().toISOString()
              });
          
              const rows = [['Order Number', 'Date', 'Customer', 'Gross', 'Commission %', 'Commission']];
              const seen = new Set();
              let totalSales = 0;
              let totalCommission = 0;
          
              const q = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
          
              for (const rec of records) {
                if (!this.shouldCountRecord(rec)) continue;
          
                const orderId = rec?.orderId || rec?.order?.id || null;
                const orderNumber = rec?.orderNumber || rec?.order?.orderNumber || '';
                const dateISO = rec.orderDateTime || rec.orderDate || rec.createdAt || '';
                const date = dateISO ? new Date(dateISO).toISOString().split('T')[0] : '';
          
                const key = orderId || (orderNumber ? `${orderNumber}#${date}` : null) || JSON.stringify(rec).slice(0, 80);
                if (seen.has(key)) continue;
                seen.add(key);
          
                const gross = this.normalizeGross(rec, { includeShipping: false });
                if (gross <= 0) continue;
          
                const commission = this.calcCommission(gross, rec, commissionPct);
                totalSales += gross;
                totalCommission += commission;
          
                rows.push([
                  q(orderNumber),
                  q(date),
                  q(this.resolveCustomerName(rec)),
                  q(gross.toFixed(2)),
                  q(commissionPct.toFixed(2)),
                  q(commission.toFixed(2)),
                ]);
              }
          
              rows.push([]);
              rows.push([q('TOTAL SALES'), q(totalSales.toFixed(2)), '', '', q('TOTAL COMMISSION'), q(totalCommission.toFixed(2))]);
          
              const csvContent = rows.map(r => r.join(',')).join('\n');
              const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
              const url = URL.createObjectURL(blob);
          
              const safeName = (agent?.username || agentId).replace(/[^\w\-]+/g, '_');
              const link = document.createElement('a');
              link.href = url;
              link.download = `all_time_earnings_${safeName}.csv`;
              document.body.appendChild(link);
              link.click();
              document.body.removeChild(link);
              URL.revokeObjectURL(url);
            } catch (error) {
              this.createNotificationError({
                title: 'Sales King',
                message: 'Failed to download agent earnings CSV.'
              });
            }
          }
    }
});