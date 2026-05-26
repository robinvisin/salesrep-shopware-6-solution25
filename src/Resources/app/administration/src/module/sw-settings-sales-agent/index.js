import './page/sw-settings-sales-agent-list'
const { Module } = Shopware;

Module.register('sw-settings-sales-agent', {
    type: 'plugin',
    name: 'sw-settings-sales-agent',
    title: 'sales-agent-settings.general.menuTitle',
    description: 'sales-agent-settings.general.moduleDescription',
    color: '#1D7AFC',
    icon: 'regular-users',
    routes: {
        index: {
            component: 'sw-settings-sales-agent-list',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system.basic'
            }
        }
    },
    settingsItem: {
        group: 'plugins',
        to: 'sw.settings.sales.agent.index',
        icon: 'regular-users',
        privilege: 'system.basic',
        label: 'sales-agent-settings.general.menuTitle'
    }
});
