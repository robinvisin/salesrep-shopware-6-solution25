const { Service } = Shopware;

Service('privileges')
  .addPrivilegeMappingEntry({
    category: 'additional_permissions',
    parent: 'salesrep',
    key: 'salesrep',
    roles: {
      price_override: {
        privileges: ['salesrep:price_override'],
        dependencies: [],
      },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'salesrep',
    roles: {
      viewer:  { privileges: [], dependencies: [] },
      editor:  { privileges: [], dependencies: ['salesrep.viewer'] },
      deleter: { privileges: [], dependencies: ['salesrep.viewer'] },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'system_config',
    roles: {
      viewer: {
        privileges: ['system_config:read'],
        dependencies: [],
      },
      editor: {
        privileges: ['system_config:update', 'system_config:read'],
        dependencies: ['system_config.viewer'],
      },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'salesrep_config',
    roles: {
      viewer: {
        privileges: [
          'salesrep_config:read',
          'user:read',
          'acl_role:read',
        ],
        dependencies: [],
      },
      editor: {
        privileges: [
          'salesrep_config:update',
          'salesrep_config:create',
          'salesrep_config:read',
          'user:read',
          'acl_role:read',
        ],
        // Also depend on system_config if this UI writes via SystemConfigService
        dependencies: ['salesrep_config.viewer', 'system_config.viewer', 'system_config.editor'],
      },
      deleter: {
        privileges: [
          'salesrep_config:delete',
          'salesrep_config:read',
        ],
        dependencies: ['salesrep_config.viewer'],
      },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'salesrep_commission',
    roles: {
      viewer:  { privileges: ['salesrep_commission:read'], dependencies: [] },
      editor:  { privileges: ['salesrep_commission:update'], dependencies: ['salesrep_commission.viewer'] },
      creator: { privileges: ['salesrep_commission:create'], dependencies: ['salesrep_commission.viewer', 'salesrep_commission.editor'] },
      deleter: { privileges: ['salesrep_commission:delete'], dependencies: ['salesrep_commission.viewer'] },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'order_light',
    roles: {
      viewer: {
        privileges: [
          'order:read',
          'order_line_item:read',
          'order_delivery:read',
          'order_transaction:read',
          'currency:read',
          'customer:read',
        ],
        dependencies: [],
      },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'infoplus_field_definition',
    roles: {
      viewer:  { privileges: ['infoplus_field_definition:read'], dependencies: [] },
      editor:  { privileges: ['infoplus_field_definition:update'], dependencies: ['infoplus_field_definition.viewer'] },
      creator: { privileges: ['infoplus_field_definition:create'], dependencies: ['infoplus_field_definition.viewer', 'infoplus_field_definition.editor'] },
      deleter: { privileges: ['infoplus_field_definition:delete'], dependencies: ['infoplus_field_definition.viewer'] },
    },
  })
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'infoplus_id_mapping',
    roles: {
      viewer: {
        privileges: ['infoplus_id_mapping:read'],
        dependencies: []
      },
      editor: {
        privileges: ['infoplus_id_mapping:update'],
        dependencies: ['salesrep.infoplus_id_mapping.viewer']
      },
      creator: {
        privileges: ['infoplus_id_mapping:create'],
        dependencies: ['salesrep.infoplus_id_mapping.viewer']
      },
      deleter: {
        privileges: ['infoplus_id_mapping:delete'],
        dependencies: ['salesrep.infoplus_id_mapping.viewer']
      }
    }})
    .addPrivilegeMappingEntry({
      category: 'permissions',
      parent: 'marketing',
      key: 'easy_coupon_integration',
      roles: {
        viewer: {
          privileges: ['neti_easy_coupon_product:read'],
          dependencies: [],
        },
      }})
      .addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'salesrep',
        key: 'lae_loyalty_points',
        roles: {
          viewer: {
            privileges: ['lae_loyalty_points:read'],
            dependencies: [],
          },
          editor: {
            privileges: ['lae_loyalty_points:update'],
            dependencies: ['lae_loyalty_points.viewer'],
          },
          deleter: {
            privileges: ['lae_loyalty_points:delete'],
            dependencies: ['lae_loyalty_points.viewer'],
          },
        },
      })
      .addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'salesrep',
        key: 'store_credit',
        roles: {
          viewer: {
            privileges: ['store_credit:read'],
            dependencies: [],
          },
          editor: {
            privileges: ['store_credit:update'],
            dependencies: ['store_credit.viewer'],
          },
          deleter: {
            privileges: ['store_credit:delete'],
            dependencies: ['store_credit.viewer'],
          },
        },
      })
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'infoplus_order_sync',
    roles: {
      viewer: {
        privileges: ['infoplus_order_sync:read'],
        dependencies: [],
      },
    },
  })
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'media',
    roles: {
      viewer: {
        privileges: ['media:read:read'],
        dependencies: [],
      },
    },
  })
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 's25_taxjar_log',
    roles: {
      viewer:  { privileges: ['s25_taxjar_log:read'], dependencies: [] },
      editor:  { privileges: ['s25_taxjar_log:update'], dependencies: ['s25_taxjar_log.viewer'] },
      creator: { privileges: ['s25_taxjar_log:create'], dependencies: ['s25_taxjar_log.viewer', 's25_taxjar_log.editor'] },
      deleter: { privileges: ['s25_taxjar_log:delete'], dependencies: ['s25_taxjar_log.viewer'] },
    },
  })
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'store_credit_history',
    roles: {
      viewer:  { privileges: ['store_credit_history:read'], dependencies: [] }
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'salesrep_order_claim_request',
    roles: {
      viewer:  { privileges: ['salesrep_order_claim_request:read'], dependencies: [] },
      editor:  { privileges: ['salesrep_order_claim_request:update'], dependencies: ['salesrep_order_claim_request.viewer'] },
      creator: { privileges: ['salesrep_order_claim_request:create'], dependencies: ['salesrep_order_claim_request.viewer', 'salesrep_order_claim_request.editor'] },
      deleter: { privileges: ['salesrep_order_claim_request:delete'], dependencies: ['salesrep_order_claim_request.viewer'] },
    },
    
  })
  
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'order_return_line_item',
    roles: {
      viewer:  { privileges: ['order_return_line_item:read'], dependencies: [] },
      editor:  { privileges: ['order_return_line_item:update'], dependencies: ['order_return_line_item.viewer'] },
      creator: { privileges: ['order_return_line_item:create'], dependencies: ['order_return_line_item.viewer', 'order_return_line_item.editor'] },
      deleter: { privileges: ['order_return_line_item:delete'], dependencies: ['order_return_line_item.viewer'] },
    },
    
  })
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'salesrep',
    key: 'notification',
    roles: {
      viewer:  { privileges: ['notification:read'], dependencies: [] },
      editor:  { privileges: ['notification:update'], dependencies: ['notification.viewer'] },
      creator: { privileges: ['notification:create'], dependencies: ['notification.viewer', 'notification.editor'] },
      deleter: { privileges: ['notification:delete'], dependencies: ['notification.viewer'] },
    },
    
  });