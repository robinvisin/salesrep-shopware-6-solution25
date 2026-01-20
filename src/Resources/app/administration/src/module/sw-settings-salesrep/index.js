import './page/sw-settings-salesrep-list'
const { Module } = Shopware;

Module.register('sw-settings-salesrep', {
    type: 'plugin',
    name: 'sw-settings-sales-agent',
    title: 'salesrep-settings.general.menuTitle',
    description: 'salesrep-settings.general.moduleDescription',
    color: '#1D7AFC',
    icon: 'regular-users',
    routes: {
        index: {
            component: 'sw-settings-salesrep-list',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system.basic'
            }
        }
    },
    settingsItem: {
        group: 'plugins',
        to: 'sw.settings.salesrep.index',
        icon: 'regular-users',
        privilege: 'system.basic',
        label: 'salesrep-settings.general.menuTitle'
    }
});
