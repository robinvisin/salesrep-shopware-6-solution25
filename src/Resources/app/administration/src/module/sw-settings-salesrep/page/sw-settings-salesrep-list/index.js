import template from './sw-settings-salesrep-list.html.twig';
import '../../components/sw-settings-salesrep-form'

const { Component, Mixin } = Shopware;

Component.register('sw-settings-salesrep-list', {
    template,
    mixins: [Mixin.getByName('notification')],

    methods: {
        async onClickSave() {
            const ok = await this.$refs.salesrepForm.saveConfig();
            if (ok) {
                this.createNotificationSuccess({
                    title: 'Sales Agent',
                    message: 'Configuration saved.'
                });
            }
        }
    }
});
