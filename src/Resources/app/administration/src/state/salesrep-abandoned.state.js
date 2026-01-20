const NS = 'salesrepAbandoned';
const KEY = 'salesrepAbandoned:payload';

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
  const S = Shopware?.State;
  if (!S) return;
  try { if (S.get(NS)) return; } catch (_) {}
  S.registerModule(NS, {
    namespaced: true,
    state: () => ({
      payload: readPersisted(),
      allowClear: false,
      lastTouchedAt: null,
    }),
    mutations: {
      setPayload(state, payload) {
        state.payload = payload ?? null;
        state.lastTouchedAt = nowIso();
        writePersisted(state.payload);
      },
      enableClear(state) { state.allowClear = true; },
      disableClear(state) { state.allowClear = false; },
      clear(state) {
        if (!state.allowClear) return;
        state.payload = null;
        state.lastTouchedAt = nowIso();
        writePersisted(null);
      },
    },
    getters: {
      hasPayload: (s) => !!s.payload,
      payload: (s) => s.payload,
      allowClear: (s) => s.allowClear,
      lastTouchedAt: (s) => s.lastTouchedAt,
    },
  });
}

export const Abandoned = {
  ns: NS,
  set(payload) {
    ensureAbandonedStore();
    Shopware.State.commit(`${NS}/setPayload`, payload);
  },
  get() {
    ensureAbandonedStore();
    return Shopware.State.get(NS)?.payload ?? null;
  },
  enableClear() {
    ensureAbandonedStore();
    Shopware.State.commit(`${NS}/enableClear`);
  },
  disableClear() {
    ensureAbandonedStore();
    Shopware.State.commit(`${NS}/disableClear`);
  },
  clear() {
    ensureAbandonedStore();
    Shopware.State.commit(`${NS}/clear`);
  },
};

let _lastSnapshot = null;

function getStoreSubscribeFn() {
  const S = Shopware?.State;
  if (!S) return null;
  if (typeof S.subscribe === 'function') return S.subscribe.bind(S);
  if (S._store && typeof S._store.subscribe === 'function') return S._store.subscribe.bind(S._store);
  return null;
}

export function attachAbandonedLogger() {
  ensureAbandonedStore();
  const subscribe = getStoreSubscribeFn();
  if (!subscribe) return;
  _lastSnapshot = JSON.parse(JSON.stringify(Shopware.State.get(NS)));
  subscribe((mutation, rootState) => {
    if (!mutation?.type?.startsWith(`${NS}/`)) return;
    const current = rootState?.[NS];
    _lastSnapshot = JSON.parse(JSON.stringify(current));
  });
}
