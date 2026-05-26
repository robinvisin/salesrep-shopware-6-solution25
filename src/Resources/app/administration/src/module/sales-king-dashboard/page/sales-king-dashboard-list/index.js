import template from './sales-king-dashboard-list.html.twig';
import '../../components';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sales-king-dashboard-list', {
    template,
    inject: ['repositoryFactory', 'salesKingAgentService'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            customerPage: 1,
            customerLimit: 10,
            orderPage: 1,
            orderLimit: 10,
            customers: [],
            orders: [],
            kpi: { customers: 0, orders: 0, sales: 0, commission: 0 },
            showCustomerSkeleton: false,
            showOrderSkeleton: false,
            alreadyLoadedCustomers: false,
            totalCustomers: 0,
            agentConfig: null,
            adminAgents: [],
            adminAgentsLoading: false,
            payoutHistoryLoading: false,
            payoutHistory: [],
            payoutHistoryColumns: [
              { property: 'monthKey', label: 'Month' },
              { property: 'paid', label: 'Status' },
              { property: 'orders', label: 'Orders' },
              { property: 'sales', label: 'Sales' },
              { property: 'commission', label: 'Commission' },
              { property: 'paidAt', label: 'Paid at' },
            ],
            adminAgentColumns: [
              { property: 'username', label: 'Username' },
              { property: 'email', label: 'Email' },
              { property: 'commissionPct', label: 'Commission %' },
              { property: 'orders', label: 'Orders (This Month)' },
              { property: 'sales', label: 'Sales (This Month)' },
              { property: 'commission', label: 'Commission (This Month)' },
            ],
            customerColumns: [
                { property: 'fullName', label: this.$tc('sales-king.dashboard.customerColumns.fullName') },
                { property: 'street', label: this.$tc('sales-king.dashboard.customerColumns.street') },
                { property: 'zipcode', label: this.$tc('sales-king.dashboard.customerColumns.zipcode') },
                { property: 'city', label: this.$tc('sales-king.dashboard.customerColumns.city') },
                { property: 'customerNumber', label: this.$tc('sales-king.dashboard.customerColumns.customerNumber') },
                { property: 'groupName', label: this.$tc('sales-king.dashboard.customerColumns.groupName') },
                { property: 'email', label: this.$tc('sales-king.dashboard.customerColumns.email') },
            ],
            orderColumns: [
                { property: 'orderNumber', label: this.$tc('sales-king.dashboard.orderColumns.orderNumber') },
                { property: 'fullName', label: this.$tc('sales-king.dashboard.orderColumns.fullName') },
                { property: 'commission', label: this.$tc('sales-king.dashboard.orderColumns.commissionAmount') },
                { property: 'amountTotal', label: this.$tc('sales-king.dashboard.orderColumns.amountTotal') },
                { property: 'paymentMethod', label: this.$tc('sales-king.dashboard.orderColumns.paymentMethod') },
                { property: 'stateMachineState.name', label: this.$tc('sales-king.dashboard.orderColumns.status') },
                { property: 'orderDate', label: this.$tc('sales-king.dashboard.orderColumns.orderDate') },
            ]
        };
    },
    computed: {
      currentAdminUser() {
        return Shopware.State.get('session')?.currentUser || null;
      },
      isAdminUser() {
        return !!this.currentAdminUser?.admin; 
      }
    },
  
    created() {
      this.customerRepository = this.repositoryFactory.create('customer');
      this.userRepository = this.repositoryFactory.create('user');
    
      if (this.isAdminUser) {
        this.loadAdminAgents();
        return;
      }
    
      this.loadDashboardData();
    },

    methods: {
      downloadPayoutHistoryCsv() {
        const rows = this.payoutHistory || [];
        if (!rows.length) return;
    
        const header = ['Month', 'Status', 'Orders', 'Sales', 'Commission', 'Paid at'];
    
        const csv = [
          header.join(','),
          ...rows.map(r => [
            this.csvCell(r.monthKey),
            this.csvCell(r.paid ? 'Paid' : 'Unpaid'),
            this.csvCell(Number(r.orders || 0)),
            this.csvCell(Number(r.sales || 0).toFixed(2)),
            this.csvCell(Number(r.commission || 0).toFixed(2)),
            this.csvCell(r.paidAt || ''),
          ].join(','))
        ].join('\n');
    
        const filename = `payout-history-${this.salesKingAgentService.getCurrentUserId?.() || 'agent'}.csv`;
        this.downloadTextFile(csv, filename, 'text/csv;charset=utf-8;');
      },
    
      csvCell(value) {
        // Escape commas/quotes/newlines correctly
        const s = String(value ?? '');
        if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
        return s;
      },
    
      downloadTextFile(content, filename, mime) {
        const blob = new Blob([content], { type: mime });
        const url = URL.createObjectURL(blob);
    
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
    
        a.remove();
        URL.revokeObjectURL(url);
    },
    
      async loadPayoutHistory() {
        this.payoutHistoryLoading = true;
    
        try {
          const userId = this.salesKingAgentService.getCurrentUserId();
          if (!userId) {
            this.payoutHistory = [];
            return;
          }
    
          const user = await this.userRepository.get(userId, Shopware.Context.api);
    
          const payoutsObj = user?.customFields?.salesKingPayouts || {};
    
          const rows = Object.entries(payoutsObj).map(([monthKey, data]) => ({
            monthKey,
            paid: !!data?.paid,
            orders: Number(data?.orders || 0),
            sales: Number(data?.sales || 0),
            commission: Number(data?.commission || 0),
            paidAt: data?.paidAt || null,
          }));
    
          rows.sort((a, b) => (a.monthKey < b.monthKey ? 1 : -1));
    
          this.payoutHistory = rows;
        } catch (e) {
          this.payoutHistory = [];
          this.createNotificationError({
            title: 'Failed to load payout history',
            message: e?.message || 'Could not load payout history',
          });
        } finally {
          this.payoutHistoryLoading = false;
        }
      },

      getMonthKey() {
        const { start } = this.salesKingAgentService.getThisMonthRange();
        const d = new Date(start);
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        return `${yyyy}-${mm}`;
      },
    
      isMonthPaid(agent) {
        const monthKey = this.getMonthKey();
        const payouts = agent?.customFields?.salesKingPayouts || {};
        return !!payouts?.[monthKey]?.paid;
      },
    
      async onMarkAgentPaid(agent) {
        try {
          const range = this.salesKingAgentService.getThisMonthRange();
          const monthKey = this.getMonthKey();
    
          const payload = {
            agentId: agent.id,
            start: range.start,
            end: range.end,
            monthKey,
            sales: Number(agent.sales || 0),
            commission: Number(agent.commission || 0),
            orders: Number(agent.orders || 0),
          };
    
          await this.salesKingAgentService.markAgentMonthAsPaid(payload);
    
          this.createNotificationSuccess({
            title: 'Payout updated',
            message: `Marked ${agent.username} as paid for ${monthKey}.`,
          });
    
          await this.loadAdminAgents();
        } catch (e) {
          this.createNotificationError({
            title: 'Payout failed',
            message: e?.message || 'Failed to mark payout as paid',
          });
        }
      },
      formatCurrency(v) {
        return this.salesKingAgentService.formatCurrency(v);
      },
    
      async loadAdminAgents() {
        this.adminAgentsLoading = true;
      
        try {
          const range = this.salesKingAgentService.getThisMonthRange();
          const rows = await this.salesKingAgentService.fetchAgentsWithMonthlyTotals(range);
      
          console.log('[AdminAgents] rows length:', rows?.length, rows);
          console.log('[AdminAgents] ids:', rows?.map(r => r.id));
      
          this.adminAgents = rows;
        } catch (e) {
          this.createNotificationError({
            title: this.$tc('sales-king.dashboard.notificationTitle'),
            message: e?.message || 'Failed to load agent commissions'
          });
        } finally {
          this.adminAgentsLoading = false;
        }
      },
      
        async loadOrderKpis() {
            try {
                const userId = this.salesKingAgentService.getCurrentUserId();
                if (!userId) return;

                const { start, end } = this.salesKingAgentService.getThisMonthRange();
                const commissions = await this.salesKingAgentService.fetchCombinedCommissions(userId, { start, end });

                let totalSales = 0;
                let totalCommission = 0;

                for (const entry of commissions) {
                    totalSales += Number(entry.gross || 0);
                    totalCommission += Number(entry.commission || 0);
                }

                this.kpi.orders = commissions.length;
                this.kpi.sales = totalSales;
                this.kpi.commission = totalCommission;
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('sales-king.dashboard.notificationTitle'),
                    message: this.$tc('sales-king.dashboard.error.kpiLoad')
                });
            }
        },
        async loadCustomerData() {
            this.showCustomerSkeleton = true;
            const delay = new Promise(r => setTimeout(r, 500));
            try {
              const userId = this.salesKingAgentService.getCurrentUserId();
              if (!userId) return;
          
              const { start, end } = this.salesKingAgentService.getThisMonthRange();
              const rows = await this.salesKingAgentService.fetchOrdersForGrid(userId, { start, end });
          
              const byNameEmail = new Map();
              for (const r of rows) {
                const key = `${r.fullName || ''}::${r.email || ''}`;
                if (!byNameEmail.has(key)) {
                  byNameEmail.set(key, {
                    fullName: r.fullName || 'Unknown',
                    street: r.street || undefined,
                    zipcode: r.zipcode || undefined,
                    city: r.city || undefined,
                    customerNumber: r.customerNumber || '—',
                    groupName: r.groupName || '—',
                    email: r.email || '—',
                  });
                }
              }
          
              const list = Array.from(byNameEmail.values());
              const startIndex = (this.customerPage - 1) * this.customerLimit;
              this.customers = list.slice(startIndex, startIndex + this.customerLimit);
          
              this.kpi.customers = list.length;
              this.totalCustomers = list.length;
              this.alreadyLoadedCustomers = true;
            } catch (error) {
              this.createNotificationError({
                title: this.$tc('sales-king.dashboard.notificationTitle'),
                message: this.$tc('sales-king.dashboard.error.customerLoad')
              });
            } finally {
              await delay;
              this.showCustomerSkeleton = false;
            }
          },          

          async loadOrderData() {
            this.showOrderSkeleton = true;
            const delay = new Promise(r => setTimeout(r, 500));
          
            try {
              const userId = this.salesKingAgentService.getCurrentUserId();
              if (!userId) return;
          
              const { start, end } = this.salesKingAgentService.getThisMonthRange();
              const rows = await this.salesKingAgentService.fetchOrdersForGrid(userId, { start, end });
          
              const startIndex = (this.orderPage - 1) * this.orderLimit;
              this.orders = rows.slice(startIndex, startIndex + this.orderLimit);
              this.kpi.orders = rows.length;
            } catch (error) {
              this.createNotificationError({
                title: this.$tc('sales-king.dashboard.notificationTitle'),
                message: this.$tc('sales-king.dashboard.error.orderLoad')
              });
            } finally {
              await delay;
              this.showOrderSkeleton = false;
            }
          },

          async loadDashboardData() {
            try {
              await Promise.all([
                this.loadCustomerData(),
                this.loadOrderKpis(),
                this.loadOrderData(),
                this.loadPayoutHistory(),
              ]);
            } catch (error) {
              this.createNotificationError({
                title: this.$tc('sales-king.dashboard.notificationTitle'),
                message: error.message || this.$tc('sales-king.dashboard.error.generalError')
              });
            }
          },          

        onOrderPageChange(newPageData) {
            if (typeof newPageData === 'object') {
                if (newPageData.page) this.orderPage = parseInt(newPageData.page, 10);
                if (newPageData.limit) this.orderLimit = parseInt(newPageData.limit, 10);
            } else if (typeof newPageData === 'number' && newPageData > 0) {
                this.orderPage = newPageData;
            }
            this.loadOrderData();
        },

        onCustomerPageChange(newPageData) {
            if (typeof newPageData === 'object') {
                if (newPageData.page) this.customerPage = parseInt(newPageData.page, 10);
                if (newPageData.limit) this.customerLimit = parseInt(newPageData.limit, 10);
            } else if (typeof newPageData === 'number' && newPageData > 0) {
                this.customerPage = newPageData;
            }
            this.loadCustomerData();
        }
    }
});
