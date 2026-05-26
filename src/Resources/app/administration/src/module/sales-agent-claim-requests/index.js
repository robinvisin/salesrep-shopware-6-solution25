import './page/sales-agent-claim-request-list';

const { Module } = Shopware;

Module.register('sales-agent-claim-requests', {
    type: 'plugin',
    name: 'SalesAgentClaimRequests',
    title: 'sales-agent-claim-requests.general.mainMenuItemGeneral',
    description: 'sales-agent-claim-requests.general.descriptionTextModule',
    color: '#A092F0',
    icon: 'regular-file-text',

    routes: {
        list: {
            component: 'sales-agent-claim-request-list',
            path: 'list',
        },
    },

    navigation: [{
        id: 'sales-agent-claim-requests',
        label: 'sales-agent-claim-requests.general.mainMenuItemGeneral',
        color: '#A092F0',
        path: 'sales.agent.claim.requests.list',
        icon: 'regular-file-text',
        parent: 'sw-order', 
        position: 80,
    }],
});
