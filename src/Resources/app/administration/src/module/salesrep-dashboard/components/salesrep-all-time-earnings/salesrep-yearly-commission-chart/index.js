import template from './salesrep-yearly-commission-chart.html.twig';

const { Component, Mixin } = Shopware;

Component.register('salesrep-yearly-commission-chart', {
    template,
    inject: ['salesrepAgentService'],
    mixins: [Mixin.getByName('notification')],

    props: {
        defaultYear: { type: Number, default: () => new Date().getUTCFullYear() },
        years: {
            type: Array,
            default: () => {
                const nowY = new Date().getUTCFullYear();
                return Array.from({ length: 6 }, (_, i) => nowY - i);
            }
        },
        currency: { type: String, default: 'USD' },
        title: { type: String, default: 'Commission by Year' }
    },

    data() {
        return {
            isLoading: false,
            selectedYear: this.defaultYear,
            series: [],
            options: {
                chart: {
                    type: 'line',
                    toolbar: { show: false },
                    animations: { enabled: true, easing: 'easeinout', speed: 600 }
                },
                xaxis: {
                    type: 'category',
                    categories: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                    axisTicks: { show: false },
                    axisBorder: { show: false }
                },
                yaxis: {
                    labels: {
                        formatter: v => this.salesrepAgentService.formatCurrency(v, this.currency)
                    }
                },
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 3 },
                tooltip: {
                    x: { show: true },
                    y: {
                        formatter: v => this.salesrepAgentService.formatCurrency(v, this.currency)
                    }
                },
                legend: { show: false }
            }
        };
    },

    created() {
        this.loadYear(this.selectedYear);
    },

    watch: {
        selectedYear(year) {
            this.loadYear(year);
        }
    },

    computed: {
        yearOptions() {
            return this.years.map(y => ({ label: String(y), value: y }));
        }
    },

    methods: {
        async loadYear(year) {
            const userId = this.salesrepAgentService.getCurrentUserId();
            if (!userId) {
                this.series = [{ name: 'Commission', data: Array(12).fill(0) }];
                return;
            }

            this.isLoading = true;
            try {
                const { start, end } = this.salesrepAgentService.yearRange(year);
                const commissions = await this.salesrepAgentService.fetchCombinedCommissions(userId, { start, end });

                const monthlyTotals = Array(12).fill(0);

                for (const c of commissions) {
                    const dateStr = c.orderDateTime ?? c.createdAt;
                    if (!dateStr) continue;

                    const month = new Date(dateStr).getUTCMonth();
                    monthlyTotals[month] += Number(c.commission ?? 0);
                }

                this.series = [{
                    name: `Commission ${year}`,
                    data: monthlyTotals.map(v => Number(v.toFixed(2)))
                }];
            } catch (e) {
                this.createNotificationError({
                    title: this.$tc?.('salesrep.dashboard.notificationTitle') || 'Sales Agent',
                    message: this.$tc?.('salesrep.dashboard.error.kpiLoad') || 'Failed to load yearly data'
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
});
