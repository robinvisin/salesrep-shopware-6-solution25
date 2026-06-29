const { Criteria } = Shopware.Data;

export default class SalesKingAgentService {
    constructor(repositoryFactory, systemConfigApiService, httpClient, loginService) {
        if (!repositoryFactory?.create) {
            throw new Error('repositoryFactory not injected properly');
        }

        this.repositoryFactory = repositoryFactory;
        this.systemConfigApiService = systemConfigApiService;
        this.httpClient = httpClient;
        this.loginService = loginService;
        this.orderRepository = this.repositoryFactory.create('order');
        this.configRepository = this.repositoryFactory.create('sales_agent_config');
        this.commissionRepository = this.repositoryFactory.create('sales_agent_commission');
        this.stateRepo = this.repositoryFactory.create('state_machine_state');
        this.userRepository = this.safeCreateRepo(this.repositoryFactory, 'user');
    }

    safeCreateRepo(factory, name) {
        try { return factory.create(name); } catch (e) { return null; }
    }

    markAgentMonthAsPaid(payload) {
        if (!this.httpClient?.post) {
          throw new Error('[salesKingAgentService] httpClient is missing (DI/wiring issue)');
        }
      
        return this.httpClient
          .post('/_action/sales-king/agents/mark-paid', payload, {
            headers: this.getBasicHeaders(),
          })
          .then((res) => res.data);
      }
      
      getBasicHeaders() {
        if (this.loginService?.getBasicHeaders) {
          return this.loginService.getBasicHeaders();
        }
      
        const token = this.loginService?.getToken?.();
        return {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        };
      }
      
    async fetchAgentUsers() {
        if (!this.configRepository) throw new Error('configRepository not available');
        if (!this.userRepository) throw new Error('userRepository not available');
      
        const pageSize = 500;
        let page = 1;
        const configs = [];
      
        while (true) {
          const criteria = new Criteria(page, pageSize);
      
          const res = await this.configRepository.search(criteria, Shopware.Context.api);
          const rows = this.normalizeDalResult(res);
          if (!rows.length) break;
      
          configs.push(...rows);
          if (rows.length < pageSize) break;
          page++;
        }
      
        const userIds = [...new Set(configs.map(c => c.userId).filter(Boolean))];
        if (!userIds.length) return [];
      
        const users = await Promise.all(
          userIds.map(id => this.userRepository.get(id, Shopware.Context.api).catch(() => null))
        );
        const byId = new Map(users.filter(Boolean).map(u => [u.id, u]));
      
        return configs.map(cfg => {
          const u = byId.get(cfg.userId) || null;
          return {
            id: cfg.userId,
            userId: cfg.userId,
            customFields: u?.customFields || {},
            username: u?.username || '—',
            firstName: u?.firstName || '',
            lastName: u?.lastName || '',
            email: u?.email || '—',
            isAdmin: !!u?.admin,
            commissionPct: this.safeNumber(cfg.commissionPercentage ?? cfg.commission ?? 0),
          };
        });
      }
      

    async fetchAgentsWithMonthlyTotals(range) {
        const agents = await this.fetchAgentUsers();
        if (!agents.length) return [];

        const { start, end } = range;

        const rows = await Promise.all(
            agents.map(async (a) => {
                const commissions = await this.fetchCombinedCommissions(a.userId, { start, end });

                let sales = 0;
                let commission = 0;

                for (const entry of commissions) {
                    sales += this.safeNumber(entry.gross || entry.amountTotal || 0, 0);
                    commission += this.safeNumber(entry.commission || 0, 0);
                }

                return {
                    ...a,
                    orders: commissions.length,
                    sales,
                    commission
                };
            })
        );

        return rows.filter(r => !r.isAdmin).sort((a, b) => b.commission - a.commission);
    }

