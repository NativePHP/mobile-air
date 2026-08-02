/**
 * EDGE web client runtime.
 *
 * Livewire-style client for NativePHP EDGE screens rendered as HTML:
 * captures DOM events on data-edge-* attributes, POSTs them to
 * /_edge/update with the sealed state snapshot, and reconciles the
 * returned HTML into the live DOM with a keyed morph (data-edge-id).
 *
 * Contract notes (must stay in sync with WebScreenRunner / WebShell):
 *   - S.snapshot is a SEALED envelope {data, checksum}. It is OPAQUE:
 *     stored and echoed back verbatim, never read or mutated — any
 *     modification 419s server-side.
 *   - POST body shape: {component, uri, params, snapshot, preview, event}
 *     as raw JSON (server parses getContent(), not input()).
 *   - Update response: {html, snapshot, effects, polls} | {redirect} |
 *     {back}. `polls` (advisory interval list, ms) resyncs the poll
 *     timers after every update; missing (old server) means "keep".
 *   - State also carries `polls` + `lazy` (protocol events
 *     {type:'poll'} / {type:'lazy'}) and `uploadEndpoint` (multipart
 *     target for window.EdgeUpload). Missing keys = [] / false / none.
 *   - SPA nav: GET with `X-Edge-Nav: 1` → {html, state, title, effects}.
 *   - Effects: [{method, params}] executed in array order, drained
 *     server-side (no ack, no replay). Drivers extendable via
 *     window.EdgeDrivers = {method: async (params, ctx) => {}}.
 */
