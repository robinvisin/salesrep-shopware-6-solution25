import './acl';
import './page/sales-king-dashboard-list';
import './page/sales-king-all-time-earnings'; 
import SalesKingAgentService from './service/sales-king-agent.service';
import SalesAgentOrderClaimApiService from './service/sales-agent-order-claim.api.service'
const { Module, Application } = Shopware;

Application.addServiceProvider('salesKingAgentService', (container) => {
    const initContainer = Application.getContainer('init');
    const repositoryFactory = container.repositoryFactory;
    const systemConfigApiService = container.systemConfigApiService;
    const httpClient = initContainer.httpClient;
    const loginService = container.loginService
     
    return new SalesKingAgentService(repositoryFactory, systemConfigApiService, httpClient, loginService);
});

Application.addServiceProvider('salesAgentOrderClaimApiService', (container) => {
  const initContainer = Application.getContainer('init');

  return new SalesAgentOrderClaimApiService(
      initContainer.httpClient,
      container.loginService
  );
});


Module.register('sales-king-dashboard', {
  type: 'plugin',
  name: 'sales-king-dashboard',
  title: 'Sales Agent Dashboard',
  description: 'Manage abandoned carts efficiently',
  color: '#A092F0',
  icon: 'default-shopping-paper-bag',

  routes: {
    list: {
      component: 'sales-king-dashboard-list',
      path: 'list',
      meta: { privilege: 'sales_king.viewer' }
    },
    earnings: {
      component: 'sales-king-all-time-earnings',
      path: 'earnings',
      meta: { privilege: 'sales_king.viewer' }
    }
  },

  navigation: [
    {
      id: 'sales-king-dashboard',
      label: 'Sales Agent Dashboard',
      color: '#A092F0',
      path: 'sales.king.dashboard.list',
      icon: 'default-shopping-paper-bag',
      parent: 'sw-order',
      position: 50,
      privilege: 'sales_king.viewer',
    },
    {
      id: 'sales-king-all-time-earnings',
      label: 'All-time earnings',
      color: '#A092F0',
      path: 'sales.king.dashboard.earnings', 
      parent: 'sales-king-dashboard',
      position: 51,
      privilege: 'sales_king.viewer',
    }
  ]
});