    async fetchAgentsWithLifetimeTotals() {
        const agents = await this.fetchAgentUsers();
        if (!agents.length) return [];
    
        const range = {
            start: '1970-01-01T00:00:00Z',
            end: new Date().toISOString()
        };
    
        const rows = await Promise.all(
            agents.map(async (a) => {
                const records = await this.fetchCombinedCommissions(a.userId, range);
    
                let sales = 0;
                let commission = 0;
    
                for (const r of records) {
                    const gross = this.safeNumber(r.gross ?? r.amountTotal ?? 0, 0);
                    if (gross <= 0) continue;
    
                    sales += gross;
                    commission += this.safeNumber(r.commission ?? 0, 0);
                }
    
                return {
                    ...a,
                    lifetimeSales: Number(sales.toFixed(2)),
                    lifetimeCommission: Number(commission.toFixed(2)),
                    lifetimeOrders: records.length
                };
            })
        );
    
        return rows
            .filter(r => !r.isAdmin)
            .sort((a, b) => b.lifetimeCommission - a.lifetimeCommission);
    }
    
    safeNumber(v, fallback = 0) {
        const n = Number(v);
        return Number.isFinite(n) ? n : fallback;
    }

    formatCurrency(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '$0.00';
  
        const truncated = Math.trunc(n * 100) / 100;
  
        return new Intl.NumberFormat('en-US', {
          style: 'currency',
          currency: 'USD',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }).format(truncated);
      }

    calculateCommission(amount, pct) {
        const a = this.safeNumber(amount, 0);
        const p = this.safeNumber(pct, 0);
        return (a * p) / 100;
    }

    normalizeDalResult(coll) {
        if (!coll) return [];
        if (Array.isArray(coll)) return coll;
        if (Array.isArray(coll.elements)) return coll.elements;
        try { return [...coll]; } catch (_) { return []; }
    }

    getCurrentUserId() {
        const user = Shopware.State.get('session')?.currentUser;
        return user?.id || null;
    }

