import './module/sales-king-dashboard';
import './module/sw-settings-sales-agent';
import './module/sw-users-permissions/page/sw-users-permissions-user-detail/index';
import './module/sw-order';
import './module/sales-king-abandoned-cart';
import './module/sw-customer';
import './app/component/sw-search-bar-item';
import './app/component/structure/sw-admin-menu/index';
import './module/sw-order/view/sw-order-create-notes/index'
import './module/sw-order/view/sw-order-detail-notes'
import './module/sw-order/view/sw-order-split-commission'
import './module/sales-agent-claim-requests'
import AbandonedCartApiService from './module/core/service/abandoned-cart.api.service';
import OrderClaimRequestApiService from './core/service/order-claim-request.api.service';
import CustomerConvertApiService from './core/service/customer-convert.api.service';
import { ensureAbandonedStore } from './state/sales-agent-abandoned.state';
import AbandonedCartReminderService from './core/service/abandoned-cart-reminder.service';

const { Application, Service, Module} = Shopware;

Application.addInitializer('sales-agent-abandoned-store', () => {
    ensureAbandonedStore();
});
const initContainer = Application.getContainer('init');

Application.addServiceProvider('abandonedCartApiService', (container) => {
    return new AbandonedCartApiService(initContainer.httpClient, container.loginService);
});
Application.addServiceProvider('orderClaimRequestApiService', (container) => {
    return new OrderClaimRequestApiService(initContainer.httpClient, container.loginService);
});
Application.addServiceProvider('customerConvertApiService', (container) => {
    return new CustomerConvertApiService(initContainer.httpClient, container.loginService);
});
Service().register('abandonedCartReminderService', () => {
    const loginService = Service('loginService'); 

    return new AbandonedCartReminderService(
        initContainer.httpClient,
        loginService
    );
});
Application.viewInitialized.then(() => {
    const searchTypeService = Service('searchTypeService');
    if (!searchTypeService) return;

    searchTypeService.upsertType('sales_agent_abandoned_cart', {
        entityName: 'sales_agent_abandoned_cart',
        label: 'Abandoned Carts',
        labelSnippet: 'abandoned-cart-admin.searchTypeLabel',
        placeholderSnippet: 'abandoned-cart-admin.searchPlaceholder',
        listingRoute: 'abandoned.cart.list',
        icon: 'default-shopping-paper-bag',
        privilege: 'abandoned_cart:read',
    });
});

Module.register('sw-order-detail-notes', {
    routeMiddleware(next, currentRoute) {
        const customRouteName = 'sw-order-detail-notes';

        if (
            currentRoute.name === 'sw.order.detail' &&
            currentRoute.children.every((r) => r.name !== customRouteName)
        ) {
            currentRoute.children.push({
                name: customRouteName,
                path: '/sw/order/detail/:id/notes',
                component: 'sw-order-detail-notes',
                meta: {
                    parentPath: 'sw.order.index',
                },
            });
        }

        next(currentRoute);
    },
});

Module.register('sw-order-create-notes', {
    routeMiddleware(next, currentRoute) {
        const customRouteName = 'sw.order.create.notes';

        if (
            currentRoute.name === 'sw.order.create' &&
            !currentRoute.children.some((child) => child.name === customRouteName)
        ) {
            currentRoute.children.push({
                name: customRouteName,
                path: '/sw/order/create/notes',
                component: 'sw-order-create-notes',
                meta: { parentPath: 'sw.order.index' },
            });
        }

        next(currentRoute);
    },
});

Module.register('sw-order-create-split-commission', {
    routeMiddleware(next, currentRoute) {
        const customRouteName = 'sw.order.create.splitCommission';

        if (
            currentRoute.name === 'sw.order.create' &&
            !currentRoute.children.some((child) => child.name === customRouteName)
        ) {
            currentRoute.children.push({
                name: customRouteName,
                path: 'split-commission',
                component: 'sw-order-split-commission',
                meta: { parentPath: 'sw.order.index' },
            });
        }

        next(currentRoute);
    },
});

Module.register('sw-order-detail-split-commission', {
    routeMiddleware(next, currentRoute) {
        const customRouteName = 'sw-order-detail-split-commission';

        if (
            currentRoute.name === 'sw.order.detail' &&
            currentRoute.children.every((r) => r.name !== customRouteName)
        ) {
            currentRoute.children.push({
                name: customRouteName,
                path: '/sw/order/detail/:id/split-commission',
                component: 'sw-order-detail-split-commission',
                meta: {
                    parentPath: 'sw.order.index',
                },
            });
        }

        next(currentRoute);
    },
});