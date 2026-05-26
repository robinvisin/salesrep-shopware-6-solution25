import template from './sw-settings-sales-agent-list.html.twig';
import '../../components/sw-settings-sales-agent-form'

const { Component, Mixin } = Shopware;

Component.register('sw-settings-sales-agent-list', {
    template,
    mixins: [Mixin.getByName('notification')],

    methods: {
        async onClickSave() {
            const ok = await this.$refs.salesAgentForm.saveConfig();
            if (ok) {
                this.createNotificationSuccess({
                    title: 'Sales Agent',
                    message: 'Configuration saved.'
                });
            }
        }
    }
});