    getThisMonthRange(includeFuture = false) {
        const now = new Date();
        const start = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1, 0, 0, 0, 0));
        const end = includeFuture
            ? new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 0, 23, 59, 59, 999))
            : new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), 23, 59, 59, 999));
        return { start: start.toISOString(), end: end.toISOString() };
    }

    yearRange(year) {
        const start = new Date(Date.UTC(year, 0, 1, 0, 0, 0, 0)).toISOString();
        const end = new Date(Date.UTC(year, 11, 31, 23, 59, 59, 999)).toISOString();
        return { start, end };
    }

    async fetchAgentConfig(userId) {
        const criteria = new Criteria();
        criteria.addFilter(Criteria.equals('userId', userId));
        const result = await this.configRepository.search(criteria, Shopware.Context.api);

        if (result.total > 0) {
            const config = result.first();
            return {
                userId: config.userId,
                commission: Number(config.commissionPercentage ?? 0),
                discountLimit: Number(config.discountLimit ?? 0),
                createdAt: config.createdAt ?? null
            };
        }
        return null;
    }

    async resolveStateNames(ids) {
        if (!ids?.length) return {};
        const criteria = new Criteria(1, ids.length);
        criteria.addFilter(Criteria.equalsAny('id', ids));
        const result = await this.stateRepo.search(criteria, Shopware.Context.api);
        const map = {};
        for (const s of result) {
            map[s.id] = s.technicalName;
        }
        return map;
    }

    isInvalidState(stateName) {
        return ['cancelled', 'returned', 'refunded'].includes(stateName);
    }

    async fetchStoredCommissions(agentId, { start, end }) {
        const pageSize = 500;
        let page = 1;
        const commissions = [];
        let suppressedByState = 0;

        while (true) {
            const criteria = new Criteria(page, pageSize);
            criteria.addAssociation('order');
            criteria.addAssociation('order.deliveries');
            criteria.addAssociation('order.lineItems');
            criteria.addFilter(Criteria.equals('agentId', agentId));
            criteria.addFilter(Criteria.range('order.orderDateTime', { gte: start, lte: end }));
            criteria.addSorting(Criteria.sort('order.orderDateTime', 'DESC'));

            const rows = await this.commissionRepository.search(criteria, Shopware.Context.api);
            if (!rows || !rows.length) break;

            const allStateIds = [];
            for (const r of rows) {
                const o = r.order;
                if (o?.stateId) allStateIds.push(o.stateId);
                this.normalizeDalResult(o?.deliveries).forEach(d => d?.stateId && allStateIds.push(d.stateId));
            }
            const stateMap = await this.resolveStateNames([...new Set(allStateIds)]);

            for (const r of rows) {
                const order = r.order;
                const orderId = (order?.id ?? r.orderId)?.replace(/^0x/, '');
                const commissionAmount = Number(r?.commissionAmount ?? NaN);
                if (!Number.isFinite(commissionAmount)) continue;

                const orderState = stateMap[order?.stateId];
                const deliveryStates = this.normalizeDalResult(order?.deliveries).map(d => stateMap[d.stateId]);

                const invalidState =
                    this.isInvalidState(orderState) || deliveryStates.some(s => this.isInvalidState(s));
                const finalCommission = invalidState ? 0 : commissionAmount;
                if (finalCommission === 0 && commissionAmount > 0) suppressedByState++;

                commissions.push({
                    source: 'stored',
                    orderId,
                    orderNumber: order?.orderNumber ?? '(no order number)',
                    orderDateTime: order?.orderDateTime ?? r.createdAt,
                    commission: finalCommission,
                    commissionPct: Number(r?.commissionPercentApplied ?? 0),
                    effectiveDiscountPercent: Number(r?.effectiveDiscountPercent ?? 0),
                    gross: this.safeNumber(order?.amountNet ?? 0),
                    amountTotal: this.safeNumber(order?.amountTotal ?? order?.amountNet ?? 0),
                    excludedByAgentEmail: Boolean(r?.excludedByAgentEmail)
                });
            }

            if (rows.length < pageSize) break;
            page++;
        }

        return commissions;
    }

    async fetchSplitCommissionsFromOrders(agentId, { start, end }) {
        const pageSize = 500;
        let page = 1;
        const results = [];
        const allStateIds = [];
        const allDeliveryStateIds = [];
    
        while (true) {
            const criteria = new Criteria(page, pageSize);
    
            criteria.addFilter(Criteria.equals('customFields.sales_agent_split_agent_id', agentId));
    
            criteria.addFilter(Criteria.range('orderDateTime', { gte: start, lte: end }));
    
            criteria.addAssociation('deliveries');
            criteria.addAssociation('stateMachineState');
            criteria.addAssociation('deliveries.stateMachineState');
    
            const orders = await this.orderRepository.search(criteria, Shopware.Context.api);
            if (!orders || !orders.length) break;
    
            for (const order of orders) {
                const cf = order.customFields || {};
    
                // ✅ your PHP writes BOTH of these (same value)
                const splitCommission =
                    this.safeNumber(cf.sales_agent_commission_split ?? cf.sales_agent_split_amount, 0);
    
                if (splitCommission <= 0) {
                    continue;
                }
    
                if (order.stateId) allStateIds.push(order.stateId);
    
                this.normalizeDalResult(order.deliveries).forEach(d => {
                    if (d?.stateId) allDeliveryStateIds.push(d.stateId);
                });
    
                results.push({
                    source: 'split',
                    orderId: order.id?.replace(/^0x/, ''),
                    orderNumber: order.orderNumber ?? '(no order number)',
                    orderDateTime: order.orderDateTime ?? order.createdAt,
                    commissionAmount: splitCommission,
                    commission: splitCommission,
                    commissionPct: this.safeNumber(cf.sales_agent_split_percent, 0),
                    effectiveDiscountPercent: 0,
                    gross: this.safeNumber(order.amountNet ?? 0),
                    amountTotal: this.safeNumber(order.amountTotal ?? order.amountNet ?? 0),
                    excludedByAgentEmail: false,
                    __orderStateId: order.stateId || null,
                    __deliveryStateIds: this.normalizeDalResult(order.deliveries)
                        .map(d => d?.stateId)
                        .filter(Boolean),
                });
            }
    
            if (orders.length < pageSize) break;
            page++;
        }
    
        const stateMap = await this.resolveStateNames([
            ...new Set([...allStateIds, ...allDeliveryStateIds]),
        ]);
    
        return results.map(r => {
            const orderState = r.__orderStateId ? stateMap[r.__orderStateId] : null;
            const deliveryStates = (r.__deliveryStateIds || []).map(id => stateMap[id]).filter(Boolean);
    
            const invalid =
                (orderState && this.isInvalidState(orderState)) ||
                deliveryStates.some(s => this.isInvalidState(s));
    
            const out = { ...r };
            delete out.__orderStateId;
            delete out.__deliveryStateIds;
    
            if (invalid) {
                out.commission = 0;
            }
    
            return out;
        });
    }
    

    async hydrateCommissionsWithOrders(commissions) {
        const ids = Array.from(new Set(commissions.map(c => c.orderId).filter(Boolean)));
        if (!ids.length) return commissions;
        const crit = new Criteria(1, ids.length);
        crit.addFilter(Criteria.equalsAny('id', ids));

        crit.addAssociation('orderCustomer');
        crit.addAssociation('orderCustomer.customer');
        crit.addAssociation('orderCustomer.customer.defaultBillingAddress');
        crit.addAssociation('orderCustomer.customer.group');
        crit.addAssociation('billingAddress');
        crit.addAssociation('transactions.paymentMethod');
        crit.addAssociation('stateMachineState');
        crit.addAssociation('currency');

        const orders = await this.orderRepository.search(crit, Shopware.Context.api);
        const byId = new Map(orders.map(o => [o.id?.replace(/^0x/, ''), o]));

        return commissions.map(c => {
            const o = byId.get(c.orderId);
            const oc = o?.orderCustomer;
            const cust = oc?.customer;
            const addr = o?.billingAddress || cust?.defaultBillingAddress || null;
            const email = oc?.email || cust?.email || '—';
            const fullName =
                (cust
                    ? `${cust.firstName || ''} ${cust.lastName || ''}`.trim()
                    : `${oc?.firstName || ''} ${oc?.lastName || ''}`.trim()) ||
                oc?.email ||
                'Unknown';

            const tx = this.normalizeDalResult(o?.transactions)?.[0] || null;

            return {
                ...c,
                orderNumber: o?.orderNumber ?? c.orderNumber ?? '(no order number)',
                orderDateTime: o?.orderDateTime ?? c.orderDateTime ?? null,
                fullName,
                email,
                paymentMethod: tx?.paymentMethod?.name || 'n/a',
                stateMachineState: o?.stateMachineState || null,
                currencyIso: o?.currency?.isoCode || 'USD',
                street: addr?.street || null,
                zipcode: addr?.zipcode || null,
                city: addr?.city || null,
                customerNumber: cust?.customerNumber || '—',
                groupName: cust?.group?.name || '—',
                amountTotal: this.safeNumber(o?.amountTotal ?? c.amountTotal ?? c.gross ?? 0)
            };
        });
    }

    async fetchOrdersForGrid(userId, range) {
        const stored = await this.fetchStoredCommissions(userId, range);
        const split  = await this.fetchSplitCommissionsFromOrders(userId, range);
        const combined = this.mergeStoredAndLegacySplitCommissions(stored, split);
        return this.hydrateCommissionsWithOrders(combined);
    }

    async fetchCombinedCommissions(userId, { start, end }) {
        const stored = await this.fetchStoredCommissions(userId, { start, end });
        const split  = await this.fetchSplitCommissionsFromOrders(userId, { start, end });
        const all = this.mergeStoredAndLegacySplitCommissions(stored, split);

        const hydrated = await this.hydrateCommissionsWithOrders(all);
        hydrated.sort((a, b) => new Date(b.orderDateTime || 0) - new Date(a.orderDateTime || 0));
        return hydrated;
    }

    mergeStoredAndLegacySplitCommissions(stored, split) {
        const storedOrderIds = new Set((stored || []).map(row => row.orderId).filter(Boolean));
        const legacyOnlySplitRows = (split || []).filter(row => !storedOrderIds.has(row.orderId));

        return [...(stored || []), ...legacyOnlySplitRows];
    }
}
