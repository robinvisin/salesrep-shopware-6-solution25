const { Service } = Shopware;

Service('privileges')
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
    key: 'abandoned_cart',
    roles: {
      viewer: {
        privileges: ['abandoned_cart:read'],
        dependencies: [],
      },
      editor: {
        privileges: ['abandoned_cart:update'],
        dependencies: ['abandoned_cart.viewer'],
      },
      creator: {
        privileges: ['abandoned_cart:create'],
        dependencies: ['abandoned_cart.viewer'],
      },
      deleter: {
        privileges: ['abandoned_cart:delete'],
        dependencies: ['abandoned_cart.viewer'],
      },
    },
  });
