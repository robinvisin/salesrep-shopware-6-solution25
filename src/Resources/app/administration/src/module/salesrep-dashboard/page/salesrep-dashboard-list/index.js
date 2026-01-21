import template from './salesrep-dashboard-list.html.twig';
import '../../components';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('salesrep-dashboard-list', {
    template,
    inject: ['repositoryFactory', 'salesrepAgentService'],
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
            adminAgentColumns: [
              { property: 'username', label: 'Username' },
              { property: 'email', label: 'Email' },
              { property: 'commissionPct', label: 'Commission %' },
              { property: 'orders', label: 'Orders (This Month)' },
              { property: 'sales', label: 'Sales (This Month)' },
              { property: 'commission', label: 'Commission (This Month)' },
            ],
            customerColumns: [
                { property: 'fullName', label: this.$tc('salesrep.dashboard.customerColumns.fullName') },
                { property: 'street', label: this.$tc('salesrep.dashboard.customerColumns.street') },
                { property: 'zipcode', label: this.$tc('salesrep.dashboard.customerColumns.zipcode') },
                { property: 'city', label: this.$tc('salesrep.dashboard.customerColumns.city') },
                { property: 'customerNumber', label: this.$tc('salesrep.dashboard.customerColumns.customerNumber') },
                { property: 'groupName', label: this.$tc('salesrep.dashboard.customerColumns.groupName') },
                { property: 'email', label: this.$tc('salesrep.dashboard.customerColumns.email') },
            ],
            orderColumns: [
                { property: 'orderNumber', label: this.$tc('salesrep.dashboard.orderColumns.orderNumber') },
                { property: 'fullName', label: this.$tc('salesrep.dashboard.orderColumns.fullName') },
                { property: 'commission', label: this.$tc('salesrep.dashboard.orderColumns.commissionAmount') },
                { property: 'amountTotal', label: this.$tc('salesrep.dashboard.orderColumns.amountTotal') },
                { property: 'paymentMethod', label: this.$tc('salesrep.dashboard.orderColumns.paymentMethod') },
                { property: 'stateMachineState.name', label: this.$tc('salesrep.dashboard.orderColumns.status') },
                { property: 'orderDate', label: this.$tc('salesrep.dashboard.orderColumns.orderDate') },
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
        if (this.isAdminUser) {
          this.loadAdminAgents();
          return;
        }
        this.loadDashboardData();
    },

    methods: {
      getMonthKey() {
        const { start } = this.salesrepAgentService.getThisMonthRange();
        const d = new Date(start);
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        return `${yyyy}-${mm}`;
      },
    
      isMonthPaid(agent) {
        const monthKey = this.getMonthKey();
        const payouts = agent?.customFields?.salesrepPayouts || {};
        return !!payouts?.[monthKey]?.paid;
      },
    
      async onMarkAgentPaid(agent) {
        try {
          const range = this.salesrepAgentService.getThisMonthRange();
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
    
          await this.salesrepAgentService.markAgentMonthAsPaid(payload);
    
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
        return this.salesrepAgentService.formatCurrency(v);
      },
    
      async loadAdminAgents() {
        this.adminAgentsLoading = true;
      
        try {
          const range = this.salesrepAgentService.getThisMonthRange();
          const rows = await this.salesrepAgentService.fetchAgentsWithMonthlyTotals(range);

          console.warn('[AdminAgents] rows length:', rows?.length, rows);
          console.warn('[AdminAgents] ids:', rows?.map(r => r.id));
      
          this.adminAgents = rows;
        } catch (e) {
          this.createNotificationError({
            title: this.$tc('salesrep.dashboard.notificationTitle'),
            message: e?.message || 'Failed to load agent commissions'
          });
        } finally {
          this.adminAgentsLoading = false;
        }
      },
      
        async loadOrderKpis() {
            try {
                const userId = this.salesrepAgentService.getCurrentUserId();
                if (!userId) return;

                const { start, end } = this.salesrepAgentService.getThisMonthRange();
                const commissions = await this.salesrepAgentService.fetchCombinedCommissions(userId, { start, end });

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
                    title: this.$tc('salesrep.dashboard.notificationTitle'),
                    message: this.$tc('salesrep.dashboard.error.kpiLoad')
                });
            }
        },
        async loadCustomerData() {
            this.showCustomerSkeleton = true;
            const delay = new Promise(r => setTimeout(r, 500));
            try {
              const userId = this.salesrepAgentService.getCurrentUserId();
              if (!userId) return;
          
              const { start, end } = this.salesrepAgentService.getThisMonthRange();
              const rows = await this.salesrepAgentService.fetchOrdersForGrid(userId, { start, end });
          
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
                title: this.$tc('salesrep.dashboard.notificationTitle'),
                message: this.$tc('salesrep.dashboard.error.customerLoad')
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
              const userId = this.salesrepAgentService.getCurrentUserId();
              if (!userId) return;
          
              const { start, end } = this.salesrepAgentService.getThisMonthRange();
              const rows = await this.salesrepAgentService.fetchOrdersForGrid(userId, { start, end });
          
              const startIndex = (this.orderPage - 1) * this.orderLimit;
              this.orders = rows.slice(startIndex, startIndex + this.orderLimit);
              this.kpi.orders = rows.length;
            } catch (error) {
              this.createNotificationError({
                title: this.$tc('salesrep.dashboard.notificationTitle'),
                message: this.$tc('salesrep.dashboard.error.orderLoad')
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
                    this.loadOrderData()
                ]);
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('salesrep.dashboard.notificationTitle'),
                    message: error.message || this.$tc('salesrep.dashboard.error.generalError')
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
