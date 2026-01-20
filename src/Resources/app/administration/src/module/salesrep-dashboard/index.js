import './acl';
import './page/salesrep-dashboard-list';
import './page/salesrep-all-time-earnings';
import SalesrepAgentService from './service/salesrep-agent.service';
import SalesrepOrderClaimApiService from './service/salesrep-order-claim.api.service'
const { Module, Application } = Shopware;

Application.addServiceProvider('salesrepAgentService', (container) => {
    const initContainer = Application.getContainer('init');
    const repositoryFactory = container.repositoryFactory;
    const systemConfigApiService = container.systemConfigApiService;
    const httpClient = initContainer.httpClient;
    const loginService = container.loginService
     
    return new SalesrepAgentService(repositoryFactory, systemConfigApiService, httpClient, loginService);
});

Application.addServiceProvider('salesrepOrderClaimApiService', (container) => {
  const initContainer = Application.getContainer('init');

  return new salesrepOrderClaimApiService(
      initContainer.httpClient,
      container.loginService
  );
});


Module.register('salesrep-dashboard', {
  type: 'plugin',
  name: 'salesrep-dashboard',
  title: 'Salesrep Dashboard',
  description: 'Manage abandoned carts efficiently',
  color: '#A092F0',
  icon: 'default-shopping-paper-bag',

  routes: {
    list: {
      component: 'salesrep-dashboard-list',
      path: 'list',
      meta: { privilege: 'salesrep.viewer' }
    },
    earnings: {
      component: 'salesrep-all-time-earnings',
      path: 'earnings',
      meta: { privilege: 'salesrep.viewer' }
    }
  },

  navigation: [
    {
      id: 'salesrep-dashboard',
      label: 'Salesrep Dashboard',
      color: '#A092F0',
      path: 'salesrep.dashboard.list',
      icon: 'default-shopping-paper-bag',
      parent: 'sw-order',
      position: 50,
      privilege: 'salesrep.viewer',
    },
    {
      id: 'salesrep-all-time-earnings',
      label: 'All-time earnings',
      color: '#A092F0',
      path: 'salesrep.dashboard.earnings',
      parent: 'salesrep-dashboard',
      position: 51,
      privilege: 'salesrep.viewer',
    }
  ]
});
