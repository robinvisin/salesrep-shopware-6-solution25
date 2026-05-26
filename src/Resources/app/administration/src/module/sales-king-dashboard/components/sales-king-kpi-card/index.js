import template from './sales-king-kpi-card.html.twig';
import formattingMixin from '../../../../mixin/formatting.mixin';

const { Component, Mixin } = Shopware;

Component.register('sales-king-kpi-card', {
    template,
    mixins: [formattingMixin, Mixin.getByName('notification')],
    inject: ['salesKingAgentService'],

    props: {
        title: String,
        value: {
            type: Number,
            default: 0
        },
        type: {
            type: String,
            default: 'number'
        },
        currency: {
            type: String,
            default: 'USD'
        }
    },

    data() {
        return {
            selectedDate: null,
            monthlyTotal: 0,
            totalSales: 0,
            orderList: [],
            agentConfig: null,
            isLoading: false
        };
    },

    computed: {
        formattedValue() {
            return this.type === 'currency'
                ? this.formatCurrency(this.value)
                : this.value;
        },
        formattedMonthlyTotal() {
            return this.type === 'currency'
                ? this.formatCurrency(this.monthlyTotal)
                : this.monthlyTotal;
        },
        formattedSalesTotal() {
            return this.type === 'currency'
                ? this.formatCurrency(this.totalSales)
                : this.totalSales;
        }
    },

    async created() {
        await this.loadAgentKpiData();
    },

    methods: {
        // formatCurrency(amount) {
        //     return this.salesKingAgentService.formatCurrency(amount, this.currency);
        // },

        async loadAgentKpiData() {
            const userId = this.salesKingAgentService.getCurrentUserId();
            if (!userId) return;

            this.isLoading = true;
            this.monthlyTotal = 0;
            this.totalSales = 0;
            this.orderList = [];

            try {
                const cfg = await this.salesKingAgentService.fetchAgentConfig(userId);
                const commissionPct = Number(cfg?.commission ?? 0);

                const { start, end } = this.salesKingAgentService.getThisMonthRange();

                const combined = await this.salesKingAgentService.fetchCombinedCommissions(userId, { start, end });

                for (const record of combined) {
                    const gross = Number(record.gross ?? 0);
                    const commission = Number(record.commission ?? 0);

                    this.totalSales += gross;
                    this.monthlyTotal += commission;

                    this.orderList.push({
                        orderNumber: record.orderNumber || 'N/A',
                        source: record.source,
                        commissionPct: record.commissionPct,
                        orderTotal: this.formatCurrency(gross),
                        commission: this.formatCurrency(commission),
                        date: record.orderDateTime
                    });
                }
            } catch (e) {
                this.createNotificationError?.({
                    title: this.$tc?.('sales-king.dashboard.notificationTitle') || 'Sales Agent',
                    message: this.$tc?.('sales-king.dashboard.error.kpiLoad') || 'Failed to load KPI totals.'
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
});