(() => {
  'use strict';

  const stateEl = document.getElementById('edge-state');
  if (!stateEl) return;

  /** Live client state: {component, uri, params, snapshot, preview, csrf}. */
  let S = JSON.parse(stateEl.textContent);

  const root = () => document.getElementById('edge-root');

  // Wire event type ints — must match NativeComponent::dispatch().
  const EV = {
    PRESS: 0, LONG_PRESS: 1, TEXT: 2, TOGGLE: 3, SUBMIT: 4,
    SLIDER: 9, CHECKBOX: 10, RADIO: 11, SELECT: 12, TAB: 13, SHEET_DISMISS: 14,
  };

  // ── URL helpers ─────────────────────────────────────────────────────

  /** Keep the ?_platform preview mode sticky across navigations. */
  function withPreview(url) {
    if (!S.preview) return url;
    try {
      const u = new URL(url, window.location.origin);
      if (u.origin !== window.location.origin) return url;
      u.searchParams.set('_platform', S.preview);
      return u.pathname + u.search + u.hash;
    } catch {
      return url;
    }
  }

  // ── Toast (errors + Dialog.Toast) ───────────────────────────────────

  let toastHost = null;

  function ensureToastHost() {
    if (toastHost && toastHost.isConnected) return toastHost;
    toastHost = document.createElement('div');
    toastHost.id = 'edge-toasts';
    toastHost.style.cssText =
      'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:2147483000;' +
      'display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none;';
    document.body.appendChild(toastHost);
    return toastHost;
  }

  function showToast(message, opts) {
    const { error = false, duration = 4000 } = opts || {};
    const t = document.createElement('div');
    t.setAttribute('role', 'status');
    t.style.cssText =
      'pointer-events:auto;max-width:min(90vw,480px);display:flex;align-items:center;gap:12px;' +
      'padding:10px 14px;border-radius:8px;font:14px/1.4 system-ui,sans-serif;color:#fff;' +
      'box-shadow:0 4px 12px rgba(0,0,0,.25);background:' +
      (error ? '#b3261e' : 'rgba(32,33,36,.95)') + ';';
    const text = document.createElement('span');
    text.textContent = String(message ?? '');
    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.textContent = '×';
    dismiss.setAttribute('aria-label', 'Dismiss');
    dismiss.style.cssText =
      'background:none;border:0;color:inherit;font-size:18px;cursor:pointer;padding:0;line-height:1;';
    dismiss.addEventListener('click', () => t.remove());
    t.append(text, dismiss);
    ensureToastHost().appendChild(t);
    if (duration > 0) setTimeout(() => t.remove(), duration);
    return t;
  }

  // ── Keyed DOM morph ─────────────────────────────────────────────────
  // Reconciles the server's fresh HTML into the live tree, keyed on
  // data-edge-id (positional fallback for unkeyed nodes). Reuses nodes
  // so CSS transitions survive, focused inputs keep value/caret/scroll,
  // and iframes/scroll positions aren't reset.

  const keyOf = (n) => (n.nodeType === 1 ? n.getAttribute('data-edge-id') : null);

  const compatible = (a, b) =>
    a.nodeType === b.nodeType && (a.nodeType !== 1 || a.tagName === b.tagName);

  function syncAttrs(from, to) {
    for (const attr of Array.from(from.attributes)) {
      if (!to.hasAttribute(attr.name)) from.removeAttribute(attr.name);
    }
    for (const attr of Array.from(to.attributes)) {
      if (from.getAttribute(attr.name) !== attr.value) from.setAttribute(attr.name, attr.value);
    }
  }

  /** Mirror live form properties (decoupled from attributes once dirty). */
  function syncFormState(from, to) {
    const focused = document.activeElement === from;
    const tag = from.tagName;
    if (tag === 'INPUT') {
      if (from.type === 'checkbox' || from.type === 'radio') {
        if (from.checked !== to.checked) from.checked = to.checked;
      } else if (!focused && from.value !== to.value) {
        from.value = to.value;
      }
    } else if ((tag === 'TEXTAREA' || tag === 'SELECT') && !focused && from.value !== to.value) {
      from.value = to.value;
    }
  }

  function morphNode(from, to) {
    if (from.nodeType !== 1) { // text / comment / cdata
      if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue;
      return;
    }
    if (from.tagName !== to.tagName) { // keyed match changed element type
      from.replaceWith(to);
      return;
    }
    syncAttrs(from, to);
    morphChildren(from, to);
    syncFormState(from, to);
  }

  function morphChildren(from, to) {
    // Index keyed old children at this level (ids are unique per render).
    const keyed = new Map();
    for (let c = from.firstChild; c; c = c.nextSibling) {
      const k = keyOf(c);
      if (k !== null) keyed.set(k, c);
    }

    // `cursor` walks the not-yet-reconciled old children; everything we
    // match/adopt is placed immediately before it, so [start..cursor) is
    // always the reconciled prefix in final order.
    let cursor = from.firstChild;

    for (let t = to.firstChild; t; ) {
      const next = t.nextSibling; // adopting t detaches it — capture first
      const k = keyOf(t);
      let match = null;

      if (k !== null) {
        match = keyed.get(k) || null;
        if (match) keyed.delete(k);
      } else if (cursor && keyOf(cursor) === null && compatible(cursor, t)) {
        match = cursor; // positional fallback for unkeyed nodes
      }

      if (match) {
        if (match === cursor) cursor = cursor.nextSibling;
        else from.insertBefore(match, cursor);
        morphNode(match, t);
      } else {
        from.insertBefore(t, cursor); // brand-new node: adopt wholesale
      }
      t = next;
    }

    // Old nodes never matched (positionally passed or keyed-and-gone).
    while (cursor) {
      const n = cursor.nextSibling;
      from.removeChild(cursor);
      cursor = n;
    }
  }

  /** Morph the fresh server HTML into #edge-root, preserving focus. */
  function swap(html) {
    const r = root();
    if (!r) { window.location.reload(); return; }

    const tpl = document.createElement('template');
    tpl.innerHTML = html;

    const active = document.activeElement;
    const hadFocus = active && active !== document.body && r.contains(active);
    const activeKey = hadFocus && active.getAttribute ? active.getAttribute('data-edge-id') : null;
    const caret = hadFocus && active.selectionStart != null
      ? [active.selectionStart, active.selectionEnd] : null;
    const scroll = hadFocus ? [active.scrollLeft, active.scrollTop] : null;

    morphChildren(r, tpl.content);

    // Safety net: the morph reuses the focused node in place, but if it
    // was replaced (key/tag change), re-focus its successor by key.
    if (activeKey && document.activeElement !== active) {
      const el = r.querySelector('[data-edge-id="' + activeKey + '"]');
      if (el && el.focus) {
        el.focus({ preventScroll: true });
        if (caret && el.setSelectionRange) {
          try { el.setSelectionRange(caret[0], caret[1]); } catch { /* type forbids */ }
        }
        if (scroll) { el.scrollLeft = scroll[0]; el.scrollTop = scroll[1]; }
      }
    }
  }

  // ── Event queue ─────────────────────────────────────────────────────
  // One request in flight at a time; further events queue FIFO and flush
  // in order. Rapid TEXT_CHANGEs for the same callback coalesce (only
  // the latest value matters). While in flight, <html> carries
  // data-edge-busy and the originating element is disabled (buttons) /
  // marked data-edge-loading, restored on settle.

  const queue = [];
  let inFlight = false;

  const setBusy = (on) => on
    ? document.documentElement.setAttribute('data-edge-busy', '')
    : document.documentElement.removeAttribute('data-edge-busy');

  /** Never hard-disable typing surfaces mid-edit — it would blur them. */
  const isEntryControl = (el) =>
    el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT';

  function enqueue(event, origin) {
    if (event.type === EV.TEXT) {
      const i = queue.findIndex((q) => q.event.type === EV.TEXT && q.event.callback_id === event.callback_id);
      if (i !== -1) { queue[i].event = event; return; }
    }
    queue.push({ event, origin: origin || null });
    drain();
  }

  async function drain() {
    if (inFlight) return;
    const job = queue.shift();
    if (!job) { setBusy(false); return; }

    inFlight = true;
    setBusy(true);

    const el = job.origin;
    const disable = !!(el && el.tagName === 'BUTTON' && !el.disabled);
    if (disable) el.disabled = true;
    if (el && el.setAttribute && !isEntryControl(el)) el.setAttribute('data-edge-loading', '');

    try {
      await send(job.event);
    } finally {
      if (disable) el.disabled = false; // harmless if morph replaced it
      if (el && el.removeAttribute) el.removeAttribute('data-edge-loading');
      inFlight = false;
      drain();
    }
  }

  async function send(event) {
    let res;
    try {
      res = await fetch(S.endpoint || '/_edge/update', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': S.csrf,
          'Accept': 'application/json',
        },
        // Raw JSON body — server reads getContent(), not input().
        body: JSON.stringify({
          component: S.component,
          uri: S.uri,
          params: S.params,
          snapshot: S.snapshot, // sealed — echoed verbatim, never touched
          preview: S.preview || null,
          event,
        }),
      });
    } catch (e) {
      console.error('[edge] update network error', e);
      showToast('Network error — change not saved.', { error: true });
      return;
    }

    if (res.status === 419) {
      // Stale/mismatched seal or expired CSRF — a fresh page fixes both.
      queue.length = 0;
      showToast('Session expired — reloading…', { error: true });
      setTimeout(() => window.location.reload(), 600);
      return;
    }

    if (!res.ok) {
      console.error('[edge] update failed', res.status);
      showToast('Update failed (HTTP ' + res.status + ').', { error: true });
      return;
    }

    let data;
    try {
      data = await res.json();
    } catch (e) {
      console.error('[edge] update returned non-JSON', e);
      showToast('Unexpected server response.', { error: true });
      return;
    }

    if (data.redirect) { queue.length = 0; navigate(data.redirect); return; }
    if (data.back) { queue.length = 0; goBack(); return; }

    S.snapshot = data.snapshot;
    // Resync poll timers to the server's refreshed advisory list: blade
    // polls are conditional markup, and a lazy screen's intervals first
    // surface on its {type:'lazy'} response. Absent (old server) = keep.
    if (Array.isArray(data.polls)) { S.polls = data.polls; resetPolls(); }
    swap(data.html);
    runEffects(data.effects);
  }

  // ── SPA navigation ──────────────────────────────────────────────────
  // Screens are fetched as JSON (X-Edge-Nav: 1), morphed in, and the
  // in-memory state replaced wholesale; document.title + history kept in
  // sync. Any failure (non-EDGE route, network, auth redirect off-app)
  // falls back to a full page load.

  let navSeq = 0;

  async function navigate(url, opts) {
    const push = !opts || opts.push !== false;
    url = withPreview(url);
    const seq = ++navSeq;

    try {
      const res = await fetch(url, {
        headers: { 'X-Edge-Nav': '1', 'Accept': 'application/json' },
      });
      const type = res.headers.get('content-type') || '';
      if (!res.ok || type.indexOf('application/json') === -1) {
        throw new Error('non-EDGE response ' + res.status);
      }
      const data = await res.json();
      if (seq !== navSeq) return; // superseded by a newer navigation

      // Server-side redirects (auth gates) are followed by fetch — land
      // the history entry on the final URL, not the one we asked for.
      const target = res.redirected ? res.url : url;

      const apply = () => {
        swap(data.html);
        S = data.state; // full replacement: component/uri/params/snapshot/csrf
        if (data.title) document.title = data.title;
      };

      if (document.startViewTransition) {
        await document.startViewTransition(apply).finished.catch(() => {});
      } else {
        apply();
      }

      if (push) {
        history.pushState({ edge: true }, '', target);
        window.scrollTo(0, 0);
      }

      resetPolls(); // clear + re-arm from the new screen's state
      armLazy();
      runEffects(data.effects);
    } catch (e) {
      console.warn('[edge] SPA navigation fell back to full load:', e);
      window.location.href = url;
    }
  }

  function goBack() {
    if (history.length > 1) history.back();
    else navigate('/');
  }

  history.replaceState({ edge: true }, '', window.location.href);
  window.addEventListener('popstate', () => {
    navigate(window.location.pathname + window.location.search, { push: false });
  });

  // ── Effects dispatcher ──────────────────────────────────────────────
  // effects = [{method, params}], oldest first, executed sequentially.
  // App code may add/override drivers: window.EdgeDrivers['My.Method'] =
  // async (params, ctx) => {...}. ctx.dispatchNativeEvent implements the
  // result-return path for result-bearing effects (params.id + FQCN in
  // params.event).

  /**
   * Result-return contract: POST the browser-API outcome back as a
   * native event dispatch; the server routes it to the component's
   * native event listeners, then re-renders.
   */
  function dispatchNativeEvent(name, payload) {
    enqueue({ type: 'native_event', event: name, payload: payload || {} });
  }

  const effectCtx = {
    dispatchNativeEvent,
    showToast,
    navigate,
    get state() { return S; },
  };

  /** Accessible in-page alert dialog (window.alert blocks automation). */
  function showAlert(params) {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.style.cssText =
        'position:fixed;inset:0;z-index:2147482000;background:rgba(0,0,0,.45);' +
        'display:flex;align-items:center;justify-content:center;padding:24px;';

      const uid = 'edge-alert-' + Math.random().toString(36).slice(2, 8);
      const box = document.createElement('div');
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', uid + '-t');
      box.setAttribute('aria-describedby', uid + '-m');
      box.style.cssText =
        'background:#fff;color:#1c1b1f;border-radius:12px;width:100%;max-width:min(90vw,400px);' +
        'padding:20px;font:14px/1.5 system-ui,sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.35);';

      const title = document.createElement('div');
      title.id = uid + '-t';
      title.style.cssText = 'font-size:16px;font-weight:600;margin-bottom:8px;';
      title.textContent = params.title || '';

      const msg = document.createElement('div');
      msg.id = uid + '-m';
      msg.style.cssText = 'color:#444746;white-space:pre-wrap;';
      msg.textContent = params.message || '';

      const row = document.createElement('div');
      row.style.cssText = 'display:flex;justify-content:flex-end;gap:4px;margin-top:16px;flex-wrap:wrap;';

      const settle = (index, label, fire) => {
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
        if (fire && params.event) {
          dispatchNativeEvent(params.event, { index, label, id: params.id ?? null });
        }
        resolve(index);
      };

      const rawButtons = Array.isArray(params.buttons) && params.buttons.length
        ? params.buttons : ['OK'];
      let cancelIndex = -1;

      rawButtons.forEach((b, index) => {
        const label = typeof b === 'string' ? b : String(b?.label ?? b?.text ?? 'OK');
        const style = (b && typeof b === 'object') ? b.style : null;
        if (style === 'cancel') cancelIndex = index;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = label;
        btn.style.cssText =
          'background:none;border:0;cursor:pointer;padding:8px 12px;border-radius:6px;' +
          'font:600 14px system-ui,sans-serif;color:' +
          (style === 'destructive' ? '#b3261e' : '#0b57d0') + ';';
        btn.addEventListener('click', () => settle(index, label, true));
        row.appendChild(btn);
      });

      const onKey = (e) => {
        if (e.key !== 'Escape') return;
        e.preventDefault();
        e.stopPropagation();
        if (cancelIndex !== -1) {
          const b = rawButtons[cancelIndex];
          settle(cancelIndex, typeof b === 'string' ? b : String(b?.label ?? ''), true);
        } else {
          settle(-1, '', false); // no cancel affordance: dismiss silently
        }
      };
      document.addEventListener('keydown', onKey, true);

      box.append(title, msg, row);
      overlay.appendChild(box);
      document.body.appendChild(overlay);
      const first = row.querySelector('button');
      if (first) first.focus();
    });
  }

  async function geoPermissionState() {
    try {
      const st = await navigator.permissions.query({ name: 'geolocation' });
      return st.state; // 'granted' | 'prompt' | 'denied'
    } catch {
      return 'prompt';
    }
  }

  const geoPermissionPayload = (state, id, error) => ({
    location: state, coarseLocation: state, fineLocation: state,
    ...(error ? { error } : {}), id: id ?? null,
  });

  const openUrl = async (p) => { if (p.url) window.open(p.url, '_blank', 'noopener'); };

  const defaultDrivers = {
    'Dialog.Alert': (p) => showAlert(p),

    'Dialog.Toast': async (p) => {
      showToast(p.message || '', { duration: p.duration === 'short' ? 2000 : 4000 });
    },

    'Device.Vibrate': async (p) => {
      if (navigator.vibrate) navigator.vibrate(Number(p.duration) || 50);
    },

    'Browser.Open': openUrl,
    'Browser.OpenInApp': openUrl,
    'Browser.OpenAuth': openUrl,

    'Share.Url': async (p) => {
      const payload = {};
      if (p.title) payload.title = p.title;
      if (p.text) payload.text = p.text;
      if (p.url) payload.url = p.url;
      if (navigator.share) {
        try { await navigator.share(payload); return; }
        catch (e) { if (e && e.name === 'AbortError') return; }
      }
      const text = p.url || p.text || '';
      if (text && navigator.clipboard) {
        try { await navigator.clipboard.writeText(text); showToast('Copied to clipboard'); return; }
        catch { /* fall through */ }
      }
      showToast('Sharing is not available in this browser.', { error: true });
    },

    'Geolocation.GetCurrentPosition': (p, ctx) => new Promise((resolve) => {
      const event = p.event || 'Native\\Mobile\\Events\\Geolocation\\LocationReceived';
      if (!navigator.geolocation) {
        ctx.dispatchNativeEvent(event, { success: false, error: 'Geolocation unavailable', id: p.id ?? null });
        resolve();
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          ctx.dispatchNativeEvent(event, {
            success: true,
            latitude: pos.coords.latitude,
            longitude: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            timestamp: Math.round(pos.timestamp),
            provider: 'browser',
            id: p.id ?? null,
          });
          resolve();
        },
        (err) => {
          ctx.dispatchNativeEvent(event, {
            success: false, error: err.message || 'Location request denied', id: p.id ?? null,
          });
          resolve();
        },
        { enableHighAccuracy: !!p.fineAccuracy, timeout: 15000 },
      );
    }),

    'Geolocation.CheckPermissions': async (p, ctx) => {
      if (!p.event) return;
      const state = navigator.geolocation ? await geoPermissionState() : 'denied';
      ctx.dispatchNativeEvent(p.event, geoPermissionPayload(state, p.id));
    },

    'Geolocation.RequestPermissions': async (p, ctx) => {
      if (!p.event) return;
      if (!navigator.geolocation) {
        ctx.dispatchNativeEvent(p.event, geoPermissionPayload('denied', p.id, 'Geolocation unavailable'));
        return;
      }
      // The web permission prompt only appears on an actual position
      // request — make one, then report the settled permission state.
      await new Promise((resolve) => navigator.geolocation.getCurrentPosition(
        () => resolve(), () => resolve(), { timeout: 15000 },
      ));
      ctx.dispatchNativeEvent(p.event, geoPermissionPayload(await geoPermissionState(), p.id));
    },
  };

  // App-registered drivers (script before this one) win over defaults.
  window.EdgeDrivers = Object.assign({}, defaultDrivers, window.EdgeDrivers || {});

  async function runEffects(effects) {
    if (!Array.isArray(effects)) return;
    for (const fx of effects) {
      if (!fx || typeof fx.method !== 'string') continue;
      const driver = window.EdgeDrivers[fx.method];
      if (!driver) {
        console.warn('[edge] no client driver for effect', fx.method, fx.params);
        continue;
      }
      try {
        await driver(fx.params || {}, effectCtx);
      } catch (e) {
        console.error('[edge] effect driver failed', fx.method, e);
      }
    }
  }

  // ── Polling (#[Poll]) + lazy boot (#[Lazy]) ─────────────────────────
  // S.polls is an advisory list of distinct intervals in ms, deliberately
  // OUTSIDE the sealed snapshot. One timer per interval; a tick enqueues
  // a {type:'poll'} protocol event (string type, no callback_id) through
  // the normal queue, so polls serialize behind — and never race — user
  // events. The server runs every #[Poll] method per tick; the client
  // owns the cadence. Ticks are skipped (not stacked) while an update is
  // in flight or queued; timers stop entirely while the tab is hidden.

  let pollTimers = [];

  function stopPolls() {
    pollTimers.forEach(clearInterval);
    pollTimers = [];
  }

  function resetPolls() {
    stopPolls();
    if (document.hidden) return; // visibilitychange re-arms on return
    const seen = new Set();
    for (const raw of Array.isArray(S.polls) ? S.polls : []) {
      const ms = parseInt(raw, 10);
      if (!(ms > 0) || seen.has(ms)) continue;
      seen.add(ms);
      pollTimers.push(setInterval(() => {
        // Busy? Skip this tick rather than queueing a backlog — the next
        // tick (or the post-update resync) catches the screen up.
        if (document.hidden || inFlight || queue.length) return;
        enqueue({ type: 'poll' });
      }, ms));
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPolls();
    else resetPolls();
  });

  /**
   * One-shot lazy boot: a #[Lazy] screen's GET served only a placeholder
   * (empty props/callbacks/nav — nothing on it is dispatchable), so POST
   * {type:'lazy'} immediately; the response carries the real html,
   * snapshot and poll intervals. Clearing the flag first makes it fire
   * exactly once per screen state (the swapped-in update response and
   * SPA nav both replace/clear it).
   */
  function armLazy() {
    if (!S.lazy) return;
    S.lazy = false;
    enqueue({ type: 'lazy' });
  }

  // ── Uploads (window.EdgeUpload) ─────────────────────────────────────
  // The upload primitive future file inputs and the camera driver call
  // (getUserMedia → canvas → Blob → EdgeUpload). No UI here. Accepts a
  // single File/Blob or an array/FileList (server default max 10 files,
  // 12MB each). Resolves with the server payload — top-level {path,
  // name, mime, size, signature} for the first file plus files:[...] for
  // all. Consumers pass {path, signature} back to the server VERBATIM
  // (EdgeUpload::validatePath re-verifies; path alone is never trusted)
  // and should consume promptly — files self-prune after 24h. Rejects
  // with Error{status, message} on failure: 422 invalid, 419 bad CSRF,
  // 413 oversize (possibly nginx HTML before PHP — hence res.ok, not
  // JSON, decides).

  window.EdgeUpload = async function edgeUpload(file) {
    if (!S.uploadEndpoint) throw new Error('EdgeUpload: no uploadEndpoint in state');

    const files = (typeof FileList !== 'undefined' && file instanceof FileList)
      ? Array.from(file)
      : (Array.isArray(file) ? file : [file]);
    if (!files.length || files.some((f) => !(f instanceof Blob))) {
      throw new Error('EdgeUpload: expected a File/Blob or a list of them');
    }

    const fd = new FormData();
    if (files.length === 1) fd.append('file', files[0]);
    else files.forEach((f) => fd.append('files[]', f));

    const res = await fetch(S.uploadEndpoint, {
      method: 'POST',
      // NO Content-Type — the browser must set the multipart boundary.
      headers: { 'X-CSRF-TOKEN': S.csrf, 'Accept': 'application/json' },
      body: fd, // session cookie rides along (same-origin default)
    });

    if (!res.ok) {
      let message = 'Upload failed (HTTP ' + res.status + ')';
      try {
        const err = await res.json();
        if (err && err.message) message = err.message;
      } catch { /* non-JSON error body (e.g. nginx 413 page) */ }
      const e = new Error(message);
      e.status = res.status;
      throw e;
    }
    return res.json();
  };

  // ── DOM event delegation ────────────────────────────────────────────

  const cb = (el, key) => parseInt(el.dataset[key], 10);

  // Clicks: navigate / back / press / tab / chip / dismiss.
  document.addEventListener('click', (e) => {
    const t = (sel) => e.target.closest(sel);
    let el;

    if ((el = t('[data-edge-navigate]'))) {
      // Honor open-in-new-tab on real anchors.
      if (el.tagName === 'A' && (e.metaKey || e.ctrlKey || e.shiftKey)) return;
      e.preventDefault();
      navigate(el.dataset.edgeNavigate);
    } else if ((el = t('[data-edge-back]'))) {
      goBack();
    } else if ((el = t('[data-edge-tab]'))) {
      enqueue({ type: EV.TAB, callback_id: cb(el, 'edgeTab'), value: parseInt(el.dataset.edgeValue || '0', 10) }, el);
    } else if ((el = t('[data-edge-chip]'))) {
      enqueue({ type: EV.TOGGLE, callback_id: cb(el, 'edgeChip'), value: el.dataset.edgeChecked !== '1' }, el);
    } else if ((el = t('[data-edge-dismiss]'))) {
      if (el === e.target) enqueue({ type: EV.SHEET_DISMISS, callback_id: cb(el, 'edgeDismiss') }, el);
    } else if ((el = t('[data-edge-press]'))) {
      enqueue({ type: EV.PRESS, callback_id: cb(el, 'edgePress') }, el);
    }
  });

  // Long press (pointer hold ≥ 500ms).
  let lpTimer = null;
  document.addEventListener('pointerdown', (e) => {
    const el = e.target.closest('[data-edge-long-press]');
    if (!el) return;
    lpTimer = setTimeout(() => {
      lpTimer = null;
      enqueue({ type: EV.LONG_PRESS, callback_id: cb(el, 'edgeLongPress') }, el);
    }, 500);
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach((n) =>
    document.addEventListener(n, () => { if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } }, true));

  // Text inputs (native:model modes: live / blur / lazy / debounce).
  // Local debounce keeps keystrokes off the wire; queue-level coalescing
  // collapses whatever still stacks up behind an in-flight request.
  const debounces = new Map();
  document.addEventListener('input', (e) => {
    const el = e.target;
    if (!el.matches || !el.matches('[data-edge-change]')) return;
    if (el.type === 'checkbox' || el.type === 'radio' || el.type === 'range' || el.tagName === 'SELECT') return;

    const id = cb(el, 'edgeChange');
    const mode = el.dataset.edgeSync || 'live';
    if (mode === 'blur' || mode === 'lazy') return;

    const ms = mode === 'debounce'
      ? parseInt(el.dataset.edgeDebounce || '300', 10)
      : 150; // small live debounce so each keystroke doesn't round-trip
    clearTimeout(debounces.get(id));
    debounces.set(id, setTimeout(() => enqueue({ type: EV.TEXT, callback_id: id, text: el.value }, el), ms));
  });

  // Change: toggle / checkbox / slider / select / radio / date / blur-mode text.
  document.addEventListener('change', (e) => {
    const el = e.target;
    if (!el.matches) return;

    if (el.matches('[data-edge-toggle]')) {
      enqueue({ type: EV.TOGGLE, callback_id: cb(el, 'edgeToggle'), value: el.checked }, el);
    } else if (el.matches('[data-edge-checkbox]')) {
      enqueue({ type: EV.CHECKBOX, callback_id: cb(el, 'edgeCheckbox'), value: el.checked }, el);
    } else if (el.matches('[data-edge-slider]')) {
      enqueue({ type: EV.SLIDER, callback_id: cb(el, 'edgeSlider'), value: parseFloat(el.value) }, el);
    } else if (el.matches('[data-edge-select]')) {
      enqueue({ type: EV.SELECT, callback_id: cb(el, 'edgeSelect'), value: el.value }, el);
    } else if (el.matches('[data-edge-radio]')) {
      if (el.checked) enqueue({ type: EV.RADIO, callback_id: cb(el, 'edgeRadio'), value: el.value }, el);
    } else if (el.matches('[data-edge-date]')) {
      enqueue({ type: EV.TEXT, callback_id: cb(el, 'edgeDate'), text: el.value }, el);
    } else if (el.matches('[data-edge-change][data-edge-sync="blur"], [data-edge-change][data-edge-sync="lazy"]')) {
      enqueue({ type: EV.TEXT, callback_id: cb(el, 'edgeChange'), text: el.value }, el);
    }
  });

  // Keyboard: Enter submits text inputs; Enter/Space activate the
  // role=button divs that carry press bindings.
  document.addEventListener('keydown', (e) => {
    const el = e.target;
    if (!el || !el.matches) return;

    if (e.key === 'Enter' && el.matches('[data-edge-submit]')) {
      if (el.tagName === 'TEXTAREA' && !e.metaKey && !e.ctrlKey) return;
      e.preventDefault();
      enqueue({ type: EV.SUBMIT, callback_id: cb(el, 'edgeSubmit'), text: el.value }, el);
    } else if ((e.key === 'Enter' || e.key === ' ') && el.matches('[data-edge-press][role="button"]')) {
      e.preventDefault();
      enqueue({ type: EV.PRESS, callback_id: cb(el, 'edgePress') }, el);
    }
  });

  // ── Boot ────────────────────────────────────────────────────────────

  resetPolls();
  armLazy(); // #[Lazy] placeholder: fetch the real content immediately
  runEffects(S.effects); // effects queued during the initial GET render

  console.log('[edge] web runtime ready', S.component);
})();
