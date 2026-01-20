import template from './salesrep-commission-chart.html.twig';
import formattingMixin from '../../../../mixin/formatting.mixin';

const { Component, Mixin } = Shopware;

Component.register('salesrep-commission-chart', {
    template,
    inject: ['salesrepAgentService'],
    mixins: [Mixin.getByName('notification'), formattingMixin],

    props: {
        title: { type: String, default: 'Commission Trend (This Month)' },
        refreshKey: { type: [String, Number], default: 0 },
        currency: { type: String, default: 'USD' }
    },

    data() {
        return {
            commissionSeries: [],
            commissionOptions: {
                chart: { type: 'line', toolbar: { show: false } },
                xaxis: {
                    type: 'category',
                    categories: [],
                    labels: { show: false },
                    axisTicks: { show: false },
                    axisBorder: { show: false }
                },
                yaxis: {
                    labels: {
                        formatter: v => this.salesrepAgentService.formatCurrency(v, this.currency)
                    }
                },
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth' },
                tooltip: { x: { format: 'yyyy-MM-dd' } }
            },
            monthlyTotal: 0,
            isLoading: false
        };
    },

    watch: {
        refreshKey() {
            this.loadCommissionSeries();
        }
    },

    created() {
        this.loadCommissionSeries();
    },

    methods: {
        async loadCommissionSeries() {
            const userId = this.salesrepAgentService.getCurrentUserId();
            if (!userId) return;

            this.isLoading = true;
            this.monthlyTotal = 0;
            this.commissionSeries = [];
            this.commissionOptions.xaxis.categories = [];

            try {

                const cfg = await this.salesrepAgentService.fetchAgentConfig(userId);
                const commissionPct = Number(cfg?.commission ?? 0);

                const { start, end } = this.salesrepAgentService.getThisMonthRange();

                const combined = await this.salesrepAgentService.fetchCombinedCommissions(userId, { start, end });


                const byDay = new Map();
                for (const record of combined) {
                    const commissionAmount = Number(record.commission ?? 0);
                    const dateStr = record.orderDateTime ?? record.createdAt;
                    if (!dateStr) continue;

                    const d = new Date(dateStr);
                    const key = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
                    const prev = byDay.get(key) || 0;
                    byDay.set(key, prev + commissionAmount);
                }

                const cats = [];
                const vals = [];
                const s = new Date(start);
                const e = new Date(end);
                const cur = new Date(Date.UTC(s.getUTCFullYear(), s.getUTCMonth(), 1));

                while (cur <= e) {
                    const key = `${cur.getUTCFullYear()}-${String(cur.getUTCMonth() + 1).padStart(2, '0')}-${String(cur.getUTCDate()).padStart(2, '0')}`;
                    cats.push(key);
                    vals.push(Number(byDay.get(key) || 0));
                    cur.setUTCDate(cur.getUTCDate() + 1);
                }

                const total = vals.reduce((a, b) => a + b, 0);
                this.monthlyTotal = total;
                this.$emit?.('month-total', total);

                this.commissionOptions = {
                    ...this.commissionOptions,
                    xaxis: { ...this.commissionOptions.xaxis, categories: cats }
                };
                this.commissionSeries = [{ name: 'Commission', data: vals }];

            } catch (e) {
                this.createNotificationError?.({
                    title: this.$tc?.('salesrep.dashboard.notificationTitle') || 'Sales Agent',
                    message: this.$tc?.('salesrep.dashboard.error.kpiLoad') || 'Failed to load commission chart.'
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
});
