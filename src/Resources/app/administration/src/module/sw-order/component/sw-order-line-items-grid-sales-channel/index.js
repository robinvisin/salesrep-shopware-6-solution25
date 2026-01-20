import { Abandoned, ensureAbandonedStore } from '../../../../state/salesrep-abandoned.state';

const { Component, State, Service, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const isUuid = (v) => /^[0-9a-f]{32}$/i.test(String(v || ''));

if (!window.__AB_HTTP_PATCHED__) window.__AB_HTTP_PATCHED__ = false;

const apiBase = () => (Shopware.Context?.api?.apiPath || '/api').replace(/\/$/, '');
const getAdminBearer = () => {
  try {
    const svc = Shopware.Service?.('loginService');
    return (
      svc?.getToken?.() ||
      Shopware?.Context?.api?.authToken?.access ||
      Shopware?.Context?.api?.bearerAuth ||
      Shopware?.Context?.api?.accessToken ||
      ''
    );
  } catch { return ''; }
};

Component.override('sw-order-line-items-grid-sales-channel', {
  inject: ['repositoryFactory', 'orderService', 'systemConfigApiService'],
  mixins: [Mixin.getByName('notification')],
  props: {
    salesChannelId: { type: String, required: true, default: '' },
    cart: { type: Object, required: true },
  },

  data() {
    return {
        agentConfig: null,    
        systemConfig: null,
      __httpPatched: false,
      __liProductMap: new Map(),  
      __unsubscribeSw: null,
      __abApplied: false,
      __abAppliedForCustomer: ''
    };
  },

  async mounted() {
    await Promise.all([
        this.fetchSystemConfig(),
        this.fetchAgentConfig(),
    ]);
    try {
      this.__rebuildLiMapFromCart(this.cart?.lineItems || []);

      this.__unsubscribeSw = State?._store?.subscribe?.((mutation, state) => {
        if (!mutation || typeof mutation.type !== 'string') return;
        if (!mutation.type.startsWith('swOrder/')) return;

        if (
          mutation.type.includes('setCartLineItems') ||
          mutation.type.includes('loadCart') ||
          mutation.type.includes('addCartLineItem') ||
          mutation.type.includes('removeCartLineItem') ||
          mutation.type.includes('updateCartLineItem') ||
          mutation.type.includes('setContextToken') ||
          mutation.type.includes('setCart')
        ) {
          const lis = state?.swOrder?.cartLineItems || [];
          this.__rebuildLiMapFromCart(lis);
        }
      });

      this._patchHttpOnce();

      await this._applyAbandonedMinimal();
    } catch (e) {
      console.error('[AbandonedMini] mounted error:', e);
    }
  },
  computed: {
    effectiveConfig() {
        if (this.agentConfig) {
            return this.agentConfig;
        }
        if (this.systemConfig) {
            return this.systemConfig;
        }
        return {
            userId: null,
            commission: 0,
            discountLimit: 0,
            createdAt: null,
        };
    },
},
  beforeDestroy() {
    try { if (typeof this.__unsubscribeSw === 'function') this.__unsubscribeSw(); } catch {}
  },

  methods: {
    async fetchSystemConfig() {
        try {
            const values = await this.systemConfigApiService.getValues('Salesrep.config');

            this.systemConfig = {
                userId: null,
                commission: values?.['Salesrep.config.commissionPercentage'] ?? 0,
                discountLimit: values?.['Salesrep.config.discountLimit'] ?? 0,
                createdAt: null,
            };
        } catch (e) {
            this.systemConfig = {
                userId: null,
                commission: 0,
                discountLimit: 0,
                createdAt: null,
            };
        }
    },
    async fetchAgentConfig() {
        try {
            const repo = this.repositoryFactory.create('salesrep_config');
            const userId = Shopware.State.get('session')?.currentUser?.id;
            if (!userId) {
                this.agentConfig = null;
                return;
            }

            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('userId', userId));
            const result = await repo.search(criteria, Shopware.Context.api);

            if (result.total > 0) {
                const config = result.first();
                this.agentConfig = {
                    userId: config.userId,
                    commission: config.commissionPercentage ?? 0,
                    discountLimit: config.discountLimit ?? 0,
                    createdAt: config.createdAt ?? null,
                };
            } else {
                this.agentConfig = null; 
            }
        } catch (error) {
            this.agentConfig = null; 
        }
    },

    checkItemPrice(price, item) {
        const cfg = this.effectiveConfig;

        if (!item?.price || !item?.priceDefinition) {
            return;
        }

        const limit = cfg.discountLimit ?? 0;
        const original = item.payload?.saOriginalUnitPrice ?? item.price.unitPrice;

        if (!original || original <= 0) {
            item.priceDefinition.price = price;
            return;
        }

        const discountPercent = ((original - price) / original) * 100;

        if (price > original) {
            this.createNotificationError({
                title: 'Invalid Price Change',
                message: 'You cannot increase the price above the original value.',
            });
            item.priceDefinition.price = original;
            return;
        }

        if (discountPercent > limit) {
            const allowedPrice = original - (original * limit / 100);
            this.createNotificationError({
                title: 'Discount Limit Exceeded',
                message: `You can only apply up to ${limit}% discount.`,
            });
            item.priceDefinition.price = allowedPrice;
            return;
        }

        item.priceDefinition.price = price;
    },
    __rebuildLiMapFromCart(lineItems) {
      this.__liProductMap.clear();
      if (!Array.isArray(lineItems)) return;
      for (const li of lineItems) {
        const lid = String(li?.id || '');
        const pid = String(li?.referencedId || li?.payload?.productId || '');
        if (lid && pid) this.__liProductMap.set(lid, pid);
      }
    },

    __lookupProductIdByLineItemId(liId) {
      const id = String(liId || '');
      if (!id) return '';
      const mapped = this.__liProductMap.get(id);
      if (isUuid(mapped)) return mapped;
      const cartLI = (State.get('swOrder')?.cartLineItems || []).find(x => String(x?.id || '') === id);
      const ref = cartLI?.referencedId || cartLI?.payload?.productId;
      return isUuid(ref) ? ref : '';
    },

    __extractUnitPrice(obj) {
      const a = Number(obj?.unitPrice);
      const b = Number(obj?.price?.unitPrice);
      const c = Number(obj?.priceDefinition?.price);
      if (Number.isFinite(a)) return a;
      if (Number.isFinite(b)) return b;
      if (Number.isFinite(c)) return c;
      return undefined;
    },

    _patchHttpOnce() {
      if (window.__AB_HTTP_PATCHED__) { this.__httpPatched = true; return; }

      const http = this.orderService?.httpClient || Service?.('httpClient');
      if (!http || typeof http.post !== 'function') return;

      const shouldNormalize = (u) => (
        /\/_proxy\/store-api\/[^/]+\/checkout\/cart\/line-item\b/i.test(u) ||  
        /\/_action\/order\/cart\/line-item\b/i.test(u) ||                      
        /\/_action\/order\/cart\/recalculate\b/i.test(u) ||                
        /\/_action\/order\/cart\/.*line-item\b/i.test(u) ||                   
        /\/checkout\/cart\/line-item\b/i.test(u) ||                         
        /\/cart\/line-item\b/i.test(u)
      );

      const normalizeArrayItem = (obj) => {
        if (!obj || typeof obj !== 'object') return obj;

        if (!obj.type) obj.type = 'product';

        const liId = String(obj.id || '');
        const ref  = String(obj.referencedId || '');

        const badRef = !isUuid(ref) || ref === liId;
        if (badRef && liId) {
          const productId = this.__lookupProductIdByLineItemId(liId);
          if (isUuid(productId)) {
            obj.referencedId = productId;   
          } else {
            delete obj.referencedId;         
          }
        }

        const unit = this.__extractUnitPrice(obj);
        if (Number.isFinite(unit)) {
          const rules =
            (Array.isArray(obj?.priceDefinition?.taxRules) && obj.priceDefinition.taxRules.length > 0)
              ? obj.priceDefinition.taxRules
              : (Array.isArray(obj?.price?.taxRules) && obj.price.taxRules.length > 0)
                ? obj.price.taxRules
                : [{ taxRate: 0, percentage: 100 }];

          obj.priceDefinition = {
            type: 'quantity',
            price: unit,
            precision: Number.isFinite(Number(obj?.precision)) ? Number(obj.precision) : 2,
            isCalculated: true,
            taxRules: rules,
          };
          obj.removable = true;
          obj.stackable = true;
        }

        return obj;
      };

      const normalizeBody = async (body) => {
        if (!body) return body;
        if (typeof body === 'string') { try { body = JSON.parse(body); } catch { return body; } }

        if (Array.isArray(body)) {
          return body.map(normalizeArrayItem);
        }
        if (Array.isArray(body?.items)) {
          body.items = body.items.map(normalizeArrayItem);
          return body;
        }
        if (body?.item && typeof body.item === 'object') {
          body.item = normalizeArrayItem(body.item);
          return body;
        }
        if (typeof body === 'object') return normalizeArrayItem(body);
        return body;
      };

      const wrap = (m) => {
        const orig = http[m].bind(http);
        http[m] = async (url, body = {}, config = {}) => {
          const u = String(url || '');
          if (shouldNormalize(u)) {
            try {
              body = await normalizeBody(body);
            } catch (e) {
              console.warn('[AbandonedMini] normalize failed:', e);
            }
          }
          return orig(url, body, config);
        };
      };

      ['post', 'put', 'patch'].forEach(wrap);
      window.__AB_HTTP_PATCHED__ = true;
      this.__httpPatched = true;
    },

    async _applyAbandonedMinimal() {
      ensureAbandonedStore();
      const payload = Abandoned.get();
    
      // Nothing to apply
      if (!payload || !Array.isArray(payload.lineItems) || !payload.lineItems.length) return;
    
      const wanted = String(payload.customerId || '');
      if (!wanted) return;
    
      // ✅ Don't "one-shot" forever. Only skip if we already applied for THIS customer.
      if (this.__abApplied && this.__abAppliedForCustomer === wanted) return;
    
      // Allow re-apply when customer changes
      this.__abApplied = false;
      this.__abAppliedForCustomer = wanted;
    
      // Wait until swOrder.customer is the one we navigated with
      for (let i = 0; i < 40; i++) {
        const cur = String(State.get('swOrder')?.customer?.id || '');
        if (cur && cur === wanted) break;
        await sleep(100);
      }
      if (String(State.get('swOrder')?.customer?.id || '') !== wanted) return;
    
      const { scId, tokenReady, token } = await this._ensureCartContext();
      if (!(scId && tokenReady && token)) return;
    
      // Add items
      for (const li of payload.lineItems) {
        const qty = Number(li.quantity || li.payload?.quantity || 1) || 1;
    
        const pid =
          li.referencedId ||
          li.productId ||
          li.payload?.productId ||
          li.payload?.referencedId ||
          '';
    
        if (!isUuid(pid)) continue;
    
        await this._addViaStoreApi(scId, token, { referencedId: pid, quantity: qty });
        await sleep(120);
      }
    
      try {
        await State.dispatch('swOrder/loadCart', { salesChannelId: scId });
      } catch (e) {}
    
      this.__rebuildLiMapFromCart(State.get('swOrder')?.cartLineItems || []);
    
      this.__abApplied = true;
      this.__abAppliedForCustomer = wanted;
    
      Abandoned.enableClear();
      Abandoned.clear();
    },

    async _ensureCartContext() {
      let scId = '';
      for (let i = 0; i < 30; i++) {
        const st = State.get('swOrder');
        scId =
          this.salesChannelId ||
          st?.salesChannelId ||
          st?.salesChannel?.id ||
          st?.customer?.salesChannelId || '';
        if (scId) break;
        await sleep(100);
      }
      if (!scId) return { scId: '', tokenReady: false, token: '' };

      let token =
        State.get('swOrder')?.contextToken ||
        State.get('swOrder')?.context?.token ||
        State.get('swOrder')?.cartToken || '';

      if (!token) {
        try {
          if (State?._store?._actions?.['swOrder/createCart']) {
            await State.dispatch('swOrder/createCart', { salesChannelId: scId });
          } else if (this.orderService?.createCart) {
            const res = await this.orderService.createCart(scId, State.get('swOrder')?.customer?.id);
            const newToken = res?.token || res?.contextToken;
            if (newToken) {
              token = newToken;
              try { State.commit('swOrder/setContextToken', newToken); } catch {}
            }
          }
        } catch { }
      }

      token =
        token ||
        State.get('swOrder')?.contextToken ||
        State.get('swOrder')?.context?.token || '';

      return { scId, tokenReady: !!token, token };
    },

    async _addViaStoreApi(scId, token, { referencedId, quantity }) {
      const base = apiBase();
      const bearer = getAdminBearer();
      const headers = {
        'Authorization': `Bearer ${bearer}`,
        'Content-Type': 'application/json',
        'sw-context-token': token,
      };
      const url = `${base}/_proxy/store-api/${scId}/checkout/cart/line-item`;
      const body = { items: [{ type: 'product', referencedId, quantity: Number(quantity) || 1 }] };

      const res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body) });
      if (!res.ok) {
        return false;
      }
      return true;
    },
  },
});
