import './page/abandoned-cart-list';
import './acl';
const {Module, Application, Service} = Shopware;

 Module.register('abandoned-cart', {
    type: 'plugin',
    name: 'abandoned-cart',
    title: 'Abandoned Carts',
    description: 'Manage abandoned carts efficiently',
    color: '#A092F0',
    icon: 'regular-shopping-bag',
    favicon: 'icon-module-orders.png',
    entity: 'salesrep_abandoned_cart',
    defaultSearchConfiguration: {
        _searchable: true,
        firstName: { _searchable: true, _score: 500 },
        lastName: { _searchable: true, _score: 500 },
        cartToken: { _searchable: true, _score: 400 },
        customerId: { _searchable: true, _score: 450 },
      },
         
    privileges: [
        'abandoned_cart:read',
        'abandoned_cart:create',
        'abandoned_cart:update',
        'abandoned_cart:delete',
    ],
    routes: {
        list: {
            component: 'abandoned-cart-list',
            path: 'list',
            meta: {
                privilege: 'abandoned_cart:read',
                searchType: 'salesrep_abandoned_cart',
                parentPath: 'sw.order.index',  
            },
        },
    },
    navigation: [
        {
            id: 'abandoned-cart',
            label: 'Abandoned Carts',
            color: '#A092F0',
            path: 'abandoned.cart.list',
            icon: 'default-shopping-paper-bag',
            privilege: 'abandoned_cart:read',
            parent: 'sw-order',
            position: 50,
        },
    ],
});
Application.addServiceProviderDecorator('searchTypeService', (searchTypeService) => {
    if (typeof searchTypeService.getType !== 'function') {
        const fallback =
            searchTypeService.getSearchType ||
            searchTypeService.getTypeByName ||
            searchTypeService.get;

        if (typeof fallback === 'function') {
            searchTypeService.getType = fallback.bind(searchTypeService);
        }
    }

    if (typeof searchTypeService.upsertType === 'function') {
        searchTypeService.upsertType('salesrep_abandoned_cart', {
            entityName: 'salesrep_abandoned_cart',
            placeholderSnippet: 'abandoned-cart-admin.searchPlaceholder',
            listingRoute: 'abandoned-cart.list',
            hideOnGlobalSearchBar: true,
        });
    }

    return searchTypeService;
});
