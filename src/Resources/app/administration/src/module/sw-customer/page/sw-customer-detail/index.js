import template from './sw-customer-detail.html.twig';

Shopware.Component.override('sw-customer-detail', {
    template,

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            isConvertingGuest: false,
            showConvertModal: false,
            showDuplicateModal: false,
        };
    },

    methods: {
        openConvertModal() {
            this.showConvertModal = true;
        },

        closeConvertModal() {
            this.showConvertModal = false;
        },

        async onConfirmConvertGuest() {
            this.showConvertModal = false;
            this.isConvertingGuest = true;

            try {
                const service = Shopware.Service('customerConvertApiService');
                await service.convertGuest(this.customer.id);

                this.createNotificationSuccess({
                    title: this.$tc('sales-agent.customerConvert.successTitle'),
                    message: this.$tc('sales-agent.customerConvert.successMessage'),
                });

                this.$router.go(0);
            } catch (error) {
                const status = error?.response?.status;

                if (status === 409) {
                    this.showDuplicateModal = true;
                } else {
                    const msg = error?.response?.data?.message
                        || this.$tc('sales-agent.customerConvert.errorMessage');

                    this.createNotificationError({
                        title: this.$tc('sales-agent.customerConvert.errorTitle'),
                        message: msg,
                    });
                }
            } finally {
                this.isConvertingGuest = false;
            }
        },
    },
});