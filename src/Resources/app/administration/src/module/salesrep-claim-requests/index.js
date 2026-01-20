import './page/salesrep-claim-request-list';

const { Module } = Shopware;

Module.register('salesrep-claim-requests', {
    type: 'plugin',
    name: 'SalesrepClaimRequests',
    title: 'salesrep-claim-requests.general.mainMenuItemGeneral',
    description: 'salesrep-claim-requests.general.descriptionTextModule',
    color: '#A092F0',
    icon: 'regular-file-text',

    routes: {
        list: {
            component: 'salesrep-claim-request-list',
            path: 'list',
        },
    },

    navigation: [{
        id: 'salesrep-claim-requests',
        label: 'salesrep-claim-requests.general.mainMenuItemGeneral',
        color: '#A092F0',
        path: 'salesrep.claim.requests.list',
        icon: 'regular-file-text',
        parent: 'sw-order', 
        position: 80,
    }],
});
