const { Service } = Shopware;

Service('privileges')
  .addPrivilegeMappingEntry({
    category: 'additional_permissions',
    parent: 'sales_king',
    key: 'sales_king',
    roles: {
      price_override: {
        privileges: ['sales_king:price_override'],
        dependencies: [],
      },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
    key: 'sales_king',
    roles: {
      viewer:  { privileges: [], dependencies: [] },
      editor:  { privileges: [], dependencies: ['sales_king.viewer'] },
      deleter: { privileges: [], dependencies: ['sales_king.viewer'] },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
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
    parent: 'sales_king',
    key: 'sales_agent_config',
    roles: {
      viewer: {
        privileges: [
          'sales_agent_config:read',
          'user:read',
          'acl_role:read',
        ],
        dependencies: [],
      },
      editor: {
        privileges: [
          'sales_agent_config:update',
          'sales_agent_config:create',
          'sales_agent_config:read',
          'user:read',
          'acl_role:read',
        ],
        // Also depend on system_config if this UI writes via SystemConfigService
        dependencies: ['sales_agent_config.viewer', 'system_config.viewer', 'system_config.editor'],
      },
      deleter: {
        privileges: [
          'sales_agent_config:delete',
          'sales_agent_config:read',
        ],
        dependencies: ['sales_agent_config.viewer'],
      },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
    key: 'sales_agent_commission',
    roles: {
      viewer:  { privileges: ['sales_agent_commission:read'], dependencies: [] },
      editor:  { privileges: ['sales_agent_commission:update'], dependencies: ['sales_agent_commission.viewer'] },
      creator: { privileges: ['sales_agent_commission:create'], dependencies: ['sales_agent_commission.viewer', 'sales_agent_commission.editor'] },
      deleter: { privileges: ['sales_agent_commission:delete'], dependencies: ['sales_agent_commission.viewer'] },
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
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
    parent: 'sales_king',
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
    parent: 'sales_king',
    key: 'infoplus_id_mapping',
    roles: {
      viewer: {
        privileges: ['infoplus_id_mapping:read'],
        dependencies: []
      },
      editor: {
        privileges: ['infoplus_id_mapping:update'],
        dependencies: ['sales_king.infoplus_id_mapping.viewer']
      },
      creator: {
        privileges: ['infoplus_id_mapping:create'],
        dependencies: ['sales_king.infoplus_id_mapping.viewer']
      },
      deleter: {
        privileges: ['infoplus_id_mapping:delete'],
        dependencies: ['sales_king.infoplus_id_mapping.viewer']
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
        parent: 'sales_king', 
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
        parent: 'sales_king',
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
    parent: 'sales_king',
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
    parent: 'sales_king',
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
    parent: 'sales_king',
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
    parent: 'sales_king',
    key: 'store_credit_history',
    roles: {
      viewer:  { privileges: ['store_credit_history:read'], dependencies: [] }
    },
  })

  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
    key: 'sales_agent_order_claim_request',
    roles: {
      viewer:  { privileges: ['sales_agent_order_claim_request:read'], dependencies: [] },
      editor:  { privileges: ['sales_agent_order_claim_request:update'], dependencies: ['sales_agent_order_claim_request.viewer'] },
      creator: { privileges: ['sales_agent_order_claim_request:create'], dependencies: ['sales_agent_order_claim_request.viewer', 'sales_agent_order_claim_request.editor'] },
      deleter: { privileges: ['sales_agent_order_claim_request:delete'], dependencies: ['sales_agent_order_claim_request.viewer'] },
    },
    
  })
  
  .addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'sales_king',
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
    parent: 'sales_king',
    key: 'notification',
    roles: {
      viewer:  { privileges: ['notification:read'], dependencies: [] },
      editor:  { privileges: ['notification:update'], dependencies: ['notification.viewer'] },
      creator: { privileges: ['notification:create'], dependencies: ['notification.viewer', 'notification.editor'] },
      deleter: { privileges: ['notification:delete'], dependencies: ['notification.viewer'] },
    },
    
  });