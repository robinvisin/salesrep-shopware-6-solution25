/**
 * Abandoned-cart hand-off state.
 *
 * Shopware 6.7 replaced Vuex with Pinia, so this is a Pinia store registered through
 * Shopware.Store. Pinia has no mutations: what were mutations are now actions, and state is
 * assigned directly inside them. The persisted sessionStorage behaviour is unchanged.
 */
const NS = 'salesAgentAbandoned';
const KEY = 'salesAgentAbandoned:payload';

function readPersisted() {
  try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch { return null; }
}
function writePersisted(val) {
  try {
    if (val == null) sessionStorage.removeItem(KEY);
    else sessionStorage.setItem(KEY, JSON.stringify(val));
  } catch {}
}

function nowIso() { return new Date().toISOString(); }

export function ensureAbandonedStore() {
  const S = Shopware?.Store;
  if (!S) return;
  // Shopware.Store.get throws when the id is unknown, so existence is checked by id list.
  try { if (S.get(NS)) return; } catch (_) {}
  S.register({
    id: NS,
    state: () => ({
      payload: readPersisted(),
      allowClear: false,
      lastTouchedAt: null,
    }),
    getters: {
      // Pinia getters take state as their argument, same as Vuex.
      hasPayload: (s) => !!s.payload,
      currentPayload: (s) => s.payload,
      canClear: (s) => s.allowClear,
      touchedAt: (s) => s.lastTouchedAt,
    },
    actions: {
      // Former mutations. Pinia assigns state directly through `this`.
      setPayload(payload) {
        this.payload = payload ?? null;
        this.lastTouchedAt = nowIso();
        writePersisted(this.payload);
      },
      enableClear() { this.allowClear = true; },
      disableClear() { this.allowClear = false; },
      clear() {
        if (!this.allowClear) return;
        this.payload = null;
        this.lastTouchedAt = nowIso();
        writePersisted(null);
      },
    },
  });
}

/** Unchanged public surface, so callers outside this file did not have to move. */
export const Abandoned = {
  ns: NS,
  set(payload) {
    ensureAbandonedStore();
    Shopware.Store.get(NS).setPayload(payload);
  },
  get() {
    ensureAbandonedStore();
    return Shopware.Store.get(NS)?.payload ?? null;
  },
  enableClear() {
    ensureAbandonedStore();
    Shopware.Store.get(NS).enableClear();
  },
  disableClear() {
    ensureAbandonedStore();
    Shopware.Store.get(NS).disableClear();
  },
  clear() {
    ensureAbandonedStore();
    Shopware.Store.get(NS).clear();
  },
};

let _lastSnapshot = null;

export function attachAbandonedLogger() {
  ensureAbandonedStore();
  const store = Shopware?.Store?.get(NS);
  // Vuex exposed a global subscribe() carrying (mutation, rootState) for every module.
  // Pinia subscribes per store and hands back (mutation, state) for that store alone, so the
  // namespace filter the Vuex version needed is gone.
  if (!store || typeof store.$subscribe !== 'function') return;
  _lastSnapshot = JSON.parse(JSON.stringify(store.$state));
  store.$subscribe((_mutation, state) => {
    _lastSnapshot = JSON.parse(JSON.stringify(state));
  });
}
