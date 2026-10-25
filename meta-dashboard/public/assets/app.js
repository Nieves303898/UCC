/* Meta Hub — panel de métricas y portal de clientes (vanilla JS, sin build). */
'use strict';

// =========================================================================
// Utilidades y estado
// =========================================================================
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const CSRF = $('meta[name=csrf]').content;
const store = {
  get(k, d) { try { const v = localStorage.getItem('mh.' + k); return v === null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem('mh.' + k, JSON.stringify(v)); } catch { /* sin storage */ } },
};

const state = {
  range: store.get('range', { preset: 'last_7d' }),
  live: store.get('live', true),
  boot: null,
  charts: [],
  tables: {},
  renderId: 0,
  force: false,
  timer: null,
  preview: null, // admin viendo el portal de un cliente
  brand: store.get('brand', null), // marca elegida por un usuario del cliente
};
const isClient = () => state.boot?.user.role === 'client';
const isAdmin = () => state.boot?.user.role === 'admin';
const isViewer = () => state.boot?.user.role === 'viewer';
const isMember = () => state.boot?.user.role === 'member';
/** Puede editar lo operativo (presupuestos, objetivos, ventas, trabajo). */
const canEdit = () => isAdmin() || isMember();
/** ID del cliente cuyo portal se está mostrando (propio o vista previa). */
const portalClient = () => (isClient() ? state.boot.client?.id : state.preview);

// =========================================================================
// Íconos (trazos propios, 24x24)
// =========================================================================
const ICONS = {
  home: '<path d="M4 10.5 12 4l8 6.5V20h-5v-5.5H9V20H4z"/>',
  grid: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
  target: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1" fill="currentColor"/>',
  page: '<rect x="4.5" y="3.5" width="15" height="17" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
  instagram: '<rect x="4" y="4" width="16" height="16" rx="4.5"/><circle cx="12" cy="12" r="3.6"/><circle cx="16.6" cy="7.4" r=".9" fill="currentColor"/>',
  social: '<circle cx="7" cy="12" r="2.5"/><circle cx="17" cy="6.5" r="2.5"/><circle cx="17" cy="17.5" r="2.5"/><path d="m9.2 10.8 5.6-3M9.2 13.2l5.6 3"/>',
  bell: '<path d="M6.5 16.5V11a5.5 5.5 0 0 1 11 0v5.5l1.5 1.5H5z"/><path d="M10 20.5h4"/>',
  sliders: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
  users: '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><path d="M15.5 5.6a3.2 3.2 0 0 1 0 5.8M17.5 14.3c1.6.7 2.6 2.3 3 4.7"/>',
  user: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.6 7-5.6s6.2 2 7 5.6"/>',
  briefcase: '<rect x="3.5" y="7" width="17" height="12.5" rx="2"/><path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7M3.5 12.5h17"/>',
  report: '<path d="M6 3.5h8l4 4V20a.5.5 0 0 1-.5.5h-11A.5.5 0 0 1 6 20z"/><path d="M14 3.5V8h4M9 16v-2.5M12 16v-5M15 16v-3.5"/>',
  search: '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
  moon: '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/>',
  refresh: '<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M19.5 4.5v4h-4"/>',
  logout: '<path d="M14 4.5h4.5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H14"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  external: '<path d="M13.5 4.5h6v6M19.5 4.5 11 13"/><path d="M18 14v4.5a1 1 0 0 1-1 1H5.5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1H10"/>',
  download: '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
  up: '<path d="M7 14l5-5 5 5"/>',
  down: '<path d="M7 10l5 5 5-5"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  x: '<path d="M6 6l12 12M18 6 6 18"/>',
  eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
  alert: '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4.5M12 17.2v.3"/>',
  key: '<circle cx="8" cy="15" r="3.5"/><path d="m10.5 12.5 8-8M16 7l2.5 2.5"/>',
  copy: '<rect x="8" y="8" width="11.5" height="11.5" rx="2"/><path d="M5.5 15.5H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h9.5a1 1 0 0 1 1 1v.5"/>',
  link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
  calendar: '<rect x="4" y="5.5" width="16" height="14.5" rx="2"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/>',
  play: '<path d="M8 5.5v13l10.5-6.5z"/>',
  library: '<path d="M5 4.5h3v15H5zM10 4.5h3v15h-3z"/><path d="m15.2 5.6 2.9-.8 3.6 13.6-2.9.8z"/>',
  layers: '<path d="m12 4 8.5 4.5L12 13 3.5 8.5z"/><path d="m3.5 12.5 8.5 4.5 8.5-4.5M3.5 16.5 12 21l8.5-4.5"/>',
};
const icon = (name, size = 16, extra = '') => `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" ${extra}>${ICONS[name] || ''}</svg>`;
const hydrateIcons = (root = document) => $$('[data-icon]', root).forEach((el) => { if (!el.dataset.iconDone) { el.insertAdjacentHTML('afterbegin', icon(el.dataset.icon)); el.dataset.iconDone = 1; } });

// =========================================================================
// Tema claro / oscuro y colores de marca
// =========================================================================
const theme = () => document.documentElement.getAttribute('data-theme') || 'light';
const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
function setTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  store.set('theme', t);
  paintThemeButtons();
  applyChartTheme();
  render({ silent: true, cachedOnly: true });
}
function paintThemeButtons() {
  $$('[data-theme-toggle]').forEach((b) => { b.innerHTML = icon(theme() === 'dark' ? 'sun' : 'moon'); });
}
function setAccent(color) {
  if (color && /^#[0-9a-f]{6}$/i.test(color)) {
    document.documentElement.style.setProperty('--accent', color);
    // Texto legible sobre el color de marca.
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(color.slice(i, i + 2), 16));
    document.documentElement.style.setProperty('--on-accent', (r * 299 + g * 587 + b * 114) / 1000 > 160 ? '#111' : '#fff');
  } else {
    document.documentElement.style.removeProperty('--accent');
    document.documentElement.style.removeProperty('--on-accent');
  }
}

// =========================================================================
// Formato (es-AR)
// =========================================================================
const LOCALE = 'es-AR';
const nf0 = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });
const bad = (v) => v == null || Number.isNaN(v) || !Number.isFinite(+v);
const fmt = {
  int: (v) => (bad(v) ? '—' : nf0.format(Math.round(v))),
  dec: (v, d = 2) => (bad(v) ? '—' : new Intl.NumberFormat(LOCALE, { minimumFractionDigits: d, maximumFractionDigits: d }).format(v)),
  pct: (v) => (bad(v) ? '—' : fmt.dec(v, 2) + '%'),
  x: (v) => (bad(v) ? '—' : fmt.dec(v, 2) + 'x'),
  money(v, cur = 'USD') {
    if (bad(v)) return '—';
    try { return new Intl.NumberFormat(LOCALE, { style: 'currency', currency: cur, maximumFractionDigits: Math.abs(v) >= 1000 ? 0 : 2 }).format(v); } catch { return cur + ' ' + fmt.dec(v); }
  },
  moneyShort(v, cur = 'USD') {
    if (bad(v)) return '—';
    if (Math.abs(v) < 100000) return fmt.money(v, cur);
    try { return new Intl.NumberFormat(LOCALE, { style: 'currency', currency: cur, notation: 'compact', maximumFractionDigits: 1 }).format(v); } catch { return fmt.compact(v); }
  },
  compact: (v) => (bad(v) ? '—' : new Intl.NumberFormat(LOCALE, { notation: 'compact', maximumFractionDigits: 1 }).format(v)),
  date: (s) => (s ? new Date(s.length === 10 ? s + 'T12:00:00' : s.replace(' ', 'T')).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) : '—'),
  dateLong: (s) => (s ? new Date(s.length === 10 ? s + 'T12:00:00' : s.replace(' ', 'T')).toLocaleDateString(LOCALE, { day: 'numeric', month: 'long', year: 'numeric' }) : '—'),
  datetime: (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'),
  ago(iso) {
    if (!iso) return '';
    const s = Math.max(0, (Date.now() - new Date(iso.replace(' ', 'T')).getTime()) / 1000);
    if (s < 60) return 'hace instantes';
    if (s < 3600) return `hace ${Math.round(s / 60)} min`;
    if (s < 86400) return `hace ${Math.round(s / 3600)} h`;
    return `hace ${Math.round(s / 86400)} d`;
  },
};

function delta(cur, prev, inverse = false) {
  if (bad(cur) || bad(prev) || prev === 0) return '';
  const d = ((cur - prev) / Math.abs(prev)) * 100;
  if (Math.abs(d) < 0.5) return '<span class="delta flat">= 0%</span>';
  const good = inverse ? d < 0 : d > 0;
  return `<span class="delta ${good ? 'up' : 'down'}">${icon(d > 0 ? 'up' : 'down', 12)}${fmt.dec(Math.abs(d), Math.abs(d) >= 100 ? 0 : 1)}%</span>`;
}

const STATUS = {
  ACTIVE: ['Activa', 'green'], PAUSED: ['Pausada', 'gray'], CAMPAIGN_PAUSED: ['Campaña pausada', 'gray'],
  ADSET_PAUSED: ['Conjunto pausado', 'gray'], IN_PROCESS: ['Procesando', 'blue'], WITH_ISSUES: ['Con problemas', 'red'],
  PENDING_REVIEW: ['En revisión', 'amber'], DISAPPROVED: ['Rechazado', 'red'], PREAPPROVED: ['Preaprobado', 'blue'],
  PENDING_BILLING_INFO: ['Falta medio de pago', 'red'], ARCHIVED: ['Archivada', 'gray'], DELETED: ['Eliminada', 'gray'],
};
const statusPill = (s) => { const [l, c] = STATUS[s] || [s || '—', 'gray']; return `<span class="pill ${c}">${esc(l)}</span>`; };
const OBJECTIVES = {
  OUTCOME_SALES: 'Ventas', OUTCOME_LEADS: 'Clientes potenciales', OUTCOME_ENGAGEMENT: 'Interacción', OUTCOME_TRAFFIC: 'Tráfico',
  OUTCOME_AWARENESS: 'Reconocimiento', OUTCOME_APP_PROMOTION: 'Promoción de app', CONVERSIONS: 'Conversiones', MESSAGES: 'Mensajes',
  LINK_CLICKS: 'Tráfico', REACH: 'Alcance', BRAND_AWARENESS: 'Reconocimiento', VIDEO_VIEWS: 'Reproducciones', LEAD_GENERATION: 'Leads',
  POST_ENGAGEMENT: 'Interacción', PRODUCT_CATALOG_SALES: 'Ventas de catálogo', APP_INSTALLS: 'Instalaciones',
};

// =========================================================================
// API
// =========================================================================
async function api(action, params = {}, body = null) {
  const q = new URLSearchParams({ action });
  // Usuario del cliente con varias marcas: todo se pide para la marca elegida.
  if (state.brand && (state.boot ? isClient() : true) && (params.client === undefined || params.client === null || params.client === '')) params = { ...params, client: state.brand };
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v); });
  if (state.force && !body) q.set('refresh', '1');
  const isForm = body instanceof FormData;
  const opts = body ? { method: 'POST', headers: isForm ? { 'X-CSRF-Token': CSRF } : { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: isForm ? body : JSON.stringify(body) } : {};
  const res = await fetch('api.php?' + q.toString(), opts);
  const json = await res.json().catch(() => ({ ok: false, error: `Respuesta inválida del servidor (HTTP ${res.status})` }));
  if (json.auth === false) { location.href = 'index.php?r=login'; throw new Error('Sesión vencida'); }
  if (!json.ok) throw new Error(json.error || 'Error desconocido');
  if (json.meta) setMeta(json.meta);
  return json.data;
}
const rp = (extra = {}) => ({ ...state.range, ...extra });
const post = async (action, body, okMsg) => {
  try { const r = await api(action, {}, body); if (okMsg) toast(okMsg); return r; } catch (e) { toast(e.message, 'error'); throw e; }
};

function setMeta(meta) {
  if (meta.cached_at) {
    $('#updated-at').innerHTML = `<span class="inline-flex items-center gap-2"><span class="live-dot ${state.live ? 'on' : ''}"></span>Datos de Meta ${fmt.ago(meta.cached_at)} · ${fmt.datetime(meta.cached_at)}${meta.stale ? ' · <span class="t-warn">Meta no respondió: se muestra el último dato guardado</span>' : ''}</span>`;
  }
  $('#usage').textContent = meta.usage != null ? `Uso de API ${meta.usage}%` : '';
}

// =========================================================================
// Componentes de UI
// =========================================================================
function toast(text, type = 'info') {
  const el = document.createElement('div');
  el.className = `toast ${type === 'error' ? 'error' : ''}`;
  el.innerHTML = `${icon(type === 'error' ? 'alert' : 'check', 16)}<span>${esc(text)}</span>`;
  $('#toasts').append(el);
  setTimeout(() => el.remove(), type === 'error' ? 8000 : 4000);
}

function modal(html, size = '') {
  const m = $('#modal');
  m.firstElementChild.className = `modal-card ${size}`;
  m.firstElementChild.innerHTML = html;
  m.classList.remove('hidden');
  m.onclick = (e) => { if (e.target === m || e.target.closest('[data-close]')) closeModal(); };
  return m.firstElementChild;
}
const closeModal = () => $('#modal').classList.add('hidden');
const modalHead = (title, sub = '') => `<div class="card-h"><div class="min-w-0 flex-1"><div class="h2 truncate">${esc(title)}</div>${sub ? `<div class="t3 text-xs mt-0.5">${sub}</div>` : ''}</div><button class="btn btn-ghost btn-icon" data-close>${icon('x')}</button></div>`;

const skeleton = () => `
  <div class="sk h-8 w-64 mb-5"></div>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">${'<div class="sk h-[118px]"></div>'.repeat(4)}</div>
  <div class="sk h-80 mb-5"></div><div class="sk h-96"></div>`;

const errorBox = (msg, retry = true) => `
  <div class="card p-6 flex gap-4 items-start">
    <div class="t-bad mt-0.5">${icon('alert', 20)}</div>
    <div class="flex-1"><div class="h2">No pudimos cargar estos datos</div><div class="t2 mt-1">${esc(msg)}</div>
      <div class="flex gap-2 mt-4">${retry ? '<button class="btn" onclick="render()">Reintentar</button>' : ''}${!isClient() ? '<a class="btn btn-ghost" href="#/settings">Configuración</a>' : ''}</div></div>
  </div>`;

const empty = (title, text = '', action = '', ic = 'layers') => `
  <div class="card px-6 py-12 text-center">
    <div class="mx-auto w-11 h-11 rounded-xl sunk grid place-items-center t3">${icon(ic, 20)}</div>
    <div class="h2 mt-4">${esc(title)}</div>
    ${text ? `<div class="t2 mt-1 max-w-md mx-auto">${text}</div>` : ''}
    ${action ? `<div class="mt-5">${action}</div>` : ''}
  </div>`;

const pageHead = (title, sub = '', right = '') => `
  <div class="flex flex-wrap items-end gap-4 mb-6">
    <div class="min-w-0"><h1 class="h1">${title}</h1>${sub ? `<div class="t2 mt-1">${sub}</div>` : ''}</div>
    <div class="flex-1"></div>${right}
  </div>`;

/** Mini gráfico de línea en SVG (sin Chart.js). */
function sparkline(values, { color = 'var(--accent)', height = 30, fill = true } = {}) {
  const v = (values || []).map((x) => (bad(x) ? 0 : +x));
  if (v.length < 2 || v.every((x) => x === 0)) return '';
  const w = 120, h = height, max = Math.max(...v), min = Math.min(...v), span = max - min || 1;
  const pts = v.map((y, i) => [(i / (v.length - 1)) * w, h - 3 - ((y - min) / span) * (h - 6)]);
  const d = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
  const last = pts[pts.length - 1];
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
    ${fill ? `<path d="${d}L${w},${h}L0,${h}Z" fill="${color}" opacity=".08"/>` : ''}
    <path d="${d}" fill="none" stroke="${color}" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
    <circle cx="${last[0]}" cy="${last[1]}" r="2.2" fill="${color}"/></svg>`;
}

/** Tarjeta KPI: etiqueta, valor, variación y sparkline. */
const kpi = (label, value, { d = '', spark = '', hint = '', vs = 'vs. período anterior' } = {}) => `
  <div class="kpi" ${hint ? `title="${esc(hint)}"` : ''}>
    <div class="lbl">${esc(label)}</div>
    <div class="val">${value}</div>
    <div class="foot">${d ? `<div class="leading-tight">${d}<div class="vs">${vs}</div></div>` : ''}${spark}</div>
  </div>`;

function crumbs(items) {
  const el = $('#breadcrumbs');
  if (!el) return;
  el.innerHTML = items.map((c, i) => (c.href && i < items.length - 1
    ? `<a class="hover:underline" href="${c.href}">${esc(c.label)}</a>`
    : `<span class="${i === items.length - 1 ? 't1 font-medium' : ''}">${esc(c.label)}</span>`)).join('<span class="mx-2 opacity-50">/</span>');
}

function downloadCsv(name, cols, rows) {
  const cell = (v) => { const s = String(v ?? ''); return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const lines = [cols.map((c) => cell(c.label)).join(';')];
  rows.forEach((r) => lines.push(cols.map((c) => {
    const v = c.csv ? c.csv(r) : (c.value ? c.value(r) : r[c.key]);
    return cell(typeof v === 'number' ? String(Math.round(v * 100) / 100).replace('.', ',') : v);
  }).join(';')));
  const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${name}-${state.range.preset}-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

const initials = (s) => String(s || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
const genPassword = () => {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const a = new Uint32Array(12); crypto.getRandomValues(a);
  return [...a].map((n) => chars[n % chars.length]).join('');
};
const copyText = (t) => { navigator.clipboard?.writeText(t).then(() => toast('Copiado al portapapeles')); };

// =========================================================================
// Tablas ordenables
// =========================================================================
/**
 * cols: [{key, label, value:(r)=>num|string, html:(r)=>string, csv:(r)=>any, left:bool, total:(rows)=>string}]
 * opts: {title, onClick, sort, dir, csvName, search, filters:[{label, fn}], actions:html}
 */
function tableHtml(id, cols, rows, opts = {}) {
  state.tables[id] = { cols, rows, opts, sort: opts.sort || null, dir: opts.dir || 'desc', q: '', filter: 0 };
  const filters = opts.filters || [];
  return `
    <div class="card overflow-hidden" id="tbl-${id}">
      <div class="card-h">
        ${opts.title ? `<div class="h2">${opts.title}</div>` : ''}
        ${filters.length ? `<div class="tabs">${filters.map((f, i) => `<button class="tab ${i === 0 ? 'active' : ''}" data-f="${i}">${esc(f.label)}</button>`).join('')}</div>` : ''}
        <div class="flex-1"></div>
        ${opts.search !== false ? `<div class="relative"><span class="absolute left-2.5 top-1/2 -translate-y-1/2 t3">${icon('search', 14)}</span><input class="input select-sm !pl-8 w-48" placeholder="Buscar" data-search></div>` : ''}
        ${opts.csvName ? `<button class="btn btn-sm" data-csv>${icon('download', 14)}CSV</button>` : ''}
        ${opts.actions || ''}
      </div>
      <div class="scroll-x" style="max-height:${opts.maxHeight || '72vh'}"><table class="tbl"><thead></thead><tbody></tbody><tfoot></tfoot></table></div>
    </div>`;
}

const cellText = (v) => (typeof v === 'number' ? (Number.isInteger(v) ? fmt.int(v) : fmt.dec(v)) : esc(v ?? '—'));

function tableVisibleRows(t) {
  let rows = t.rows;
  const f = (t.opts.filters || [])[t.filter];
  if (f && f.fn) rows = rows.filter(f.fn);
  if (t.q) {
    const q = t.q.toLowerCase();
    rows = rows.filter((r) => t.cols.some((c) => String(c.value ? c.value(r) : r[c.key] ?? '').toLowerCase().includes(q)));
  }
  if (t.sort) {
    const col = t.cols.find((c) => c.key === t.sort);
    const val = (r) => (col.value ? col.value(r) : r[col.key]);
    rows = [...rows].sort((a, b) => {
      const va = val(a), vb = val(b);
      if (va == null && vb == null) return 0;
      if (va == null) return 1;
      if (vb == null) return -1;
      const cmp = typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), LOCALE);
      return t.dir === 'asc' ? cmp : -cmp;
    });
  }
  return rows;
}

function mountTable(id) {
  const t = state.tables[id];
  const root = $(`#tbl-${id}`);
  if (!t || !root) return;
  const align = (c) => (c.left ? 'text-align:left' : '');
  const draw = () => {
    const rows = tableVisibleRows(t);
    $('thead', root).innerHTML = '<tr>' + t.cols.map((c) => `<th data-k="${c.key}" class="${t.sort === c.key ? 'sorted' : ''}" style="${align(c)}">${esc(c.label)}${t.sort === c.key ? (t.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('') + '</tr>';
    $('tbody', root).innerHTML = rows.length
      ? rows.map((r, i) => `<tr data-i="${i}" class="${t.opts.onClick ? 'clickable' : ''}">${t.cols.map((c) => `<td style="${align(c)}">${c.html ? c.html(r) : cellText(c.value ? c.value(r) : r[c.key])}</td>`).join('')}</tr>`).join('')
      : `<tr><td colspan="${t.cols.length}" style="text-align:center" class="t3 !py-10">Sin datos para este período o filtro</td></tr>`;
    $('tfoot', root).innerHTML = t.cols.some((c) => c.total) && rows.length > 1
      ? '<tr>' + t.cols.map((c, i) => `<td style="${align(c)}">${c.total ? c.total(rows) : (i === 0 ? `Total · ${rows.length}` : '')}</td>`).join('') + '</tr>'
      : '';
    t.visible = rows;
  };
  root.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-k]');
    if (th) {
      const k = th.dataset.k;
      t.dir = t.sort === k && t.dir === 'desc' ? 'asc' : 'desc';
      t.sort = k;
      return draw();
    }
    const fb = e.target.closest('[data-f]');
    if (fb) {
      t.filter = +fb.dataset.f;
      $$('[data-f]', root).forEach((b) => b.classList.toggle('active', b === fb));
      return draw();
    }
    if (e.target.closest('[data-csv]')) return downloadCsv(t.opts.csvName, t.cols, t.visible);
    if (e.target.closest('a,button,input,label')) return;
    const tr = e.target.closest('tbody tr[data-i]');
    if (tr && t.opts.onClick) t.opts.onClick(t.visible[+tr.dataset.i]);
  });
  const s = $('[data-search]', root);
  if (s) s.addEventListener('input', () => { t.q = s.value.trim(); draw(); });
  draw();
}

// =========================================================================
// Gráficos (Chart.js, colores tomados del tema)
// =========================================================================
const palette = () => ['--c1', '--c2', '--c3', '--c4', '--c5', '--c6', '--c7', '--c8'].map(cssVar);
function applyChartTheme() {
  Chart.defaults.color = cssVar('--t3');
  Chart.defaults.borderColor = cssVar('--grid');
  Chart.defaults.font.family = 'Geist, Inter, system-ui, sans-serif';
  Chart.defaults.font.size = 11.5;
}
function destroyCharts() { state.charts.forEach((c) => c.destroy()); state.charts = []; }
const tooltip = () => ({
  backgroundColor: cssVar('--panel'), titleColor: cssVar('--t1'), bodyColor: cssVar('--t2'), borderColor: cssVar('--t3'),
  borderWidth: 1, padding: 11, boxPadding: 5, usePointStyle: true, titleFont: { weight: '600' }, cornerRadius: 10,
});

/** datasets: [{label, data, type:'bar'|'line', axis:'y'|'y1', fmt, color}] */
function chart(canvas, labels, datasets, { fmtY, fmtY1, stacked = false, horizontal = false } = {}) {
  if (!canvas) return null;
  const pal = palette();
  const hasY1 = datasets.some((d) => d.axis === 'y1');
  const c = new Chart(canvas, {
    data: {
      labels,
      datasets: datasets.map((d, i) => {
        const color = d.color || pal[i % pal.length];
        const isBar = d.type === 'bar';
        return {
          type: d.type || 'line', label: d.label, data: d.data, yAxisID: horizontal ? undefined : (d.axis || 'y'),
          borderColor: color, backgroundColor: isBar ? color + (d.alpha || (datasets.length > 1 ? '55' : 'cc')) : color + '14',
          hoverBackgroundColor: color, fill: !isBar && datasets.filter((x) => x.type !== 'bar').length === 1,
          tension: 0.35, pointRadius: 0, pointHoverRadius: 4, pointBackgroundColor: color,
          borderWidth: isBar ? 0 : 2, borderRadius: 5, maxBarThickness: 34, order: isBar ? 2 : 1, _fmt: d.fmt,
        };
      }),
    },
    options: {
      indexAxis: horizontal ? 'y' : 'x',
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { align: 'end', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 14 } },
        tooltip: { ...tooltip(), callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${(ctx.dataset._fmt || fmt.int)(ctx.parsed[horizontal ? 'x' : 'y'])}` } },
      },
      scales: horizontal
        ? { x: { stacked, border: { display: false }, ticks: { callback: (v) => (fmtY || fmt.compact)(v) } }, y: { stacked, grid: { display: false }, border: { display: false } } }
        : {
          x: { stacked, grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 16 } },
          y: { stacked, beginAtZero: true, border: { display: false }, ticks: { callback: (v) => (fmtY || fmt.compact)(v), maxTicksLimit: 6 } },
          ...(hasY1 ? { y1: { position: 'right', beginAtZero: true, grid: { display: false }, border: { display: false }, ticks: { callback: (v) => (fmtY1 || fmt.compact)(v), maxTicksLimit: 6 } } } : {}),
        },
    },
  });
  state.charts.push(c);
  return c;
}

function doughnut(canvas, labels, values, fmtV = fmt.int) {
  if (!canvas || !values.length) return;
  state.charts.push(new Chart(canvas, {
    type: 'doughnut',
    data: { labels, datasets: [{ data: values, backgroundColor: palette(), borderColor: cssVar('--panel'), borderWidth: 3, hoverOffset: 4 }] },
    options: { maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 12 } }, tooltip: { ...tooltip(), callbacks: { label: (c) => ` ${c.label}: ${fmtV(c.parsed)}` } } } },
  }));
}

function dropChart(c) { if (c) { c.destroy(); state.charts = state.charts.filter((x) => x !== c); } }

// =========================================================================
// Ads: métricas, columnas, KPIs
// =========================================================================
function sumMetrics(rows) {
  const keys = ['spend', 'spend_tax', 'impressions', 'reach', 'clicks', 'link_clicks', 'purchases', 'purchase_value', 'messages', 'leads', 'results', 'add_to_cart', 'initiate_checkout'];
  const t = Object.fromEntries(keys.map((k) => [k, rows.reduce((s, r) => s + (r[k] || 0), 0)]));
  const div = (a, b) => (b > 0 ? a / b : null);
  return {
    ...t, cost_per_result: div(t.spend, t.results), link_ctr: div(t.link_clicks * 100, t.impressions), cpc: div(t.spend, t.link_clicks),
    cpm: div(t.spend * 1000, t.impressions), roas: div(t.purchase_value, t.spend), frequency: div(t.impressions, t.reach),
  };
}

function metricCols(cur, get = (r) => r.metrics, { compact = false } = {}) {
  const money = (v) => fmt.money(v, cur);
  const col = (key, label, f, opts = {}) => ({
    key, label, value: (r) => get(r)[key], html: (r) => f(get(r)[key]),
    total: opts.noTotal ? null : (rows) => f(sumMetrics(rows.map(get))[key]), ...opts,
  });
  const cols = [
    col('spend', 'Inversión', money),
    col('spend_tax', `Inv. + ${taxPct()}`, money),
    { key: 'results', label: 'Resultados', value: (r) => get(r).results,
      html: (r) => `${fmt.int(get(r).results)} <span class="sub">${esc(get(r).result_label || '')}</span>`,
      total: (rows) => fmt.int(sumMetrics(rows.map(get)).results) },
    col('cost_per_result', 'Costo/resultado', money),
    col('purchases', 'Compras', fmt.int),
    col('purchase_value', 'Valor compras', money),
    col('roas', 'ROAS', fmt.x),
    col('messages', 'Conversaciones', fmt.int),
    col('leads', 'Leads', fmt.int),
    col('reach', 'Alcance', fmt.int, { noTotal: true }),
    col('impressions', 'Impresiones', fmt.int),
    col('frequency', 'Frecuencia', (v) => fmt.dec(v), { noTotal: true }),
    col('link_clicks', 'Clics enlace', fmt.int),
    col('link_ctr', 'CTR enlace', fmt.pct),
    col('cpc', 'CPC', money),
    col('cpm', 'CPM', money),
  ];
  return compact ? cols.filter((c) => ['spend', 'spend_tax', 'results', 'cost_per_result', 'roas', 'reach', 'link_ctr'].includes(c.key)) : cols;
}

/** Elige KPIs relevantes: siempre los base y los de conversión sólo si hay datos. */
function adsKpis(m, p, cur, daily = [], { client = false } = {}) {
  const money = (v) => fmt.moneyShort(v, cur);
  const P = p || {};
  const s = (k) => sparkline(daily.map((d) => d[k]));
  const has = (k) => (m[k] || 0) > 0 || (P[k] || 0) > 0;
  const items = [
    ['Inversión', money(m.spend), delta(m.spend, P.spend), s('spend')],
    [`Inversión + imp. (${taxPct()})`, money(m.spend_tax), delta(m.spend_tax, P.spend_tax), ''],
    ['Resultados', fmt.int(m.results), delta(m.results, P.results), s('results')],
    ['Costo por resultado', money(m.cost_per_result), delta(m.cost_per_result, P.cost_per_result, true), s('cost_per_result')],
    has('purchases') && ['Compras', fmt.int(m.purchases), delta(m.purchases, P.purchases), s('purchases')],
    has('purchase_value') && ['Facturación atribuida', money(m.purchase_value), delta(m.purchase_value, P.purchase_value), s('purchase_value')],
    has('purchase_value') && ['ROAS', fmt.x(m.roas), delta(m.roas, P.roas), s('roas')],
    has('messages') && ['Conversaciones', fmt.int(m.messages), delta(m.messages, P.messages), s('messages')],
    has('leads') && ['Leads', fmt.int(m.leads), delta(m.leads, P.leads), s('leads')],
    ['Alcance', fmt.compact(m.reach), delta(m.reach, P.reach), s('reach')],
    ['Impresiones', fmt.compact(m.impressions), delta(m.impressions, P.impressions), s('impressions')],
    ['CTR enlace', fmt.pct(m.link_ctr), delta(m.link_ctr, P.link_ctr), s('link_ctr')],
    !client && ['CPM', money(m.cpm), delta(m.cpm, P.cpm, true), s('cpm')],
    !client && ['Clics en enlace', fmt.compact(m.link_clicks), delta(m.link_clicks, P.link_clicks), s('link_clicks')],
  ].filter(Boolean).slice(0, 12);
  return `<div class="kpi-row grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 mb-6" style="display:grid">${items.map(([l, v, d, sp]) => kpi(l, v, { d: p ? d : '', spark: sp })).join('')}</div>`;
}

const AD_CHART_METRICS = {
  results: ['Resultados', fmt.int], cost_per_result: ['Costo por resultado', null], purchases: ['Compras', fmt.int],
  purchase_value: ['Facturación', null], roas: ['ROAS', fmt.x], messages: ['Conversaciones', fmt.int], leads: ['Leads', fmt.int],
  impressions: ['Impresiones', fmt.int], reach: ['Alcance', fmt.int], link_clicks: ['Clics en enlace', fmt.int],
  link_ctr: ['CTR enlace', fmt.pct], cpc: ['CPC', null], cpm: ['CPM', null], frequency: ['Frecuencia', (v) => fmt.dec(v)],
};

const adsChartBlock = (id, title = 'Evolución diaria') => `
  <div class="card mb-6">
    <div class="card-h"><div class="h2">${title}</div><div class="flex-1"></div>
      <span class="t3 text-xs">Inversión vs.</span>
      <select class="select select-sm" id="${id}-metric">${Object.entries(AD_CHART_METRICS).map(([k, [l]]) => `<option value="${k}">${l}</option>`).join('')}</select>
    </div>
    <div class="p-4 h-[300px]"><canvas id="${id}"></canvas></div>
  </div>`;

function mountAdsChart(id, daily, currency) {
  const sel = $(`#${id}-metric`);
  if (!sel) return;
  sel.value = store.get('chartMetric', 'results');
  let c;
  const draw = () => {
    dropChart(c);
    const [label, f] = AD_CHART_METRICS[sel.value];
    const money = (v) => fmt.money(v, currency);
    c = chart($('#' + id), daily.map((d) => fmt.date(d.date)), [
      { label: 'Inversión', data: daily.map((d) => d.spend), type: 'bar', fmt: money, color: cssVar('--t3') },
      { label, data: daily.map((d) => d[sel.value]), axis: 'y1', fmt: f || money, color: cssVar('--accent') },
    ]);
  };
  sel.addEventListener('change', () => { store.set('chartMetric', sel.value); draw(); });
  draw();
}

const ALERT_ICON = { danger: ['alert', 't-bad'], warning: ['alert', 't-warn'], info: ['eye', 't-accent'] };
const alertsBlock = (alerts) => (alerts && alerts.length ? `
  <div class="card mb-6"><div class="card-h"><div class="h2">Alertas y oportunidades</div><span class="pill amber plain">${alerts.length}</span></div>
    <ul class="divide max-h-72 overflow-y-auto">${alerts.map((a) => { const [ic, cls] = ALERT_ICON[a.level] || ALERT_ICON.info; return `
      <li class="px-5 py-3 flex items-start gap-3"><span class="${cls} mt-0.5">${icon(ic, 15)}</span><span class="flex-1 t1">${esc(a.text)}</span>
        ${a.account ? `<a class="link text-xs" href="#/ads/${esc(a.account)}">Abrir</a>` : ''}</li>`; }).join('')}</ul></div>` : '');

// =========================================================================
// Bloque "Hoy, en vivo"
// =========================================================================
const liveTodayBlock = (id) => `<div id="${id}" class="card mb-6"><div class="sk h-[104px]" style="border-radius:var(--radius)"></div></div>`;

async function mountLiveToday(id, clientId) {
  const box = $('#' + id);
  if (!box) return;
  try {
    const params = { preset: 'today', client: clientId };
    const d = await api('overview', params);
    if (!d.totals.length) { box.remove(); return; }
    const t = d.totals[0];
    const m = t.metrics, cur = t.currency;
    const items = [
      ['Inversión hoy', fmt.money(m.spend, cur)],
      ['Resultados hoy', fmt.int(m.results)],
      ['Costo por resultado', fmt.money(m.cost_per_result, cur)],
      m.purchase_value > 0 ? ['Facturación hoy', fmt.money(m.purchase_value, cur)] : ['Conversaciones hoy', fmt.int(m.messages)],
      ['Personas alcanzadas', fmt.compact(m.reach)],
    ];
    const active = d.accounts.reduce((s, a) => s + a.active_campaigns, 0);
    const prev = box.dataset.spend;
    box.innerHTML = `
      <div class="card-h" style="min-height:48px"><span class="live-dot on"></span><div class="h2">Hoy, en vivo</div>
        <span class="t3 text-xs">${active} campañas activas · ${cur}${d.totals.length > 1 ? ' · otras monedas en el detalle' : ''}</span></div>
      <div class="ticker">${items.map(([l, v]) => `<div><div class="t2 text-[12.5px] font-medium">${l}</div><div class="hero-num t1 mt-2" style="font-size:30px">${v}</div></div>`).join('')}</div>`;
    if (prev !== undefined && prev !== String(m.spend)) box.classList.add('flash');
    box.dataset.spend = String(m.spend);
  } catch {
    box.remove();
  }
}

// =========================================================================
// Admin · Resumen general
// =========================================================================
async function viewOverview() {
  crumbs([{ label: 'Resumen general' }]);
  if (state.boot.connections === 0) {
    return { html: empty('Conectá tu cuenta de Meta', 'Para ver campañas, páginas e Instagram primero conectá un usuario de Facebook o un token de System User con acceso a tus portfolios comerciales.', '<a class="btn btn-primary" href="#/settings">Ir a Configuración</a>', 'link') };
  }
  const d = await api('overview', rp());
  if (!d.accounts.length) {
    return { html: empty('No hay cuentas publicitarias visibles', 'Sincronizá la conexión o revisá en Configuración si las ocultaste.', '<a class="btn" href="#/settings">Configuración</a>') };
  }
  const currencies = d.totals.map((t) => t.currency);
  let cur = store.get('ovCurrency', currencies[0]);
  if (!currencies.includes(cur)) cur = currencies[0];
  const total = d.totals.find((t) => t.currency === cur);
  const series = d.daily[cur] || {};
  const daily = Object.entries(series).map(([date, v]) => ({ date, ...v, cost_per_result: v.results ? v.spend / v.results : null }));

  const cols = [
    { key: 'name', label: 'Cuenta', left: true, value: (r) => r.name,
      html: (r) => `<div class="font-medium">${esc(r.name)}</div><div class="sub">${esc(r.business || 'Sin portfolio')} · ${esc(r.currency)}</div>` },
    { key: 'status', label: 'Estado', value: (r) => r.status_label, html: (r) => `<span class="pill ${[1, 201].includes(r.status) ? 'green' : 'red'}">${esc(r.status_label)}</span>` },
    { key: 'active_campaigns', label: 'Activas', value: (r) => r.active_campaigns },
    { key: 'spend', label: 'Inversión', value: (r) => r.metrics.spend, html: (r) => `${fmt.money(r.metrics.spend, r.currency)}<div>${delta(r.metrics.spend, r.previous.spend)}</div>` },
    { key: 'results', label: 'Resultados', value: (r) => r.metrics.results, html: (r) => `${fmt.int(r.metrics.results)} <span class="sub">${esc(r.metrics.result_label)}</span>` },
    { key: 'cpr', label: 'Costo/resultado', value: (r) => r.metrics.cost_per_result, html: (r) => `${fmt.money(r.metrics.cost_per_result, r.currency)}<div>${delta(r.metrics.cost_per_result, r.previous.cost_per_result, true)}</div>` },
    { key: 'purchases', label: 'Compras', value: (r) => r.metrics.purchases },
    { key: 'roas', label: 'ROAS', value: (r) => r.metrics.roas, html: (r) => fmt.x(r.metrics.roas) },
    { key: 'messages', label: 'Conversaciones', value: (r) => r.metrics.messages },
    { key: 'ctr', label: 'CTR enlace', value: (r) => r.metrics.link_ctr, html: (r) => fmt.pct(r.metrics.link_ctr) },
    { key: 'cpm', label: 'CPM', value: (r) => r.metrics.cpm, html: (r) => fmt.money(r.metrics.cpm, r.currency) },
  ];

  const html = `
    ${pageHead('Resumen general', `${d.accounts.length} cuentas publicitarias · ${fmt.dateLong(d.range.since)} → ${fmt.dateLong(d.range.until)} · vs. ${d.range.days} días anteriores`,
      currencies.length > 1 ? `<div class="seg" id="cur-seg">${currencies.map((c) => `<button class="${c === cur ? 'on' : ''}" data-cur="${esc(c)}">${esc(c)}</button>`).join('')}</div>` : '')}
    ${state.range.preset !== 'today' ? liveTodayBlock('live-today') : ''}
    ${adsKpis(total.metrics, total.previous, cur, daily)}
    <div class="grid xl:grid-cols-3 gap-6 mb-6">
      <div class="card xl:col-span-2"><div class="card-h"><div class="h2">Inversión, resultados y facturación</div><span class="t3 text-xs">${esc(cur)}</span></div><div class="p-4 h-[300px]"><canvas id="ov-chart"></canvas></div></div>
      <div class="card"><div class="card-h"><div class="h2">Distribución de inversión</div></div><div class="p-4 h-[300px]"><canvas id="ov-pie"></canvas></div></div>
    </div>
    ${alertsBlock(d.alerts)}
    ${tableHtml('ov', cols, d.accounts, { title: 'Cuentas publicitarias', sort: 'spend', csvName: 'cuentas', onClick: (r) => (location.hash = `#/ads/${r.id}`),
      filters: [{ label: 'Todas' }, { label: 'Con inversión', fn: (r) => r.metrics.spend > 0 }, { label: 'Con problemas', fn: (r) => ![1, 201].includes(r.status) || r.error }] })}`;

  return {
    html,
    mount() {
      mountTable('ov');
      mountLiveToday('live-today');
      const days = daily.map((x) => fmt.date(x.date));
      chart($('#ov-chart'), days, [
        { label: 'Inversión', data: daily.map((x) => x.spend), type: 'bar', fmt: (v) => fmt.money(v, cur), color: cssVar('--t3') },
        { label: 'Facturación', data: daily.map((x) => x.purchase_value), fmt: (v) => fmt.money(v, cur), color: cssVar('--c2') },
        { label: 'Resultados', data: daily.map((x) => x.results), axis: 'y1', color: cssVar('--accent') },
      ]);
      const withSpend = d.accounts.filter((a) => a.currency === cur && a.metrics.spend > 0);
      const top = withSpend.slice(0, 6);
      const rest = withSpend.slice(6).reduce((s, a) => s + a.metrics.spend, 0);
      doughnut($('#ov-pie'), [...top.map((a) => a.name), ...(rest ? ['Otras'] : [])], [...top.map((a) => a.metrics.spend), ...(rest ? [rest] : [])], (v) => fmt.money(v, cur));
      $$('#cur-seg [data-cur]').forEach((b) => b.addEventListener('click', () => { store.set('ovCurrency', b.dataset.cur); render({ silent: true }); }));
    },
  };
}

// =========================================================================
// Cuentas publicitarias
// =========================================================================
async function viewAdAccounts() {
  crumbs([{ label: 'Campañas' }]);
  const a = await api('assets', { client: portalClient() });
  if (!a.ad_accounts.length) return { html: empty('Sin cuentas publicitarias', isClient() ? 'Todavía no hay cuentas publicitarias asignadas.' : 'Conectá Meta o sincronizá desde Configuración.', isClient() ? '' : '<a class="btn btn-primary" href="#/settings">Configuración</a>', 'target') };
  if (isClient() && a.ad_accounts.length === 1) { location.replace('#/ads/' + a.ad_accounts[0].external_id); return { html: '' }; }
  const groups = {};
  a.ad_accounts.forEach((acc) => { (groups[acc.data.business?.name || 'Sin portfolio comercial'] ||= []).push(acc); });
  const ok = (s) => [1, 201].includes(+s);
  const html = `
    ${pageHead('Cuentas publicitarias', `${a.ad_accounts.length} cuentas${isClient() ? '' : ` en ${Object.keys(groups).length} portfolios`}`, `<div class="flex gap-2 flex-wrap"><a class="btn btn-primary" href="#/ads/top">${icon('up', 14)}Creativos que más traccionan</a><input class="input w-64" placeholder="Buscar cuenta" id="acc-search"></div>`)}
    ${Object.entries(groups).sort(([x], [y]) => x.localeCompare(y)).map(([biz, list]) => `
      <section class="mb-8" data-group>
        <div class="eyebrow mb-3 flex items-center gap-2">${icon('briefcase', 13)}${esc(biz)}<span class="opacity-60">· ${list.length}</span></div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3">${list.map((acc) => `
          <a href="#/ads/${esc(acc.external_id)}" data-name="${esc(acc.name.toLowerCase())} ${esc(acc.external_id)}" class="card card-link p-4 block">
            <div class="flex items-start gap-3">
              <div class="mono-avatar w-9 h-9 text-xs" style="background:var(--sunk);color:var(--t2)">${esc(initials(acc.name))}</div>
              <div class="min-w-0 flex-1"><div class="font-medium t1 truncate">${esc(acc.name)}</div><div class="t3 text-xs mt-0.5 mono">${esc(acc.external_id)}</div></div>
              <span class="pill ${ok(acc.data.account_status) ? 'green' : 'red'}">${ok(acc.data.account_status) ? 'Activa' : 'Revisar'}</span>
            </div>
            <div class="flex text-xs t2 mt-4 pt-3 hair-t"><span>${esc(acc.data.currency || '')}</span><span class="flex-1"></span><span>Histórico ${fmt.moneyShort((acc.data.amount_spent || 0) / 100, acc.data.currency)}</span></div>
          </a>`).join('')}</div>
      </section>`).join('')}`;
  return {
    html,
    mount() {
      $('#acc-search').addEventListener('input', (e) => {
        const q = e.target.value.toLowerCase();
        $$('[data-name]').forEach((el) => { el.style.display = el.dataset.name.includes(q) ? '' : 'none'; });
        $$('[data-group]').forEach((g) => { g.style.display = $$('[data-name]', g).some((el) => el.style.display !== 'none') ? '' : 'none'; });
      });
    },
  };
}

// =========================================================================
// Desgloses
// =========================================================================
const BREAKDOWNS = { age_gender: 'Edad y género', age: 'Edad', gender: 'Género', platform: 'Plataforma', placement: 'Ubicación', device: 'Dispositivo', region: 'Provincia', country: 'País', hour: 'Hora del día' };

const breakdownBlock = (id) => `
  <div class="card mb-6" id="${id}">
    <div class="card-h"><div class="h2">¿Quién y dónde responde mejor?</div><div class="flex-1"></div>
      <select class="select select-sm" data-bd-sel>${Object.entries(BREAKDOWNS).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></div>
    <div class="grid lg:grid-cols-5">
      <div class="lg:col-span-2 p-4 h-[340px] hair-b lg:border-b-0 lg:hair-r"><canvas></canvas></div>
      <div class="lg:col-span-3 scroll-x max-h-[340px]" data-bd-table><div class="p-6 t3">Cargando…</div></div>
    </div>
  </div>`;

function mountBreakdown(id, objectId, accountId, currency, objective) {
  const root = $('#' + id);
  if (!root) return;
  let c;
  const sel = $('[data-bd-sel]', root);
  const load = async (type) => {
    $('[data-bd-table]', root).innerHTML = '<div class="p-6 t3">Cargando…</div>';
    try {
      const rows = await api('breakdown', rp({ id: objectId, account: accountId, type, objective }));
      if (['hour', 'age', 'age_gender'].includes(type)) rows.sort((a, b) => a.label.localeCompare(b.label));
      dropChart(c);
      const top = ['hour', 'age', 'age_gender'].includes(type) ? rows : rows.slice(0, 12);
      c = chart($('canvas', root), top.map((r) => r.label), type === 'hour'
        ? [{ label: 'Inversión', data: top.map((r) => r.spend), type: 'bar', fmt: (v) => fmt.money(v, currency), color: cssVar('--t3') }, { label: 'Resultados', data: top.map((r) => r.results), axis: 'y1', color: cssVar('--accent') }]
        : [{ label: 'Inversión', data: top.map((r) => r.spend), type: 'bar', fmt: (v) => fmt.money(v, currency), color: cssVar('--accent') }],
      { horizontal: type !== 'hour' });
      const money = (v) => fmt.money(v, currency);
      const best = rows.filter((r) => r.results > 0).sort((a, b) => a.cost_per_result - b.cost_per_result)[0];
      $('[data-bd-table]', root).innerHTML = `<table class="tbl"><thead><tr><th>Segmento</th><th>Inversión</th><th>Resultados</th><th>Costo/res.</th><th>CTR enlace</th><th>CPM</th><th>ROAS</th></tr></thead><tbody>
        ${rows.map((r) => `<tr><td>${esc(r.label)}${best && r === best ? ' <span class="pill accent plain">Mejor costo</span>' : ''}</td><td>${money(r.spend)}</td><td>${fmt.int(r.results)}</td><td>${money(r.cost_per_result)}</td><td>${fmt.pct(r.link_ctr)}</td><td>${money(r.cpm)}</td><td>${fmt.x(r.roas)}</td></tr>`).join('') || '<tr><td colspan="7" style="text-align:center" class="t3 !py-8">Sin datos</td></tr>'}
      </tbody></table>`;
    } catch (e) {
      $('[data-bd-table]', root).innerHTML = `<div class="p-6 t-bad">${esc(e.message)}</div>`;
    }
  };
  sel.addEventListener('change', () => load(sel.value));
  load('age_gender');
}

// =========================================================================
// Cuenta / campaña / conjunto
// =========================================================================
const entityName = (r, sub = '') => `<div class="font-medium max-w-[360px] truncate" title="${esc(r.name)}">${esc(r.name)}</div><div class="flex gap-2 items-center mt-1">${statusPill(r.effective_status)}<span class="sub truncate max-w-[260px]">${sub}</span></div>`;
const budgetText = (r, cur) => (r.budget ? `${fmt.money(r.budget, cur)} <span class="sub">${esc(r.budget_type)}</span>` : '<span class="t3">—</span>');
const liveFilters = [
  { label: 'Todas' },
  { label: 'Activas', fn: (r) => r.effective_status === 'ACTIVE' },
  { label: 'Con inversión', fn: (r) => r.metrics.spend > 0 },
  { label: 'Con problemas', fn: (r) => ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_BILLING_INFO'].includes(r.effective_status) },
];
const backLink = (href, label) => `<a href="${href}" class="inline-flex items-center gap-1 t3 hover:underline text-[13px] mb-3"><span style="transform:rotate(90deg);display:inline-flex">${icon('down', 14)}</span>${esc(label)}</a>`;

async function viewAccount(accountId) {
  const d = await api('account', rp({ id: accountId }));
  const a = d.account;
  const cur = a.currency;
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: a.name }]);
  const cols = [
    { key: 'name', label: 'Campaña', left: true, value: (r) => r.name, html: (r) => entityName(r, esc(OBJECTIVES[r.objective] || r.objective || '')) },
    { key: 'budget', label: 'Presupuesto', value: (r) => r.budget, html: (r) => budgetText(r, cur) },
    ...metricCols(cur, undefined, { compact: isClient() }),
  ];
  const capPct = a.spend_cap ? Math.min(100, (a.amount_spent / a.spend_cap) * 100) : null;
  const html = `
    ${isClient() ? backLink('#/', 'Inicio') : ''}
    ${pageHead(esc(a.name), `<span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1"><span class="pill ${[1, 201].includes(a.status) ? 'green' : 'red'}">${esc(a.status_label)}</span><span class="mono t3">${esc(a.id)}</span>${a.business ? `<span>${esc(a.business)}</span>` : ''}<span>${esc(cur)}</span>${a.payment ? `<span>${esc(a.payment)}</span>` : ''}</span>`,
      isClient() ? '' : `<a class="btn" target="_blank" rel="noopener" href="https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=${esc(a.id.replace('act_', ''))}">${icon('external', 14)}Ads Manager</a>`)}
    ${capPct !== null && !isClient() ? `<div class="card p-4 mb-6"><div class="flex text-xs t2 mb-2"><span>Límite de gasto de la cuenta</span><span class="flex-1"></span><span class="num">${fmt.money(a.amount_spent, cur)} de ${fmt.money(a.spend_cap, cur)}</span></div><div class="bar"><i style="width:${capPct}%;${capPct > 90 ? 'background:var(--bad)' : ''}"></i></div></div>` : ''}
    ${adsKpis(d.metrics, d.previous, cur, d.daily, { client: isClient() })}
    ${adsChartBlock('acc-chart')}
    <div id="acc-top"></div>
    ${isClient() ? '' : alertsBlock(d.alerts)}
    ${tableHtml('camps', cols, d.campaigns, { title: `Campañas <span class="t3 font-normal">${d.campaigns.length}</span>`, sort: 'spend', csvName: 'campanas', filters: liveFilters,
      onClick: (r) => (location.hash = `#/ads/${accountId}/c/${r.id}`) })}
    ${d.sliced ? '' : `<div class="mt-6">${breakdownBlock('acc-bd')}</div>`}`;
  return { html, mount() { mountTable('camps'); mountAdsChart('acc-chart', d.daily, cur); topCreatives($('#acc-top'), { account: accountId, limit: 8 }); if (!d.sliced) mountBreakdown('acc-bd', accountId, accountId, cur); } };
}

function entityHeader(e, cur, sub) {
  return pageHead(esc(e.name), `<span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1">${statusPill(e.effective_status)}${sub ? `<span>${esc(sub)}</span>` : ''}
    ${e.budget ? `<span>Presupuesto ${budgetText(e, cur)}</span>` : ''}${e.start_time ? `<span>Desde ${fmt.date(e.start_time)}</span>` : ''}${e.stop_time ? `<span>Hasta ${fmt.date(e.stop_time)}</span>` : ''}</span>`);
}

async function viewCampaign(accountId, campaignId) {
  const [acc, adsets] = await Promise.all([api('account', rp({ id: accountId })), api('adsets', rp({ id: campaignId, account: accountId }))]);
  const camp = acc.campaigns.find((c) => c.id === campaignId);
  if (!camp) return { html: empty('Campaña no encontrada', 'Puede estar archivada o eliminada.') };
  const cur = acc.account.currency;
  const daily = await api('timeseries', rp({ id: campaignId, account: accountId, objective: camp.objective }));
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: acc.account.name, href: `#/ads/${accountId}` }, { label: camp.name }]);
  const cols = [
    { key: 'name', label: 'Conjunto de anuncios', left: true, value: (r) => r.name, html: (r) => entityName(r, esc(r.targeting)) },
    { key: 'budget', label: 'Presupuesto', value: (r) => r.budget, html: (r) => budgetText(r, cur) },
    ...metricCols(cur, undefined, { compact: isClient() }),
  ];
  const html = `
    ${isClient() ? backLink(`#/ads/${accountId}`, acc.account.name) : ''}
    ${entityHeader(camp, cur, `Objetivo: ${OBJECTIVES[camp.objective] || camp.objective || '—'}`)}
    ${adsKpis(camp.metrics, null, cur, daily, { client: isClient() })}
    ${adsChartBlock('camp-chart')}
    <div id="camp-ads" class="mb-6"></div>
    ${tableHtml('adsets', cols, adsets, { title: `Conjuntos de anuncios <span class="t3 font-normal">${adsets.length}</span>`, sort: 'spend', csvName: 'conjuntos', filters: liveFilters,
      onClick: (r) => (location.hash = `#/ads/${accountId}/c/${campaignId}/s/${r.id}`) })}
    <div class="mt-6">${breakdownBlock('camp-bd')}</div>`;
  return {
    html,
    mount() {
      mountTable('adsets');
      mountAdsChart('camp-chart', daily, cur);
      mountBreakdown('camp-bd', campaignId, accountId, cur, camp.objective);
      loadAds('camp-ads', campaignId, accountId, cur);
    },
  };
}

async function viewAdset(accountId, campaignId, adsetId) {
  const [acc, adsets] = await Promise.all([api('account', rp({ id: accountId })), api('adsets', rp({ id: campaignId, account: accountId }))]);
  const set = adsets.find((s) => s.id === adsetId);
  const camp = acc.campaigns.find((c) => c.id === campaignId);
  if (!set) return { html: empty('Conjunto no encontrado') };
  const cur = acc.account.currency;
  const daily = await api('timeseries', rp({ id: adsetId, account: accountId, objective: set.objective }));
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: acc.account.name, href: `#/ads/${accountId}` }, { label: camp ? camp.name : 'Campaña', href: `#/ads/${accountId}/c/${campaignId}` }, { label: set.name }]);
  const html = `
    ${isClient() ? backLink(`#/ads/${accountId}/c/${campaignId}`, camp ? camp.name : 'Campaña') : ''}
    ${entityHeader(set, cur, set.targeting)}
    ${adsKpis(set.metrics, null, cur, daily, { client: isClient() })}
    ${adsChartBlock('set-chart')}
    <div id="set-ads" class="mb-6"></div>
    ${breakdownBlock('set-bd')}`;
  return {
    html,
    mount() {
      mountAdsChart('set-chart', daily, cur);
      mountBreakdown('set-bd', adsetId, accountId, cur, set.objective);
      loadAds('set-ads', adsetId, accountId, cur);
    },
  };
}

// =========================================================================
// Anuncios (creativos)
// =========================================================================
async function loadAds(containerId, parentId, accountId, cur) {
  const box = $('#' + containerId);
  box.innerHTML = '<div class="sk h-72"></div>';
  let ads;
  try { ads = await api('ads', rp({ id: parentId, account: accountId })); } catch (e) { box.innerHTML = errorBox(e.message, false); return; }
  const sortKeys = { spend: 'Inversión', results: 'Resultados', cost_per_result: 'Menor costo/resultado', link_ctr: 'CTR', roas: 'ROAS' };
  let sortBy = store.get('adsSort', 'spend');
  let viewMode = store.get('adsView', 'cards');
  const money = (v) => fmt.money(v, cur);
  const cols = [
    { key: 'name', label: 'Anuncio', left: true, value: (r) => r.name, html: (r) => `<div class="flex items-center gap-3">${r.creative.thumbnail ? `<img src="${esc(r.creative.thumbnail)}" class="w-10 h-10 rounded-lg object-cover" loading="lazy" alt="">` : '<div class="w-10 h-10 rounded-lg sunk"></div>'}<div>${entityName(r)}</div></div>` },
    ...metricCols(cur, undefined, { compact: isClient() }),
  ];
  const draw = () => {
    const sorted = [...ads].sort((a, b) => {
      const inv = sortBy === 'cost_per_result';
      const va = a.metrics[sortBy] ?? (inv ? Infinity : -Infinity);
      const vb = b.metrics[sortBy] ?? (inv ? Infinity : -Infinity);
      return inv ? va - vb : vb - va;
    });
    const maxSpend = Math.max(1, ...ads.map((x) => x.metrics.spend));
    const header = `
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <div class="h2">Anuncios <span class="t3 font-normal">${ads.length}</span></div><div class="flex-1"></div>
        <select class="select select-sm" data-sort>${Object.entries(sortKeys).map(([k, l]) => `<option value="${k}" ${k === sortBy ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <div class="seg"><button class="${viewMode === 'cards' ? 'on' : ''}" data-mode="cards">Creativos</button><button class="${viewMode === 'table' ? 'on' : ''}" data-mode="table">Tabla</button></div>
      </div>`;
    if (viewMode === 'table') {
      box.innerHTML = header + tableHtml('ads-' + containerId, cols, sorted, { csvName: 'anuncios', filters: liveFilters, onClick: (r) => adModal(r, cur) });
      mountTable('ads-' + containerId);
    } else {
      box.innerHTML = header + (sorted.length ? `<div class="grid sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">${sorted.map((ad, i) => `
        <button class="card card-link overflow-hidden flex flex-col text-left" data-ad="${i}">
          <div class="relative w-full thumb-wrap">${ad.creative.thumbnail ? `<img src="${esc(ad.creative.thumbnail)}" class="thumb" loading="lazy" alt="">` : '<div class="thumb-ph">Sin vista previa</div>'}
            <div class="absolute top-2.5 left-2.5">${statusPill(ad.effective_status)}</div>
            ${i < 3 && ad.metrics.spend > 0 ? `<div class="absolute top-2.5 right-2.5 pill plain" style="background:var(--panel);color:var(--t1)">Top ${i + 1}</div>` : ''}</div>
          <div class="p-4 flex-1 flex flex-col w-full">
            <div class="font-medium t1 line-clamp-2">${esc(ad.name)}</div>
            ${ad.creative.body ? `<div class="t3 text-xs mt-1 line-clamp-2">${esc(ad.creative.body)}</div>` : ''}
            <div class="grid grid-cols-3 gap-3 mt-4 pt-3 hair-t">
              <div><div class="t3 text-[11px]">Inversión</div><div class="num font-semibold t1">${fmt.moneyShort(ad.metrics.spend, cur)}</div></div>
              <div><div class="t3 text-[11px] truncate">${esc(ad.metrics.result_label)}</div><div class="num font-semibold t1">${fmt.int(ad.metrics.results)}</div></div>
              <div><div class="t3 text-[11px]">Costo/res.</div><div class="num font-semibold t1">${fmt.moneyShort(ad.metrics.cost_per_result, cur)}</div></div>
              <div><div class="t3 text-[11px]">CTR</div><div class="num t2">${fmt.pct(ad.metrics.link_ctr)}</div></div>
              <div><div class="t3 text-[11px]">CPM</div><div class="num t2">${fmt.moneyShort(ad.metrics.cpm, cur)}</div></div>
              <div><div class="t3 text-[11px]">ROAS</div><div class="num t2">${fmt.x(ad.metrics.roas)}</div></div>
            </div>
            <div class="bar mt-3"><i style="width:${(ad.metrics.spend / maxSpend) * 100}%"></i></div>
          </div>
        </button>`).join('')}</div>` : empty('Sin anuncios en este período'));
      $$('[data-ad]', box).forEach((el) => el.addEventListener('click', () => adModal(sorted[+el.dataset.ad], cur)));
    }
    $('[data-sort]', box).addEventListener('change', (e) => { sortBy = e.target.value; store.set('adsSort', sortBy); draw(); });
    $$('[data-mode]', box).forEach((b) => b.addEventListener('click', () => { viewMode = b.dataset.mode; store.set('adsView', viewMode); draw(); }));
  };
  draw();
}

// =========================================================================
// Creativos activos que más traccionan
// =========================================================================
const TOP_METRICS = {
  results: ['Resultados', fmt.int], cost_per_result: ['Menor costo por resultado', null], link_ctr: ['CTR de enlace', fmt.pct],
  roas: ['ROAS', fmt.x], purchases: ['Compras', fmt.int], messages: ['Conversaciones', fmt.int], link_clicks: ['Clics en enlace', fmt.int],
  spend: ['Inversión', null], cpm: ['Menor CPM', null],
};
const topFmt = (k, cur) => TOP_METRICS[k][1] || ((v) => fmt.money(v, cur));
const topLabel = (k) => TOP_METRICS[k][0].replace(/^Menor /, '').replace(/^c/, 'C');

/** Bloque reutilizable. opts: { account, limit, title, full } */
async function topCreatives(box, opts = {}) {
  if (!box) return;
  let metric = store.get('topMetric', 'results');
  const limit = opts.limit || 12;
  const load = async () => {
    box.innerHTML = `<div class="card p-5 mb-6"><div class="sk h-8 w-72 mb-4"></div><div class="sk h-56"></div></div>`;
    let d;
    try { d = await api('top_ads', rp({ account: opts.account, metric, limit })); } catch (e) { box.innerHTML = errorBox(e.message, false); return; }
    paint(d);
  };
  const paint = (d) => {
    const ads = d.ads;
    const cur = d.currencies[0] || 'ARS';
    const f = (a) => topFmt(metric, a.account.currency);
    const sel = `<select class="select select-sm" data-top-metric>${Object.entries(TOP_METRICS).map(([k, [l]]) => `<option value="${k}" ${k === metric ? 'selected' : ''}>${l}</option>`).join('')}</select>`;
    const head = `<div class="card-h flex-wrap gap-2"><div class="min-w-0"><div class="h2">${esc(opts.title || 'Creativos activos que más traccionan')}</div>
        <div class="t3 text-xs mt-0.5">${d.active_total} anuncios activos con inversión en el período · ranking por ${esc(lower(TOP_METRICS[metric][0]))}</div></div>
        <div class="flex-1"></div><span class="t3 text-xs">Ordenar por</span>${sel}
        ${opts.full ? '' : `<a class="btn btn-sm" href="#/ads/top">${icon('grid', 13)}Ver todos</a>`}</div>`;
    if (!ads.length) {
      box.innerHTML = `<div class="card mb-6">${head}<div class="p-8 text-center t3">No hay anuncios activos con inversión en este período${metric === 'cost_per_result' ? ' y con resultados' : ''}.</div></div>`;
      hydrateIcons(box); bind(); return;
    }
    const best = ads[0];
    const cards = ads.map((a, i) => {
      const series = a.daily.map((x) => x[metric]);
      const v = a.metrics[metric];
      return `<button class="card card-link overflow-hidden flex flex-col text-left top-card" data-top="${i}">
        <div class="relative w-full thumb-wrap">${a.creative.thumbnail ? `<img src="${esc(a.creative.thumbnail)}" class="thumb" loading="lazy" alt="">` : '<div class="thumb-ph">Sin vista previa</div>'}
          <div class="absolute top-2.5 left-2.5 rank-badge ${i < 3 ? 'gold' : ''}">#${i + 1}</div>
          ${a.creative.type ? `<div class="absolute top-2.5 right-2.5 pill plain" style="background:var(--panel);color:var(--t2)">${esc(lower(a.creative.type).replace(/_/g, ' '))}</div>` : ''}</div>
        <div class="p-4 flex-1 flex flex-col w-full gap-1">
          <div class="font-medium t1 line-clamp-2">${esc(a.name)}</div>
          <div class="t3 text-[11.5px] truncate" title="${esc(a.campaign.name || '')}">${esc(a.campaign.name || '')}${opts.account ? '' : ` · ${esc(a.account.name)}`}</div>
          <div class="flex items-end gap-2 mt-2"><div><div class="t3 text-[11px]">${esc(topLabel(metric))}</div><div class="num text-xl font-semibold t1">${f(a)(v)}</div></div><div class="flex-1 min-w-0 h-[34px] top-spark">${sparkline(series, { height: 34 })}</div></div>
          <div class="grid grid-cols-3 gap-2 mt-2 pt-3 hair-t">
            <div><div class="t3 text-[11px]">Inversión</div><div class="num font-medium t1">${fmt.moneyShort(a.metrics.spend, a.account.currency)}</div></div>
            <div><div class="t3 text-[11px] truncate">${esc(a.metrics.result_label || 'Resultados')}</div><div class="num font-medium t1">${fmt.int(a.metrics.results)}</div></div>
            <div><div class="t3 text-[11px]">Costo/res.</div><div class="num font-medium t1">${fmt.moneyShort(a.metrics.cost_per_result, a.account.currency)}</div></div>
            <div><div class="t3 text-[11px]">CTR</div><div class="num t2">${fmt.pct(a.metrics.link_ctr)}</div></div>
            <div><div class="t3 text-[11px]">CPM</div><div class="num t2">${fmt.moneyShort(a.metrics.cpm, a.account.currency)}</div></div>
            <div><div class="t3 text-[11px]">ROAS</div><div class="num t2">${fmt.x(a.metrics.roas)}</div></div>
          </div>
        </div></button>`;
    }).join('');
    box.innerHTML = `<div class="card mb-6">${head}
      <div class="grid lg:grid-cols-[1fr_300px] gap-0 hair-b">
        <div class="p-4" style="height:${Math.max(220, Math.min(ads.length, 12) * 30 + 40)}px"><canvas data-top-chart></canvas></div>
        <div class="p-5 lg:border-l bd flex flex-col gap-3">
          <div class="eyebrow">El que más tracciona</div>
          <div class="flex gap-3 items-center">${best.creative.thumbnail ? `<img src="${esc(best.creative.thumbnail)}" class="w-14 h-14 rounded-xl object-cover" alt="">` : ''}<div class="min-w-0"><div class="font-semibold t1 line-clamp-2">${esc(best.name)}</div><div class="t3 text-xs truncate">${esc(best.campaign.name || '')}</div></div></div>
          <div class="num text-3xl font-semibold t1">${f(best)(best.metrics[metric])}</div>
          <div class="t2 text-[13px]">${esc(topLabel(metric))} en el período${ads[1] && ads[1].metrics[metric] ? ` · ${(() => { if (d.asc) { const p = (1 - best.metrics[metric] / ads[1].metrics[metric]) * 100; return p > 0.5 ? fmt.dec(p, 0) + '% más barato que el #2' : 'parejo con el #2'; } const r = best.metrics[metric] / ads[1].metrics[metric]; return r > 1.005 ? fmt.dec((r - 1) * 100, 0) + '% más que el #2' : 'parejo con el #2'; })()}` : ''}.</div>
          <div class="h-[60px] top-spark">${sparkline(best.daily.map((x) => x[metric]), { height: 60 })}</div>
        </div>
      </div>
      <div class="p-4 grid sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">${cards}</div>
      ${d.errors.length ? `<div class="px-5 pb-4 t-warn text-xs">${esc(d.errors.join(' · '))}</div>` : ''}
    </div>`;
    hydrateIcons(box);
    const top = ads.slice(0, 12);
    chart($('[data-top-chart]', box), top.map((a, i) => `#${i + 1} ${a.name.length > 34 ? a.name.slice(0, 33) + '…' : a.name}`),
      [{ label: topLabel(metric), data: top.map((a) => a.metrics[metric]), type: 'bar', fmt: topFmt(metric, cur), color: cssVar('--accent'), alpha: 'cc' }],
      { horizontal: true, fmtY: topFmt(metric, cur) });
    $$('[data-top]', box).forEach((el) => el.addEventListener('click', () => topAdModal(ads[+el.dataset.top], metric)));
    bind();
  };
  const bind = () => $('[data-top-metric]', box)?.addEventListener('change', (e) => { metric = e.target.value; store.set('topMetric', metric); load(); });
  await load();
}

function topAdModal(a, metric) {
  adModal(a, a.account.currency);
  const card = $('#modal .modal-card');
  $('.card-h', card).insertAdjacentHTML('afterend', `<div class="px-5 pt-5 hair-b pb-4"><div class="flex items-center gap-2 mb-2"><div class="h2">Día a día</div><div class="flex-1"></div>
    <select class="select select-sm" id="top-modal-metric">${Object.entries(TOP_METRICS).filter(([k]) => k !== 'spend').map(([k, [l]]) => `<option value="${k}" ${k === metric ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
    <div class="h-[240px]"><canvas id="top-modal-chart"></canvas></div></div>`);
  let c;
  const draw = (k) => {
    dropChart(c);
    const money = (v) => fmt.money(v, a.account.currency);
    c = chart($('#top-modal-chart'), a.daily.map((x) => fmt.date(x.date)), [
      { label: 'Inversión', data: a.daily.map((x) => x.spend), type: 'bar', fmt: money, color: cssVar('--t3') },
      { label: topLabel(k), data: a.daily.map((x) => x[k]), axis: 'y1', fmt: topFmt(k, a.account.currency), color: cssVar('--accent') },
    ], { fmtY: money, fmtY1: topFmt(k, a.account.currency) });
  };
  $('#top-modal-metric').addEventListener('change', (e) => draw(e.target.value));
  draw(metric === 'spend' ? 'results' : metric);
}

async function viewTopAds() {
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: 'Creativos que más traccionan' }]);
  return {
    html: `${pageHead('Creativos que más traccionan', 'Anuncios activos de todas tus cuentas, ordenados por la métrica que elijas. Tocá uno para ver su evolución día a día.', `<a class="btn" href="#/ads">${icon('target', 14)}Cuentas</a>`)}
      <div id="top-all"></div>`,
    mount() { topCreatives($('#top-all'), { limit: 24, full: true, title: 'Ranking de creativos activos' }); },
  };
}

function adModal(ad, cur) {
  const m = ad.metrics;
  const money = (v) => fmt.money(v, cur);
  const rows = [
    ['Inversión', money(m.spend)], [m.result_label, fmt.int(m.results)], ['Costo por resultado', money(m.cost_per_result)],
    ['Alcance', fmt.int(m.reach)], ['Impresiones', fmt.int(m.impressions)], ['Frecuencia', fmt.dec(m.frequency)],
    ['Clics en enlace', fmt.int(m.link_clicks)], ['CTR enlace', fmt.pct(m.link_ctr)], ['CPC', money(m.cpc)], ['CPM', money(m.cpm)],
    ['Visitas a la landing', fmt.int(m.landing_page_views)], ['Agregados al carrito', fmt.int(m.add_to_cart)], ['Pagos iniciados', fmt.int(m.initiate_checkout)],
    ['Compras', fmt.int(m.purchases)], ['Valor de compras', money(m.purchase_value)], ['ROAS', fmt.x(m.roas)],
    ['Conversaciones', fmt.int(m.messages)], ['Leads', fmt.int(m.leads)], ['Reproducciones (3 s)', fmt.int(m.video_views)],
  ];
  const postUrl = ad.creative.post_id ? `https://www.facebook.com/${ad.creative.post_id}` : null;
  modal(`
    ${modalHead(ad.name, statusPill(ad.effective_status))}
    <div class="grid md:grid-cols-2 gap-6 p-5">
      <div>
        ${ad.creative.thumbnail ? `<img src="${esc(ad.creative.thumbnail)}" class="w-full rounded-xl sunk" alt="">` : '<div class="thumb-ph rounded-xl">Sin vista previa</div>'}
        ${ad.creative.title ? `<div class="mt-4 font-semibold t1">${esc(ad.creative.title)}</div>` : ''}
        ${ad.creative.body ? `<p class="mt-2 t2 whitespace-pre-line leading-relaxed">${esc(ad.creative.body)}</p>` : ''}
        ${ad.creative.cta ? `<div class="mt-2"><span class="pill plain">CTA · ${esc(ad.creative.cta.replace(/_/g, ' ').toLowerCase())}</span></div>` : ''}
        ${ad.review_feedback ? `<div class="mt-3 pill red plain" style="height:auto;padding:8px 10px;border-radius:10px;white-space:normal">Revisión de Meta: ${esc(Object.values(ad.review_feedback).join(' · '))}</div>` : ''}
        <div class="flex flex-wrap gap-2 mt-4">
          ${ad.preview_link ? `<a class="btn btn-sm" target="_blank" rel="noopener" href="${esc(ad.preview_link)}">${icon('eye', 14)}Vista previa</a>` : ''}
          ${postUrl ? `<a class="btn btn-sm" target="_blank" rel="noopener" href="${esc(postUrl)}">${icon('external', 14)}Facebook</a>` : ''}
          ${ad.creative.ig_link ? `<a class="btn btn-sm" target="_blank" rel="noopener" href="${esc(ad.creative.ig_link)}">${icon('instagram', 14)}Instagram</a>` : ''}
        </div>
      </div>
      <dl class="grid grid-cols-2 content-start">${rows.map(([k, v]) => `<dt class="t2 py-2 hair-b">${esc(k)}</dt><dd class="text-right num font-medium t1 py-2 hair-b">${v}</dd>`).join('')}</dl>
    </div>`);
}

// =========================================================================
// Redes: páginas de Facebook e Instagram
// =========================================================================
const assetAvatar = (url, size = 44, label = '') => (url
  ? `<img src="${esc(url)}" class="avatar" style="width:${size}px;height:${size}px" loading="lazy" alt="" onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'mono-avatar',style:'width:${size}px;height:${size}px;background:var(--sunk);color:var(--t2);border-radius:999px',textContent:'${esc(initials(label))}'}))">`
  : `<div class="mono-avatar" style="width:${size}px;height:${size}px;background:var(--sunk);color:var(--t2);border-radius:999px">${esc(initials(label))}</div>`);

function assetCards(list, kind) {
  return `<div class="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3">${list.map((x) => {
    const ig = kind === 'ig';
    return `
    <a href="#/${ig ? 'ig' : 'pages'}/${x.external_id}" class="card card-link p-4 flex items-center gap-3" data-name="${esc(x.name.toLowerCase())}">
      ${assetAvatar(x.data.picture || x.data.profile_picture_url, 46, x.name)}
      <div class="min-w-0 flex-1"><div class="font-medium t1 truncate">${esc(x.name)}</div>
        <div class="t3 text-xs truncate mt-0.5 flex items-center gap-1.5">${icon(ig ? 'instagram' : 'page', 12)}${ig ? 'Instagram' : esc(x.data.category || 'Facebook')}</div></div>
      <div class="text-right"><div class="num font-semibold t1">${fmt.compact(x.data.followers_count)}</div><div class="t3 text-[11px]">seguidores</div></div>
    </a>`;
  }).join('')}</div>`;
}

async function viewSocial() {
  crumbs([{ label: 'Redes sociales' }]);
  const a = await api('assets', { client: portalClient() });
  if (!a.pages.length && !a.ig_accounts.length) return { html: empty('Sin redes conectadas', 'Todavía no hay páginas ni cuentas de Instagram asignadas.', '', 'social') };
  return {
    html: `${isClient() ? backLink('#/', 'Inicio') : ''}${pageHead('Redes sociales', 'Facebook e Instagram')}
      ${a.ig_accounts.length ? `<div class="eyebrow mb-3">Instagram</div>${assetCards(a.ig_accounts, 'ig')}` : ''}
      ${a.pages.length ? `<div class="eyebrow mb-3 mt-8">Páginas de Facebook</div>${assetCards(a.pages, 'page')}` : ''}`,
  };
}

async function viewPages() {
  crumbs([{ label: 'Páginas de Facebook' }]);
  const a = await api('assets');
  if (!a.pages.length) return { html: empty('Sin páginas', 'El usuario conectado necesita rol en las páginas (o el System User tenerlas asignadas).', '<a class="btn" href="#/settings">Configuración</a>', 'page') };
  return { html: pageHead('Páginas de Facebook', `${a.pages.length} páginas`, '<input class="input w-64" placeholder="Buscar" id="as-search">') + assetCards(a.pages, 'page'), mount: () => searchCards('#as-search') };
}

async function viewIgList() {
  crumbs([{ label: 'Instagram' }]);
  const a = await api('assets');
  if (!a.ig_accounts.length) return { html: empty('Sin cuentas de Instagram', 'Las cuentas deben ser profesionales y estar vinculadas a una página de Facebook.', '<a class="btn" href="#/settings">Configuración</a>', 'instagram') };
  return { html: pageHead('Instagram', `${a.ig_accounts.length} cuentas`, '<input class="input w-64" placeholder="Buscar" id="as-search">') + assetCards(a.ig_accounts, 'ig'), mount: () => searchCards('#as-search') };
}

function searchCards(sel) {
  $(sel)?.addEventListener('input', (e) => { const q = e.target.value.toLowerCase(); $$('[data-name]').forEach((el) => { el.style.display = el.dataset.name.includes(q) ? '' : 'none'; }); });
}

function seriesChartBlock(id, title, series, labels, preferred) {
  const keys = Object.keys(series);
  if (!keys.length) return '';
  const sel = preferred.filter((k) => keys.includes(k)).slice(0, 2);
  if (!sel.length) sel.push(keys[0]);
  return `<div class="card mb-6"><div class="card-h"><div class="h2">${esc(title)}</div><div class="flex-1"></div>
    <select class="select select-sm" id="${id}-a">${keys.map((k) => `<option value="${k}" ${k === sel[0] ? 'selected' : ''}>${esc(labels[k] || k)}</option>`).join('')}</select>
    <span class="t3 text-xs">vs.</span>
    <select class="select select-sm" id="${id}-b"><option value="">—</option>${keys.map((k) => `<option value="${k}" ${k === sel[1] ? 'selected' : ''}>${esc(labels[k] || k)}</option>`).join('')}</select>
    </div><div class="p-4 h-[300px]"><canvas id="${id}"></canvas></div></div>`;
}

function mountSeriesChart(id, series, labels) {
  const a = $(`#${id}-a`), b = $(`#${id}-b`);
  if (!a) return;
  let c;
  const draw = () => {
    dropChart(c);
    const days = [...new Set(Object.values(series).flatMap((s) => Object.keys(s)))].sort();
    const ds = [{ label: labels[a.value] || a.value, data: days.map((d) => series[a.value]?.[d] ?? null), type: 'bar', color: cssVar('--t3') }];
    if (b.value) ds.push({ label: labels[b.value] || b.value, data: days.map((d) => series[b.value]?.[d] ?? null), axis: 'y1', color: cssVar('--accent') });
    c = chart($('#' + id), days.map(fmt.date), ds);
  };
  a.addEventListener('change', draw);
  b.addEventListener('change', draw);
  draw();
}

function followersBlock(id, history) {
  if (!history || history.length < 2) return '';
  const first = history[0].followers, last = history[history.length - 1].followers;
  return `<div class="card mb-6"><div class="card-h"><div class="h2">Evolución de seguidores</div>
    <span class="t3 text-xs">${history.length} días registrados</span><div class="flex-1"></div>
    ${first != null && last != null ? `<span class="num font-semibold ${last >= first ? 't-pos' : 't-bad'}">${last >= first ? '+' : ''}${fmt.int(last - first)}</span>` : ''}</div>
    <div class="p-4 h-[240px]"><canvas id="${id}"></canvas></div></div>`;
}

function mountFollowers(id, history) {
  if (!$('#' + id)) return;
  const c = chart($('#' + id), history.map((h) => fmt.date(h.date)), [{ label: 'Seguidores', data: history.map((h) => h.followers), color: cssVar('--accent') }]);
  c.options.scales.y.beginAtZero = false;
  c.update();
}

async function viewPage(pageId) {
  const [d, posts] = await Promise.all([api('page', rp({ id: pageId })), api('page_posts', rp({ id: pageId }))]);
  const p = d.page;
  crumbs([{ label: 'Páginas', href: '#/pages' }, { label: p.name }]);
  const t = d.totals;
  const pick = (...keys) => keys.map((k) => t[k]).find((v) => v != null);
  const s = (...keys) => { const k = keys.find((x) => d.series[x]); return k ? sparkline(Object.values(d.series[k])) : ''; };
  const kpis = [
    ['Seguidores', fmt.int(p.followers), ''],
    ['Visualizaciones', fmt.compact(pick('page_media_view', 'page_impressions')), s('page_media_view', 'page_impressions')],
    ['Espectadores', fmt.compact(pick('page_total_media_view_unique', 'page_impressions_unique')), s('page_total_media_view_unique', 'page_impressions_unique')],
    ['Interacciones', fmt.int(pick('page_post_engagements') ?? posts.reduce((x, y) => x + y.interactions, 0)), s('page_post_engagements')],
    ['Nuevos seguidores', fmt.int(t.page_daily_follows_unique), s('page_daily_follows_unique')],
    ['Visitas a la página', fmt.int(t.page_views_total), s('page_views_total')],
    ['Reproducciones de video', fmt.compact(t.page_video_views), s('page_video_views')],
    ['Publicaciones', fmt.int(posts.length), ''],
  ].filter(([, v]) => v !== '—');

  const cols = [
    { key: 'message', label: 'Publicación', left: true, value: (r) => r.message,
      html: (r) => `<div class="flex items-center gap-3 max-w-[440px]">${r.picture ? `<img src="${esc(r.picture)}" class="w-11 h-11 rounded-lg object-cover shrink-0" loading="lazy" alt="">` : '<div class="w-11 h-11 rounded-lg sunk shrink-0"></div>'}<div class="min-w-0"><div class="truncate">${esc(r.message || '(sin texto)')}</div><div class="sub">${fmt.datetime(r.created_time)}${r.permalink ? ` · <a class="link" target="_blank" rel="noopener" href="${esc(r.permalink)}">ver</a>` : ''}</div></div></div>` },
    { key: 'views', label: 'Visualizaciones', value: (r) => r.views },
    { key: 'reach', label: 'Alcance', value: (r) => r.reach },
    { key: 'reactions', label: 'Reacciones', value: (r) => r.reactions, total: (rows) => fmt.int(rows.reduce((x, r) => x + r.reactions, 0)) },
    { key: 'comments', label: 'Comentarios', value: (r) => r.comments, total: (rows) => fmt.int(rows.reduce((x, r) => x + r.comments, 0)) },
    { key: 'shares', label: 'Compartidos', value: (r) => r.shares, total: (rows) => fmt.int(rows.reduce((x, r) => x + r.shares, 0)) },
    { key: 'clicks', label: 'Clics', value: (r) => r.clicks },
    { key: 'engagement_rate', label: 'Engagement', value: (r) => r.engagement_rate, html: (r) => fmt.pct(r.engagement_rate) },
  ];

  const html = `
    ${isClient() ? backLink('#/social', 'Redes sociales') : ''}
    <div class="flex flex-wrap items-center gap-4 mb-6">
      ${assetAvatar(p.picture, 56, p.name)}
      <div class="min-w-0"><h1 class="h1">${esc(p.name)}</h1><div class="t2 mt-0.5">${esc(p.category || 'Página de Facebook')} · ${fmt.int(p.followers)} seguidores</div></div>
      <div class="flex-1"></div>${p.link ? `<a class="btn" target="_blank" rel="noopener" href="${esc(p.link)}">${icon('external', 14)}Abrir página</a>` : ''}
    </div>
    <div class="kpi-row grid-cols-2 sm:grid-cols-4 mb-6" style="display:grid">${kpis.map(([l, v, sp]) => kpi(l, v, { spark: sp })).join('')}</div>
    ${seriesChartBlock('pg-chart', 'Métricas diarias', d.series, d.labels, ['page_media_view', 'page_impressions', 'page_post_engagements', 'page_daily_follows_unique'])}
    ${followersBlock('pg-followers', d.followers_history)}
    ${topPosts(posts.map((x) => ({ ...x, thumbnail: x.picture, caption: x.message, timestamp: x.created_time, likes: x.reactions })), 'Publicaciones con mejor rendimiento')}
    ${tableHtml('posts', cols, posts, { title: `Todas las publicaciones <span class="t3 font-normal">${posts.length}</span>`, sort: 'reach', csvName: 'posteos-facebook', onClick: (r) => r.permalink && window.open(r.permalink, '_blank', 'noopener') })}`;
  return { html, mount() { mountSeriesChart('pg-chart', d.series, d.labels); mountFollowers('pg-followers', d.followers_history); mountTable('posts'); } };
}

/** Podio de las 3 mejores publicaciones por alcance. */
function topPosts(items, title) {
  const top = [...items].filter((x) => x.reach || x.interactions).sort((a, b) => (b.reach || 0) - (a.reach || 0)).slice(0, 3);
  if (!top.length) return '';
  return `<div class="mb-6"><div class="h2 mb-3">${esc(title)}</div><div class="grid md:grid-cols-3 gap-4">${top.map((m, i) => `
    <a href="${esc(m.permalink || '#')}" target="_blank" rel="noopener" class="card card-link overflow-hidden flex">
      <div class="w-28 shrink-0">${m.thumbnail ? `<img src="${esc(m.thumbnail)}" class="thumb h-full" loading="lazy" alt="">` : '<div class="thumb-ph h-full"></div>'}</div>
      <div class="p-3.5 min-w-0 flex flex-col">
        <div class="flex items-center gap-2"><span class="pill accent plain">#${i + 1}</span><span class="t3 text-xs">${fmt.date(m.timestamp)}</span></div>
        <div class="t2 text-xs mt-2 line-clamp-2">${esc(m.caption || '')}</div>
        <div class="flex gap-4 mt-auto pt-2"><div><div class="t3 text-[11px]">Alcance</div><div class="num font-semibold t1">${fmt.compact(m.reach)}</div></div>
          <div><div class="t3 text-[11px]">Interacciones</div><div class="num font-semibold t1">${fmt.compact(m.interactions)}</div></div>
          <div><div class="t3 text-[11px]">Eng.</div><div class="num font-semibold t1">${fmt.pct(m.engagement_rate)}</div></div></div>
      </div>
    </a>`).join('')}</div></div>`;
}

function mediaGrid(items, kind) {
  if (!items.length) return empty(kind === 'STORY' ? 'No hay historias activas' : 'Sin publicaciones en este período', kind === 'STORY' ? 'Meta sólo muestra las historias de las últimas 24 horas.' : '', '', 'instagram');
  const label = (m) => ({ REELS: 'Reel', STORY: 'Historia', FEED: m.media_type === 'CAROUSEL_ALBUM' ? 'Carrusel' : 'Post' }[m.type]);
  return `<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5 gap-3">${items.map((m) => `
    <a href="${esc(m.permalink || '#')}" target="_blank" rel="noopener" class="card card-link overflow-hidden block">
      <div class="relative thumb-wrap">${m.thumbnail ? `<img src="${esc(m.thumbnail)}" class="thumb" loading="lazy" alt="">` : '<div class="thumb-ph">Sin miniatura</div>'}
        <span class="absolute top-2 left-2 pill plain" style="background:var(--panel);color:var(--t1)">${m.type === 'REELS' ? icon('play', 11) : ''}${label(m)}</span></div>
      <div class="p-3">
        <div class="t3 text-[11px]">${fmt.datetime(m.timestamp)}</div>
        <div class="t2 text-xs line-clamp-2 h-8 mt-1">${esc(m.caption)}</div>
        <div class="grid grid-cols-3 gap-1 mt-3 pt-2 hair-t text-[11px]">
          <div><div class="t3">Alcance</div><div class="num font-semibold t1 text-[13px]">${fmt.compact(m.reach)}</div></div>
          <div><div class="t3">Vistas</div><div class="num font-semibold t1 text-[13px]">${fmt.compact(m.views)}</div></div>
          <div><div class="t3">Eng.</div><div class="num font-semibold t1 text-[13px]">${fmt.pct(m.engagement_rate)}</div></div>
          ${kind === 'STORY'
            ? `<div><div class="t3">Respuestas</div><div class="num t2">${fmt.int(m.replies)}</div></div><div><div class="t3">Compart.</div><div class="num t2">${fmt.int(m.shares)}</div></div><div><div class="t3">Interac.</div><div class="num t2">${fmt.int(m.interactions)}</div></div>`
            : `<div><div class="t3">Me gusta</div><div class="num t2">${fmt.compact(m.likes)}</div></div><div><div class="t3">Coment.</div><div class="num t2">${fmt.int(m.comments)}</div></div><div><div class="t3">Guard.</div><div class="num t2">${fmt.int(m.saved)}</div></div>`}
        </div>
      </div>
    </a>`).join('')}</div>`;
}

const IG_COLS = [
  { key: 'timestamp', label: 'Fecha', left: true, value: (r) => r.timestamp, html: (r) => fmt.datetime(r.timestamp) },
  { key: 'type', label: 'Tipo', value: (r) => r.type },
  { key: 'caption', label: 'Texto', value: (r) => (r.caption || '').slice(0, 70) },
  { key: 'reach', label: 'Alcance', value: (r) => r.reach }, { key: 'views', label: 'Visualizaciones', value: (r) => r.views },
  { key: 'likes', label: 'Me gusta', value: (r) => r.likes }, { key: 'comments', label: 'Comentarios', value: (r) => r.comments },
  { key: 'saved', label: 'Guardados', value: (r) => r.saved }, { key: 'shares', label: 'Compartidos', value: (r) => r.shares },
  { key: 'interactions', label: 'Interacciones', value: (r) => r.interactions },
  { key: 'engagement_rate', label: 'Engagement', value: (r) => r.engagement_rate, html: (r) => fmt.pct(r.engagement_rate) },
  { key: 'permalink', label: 'Link', value: (r) => r.permalink, html: (r) => (r.permalink ? `<a class="link" target="_blank" rel="noopener" href="${esc(r.permalink)}">abrir</a>` : '—') },
];

async function viewIg(igId) {
  const d = await api('ig', rp({ id: igId }));
  const a = d.account;
  const t = d.totals;
  crumbs([{ label: 'Instagram', href: '#/ig' }, { label: '@' + (a.username || igId) }]);
  const s = (k) => (d.series[k] ? sparkline(Object.values(d.series[k])) : '');
  const kpis = [
    ['Seguidores', fmt.int(a.followers), s('follower_count')], ['Visualizaciones', fmt.compact(t.views), ''], ['Alcance', fmt.compact(t.reach), s('reach')],
    ['Cuentas que interactuaron', fmt.compact(t.accounts_engaged), ''], ['Interacciones', fmt.compact(t.total_interactions), ''],
    ['Me gusta', fmt.compact(t.likes), ''], ['Comentarios', fmt.int(t.comments), ''], ['Compartidos', fmt.int(t.shares), ''], ['Guardados', fmt.int(t.saves), ''],
    ['Seguidores netos', fmt.int(t.follows_and_unfollows), ''], ['Toques en enlaces', fmt.int(t.profile_links_taps), ''], ['Visitas al perfil', fmt.int(t.profile_views), ''],
  ].filter(([, v]) => v !== '—');
  const tabs = { FEED: 'Publicaciones', REELS: 'Reels', STORY: 'Historias', AUD: 'Audiencia', TABLE: 'Tabla' };

  const html = `
    ${isClient() ? backLink('#/social', 'Redes sociales') : ''}
    <div class="flex flex-wrap items-center gap-4 mb-6">
      ${assetAvatar(a.picture, 60, a.username)}
      <div class="min-w-0"><h1 class="h1">@${esc(a.username)}</h1>
        <div class="t2 mt-0.5"><span class="num t1 font-medium">${fmt.int(a.followers)}</span> seguidores · <span class="num">${fmt.int(a.follows)}</span> seguidos · <span class="num">${fmt.int(a.media_count)}</span> publicaciones</div>
        ${a.biography ? `<div class="t3 text-xs mt-1 max-w-2xl line-clamp-2">${esc(a.biography)}</div>` : ''}</div>
      <div class="flex-1"></div><a class="btn" target="_blank" rel="noopener" href="https://instagram.com/${esc(a.username)}">${icon('external', 14)}Abrir perfil</a>
    </div>
    ${d.totals_capped ? '<div class="t3 text-xs mb-3">Instagram calcula los totales de la cuenta sobre 30 días como máximo: se muestran los últimos 30 días del rango.</div>' : ''}
    <div class="kpi-row grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 mb-6" style="display:grid">${kpis.map(([l, v, sp]) => kpi(l, v, { spark: sp })).join('')}</div>
    ${seriesChartBlock('ig-chart', 'Métricas diarias', d.series, d.labels, ['reach', 'follower_count'])}
    ${followersBlock('ig-followers', d.followers_history)}
    <div id="ig-top"></div>
    <div class="card"><div class="px-5 flex flex-wrap items-center gap-4 hair-b"><div class="utabs !border-0 flex-1" id="ig-tabs">${Object.entries(tabs).map(([k, l], i) => `<button class="${i === 0 ? 'active' : ''}" data-t="${k}">${l}</button>`).join('')}</div>
      <select class="select select-sm my-2" id="ig-sort"><option value="timestamp">Más recientes</option><option value="reach">Mayor alcance</option><option value="views">Más vistas</option><option value="interactions">Más interacciones</option><option value="engagement_rate">Mayor engagement</option><option value="saved">Más guardados</option><option value="shares">Más compartidos</option></select></div>
      <div class="p-4" id="ig-body"><div class="sk h-64"></div></div></div>`;

  return {
    html,
    mount() {
      mountSeriesChart('ig-chart', d.series, d.labels);
      mountFollowers('ig-followers', d.followers_history);
      let media = null, stories = null, tab = 'FEED';
      const sortSel = $('#ig-sort');
      sortSel.value = store.get('igSort', 'timestamp');
      const body = $('#ig-body');
      const sorted = (list) => [...list].sort((x, y) => (sortSel.value === 'timestamp' ? String(y.timestamp).localeCompare(String(x.timestamp)) : (y[sortSel.value] ?? -1) - (x[sortSel.value] ?? -1)));
      const show = async () => {
        $$('#ig-tabs [data-t]').forEach((b) => b.classList.toggle('active', b.dataset.t === tab));
        sortSel.style.visibility = tab === 'AUD' ? 'hidden' : '';
        try {
          if (tab === 'AUD') {
            body.innerHTML = '<div class="sk h-64"></div>';
            const aud = await api('ig_audience', { id: igId });
            body.innerHTML = audienceHtml(aud);
            mountAudience(aud);
            return;
          }
          if (tab === 'STORY') {
            stories ??= await api('ig_stories', { id: igId });
            body.innerHTML = mediaGrid(sorted(stories), 'STORY');
            return;
          }
          media ??= await api('ig_media', rp({ id: igId }));
          if (!$('#ig-top').innerHTML) $('#ig-top').innerHTML = topPosts(media, 'Lo mejor del período');
          if (tab === 'TABLE') {
            body.innerHTML = tableHtml('igt', IG_COLS, sorted(media), { csvName: 'instagram-' + a.username });
            mountTable('igt');
            return;
          }
          body.innerHTML = mediaGrid(sorted(media.filter((m) => m.type === tab)), tab);
        } catch (e) { body.innerHTML = errorBox(e.message, false); }
      };
      $('#ig-tabs').addEventListener('click', (e) => { const b = e.target.closest('[data-t]'); if (b) { tab = b.dataset.t; show(); } });
      sortSel.addEventListener('change', () => { store.set('igSort', sortSel.value); show(); });
      show();
    },
  };
}

function audienceHtml(aud) {
  if (!Object.values(aud).some((l) => l.length)) return empty('Sin datos demográficos', 'Instagram los muestra sólo para cuentas con más de 100 seguidores.', '', 'users');
  return `<div class="grid md:grid-cols-2 gap-8 p-2">
    <div><div class="eyebrow mb-3">Edad</div><div class="h-56"><canvas id="aud-age"></canvas></div></div>
    <div><div class="eyebrow mb-3">Género</div><div class="h-56"><canvas id="aud-gender"></canvas></div></div>
    <div><div class="eyebrow mb-3">Ciudades principales</div><div class="h-72"><canvas id="aud-city"></canvas></div></div>
    <div><div class="eyebrow mb-3">Países</div><div class="h-72"><canvas id="aud-country"></canvas></div></div></div>`;
}

function mountAudience(aud) {
  const bar = (id, rows, horizontal) => rows.length && chart($('#' + id), rows.map((r) => r.label), [{ label: 'Seguidores', data: rows.map((r) => r.value), type: 'bar', color: cssVar('--accent') }], { horizontal });
  bar('aud-age', aud.age || [], false);
  bar('aud-city', (aud.city || []).slice(0, 10), true);
  bar('aud-country', (aud.country || []).slice(0, 10), true);
  doughnut($('#aud-gender'), (aud.gender || []).map((g) => g.label), (aud.gender || []).map((g) => g.value));
}

// =========================================================================
// Portal del cliente
// =========================================================================
function todayLabel() {
  const s = new Date().toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long' });
  return s.charAt(0).toUpperCase() + s.slice(1);
}

const previewBanner = (cid, name) => (state.preview ? (crumbs([{ label: 'Clientes', href: '#/clients' }, { label: name, href: isAdmin() ? `#/clients/${cid}` : `#/preview/${cid}` }, { label: 'Vista del cliente' }]), `
  <div class="card mb-5 px-4 py-3 flex flex-wrap items-center gap-3 no-print" style="border-color:var(--accent-line);background:var(--accent-soft)">
    <span class="t-accent">${icon('eye', 16)}</span><div class="flex-1 t1 text-[13px]">Vista previa del portal de <b>${esc(name)}</b>: así lo ve el cliente.</div>
    <a class="btn btn-sm" href="#/preview/${cid}">Inicio</a><a class="btn btn-sm" href="#/preview/${cid}/sales">Ventas</a><a class="btn btn-sm" href="#/preview/${cid}/work">Trabajo</a><a class="btn btn-sm" href="#/preview/${cid}/report">Reporte</a><a class="btn btn-sm" href="#/preview/${cid}/sheets">Planillas</a>
    <a class="btn btn-sm btn-primary" href="${isAdmin() ? `#/clients/${cid}` : '#/clients'}">Salir de la vista previa</a>
  </div>`) : '');

async function clientBrand(cid) {
  if (isClient()) return state.boot.client;
  return api('client_brand', { id: cid });
}

function updateCard(u, compact = false) {
  return `
    <div class="tl-item">
      <div class="tl-dot ${esc(u.status)}">${u.status === 'hecho' ? icon('check', 12) : '<i></i>'}</div>
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2"><span class="t1 font-medium">${esc(u.title)}</span><span class="pill plain">${esc(u.kind_label)}</span></div>
        ${u.body && !compact ? `<div class="t2 mt-1 whitespace-pre-line leading-relaxed">${esc(u.body)}</div>` : ''}
        ${u.body && compact ? `<div class="t2 text-xs mt-0.5 line-clamp-2">${esc(u.body)}</div>` : ''}
        <div class="t3 text-xs mt-1.5 flex flex-wrap gap-x-3">
          <span>${esc(u.status_label)} · ${fmt.ago(u.updated_at)}</span>
          ${u.due_date ? `<span class="inline-flex items-center gap-1">${icon('calendar', 12)}${u.status === 'hecho' ? '' : 'Entrega '}${fmt.date(u.due_date)}</span>` : ''}
          ${u.link ? `<a class="link inline-flex items-center gap-1" href="${esc(u.link)}" target="_blank" rel="noopener">${icon('link', 12)}Ver material</a>` : ''}
        </div>
      </div>
    </div>`;
}

/** Usuario del cliente: cambia la marca que está mirando. */
async function switchBrand(id) {
  state.brand = id || null;
  store.set('brand', state.brand);
  state.boot = await api('bootstrap');
  setAccent(state.boot.client?.color);
  renderNav();
  if (location.hash === '#/' || location.hash === '') render(); else location.hash = '#/';
}

/** Cliente con varias marcas (ej. PayLab Gestión): todas las marcas en una pantalla. */
async function viewOrgHome(cid, org) {
  setAccent(org.color);
  const d = await api('org_overview', rp({ client: cid }));
  const t = d.totals;
  const cur = d.org.currency || 'ARS';
  const m = (v) => fmt.money(v, cur);
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  const first = (state.boot.user.name || '').split(' ')[0];
  const open = (b) => (isClient() ? `data-open-brand="${b.id}"` : `href="#/preview/${b.id}"`);
  const card = (b) => {
    const L = esc(b.sales_label || 'Ventas');
    const gp = b.goal_pct;
    return `<${isClient() ? 'button' : 'a'} ${open(b)} class="card card-link p-5 text-left block w-full">
      <div class="flex items-center gap-3">
        <div class="mono-avatar w-10 h-10 text-xs" style="background:${esc(b.color)};color:#fff">${b.logo_url ? `<img src="${esc(b.logo_url)}" class="w-full h-full object-contain rounded-[10px] bg-white" alt="">` : esc(initials(b.name))}</div>
        <div class="min-w-0 flex-1"><div class="h2 truncate">${esc(b.name)}</div><div class="t3 text-xs">${esc(b.gestion_label || 'Marca')}${b.last_day ? ` · última carga ${fmt.date(b.last_day)}` : ''}</div></div>
        <div class="w-24 h-8">${sparkline(b.series, { color: b.color, height: 32 })}</div>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 hair-t">
        <div><div class="t3 text-[11px]">Inversión +${tax}</div><div class="num font-semibold t1">${b.has_ads ? fmt.moneyShort(b.ads.spend_tax, cur) : '—'}</div></div>
        <div><div class="t3 text-[11px]">Conversaciones</div><div class="num font-semibold t1">${b.has_ads ? fmt.int(b.ads.messages) : '—'}</div></div>
        <div><div class="t3 text-[11px]">${L}</div><div class="num font-semibold t1">${fmt.int(b.sales.orders)}</div></div>
        <div><div class="t3 text-[11px]">Costo por ${esc(lower(b.sales_label || 'venta').replace(/s$/, ''))}</div><div class="num font-semibold t1">${b.cost_per_sale ? fmt.moneyShort(b.cost_per_sale, cur) : '—'}</div></div>
      </div>
      ${b.goal ? `<div class="mt-4"><div class="flex text-xs t2"><span>Objetivo del mes</span><span class="flex-1"></span><span class="num"><b class="t1">${fmt.int(b.goal_mtd)}</b> de ${fmt.int(b.goal)} · ${fmt.dec(gp, 0)}%</span></div>
        <div class="relative h-1.5 rounded-full sunk overflow-hidden mt-1.5"><div class="absolute inset-y-0 left-0 rounded-full" style="width:${Math.min(100, gp)}%;background:${gp >= b.expected_pct ? 'var(--pos)' : gp >= b.expected_pct * 0.8 ? 'var(--warn)' : 'var(--bad)'}"></div><div class="absolute inset-y-0 w-px" style="left:${Math.min(100, b.expected_pct)}%;background:var(--t2)"></div></div></div>` : ''}
    </${isClient() ? 'button' : 'a'}>`;
  };
  const html = `
    ${previewBanner(cid, org.name)}
    <div class="flex flex-wrap items-end gap-4 mb-6">
      <div><div class="eyebrow">${esc(todayLabel())}</div>
        <h1 class="text-[28px] font-semibold tracking-tight t1 mt-1">${isClient() ? `Hola${first ? ', ' + esc(first) : ''}.` : esc(org.name)}</h1>
        <div class="t2 mt-1">Todas las marcas de <b class="t1">${esc(d.org.name)}</b> · ${fmt.dateLong(d.range.since)} – ${fmt.dateLong(d.range.until)}</div></div>
    </div>
    <div class="kpi-row grid-cols-2 lg:grid-cols-5 mb-6" style="display:grid">
      ${kpi(`Inversión +${tax}`, fmt.moneyShort(t.spend_tax, cur), { spark: '' })}
      ${kpi('Conversaciones', fmt.int(t.messages), { spark: '' })}
      ${kpi('Ventas / gestiones', fmt.int(t.orders), { spark: '' })}
      ${kpi('Costo por venta', t.cost_per_sale ? m(t.cost_per_sale) : '—', { spark: '' })}
      ${kpi('Facturación', t.revenue ? fmt.moneyShort(t.revenue, cur) : '—', { spark: '' })}
    </div>
    <div class="flex items-center gap-2 mb-3"><div class="h2">Por marca</div><span class="t3 text-xs">${isClient() ? 'tocá una marca para ver su detalle' : 'abrí una marca para ver su portal'}</span></div>
    <div class="grid md:grid-cols-2 gap-4 mb-6">${d.brands.map(card).join('')}</div>
    ${d.brands.length > 1 ? `<div class="card mb-6"><div class="card-h"><div class="h2">Inversión y resultados por marca</div></div><div class="p-4 h-[300px]"><canvas id="org-chart"></canvas></div></div>` : ''}`;
  return {
    html,
    mount() {
      $$('[data-open-brand]').forEach((b) => b.addEventListener('click', () => { switchBrand(+b.dataset.openBrand); const sel = $('#brand-switch'); if (sel) sel.value = b.dataset.openBrand; }));
      if (d.brands.length > 1) {
        chart($('#org-chart'), d.brands.map((b) => b.name), [
          { label: `Inversión +${tax}`, data: d.brands.map((b) => b.ads.spend_tax), type: 'bar', fmt: m, color: cssVar('--t3') },
          { label: 'Ventas / gestiones', data: d.brands.map((b) => b.sales.orders), type: 'bar', axis: 'y1', fmt: fmt.int, color: cssVar('--accent') },
        ], { fmtY: (v) => fmt.moneyShort(v, cur), fmtY1: fmt.int });
      }
    },
  };
}

/** Planillas de Google vinculadas (cualquier tipo): se ven tal cual y se actualizan solas. */
async function viewSheets(cid) {
  cid = +cid || portalClient();
  const brand = await clientBrand(cid);
  setAccent(brand.color);
  crumbs([{ label: 'Planillas' }]);
  const list = await api('sheets', { client: cid });
  let si = 0, ti = 0, q = '';
  const html = `${previewBanner(cid, brand.name)}${pageHead('Planillas', `Lo que el equipo va cargando en Google Sheets para <b class="t1">${esc(brand.name)}</b>. Se actualiza solo cada 5 minutos.`)}
    <div id="sheet-box"></div>`;
  const paint = () => {
    const box = $('#sheet-box');
    if (!list.length) { box.innerHTML = empty('Sin planillas', 'Todavía no hay planillas vinculadas.', '', 'grid'); return; }
    const s = list[si];
    const tabs = s.tabs || [];
    const tab = tabs[ti] || { rows: [] };
    const [head = [], ...rows] = tab.rows;
    const shown = q ? rows.filter((r) => r.join(' ').toLowerCase().includes(q)) : rows;
    box.innerHTML = `
      <div class="flex flex-wrap items-center gap-2 mb-3">
        ${list.length > 1 ? `<div class="seg">${list.map((x, i) => `<button data-si="${i}" class="${i === si ? 'on' : ''}">${esc(x.name)}</button>`).join('')}</div>` : `<div class="h2">${esc(s.name)}</div>`}
        <div class="flex-1"></div>
        <span class="t3 text-xs">${s.last_sync_at ? 'Actualizada ' + fmt.ago(s.last_sync_at) : ''}${s.last_error ? ` · <span class="t-warn">${esc(s.last_error)}</span>` : ''}</span>
        <input class="input select-sm w-48" id="sheet-q" placeholder="Buscar en la planilla" value="${esc(q)}">
      </div>
      ${tabs.length > 1 ? `<div class="flex flex-wrap gap-1.5 mb-3">${tabs.map((x, i) => `<button class="pill ${i === ti ? 'blue' : 'plain'} !h-7 !px-3" data-ti="${i}">${esc(x.name)}</button>`).join('')}</div>` : ''}
      <div class="card overflow-hidden"><div class="scroll-x max-h-[70vh] overflow-y-auto"><table class="tbl sheet-tbl"><thead><tr>${head.map((h) => `<th style="text-align:left">${esc(h)}</th>`).join('')}</tr></thead>
        <tbody>${shown.map((r) => `<tr>${head.map((_, i) => `<td style="text-align:left">${esc(r[i] ?? '')}</td>`).join('')}</tr>`).join('') || `<tr><td class="t3" colspan="${head.length || 1}">Sin filas</td></tr>`}</tbody></table></div></div>`;
  };
  return {
    html,
    mount() {
      paint();
      $('#sheet-box').addEventListener('click', (e) => {
        const a = e.target.closest('[data-si]'); if (a) { si = +a.dataset.si; ti = 0; return paint(); }
        const b = e.target.closest('[data-ti]'); if (b) { ti = +b.dataset.ti; return paint(); }
      });
      $('#sheet-box').addEventListener('input', (e) => { if (e.target.id === 'sheet-q') { q = e.target.value.toLowerCase(); const pos = e.target.selectionStart; paint(); const i = $('#sheet-q'); i.focus(); i.setSelectionRange(pos, pos); } });
    },
  };
}

async function viewClientHome(cid) {
  cid = +cid || portalClient();
  const brand = await clientBrand(cid);
  if (brand.has_brands && brand.kind !== 'marca') return viewOrgHome(cid, brand);
  setAccent(brand.color);
  const [ov, assets, updates] = await Promise.all([
    api('overview', rp({ client: cid })), api('assets', { client: cid }), api('updates', { client: cid }),
  ]);
  const first = (state.boot.user.name || '').split(' ')[0];
  const t = ov.totals[0];
  const cur = t?.currency;
  const daily = t ? Object.entries(ov.daily[cur] || {}).map(([date, v]) => ({ date, ...v, cost_per_result: v.results ? v.spend / v.results : null })) : [];
  const open = updates.filter((u) => u.status !== 'hecho');
  const done = updates.filter((u) => u.status === 'hecho').slice(0, 3);
  const socials = [...assets.ig_accounts.map((x) => ({ ...x, kind: 'ig' })), ...assets.pages.map((x) => ({ ...x, kind: 'page' }))];

  const html = `
    ${previewBanner(cid, brand.name)}
    <div class="flex flex-wrap items-end gap-4 mb-6">
      <div><div class="eyebrow">${esc(todayLabel())}</div>
        <h1 class="text-[28px] font-semibold tracking-tight t1 mt-1">${isClient() ? `Hola${first ? ', ' + esc(first) : ''}.` : esc(brand.name)}</h1>
        <div class="t2 mt-1">Así vienen los resultados de <b class="t1">${esc(brand.name)}</b> · ${fmt.dateLong(ov.range.since)} – ${fmt.dateLong(ov.range.until)}</div></div>
      <div class="flex-1"></div>
      <a class="btn" href="${state.preview ? `#/preview/${cid}/report` : '#/report'}">${icon('report', 15)}Reporte PDF</a>
    </div>

    ${ov.accounts.length ? liveTodayBlock('live-today') : ''}
    ${!ov.accounts.length ? '<div id="home-sales"><div class="sk h-64 mb-6"></div></div>' : ''}
    ${t ? `<div class="h2 mb-3">Publicidad en Meta</div>${adsKpis(t.metrics, t.previous, cur, daily, { client: true })}` : ''}
    ${ov.accounts.length ? '<div id="home-sales"></div>' : ''}

    <div class="grid xl:grid-cols-3 gap-6 mb-6">
      ${t ? `<div class="card xl:col-span-2"><div class="card-h"><div class="h2">Rendimiento diario</div><span class="t3 text-xs">${esc(cur)}</span></div><div class="p-4 h-[300px]"><canvas id="home-chart"></canvas></div></div>` : ''}
      <div class="card ${t ? '' : 'xl:col-span-3'} flex flex-col">
        <div class="card-h"><div class="h2">Trabajo en curso</div><span class="pill accent plain">${open.length}</span><div class="flex-1"></div><a class="link text-[13px]" href="${state.preview ? `#/preview/${cid}/work` : '#/work'}">Ver todo</a></div>
        <div class="p-5 tl flex-1 overflow-y-auto max-h-[300px]">${open.length || done.length ? [...open.slice(0, 4), ...done.slice(0, Math.max(0, 4 - open.length))].map((u) => updateCard(u, true)).join('') : '<div class="t3">La agencia todavía no cargó novedades.</div>'}</div>
      </div>
    </div>

    ${ov.accounts.length ? '<div id="home-camps" class="mb-6"><div class="sk h-64"></div></div>' : ''}

    ${socials.length ? `<div class="flex items-center mb-3"><div class="h2">Redes sociales</div><div class="flex-1"></div><a class="link text-[13px]" href="#/social">Ver detalle</a></div>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">${socials.map((x) => `
        <a href="#/${x.kind === 'ig' ? 'ig' : 'pages'}/${x.external_id}" class="card card-link p-4 flex items-center gap-3">
          ${assetAvatar(x.data.picture || x.data.profile_picture_url, 42, x.name)}
          <div class="min-w-0 flex-1"><div class="font-medium t1 truncate">${esc(x.name)}</div><div class="t3 text-xs flex items-center gap-1">${icon(x.kind === 'ig' ? 'instagram' : 'page', 12)}${x.kind === 'ig' ? 'Instagram' : 'Facebook'}</div></div>
          <div class="text-right"><div class="num font-semibold t1">${fmt.compact(x.data.followers_count)}</div><div class="t3 text-[11px]">seguidores</div></div>
        </a>`).join('')}</div>
      <div id="home-top"></div>` : ''}

    <div class="t3 text-xs text-center py-6">Panel preparado por ${esc(state.boot.agency_name)} · Datos oficiales de Meta</div>`;

  return {
    html,
    async mount() {
      mountLiveToday('live-today', cid);
      mountSalesHome('home-sales', cid);
      if (t) {
        chart($('#home-chart'), daily.map((x) => fmt.date(x.date)), [
          { label: 'Inversión', data: daily.map((x) => x.spend), type: 'bar', fmt: (v) => fmt.money(v, cur), color: cssVar('--t3') },
          { label: 'Resultados', data: daily.map((x) => x.results), axis: 'y1', color: cssVar('--accent') },
        ]);
      }
      // Campañas activas de todas las cuentas del cliente.
      if (ov.accounts.length) {
        const box = $('#home-camps');
        try {
          const accs = await Promise.all(ov.accounts.map((a) => api('account', rp({ id: a.id }))));
          const camps = accs.flatMap((acc) => acc.campaigns.map((c) => ({ ...c, account: acc.account.id, currency: acc.account.currency })))
            .filter((c) => c.metrics.spend > 0 || c.effective_status === 'ACTIVE')
            .sort((a, b) => b.metrics.spend - a.metrics.spend);
          const maxSpend = Math.max(1, ...camps.map((c) => c.metrics.spend));
          box.innerHTML = camps.length ? `
            <div class="card overflow-hidden"><div class="card-h"><div class="h2">Campañas en marcha</div><span class="t3 text-xs">${camps.length}</span><div class="flex-1"></div><a class="link text-[13px]" href="#/ads">Ver todas</a></div>
            <div class="scroll-x"><table class="tbl"><thead><tr><th style="text-align:left">Campaña</th><th>Inversión</th><th>Resultados</th><th>Costo por resultado</th><th>Alcance</th><th style="width:140px">Peso en la inversión</th></tr></thead><tbody>
            ${camps.slice(0, 8).map((c) => `<tr class="clickable" onclick="location.hash='#/ads/${esc(c.account)}/c/${esc(c.id)}'">
              <td style="text-align:left">${entityName(c, esc(OBJECTIVES[c.objective] || ''))}</td>
              <td>${fmt.money(c.metrics.spend, c.currency)}</td><td>${fmt.int(c.metrics.results)} <span class="sub">${esc(c.metrics.result_label)}</span></td>
              <td>${fmt.money(c.metrics.cost_per_result, c.currency)}</td><td>${fmt.compact(c.metrics.reach)}</td>
              <td><div class="bar"><i style="width:${(c.metrics.spend / maxSpend) * 100}%"></i></div></td></tr>`).join('')}
            </tbody></table></div></div>` : '';
        } catch (e) { box.innerHTML = errorBox(e.message, false); }
      }
      if (assets.ig_accounts[0] && $('#home-top')) {
        try { $('#home-top').innerHTML = topPosts(await api('ig_media', rp({ id: assets.ig_accounts[0].external_id })), 'Lo mejor en Instagram'); } catch { /* opcional */ }
      }
    },
  };
}

async function viewWork(cid) {
  cid = +cid || portalClient();
  const brand = await clientBrand(cid);
  setAccent(brand.color);
  const updates = await api('updates', { client: cid });
  const cols = [['en_curso', 'En curso'], ['planificado', 'Planificado'], ['hecho', 'Hecho']];
  return {
    html: `${previewBanner(cid, brand.name)}${isClient() ? backLink('#/', 'Inicio') : ''}
      ${pageHead('Trabajo en curso', `Todo lo que ${esc(state.boot.agency_name)} está haciendo para ${esc(brand.name)}`)}
      ${updates.length ? `<div class="kanban">${cols.map(([k, l]) => { const list = updates.filter((u) => u.status === k); return `
        <div><div class="flex items-center gap-2 mb-3"><span class="tl-dot ${k}" style="width:16px;height:16px">${k === 'hecho' ? icon('check', 10) : '<i></i>'}</span><div class="h2">${l}</div><span class="t3 text-xs">${list.length}</span></div>
          <div class="space-y-3">${list.map((u) => `
            <div class="card p-4">
              <div class="flex items-start gap-2"><div class="t1 font-medium flex-1">${esc(u.title)}</div><span class="pill plain">${esc(u.kind_label)}</span></div>
              ${u.body ? `<div class="t2 mt-2 whitespace-pre-line leading-relaxed text-[13px]">${esc(u.body)}</div>` : ''}
              <div class="t3 text-xs mt-3 pt-3 hair-t flex flex-wrap gap-x-3 gap-y-1">
                <span>Actualizado ${fmt.ago(u.updated_at)}</span>
                ${u.due_date ? `<span class="inline-flex items-center gap-1">${icon('calendar', 12)}${fmt.date(u.due_date)}</span>` : ''}
                ${u.link ? `<a class="link inline-flex items-center gap-1" href="${esc(u.link)}" target="_blank" rel="noopener">${icon('link', 12)}Ver material</a>` : ''}
              </div>
            </div>`).join('') || '<div class="t3 text-[13px] px-1">Nada por acá.</div>'}</div></div>`; }).join('')}</div>`
        : empty('Sin novedades todavía', 'Cuando la agencia cargue avances, entregas o reuniones, los vas a ver acá.', '', 'briefcase')}`,
  };
}

async function viewReport(cid) {
  cid = +cid || portalClient();
  const brand = await clientBrand(cid);
  setAccent(brand.color);
  const [ov, assets, updates] = await Promise.all([api('overview', rp({ client: cid })), api('assets', { client: cid }), api('updates', { client: cid })]);
  const t = ov.totals[0];
  const cur = t?.currency;
  const daily = t ? Object.entries(ov.daily[cur] || {}).map(([date, v]) => ({ date, ...v })) : [];
  const accs = await Promise.all(ov.accounts.map((a) => api('account', rp({ id: a.id })).catch(() => null)));
  const camps = accs.filter(Boolean).flatMap((acc) => acc.campaigns.map((c) => ({ ...c, currency: acc.account.currency }))).filter((c) => c.metrics.spend > 0).sort((a, b) => b.metrics.spend - a.metrics.spend).slice(0, 10);
  const igs = await Promise.all(assets.ig_accounts.map((x) => api('ig', rp({ id: x.external_id })).catch(() => null)));
  const media = assets.ig_accounts[0] ? await api('ig_media', rp({ id: assets.ig_accounts[0].external_id })).catch(() => []) : [];
  const sales = await api('sales', rp({ client: cid })).catch(() => null);
  const inRange = (u) => u.updated_at.slice(0, 10) >= ov.range.since;
  const doneWork = updates.filter((u) => u.status === 'hecho' && inRange(u));
  const openWork = updates.filter((u) => u.status !== 'hecho');

  const html = `
    ${previewBanner(cid, brand.name)}
    <div class="flex flex-wrap items-center gap-3 mb-5 no-print">${isClient() ? backLink('#/', 'Inicio') : ''}<div class="flex-1"></div>
      <button class="btn btn-primary" onclick="window.print()">${icon('download', 15)}Descargar PDF</button></div>
    <article class="space-y-6">
      <header class="card p-6 flex flex-wrap items-center gap-5">
        <div class="mono-avatar w-14 h-14 text-lg" style="background:var(--accent);color:var(--on-accent)">${brand.logo_url ? `<img src="${esc(brand.logo_url)}" class="w-full h-full object-cover rounded-[10px]" alt="">` : esc(initials(brand.name))}</div>
        <div class="flex-1 min-w-0"><div class="eyebrow">Reporte de resultados</div><div class="text-2xl font-semibold tracking-tight t1 mt-1">${esc(brand.name)}</div>
          <div class="t2 mt-0.5">${fmt.dateLong(ov.range.since)} – ${fmt.dateLong(ov.range.until)} · comparado con los ${ov.range.days} días anteriores</div></div>
        <div class="text-right t3 text-xs">Preparado por<div class="t1 font-medium text-[13px]">${esc(state.boot.agency_name)}</div>${fmt.dateLong(new Date().toISOString().slice(0, 10))}</div>
      </header>
      ${sales?.has_data ? `<section><div class="flex items-center gap-2 mb-3"><div class="h2">${esc(sales.labels.sales)}</div>${freshness(sales)}</div>${salesKpis(sales)}
        <div class="grid md:grid-cols-2 gap-6">${projectionCard(sales.month, sales.labels, sales.currency)}${sales.ads ? crossoverCard(sales.ads, sales.labels, sales.totals) : `<div class="card"><div class="card-h"><div class="h2">${esc(sales.labels.sales)} por día</div></div><div class="p-4 h-[200px]"><canvas id="rep-sales"></canvas></div></div>`}</div></section>` : ''}
      ${t ? `<section><div class="h2 mb-3">Publicidad en Meta</div>${adsKpis(t.metrics, t.previous, cur, daily, { client: true })}
        <div class="card"><div class="card-h"><div class="h2">Inversión y resultados por día</div></div><div class="p-4 h-[260px]"><canvas id="rep-chart"></canvas></div></div></section>` : ''}
      ${camps.length ? `<section class="card overflow-hidden"><div class="card-h"><div class="h2">Campañas</div></div><div class="scroll-x"><table class="tbl"><thead><tr><th style="text-align:left">Campaña</th><th>Inversión</th><th>Resultados</th><th>Costo/resultado</th><th>Alcance</th><th>CTR</th><th>ROAS</th></tr></thead><tbody>
        ${camps.map((c) => `<tr><td style="text-align:left"><div class="font-medium max-w-[320px] truncate">${esc(c.name)}</div><div class="sub">${esc(OBJECTIVES[c.objective] || '')}</div></td><td>${fmt.money(c.metrics.spend, c.currency)}</td><td>${fmt.int(c.metrics.results)} <span class="sub">${esc(c.metrics.result_label)}</span></td><td>${fmt.money(c.metrics.cost_per_result, c.currency)}</td><td>${fmt.compact(c.metrics.reach)}</td><td>${fmt.pct(c.metrics.link_ctr)}</td><td>${fmt.x(c.metrics.roas)}</td></tr>`).join('')}
        </tbody></table></div></section>` : ''}
      ${igs.filter(Boolean).map((ig) => `<section class="print-break"><div class="h2 mb-3">Instagram · @${esc(ig.account.username)}</div>
        <div class="kpi-row grid-cols-2 sm:grid-cols-4" style="display:grid">${[['Seguidores', fmt.int(ig.account.followers)], ['Alcance', fmt.compact(ig.totals.reach)], ['Visualizaciones', fmt.compact(ig.totals.views)], ['Interacciones', fmt.compact(ig.totals.total_interactions)]].map(([l, v]) => kpi(l, v)).join('')}</div></section>`).join('')}
      ${topPosts(media, 'Contenido destacado')}
      <section class="card"><div class="card-h"><div class="h2">Trabajo realizado en el período</div><span class="pill green plain">${doneWork.length}</span></div>
        <div class="p-5 tl">${doneWork.length ? doneWork.map((u) => updateCard(u)).join('') : '<div class="t3">Sin entregas registradas en este período.</div>'}</div></section>
      ${openWork.length ? `<section class="card"><div class="card-h"><div class="h2">Próximos pasos</div></div><div class="p-5 tl">${openWork.map((u) => updateCard(u)).join('')}</div></section>` : ''}
      <div class="t3 text-xs text-center py-4">Fuente: API oficial de Meta. Las compras pueden ajustarse hasta 28 días por la ventana de atribución.</div>
    </article>`;
  return {
    html,
    mount() {
      if (sales?.has_data && $('#rep-sales')) chart($('#rep-sales'), sales.daily.map((x) => fmt.date(x.date)), [{ label: sales.labels.sales, data: sales.daily.map((x) => x.orders), type: 'bar', color: cssVar('--accent') }]);
      if (t) chart($('#rep-chart'), daily.map((x) => fmt.date(x.date)), [
        { label: 'Inversión', data: daily.map((x) => x.spend), type: 'bar', fmt: (v) => fmt.money(v, cur), color: cssVar('--t3') },
        { label: 'Resultados', data: daily.map((x) => x.results), axis: 'y1', color: cssVar('--accent') },
      ]);
    },
  };
}

function accountMenu() {
  const el = modal(`${modalHead(state.boot.user.name, '@' + esc(state.boot.user.username))}
    <form class="p-5 space-y-3" id="pass-form">
      <div class="h2">Cambiar contraseña</div>
      <label class="field"><span>Actual</span><input class="input" type="password" name="current" required></label>
      <label class="field"><span>Nueva (mínimo 8)</span><input class="input" type="password" name="new" minlength="8" required></label>
      <div class="flex gap-2 pt-2"><button class="btn btn-primary">Guardar</button><div class="flex-1"></div><a class="btn btn-ghost" href="index.php?r=logout">${icon('logout', 15)}Cerrar sesión</a></div>
    </form>`, 'sm');
  $('#pass-form', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await post('password_change', Object.fromEntries(new FormData(e.target)), 'Contraseña actualizada'); closeModal(); } catch { /* toast */ }
  });
}

// =========================================================================
// Admin · Clientes
// =========================================================================
async function viewClients() {
  crumbs([{ label: 'Clientes' }]);
  const list = await api('clients');
  const brandsOf = (c) => list.filter((b) => b.kind === 'marca' && +b.parent_id === c.id);
  const parentName = (b) => list.find((x) => x.id === +b.parent_id)?.name;
  // Sólo los clientes: sus marcas se ven y se abren desde adentro. Las marcas sueltas, al final.
  const ordered = [...list.filter((c) => c.kind !== 'marca'), ...list.filter((b) => b.kind === 'marca' && !parentName(b))];
  const html = `
    ${pageHead('Clientes', isViewer() ? 'Abrí el portal de cada cliente para ver sus métricas, ventas, trabajo y reporte.' : 'Cada cliente entra con su usuario y ve sólo sus cuentas, redes y el trabajo en curso.', isAdmin() ? `<div class="flex gap-2"><button class="btn" id="bulk-toggle">${icon('check', 15)}Seleccionar</button><button class="btn" id="detect-brands">${icon('sliders', 15)}Marcas y cuentas</button><button class="btn btn-primary" id="new-client">${icon('plus', 15)}Nuevo cliente</button></div>` : '')}
    ${list.length ? `<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">${ordered.map((c) => `
      <a href="#/${isViewer() ? 'preview' : 'clients'}/${c.id}" class="card card-link p-5 block relative" data-cid="${c.id}">
        <input type="checkbox" class="bulk-check hidden absolute top-4 right-4 w-5 h-5" data-bulk="${c.id}" aria-label="Seleccionar ${esc(c.name)}">
        <div class="flex items-center gap-3">
          <div class="mono-avatar w-11 h-11" style="background:${esc(c.color)};color:#fff">${esc(initials(c.name))}</div>
          <div class="min-w-0 flex-1"><div class="h2 truncate flex items-center gap-2">${esc(c.name)}${c.kind === 'marca' ? '<span class="pill blue plain">Marca</span>' : ''}</div>
            <div class="t3 text-xs mt-0.5 truncate">${c.kind === 'marca' ? 'Marca sin cliente' : brandsOf(c).length ? `${brandsOf(c).length} marca${brandsOf(c).length > 1 ? 's' : ''}` : `Creado ${fmt.date(c.created_at)}`}</div></div>
        </div>
        ${brandsOf(c).length ? `<div class="flex flex-wrap gap-1.5 mt-3">${brandsOf(c).map((b) => `<span class="pill plain" style="background:color-mix(in srgb, ${esc(b.color)} 14%, transparent);color:${esc(b.color)}">${esc(b.name)}</span>`).join('')}</div>` : ''}
        <div class="grid grid-cols-3 mt-5 pt-4 hair-t text-center">
          <div><div class="num font-semibold t1 text-lg">${c.assets}</div><div class="t3 text-xs">activos</div></div>
          <div><div class="num font-semibold t1 text-lg">${c.users}</div><div class="t3 text-xs">accesos</div></div>
          <div><div class="num font-semibold t1 text-lg">${c.open_updates}</div><div class="t3 text-xs">en curso</div></div>
        </div>
      </a>`).join('')}</div>`
      : empty('Todavía no creaste clientes', 'Creá un cliente, asignale sus cuentas publicitarias, páginas e Instagram y generale un usuario para que vea su portal en tiempo real.', `<button class="btn btn-primary" id="new-client-2">${icon('plus', 15)}Crear el primero</button>`, 'users')}`;
  return {
    html,
    mount() {
      ['#new-client', '#new-client-2'].forEach((s) => $(s)?.addEventListener('click', () => clientForm()));
      $('#detect-brands')?.addEventListener('click', () => brandsModal(() => render({ silent: true })));
      // Selección múltiple para limpiar clientes de prueba o creados por error.
      let bulk = false;
      const bar = document.createElement('div');
      bar.className = 'bulk-bar hidden';
      bar.innerHTML = `<span id="bulk-n">0 seleccionados</span><div class="flex-1"></div><button class="btn btn-sm" id="bulk-all">Todos menos los que tienen marcas</button><button class="btn btn-sm btn-danger" id="bulk-del">${icon('x', 13)}Eliminar</button><button class="btn btn-sm btn-ghost" id="bulk-cancel">Cancelar</button>`;
      $('#view').append(bar);
      const sel = () => $$('[data-bulk]:checked').map((x) => +x.dataset.bulk);
      const sync = () => { $('#bulk-n').textContent = `${sel().length} seleccionado${sel().length === 1 ? '' : 's'}`; };
      const toggle = (on) => { bulk = on; $$('.bulk-check').forEach((x) => { x.classList.toggle('hidden', !on); if (!on) x.checked = false; }); bar.classList.toggle('hidden', !on); sync(); };
      $('#bulk-toggle')?.addEventListener('click', () => toggle(!bulk));
      $('#bulk-cancel', bar).addEventListener('click', () => toggle(false));
      $('#bulk-all', bar).addEventListener('click', () => { $$('[data-bulk]').forEach((x) => { const c = list.find((y) => y.id === +x.dataset.bulk); x.checked = !brandsOf(c).length; }); sync(); });
      $('#view').addEventListener('click', (e) => {
        if (!bulk) return;
        const card = e.target.closest('[data-cid]');
        if (!card) return;
        e.preventDefault();
        const cb = $('[data-bulk]', card);
        if (e.target !== cb) cb.checked = !cb.checked;
        sync();
      });
      $('#bulk-del', bar).addEventListener('click', async () => {
        const ids = sel();
        if (!ids.length) return toast('Elegí al menos un cliente.', 'error');
        const names = ids.map((id) => list.find((c) => c.id === id)?.name).join(', ');
        if (!confirm(`¿Eliminar ${ids.length} cliente${ids.length > 1 ? 's' : ''}?\n\n${names}\n\nSe borran sus accesos, ventas cargadas, planillas vinculadas y novedades. No afecta nada en Meta ni en Google.`)) return;
        await post('clients_delete', { ids }, `${ids.length} eliminado${ids.length > 1 ? 's' : ''}`);
        render();
      });
    },
  };
}

function clientForm(c = null) {
  const el = modal(`${modalHead(c ? 'Editar cliente' : 'Nuevo cliente')}
    <form class="p-5 space-y-4" id="client-form">
      <div class="grid grid-cols-3 gap-3">
        <label class="field col-span-2"><span>Nombre</span><input class="input" name="name" value="${esc(c?.name || '')}" required></label>
        <label class="field"><span>Tipo</span><select class="input" name="kind" id="cf-kind"><option value="cliente" ${c?.kind !== 'marca' ? 'selected' : ''}>Cliente</option><option value="marca" ${c?.kind === 'marca' ? 'selected' : ''}>Marca</option></select></label>
      </div>
      <div class="grid grid-cols-2 gap-3 ${c?.kind === 'marca' ? '' : 'hidden'}" id="cf-parent-wrap">
        <label class="field"><span>Marca de (cliente)</span><select class="input" name="parent_id" id="cf-parent"><option value="">— Sin cliente —</option></select></label>
        <label class="field"><span>Tipo de gestión</span><select class="input" name="gestion"><option value="">—</option>${Object.entries(state.boot.gestiones || {}).map(([k, l]) => `<option value="${k}" ${c?.gestion === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
      </div>
      <p class="t3 text-xs -mt-2">Las <b>marcas</b> (ej. Go Celular, GetNet, Osacra, Clover) entran al plan por objetivos y a Presupuestos → Por marca.</p>
      <div class="flex gap-3 items-end">
        <label class="field"><span>Color de marca</span><input type="color" name="color" value="${esc(c?.color || '#0e7c66')}"></label>
        <label class="field flex-1"><span>Logo (URL https, opcional)</span><input class="input" name="logo_url" value="${esc(c?.logo_url || '')}" placeholder="https://…/logo.png"></label>
      </div>
      ${c ? `<div class="flex items-center gap-3">${c.logo_url ? `<img src="${esc(c.logo_url)}" class="h-10 rounded border bd bg-white px-1" alt="">` : ''}<label class="btn btn-sm cursor-pointer">${icon('plus', 13)}Subir logo desde la compu<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" id="client-logo"></label><span class="t3 text-xs">PNG, JPG o WebP · máx. 2 MB</span></div>` : '<p class="t3 text-xs">Después de crearlo vas a poder subir el logo desde la compu.</p>'}
      <p class="t3 text-xs">El color se usa como acento en el portal y en los reportes del cliente.</p>
      <div class="hair-t pt-4"><div class="h2">Ventas / conversiones</div><p class="t3 text-xs mt-0.5">Cómo se llaman en este negocio (ej. VTV: “Verificaciones”, “Turnos”, “Planta”).</p></div>
      <div class="grid grid-cols-2 gap-3">
        <label class="field"><span>Nombre de la conversión</span><input class="input" name="sales_label" value="${esc(c?.sales_label || 'Ventas')}"></label>
        <label class="field"><span>Nombre de los contactos</span><input class="input" name="leads_label" value="${esc(c?.leads_label || 'Consultas')}"></label>
        <label class="field"><span>Nombre del desglose</span><input class="input" name="dim_label" value="${esc(c?.dim_label || 'Sucursal')}"></label>
        <label class="field"><span>Moneda</span><input class="input" name="currency" maxlength="3" value="${esc(c?.currency || 'ARS')}"></label>
        <label class="field"><span>Objetivo mensual (cantidad)</span><input class="input num" name="target_orders" inputmode="decimal" value="${esc(c?.target_orders ?? '')}" placeholder="opcional"></label>
        <label class="field"><span>Objetivo mensual (facturación)</span><input class="input num" name="target_revenue" inputmode="decimal" value="${esc(c?.target_revenue ?? '')}" placeholder="opcional"></label>
      </div>
      <div class="flex gap-2 pt-1"><button class="btn btn-primary">${c ? 'Guardar' : 'Crear cliente'}</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button></div>
    </form>`, 'sm');
  $('#client-logo', el)?.addEventListener('change', async (e) => {
    const f = e.target.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('file', f); fd.append('target', String(c.id));
    try { const r = await post('logo_upload', fd, 'Logo cargado'); $('[name=logo_url]', el).value = r.url; } catch { /* toast */ }
  });
  api('clients').then((all) => {
    const sel = $('#cf-parent', el);
    if (!sel) return;
    all.filter((x) => x.kind !== 'marca' && x.id !== c?.id).forEach((x) => sel.insertAdjacentHTML('beforeend', `<option value="${x.id}" ${+c?.parent_id === x.id ? 'selected' : ''}>${esc(x.name)}</option>`));
  }).catch(() => {});
  $('#cf-kind', el)?.addEventListener('change', (e) => $('#cf-parent-wrap', el).classList.toggle('hidden', e.target.value !== 'marca'));
  $('[name=gestion]', el)?.addEventListener('change', (e) => { const l = state.boot.gestiones?.[e.target.value]; if (l && $('[name=sales_label]', el)) $('[name=sales_label]', el).value = l; });
  $('#client-form', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const r = await post('client_save', { ...Object.fromEntries(new FormData(e.target)), id: c?.id }, c ? 'Cliente actualizado' : 'Cliente creado');
      closeModal();
      if (c) render(); else location.hash = `#/clients/${r.id}`;
    } catch { /* toast */ }
  });
}

/** Campos de rol y marcas para una persona del cliente. */
function roleFields(u, brands) {
  const R = state.boot.client_roles || {};
  const role = u?.client_role || 'supervisor';
  return `<label class="field"><span>Rol</span><select class="input" name="client_role">${Object.entries(R).map(([k, l]) => `<option value="${k}" ${k === role ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
    <div data-brand-pick><div class="t3 text-xs mb-1.5">Marcas que ve</div>
      <div class="flex flex-wrap gap-2">${brands.map((b) => `<label class="pill plain !h-8 !px-3 gap-2 cursor-pointer"><input type="checkbox" name="brands" value="${b.id}" ${u?.brands?.includes(b.id) ? 'checked' : ''}>${esc(b.name)}</label>`).join('')}</div>
      <p class="t3 text-xs mt-1.5">Sin tildar ninguna, ve todas.</p></div>
    <p class="t3 text-xs" data-brand-all>Este rol ve <b>todas</b> las marcas (también las nuevas).</p>`;
}
function roleToggle(form) {
  if (!form) return;
  const all = ALL_BRANDS_ROLES.includes($('[name=client_role]', form)?.value);
  $('[data-brand-pick]', form)?.classList.toggle('hidden', all);
  $('[data-brand-all]', form)?.classList.toggle('hidden', !all);
}

async function viewClient(id, tab = 'assets') {
  const [c, assets] = await Promise.all([api('client', { id }), api('assets', { all: 1 })]);
  crumbs([{ label: 'Clientes', href: '#/clients' }, ...(c.parent ? [{ label: c.parent.name, href: `#/clients/${c.parent.id}` }] : []), { label: c.name }]);
  const assigned = new Set(c.assets.map((a) => `${a.asset_type}:${a.external_id}`));
  const portalUrl = location.origin + location.pathname.replace(/index\.php$/, '');
  const isBrand = c.kind === 'marca';
  const tabs = [...(isBrand ? [] : [['brands', `Marcas <span class="t3">${c.children.length}</span>`]]), ['assets', 'Activos asignados'], ['sales', esc(c.sales_label) + ' / conversiones'], ['sheets', 'Planillas'], ['users', 'Accesos'], ['work', 'Trabajo en curso']]
    .filter(([k]) => isAdmin() || ['sales', 'work', 'sheets'].includes(k));
  tab = store.get('clientTab', isBrand ? tab : 'brands');
  if (!tabs.some(([k]) => k === tab)) tab = tabs[0][0];

  const G = state.boot.gestiones || {};
  const brandsPane = `
    <div class="grid lg:grid-cols-5 gap-6">
      <div class="lg:col-span-3">
        ${c.children.length ? `<div class="grid sm:grid-cols-2 gap-3">${c.children.map((b) => `
          <a href="#/clients/${b.id}" class="card card-link p-4 flex items-center gap-3">
            <div class="mono-avatar w-10 h-10 text-xs" style="background:${esc(b.color)};color:#fff">${b.logo_url ? `<img src="${esc(b.logo_url)}" class="w-full h-full object-contain rounded-[10px] bg-white" alt="">` : esc(initials(b.name))}</div>
            <div class="min-w-0 flex-1"><div class="font-semibold t1 truncate">${esc(b.name)}</div><div class="t3 text-xs">${esc(G[b.gestion] ? 'Gestión: ' + G[b.gestion] : 'Marca')}${b.sales_label && b.sales_label !== G[b.gestion] ? ' · ' + esc(b.sales_label) : ''}</div></div>
            ${icon('external', 14)}
          </a>`).join('')}</div>`
          : empty('Sin marcas todavía', `Agregá las marcas o unidades de ${esc(c.name)} (ej. Go Celular, GetNet, Osacra, Clover, VTV Buenos Aires…). Cada una tiene sus métricas, planillas y objetivos.`, '', 'layers')}
        <p class="t3 text-xs mt-3">Las cuentas publicitarias de cada marca se asignan en <a class="link" href="#" id="open-brand-map">Marcas y cuentas</a>.</p>
      </div>
      ${isAdmin() ? `<form class="lg:col-span-2 card p-5 space-y-3 self-start" id="brand-form">
        <div class="h2">Nueva marca de ${esc(c.name)}</div>
        <label class="field"><span>Nombre</span><input class="input" name="name" required placeholder="Ej: Go Celular, VTV Buenos Aires"></label>
        <div class="grid grid-cols-2 gap-3">
          <label class="field"><span>Tipo de gestión</span><select class="input" name="gestion">${Object.entries(G).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></label>
          <label class="field"><span>Color</span><input type="color" name="color" value="#3a6fd8"></label>
        </div>
        <button class="btn btn-accent w-full">${icon('plus', 14)}Agregar marca</button>
        <p class="t3 text-xs">El tipo define cómo se llama su resultado (Ventas, Cobranzas, Recuperos, Turnos…). Se puede cambiar después.</p>
      </form>` : ''}
    </div>`;

  const sheetsPane = `
    <div class="grid lg:grid-cols-5 gap-6">
      <div class="lg:col-span-3 card overflow-hidden"><div class="card-h"><div class="h2">Planillas vinculadas</div><div class="flex-1"></div><a class="btn btn-sm" href="#/preview/${c.id}/sheets">${icon('eye', 13)}Ver</a></div>
        <div class="divide" id="sheet-list"><div class="p-4"><div class="sk h-10"></div></div></div></div>
      <form class="lg:col-span-2 card p-5 space-y-3 self-start" id="sheet-form">
        <div class="h2">Vincular planilla de Google</div>
        <label class="field"><span>Nombre</span><input class="input" name="name" placeholder="Ej: Cobranzas diarias, Turnos, Leads" required></label>
        <label class="field"><span>Link de Google Sheets</span><input class="input" name="url" placeholder="https://docs.google.com/spreadsheets/d/…" required></label>
        <button class="btn btn-accent w-full">Vincular</button>
        <p class="t3 text-xs">Cualquier planilla (no sólo ventas): se ve tal cual, con todas sus pestañas, y se actualiza sola cada 5 minutos. Compartila como “Cualquier persona con el enlace → Lector”. Para que las ventas entren en las métricas, conectala además en la pestaña de ${esc(lower(c.sales_label))}.</p>
      </form>
    </div>`;

  // Cuentas publicitarias: completa o sólo campañas por palabra clave (cuentas compartidas entre marcas).
  const accMode = (id) => (assigned.has(`ad_account:${id}`) ? 'full' : c.rules?.[id] ? 'rules' : 'none');
  const adAccountList = (list) => `
    <div class="card overflow-hidden mb-4">
      <div class="card-h"><div class="h2">Cuentas publicitarias</div><span class="t3 text-xs" data-count="ad_account"></span><div class="flex-1"></div>
        <span class="t3 text-xs">¿Una cuenta con varias marcas? Elegí “Solo algunas campañas” y escribí cómo se llaman.</span></div>
      <div class="divide max-h-[560px] overflow-y-auto">${list.map((a) => { const mode = accMode(a.external_id); return `
        <div class="px-4 py-3" data-name="${esc(a.name.toLowerCase())} ${esc(a.external_id)}">
          <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1"><div class="t1 truncate">${esc(a.name)}</div><div class="t3 text-xs truncate">${esc((a.data.business?.name || 'Sin portfolio') + ' · ' + (a.data.currency || '') + ' · ' + a.external_id)}</div></div>
            <div class="seg" data-acc-mode="${esc(a.external_id)}">${[['none', 'No'], ['full', 'Toda la cuenta'], ['rules', 'Solo algunas campañas']].map(([m, l]) => `<button type="button" data-m="${m}" class="${mode === m ? 'on' : ''}">${l}</button>`).join('')}</div>
          </div>
          <div class="mt-3 ${mode === 'rules' ? '' : 'hidden'}" data-rules-box="${esc(a.external_id)}">
            <input class="input" data-rules="${esc(a.external_id)}" value="${esc((c.rules?.[a.external_id] || []).join(', '))}" placeholder="Palabras clave separadas por coma. Ej: go celular, gocel">
            <div class="t3 text-xs mt-1.5" data-rules-preview="${esc(a.external_id)}"></div>
          </div>
        </div>`; }).join('') || '<div class="p-4 t3">No hay cuentas sincronizadas.</div>'}</div>
    </div>`;

  const assetList = (type, list, label) => `
    <div class="card overflow-hidden flex flex-col">
      <div class="card-h"><div class="h2">${label}</div><span class="t3 text-xs" data-count="${type}"></span></div>
      <div class="max-h-[420px] overflow-y-auto divide">${list.map((a) => `
        <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-[var(--hover)]" data-name="${esc(a.name.toLowerCase())} ${esc(a.external_id)}">
          <input type="checkbox" class="check" data-asset="${type}" value="${esc(a.external_id)}" ${assigned.has(`${type}:${a.external_id}`) ? 'checked' : ''}>
          <div class="min-w-0 flex-1"><div class="t1 truncate">${esc(a.name)}</div><div class="t3 text-xs truncate">${type === 'ad_account' ? esc((a.data.business?.name || 'Sin portfolio') + ' · ' + (a.data.currency || '')) : fmt.compact(a.data.followers_count) + ' seguidores'}</div></div>
        </label>`).join('') || '<div class="p-4 t3">No hay activos sincronizados.</div>'}</div>
    </div>`;

  const R = state.boot.client_roles || {};
  const users = `
    <div class="grid lg:grid-cols-5 gap-6">
      <div class="lg:col-span-3 card overflow-hidden">
        <div class="card-h"><div class="h2">Accesos al portal</div><span class="t3 text-xs">${c.users.length}</span></div>
        ${c.users.length ? `<table class="tbl"><thead><tr><th style="text-align:left">Usuario</th>${c.children.length ? '<th style="text-align:left">Rol y marcas</th>' : ''}<th>Último ingreso</th><th></th></tr></thead><tbody>${c.users.map((u) => `
          <tr><td style="text-align:left"><div class="font-medium">${esc(u.name)}</div><div class="sub mono">${esc(u.username)}</div></td>
            ${c.children.length ? `<td style="text-align:left"><div class="flex items-center gap-2"><span class="pill blue plain">${esc(R[u.client_role] || 'Usuario')}</span><button class="btn btn-sm btn-ghost !h-6 !px-1.5" data-edit-cuser="${u.id}">${icon('sliders', 12)}</button></div>
              <div class="sub mt-1">${ALL_BRANDS_ROLES.includes(u.client_role) || !u.brands.length ? 'Todas las marcas' : esc(u.brands.map((id) => c.children.find((b) => b.id === id)?.name).filter(Boolean).join(', '))}</div></td>` : ''}
            <td>${u.last_login_at ? fmt.ago(u.last_login_at) : '<span class="t3">Nunca</span>'}</td>
            <td><div class="flex justify-end gap-1"><button class="btn btn-sm btn-ghost" data-reset="${u.id}" data-user="${esc(u.username)}">${icon('key', 14)}Nueva clave</button><button class="btn btn-sm btn-ghost btn-danger" data-del-user="${u.id}">${icon('x', 14)}</button></div></td></tr>`).join('')}</tbody></table>`
          : '<div class="p-5 t3">Todavía no hay accesos. Creá uno con el formulario.</div>'}
      </div>
      <form class="lg:col-span-2 card p-5 space-y-3 self-start" id="cuser-form">
        <div class="h2">Nuevo acceso</div>
        <label class="field"><span>Nombre de la persona</span><input class="input" name="name" placeholder="Ej: Laura (Marketing)" required></label>
        <label class="field"><span>Usuario</span><input class="input mono" name="username" autocapitalize="none" required pattern="[a-zA-Z0-9._@+\\-]{3,}" placeholder="laura.marca"></label>
        <label class="field"><span>Contraseña</span><div class="flex gap-2"><input class="input mono" name="password" minlength="8" required><button type="button" class="btn" id="gen-pass" title="Generar">${icon('refresh', 14)}</button></div></label>
        ${c.children.length ? roleFields(null, c.children) : ''}
        <button class="btn btn-accent w-full">Crear acceso</button>
      </form>
    </div>`;

  const work = `
    <div class="grid lg:grid-cols-5 gap-6">
      <div class="lg:col-span-3 card"><div class="card-h"><div class="h2">Novedades publicadas</div><span class="t3 text-xs">${c.updates.length}</span></div>
        <div class="divide">${c.updates.map((u) => `
          <div class="px-5 py-3.5 flex items-start gap-3">
            <span class="tl-dot ${esc(u.status)}" style="flex:none">${u.status === 'hecho' ? icon('check', 12) : '<i></i>'}</span>
            <div class="min-w-0 flex-1"><div class="t1 font-medium">${esc(u.title)}</div><div class="t3 text-xs mt-0.5">${esc(u.kind_label)} · ${fmt.ago(u.updated_at)}${u.due_date ? ' · ' + fmt.date(u.due_date) : ''}</div></div>
            <select class="select select-sm" data-status="${u.id}">${Object.entries(state.boot.statuses).map(([k, l]) => `<option value="${k}" ${k === u.status ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <button class="btn btn-sm btn-ghost" data-edit-update="${u.id}">Editar</button><button class="btn btn-sm btn-ghost btn-danger" data-del-update="${u.id}">${icon('x', 14)}</button>
          </div>`).join('') || '<div class="p-5 t3">Sin novedades. Cargá la primera: el cliente la ve al instante.</div>'}</div></div>
      <div class="lg:col-span-2 self-start">${updateForm(c.id)}</div>
    </div>`;

  const html = `
    <div class="flex flex-wrap items-center gap-4 mb-6">
      <div class="mono-avatar w-12 h-12 text-base" style="background:${esc(c.color)};color:#fff">${esc(initials(c.name))}</div>
      <div class="min-w-0"><h1 class="h1 flex items-center gap-2">${esc(c.name)}${isBrand ? `<span class="pill blue plain">Marca${c.parent ? ' de ' + esc(c.parent.name) : ''}</span>` : ''}</h1><div class="t2 mt-0.5">${isBrand ? '' : `${c.children.length} marcas · `}${c.assets.length + Object.keys(c.rules || {}).length} activos · ${c.users.length} accesos · portal en <span class="mono text-xs">${esc(portalUrl)}</span></div></div>
      <div class="flex-1"></div>
      <a class="btn btn-accent" href="#/preview/${c.id}" style="--accent:${esc(c.color)}">${icon('eye', 15)}Ver como cliente</a>
      ${isAdmin() ? `<button class="btn" id="edit-client">Editar</button>
      <button class="btn btn-ghost btn-danger" id="del-client">Eliminar</button>` : ''}
    </div>
    <div class="utabs mb-6" id="ctabs">${tabs.map(([k, l]) => `<button data-ct="${k}" class="${k === tab ? 'active' : ''}">${l}</button>`).join('')}</div>
    ${isAdmin() ? `<div data-pane="assets" class="${tab === 'assets' ? '' : 'hidden'}">
      <div class="flex flex-wrap items-center gap-3 mb-4"><div class="t2 flex-1">Elegí qué puede ver este cliente. Todo lo que no esté marcado queda oculto para él.</div>
        <input class="input w-64" placeholder="Filtrar" id="asset-filter"><button class="btn btn-primary" id="save-assets">Guardar asignación</button></div>
      ${adAccountList(assets.ad_accounts)}
      <div class="grid lg:grid-cols-2 gap-4">${assetList('page', assets.pages, 'Páginas de Facebook')}${assetList('ig_account', assets.ig_accounts, 'Instagram')}</div>
    </div>` : ''}
    ${isBrand ? '' : `<div data-pane="brands" class="${tab === 'brands' ? '' : 'hidden'}">${brandsPane}</div>`}
    <div data-pane="sales" class="${tab === 'sales' ? '' : 'hidden'}">${salesPane(c)}</div>
    <div data-pane="sheets" class="${tab === 'sheets' ? '' : 'hidden'}">${sheetsPane}</div>
    ${isAdmin() ? `<div data-pane="users" class="${tab === 'users' ? '' : 'hidden'}">${users}</div>` : ''}
    <div data-pane="work" class="${tab === 'work' ? '' : 'hidden'}">${work}</div>`;

  return {
    html,
    mount() {
      const view = $('#view');
      const modeOf = (id) => $(`[data-acc-mode="${id}"] .on`)?.dataset.m || 'none';
      const counts = () => {
        ['page', 'ig_account'].forEach((t) => { const el = $(`[data-count="${t}"]`); if (el) el.textContent = `${$$(`[data-asset="${t}"]:checked`).length} asignados`; });
        const accs = $$('[data-acc-mode]').map((x) => modeOf(x.dataset.accMode));
        const el = $('[data-count="ad_account"]');
        if (el) el.textContent = `${accs.filter((m) => m === 'full').length} completas · ${accs.filter((m) => m === 'rules').length} compartidas`;
      };
      counts();
      // Vista previa de qué campañas toma cada palabra clave.
      const campCache = {};
      const preview = async (accId) => {
        const box = $(`[data-rules-preview="${accId}"]`);
        const patterns = $(`[data-rules="${accId}"]`).value.split(',').map((x) => x.trim()).filter(Boolean);
        if (!patterns.length) { box.textContent = 'Escribí al menos una palabra clave.'; return; }
        box.textContent = 'Buscando campañas…';
        try {
          campCache[accId] ??= (await api('account', { id: accId, preset: 'last_90d' })).campaigns;
          const hit = campCache[accId].filter((x) => campaignMatches(patterns, x.id, x.name));
          box.innerHTML = hit.length
            ? `<span class="t-pos">Toma ${hit.length} campaña${hit.length > 1 ? 's' : ''}:</span> ${hit.slice(0, 6).map((x) => esc(x.name)).join(' · ')}${hit.length > 6 ? ' …' : ''}`
            : '<span class="t-warn">Ninguna campaña coincide (últimos 90 días). Revisá cómo se llaman.</span>';
        } catch (err) { box.textContent = err.message; }
      };
      let tmr;
      view.addEventListener('input', (e) => { if (e.target.dataset.rules) { clearTimeout(tmr); tmr = setTimeout(() => preview(e.target.dataset.rules), 400); } });
      $$('[data-rules-box]:not(.hidden)').forEach((b) => preview(b.dataset.rulesBox));
      view.addEventListener('click', (e) => {
        const b = e.target.closest('[data-acc-mode] [data-m]');
        if (!b) return;
        const seg = b.parentElement;
        $$('[data-m]', seg).forEach((x) => x.classList.toggle('on', x === b));
        const box = $(`[data-rules-box="${seg.dataset.accMode}"]`);
        box.classList.toggle('hidden', b.dataset.m !== 'rules');
        if (b.dataset.m === 'rules') { $('input', box).focus(); preview(seg.dataset.accMode); }
        counts();
      });
      $('#ctabs').addEventListener('click', (e) => {
        const b = e.target.closest('[data-ct]');
        if (!b) return;
        store.set('clientTab', b.dataset.ct);
        $$('#ctabs [data-ct]').forEach((x) => x.classList.toggle('active', x === b));
        $$('[data-pane]').forEach((p) => p.classList.toggle('hidden', p.dataset.pane !== b.dataset.ct));
      });
      $('#asset-filter')?.addEventListener('input', (e) => { const q = e.target.value.toLowerCase(); $$('[data-pane=assets] [data-name]').forEach((el) => { el.style.display = el.dataset.name.includes(q) ? '' : 'none'; }); });
      view.addEventListener('change', async (e) => {
        if (e.target.dataset.asset) counts();
        if (e.target.dataset.status) {
          const u = c.updates.find((x) => x.id === +e.target.dataset.status);
          await post('update_save', { ...u, client_id: c.id, status: e.target.value }, 'Estado actualizado');
          render({ silent: true });
        }
      });
      $('#save-assets')?.addEventListener('click', async () => {
        const list = $$('[data-asset]:checked').map((x) => ({ type: x.dataset.asset, id: x.value }));
        const rules = [];
        $$('[data-acc-mode]').forEach((x) => {
          const id = x.dataset.accMode, m = modeOf(id);
          if (m === 'full') list.push({ type: 'ad_account', id });
          if (m === 'rules') rules.push({ account: id, patterns: $(`[data-rules="${id}"]`).value.split(',').map((p) => p.trim()).filter(Boolean) });
        });
        if (rules.some((r) => !r.patterns.length)) { toast('Completá las palabras clave de las cuentas compartidas.', 'error'); return; }
        await post('client_assets', { id: c.id, assets: list, rules }, 'Asignación guardada');
        state.boot = await api('bootstrap'); renderNav();
      });
      $('#open-brand-map')?.addEventListener('click', (e) => { e.preventDefault(); brandsModal(() => render({ silent: true })); });
      $('#brand-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = Object.fromEntries(new FormData(e.target));
        try {
          await post('client_save', { ...f, kind: 'marca', parent_id: c.id, sales_label: G[f.gestion] || 'Ventas', currency: c.currency }, 'Marca agregada');
          render({ silent: true });
        } catch { /* toast */ }
      });
      const loadSheets = async () => {
        const box = $('#sheet-list');
        if (!box) return;
        try {
          const list = await api('sheets', { client: c.id });
          box.innerHTML = list.map((x) => `<div class="px-4 py-3 flex items-center gap-3">
            <div class="min-w-0 flex-1"><div class="t1 font-medium truncate">${esc(x.name)}</div><div class="t3 text-xs truncate">${(x.tabs || []).length} pestañas · ${x.last_sync_at ? 'actualizada ' + fmt.ago(x.last_sync_at) : ''}${x.last_error ? ` · <span class="t-warn">${esc(x.last_error)}</span>` : ''}</div></div>
            <button class="btn btn-sm btn-ghost" data-sheet-sync="${x.id}" title="Actualizar ahora">${icon('refresh', 13)}</button>
            <button class="btn btn-sm btn-ghost btn-danger" data-sheet-del="${x.id}">${icon('x', 13)}</button></div>`).join('') || '<div class="p-4 t3">Todavía no hay planillas vinculadas.</div>';
          hydrateIcons(box);
        } catch (err) { box.innerHTML = `<div class="p-4 t-warn">${esc(err.message)}</div>`; }
      };
      loadSheets();
      $('#sheet-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const b = $('button', e.target); b.disabled = true; b.textContent = 'Leyendo la planilla…';
        try { await post('sheet_add', { ...Object.fromEntries(new FormData(e.target)), client_id: c.id }, 'Planilla vinculada'); e.target.reset(); loadSheets(); } catch { /* toast */ }
        b.disabled = false; b.textContent = 'Vincular';
      });
      $('#sheet-list')?.addEventListener('click', async (e) => {
        const d = e.target.closest('[data-sheet-del]');
        if (d && confirm('¿Desvincular esta planilla? (no se borra nada en Google)')) { await post('sheet_delete', { id: +d.dataset.sheetDel }, 'Planilla desvinculada'); return loadSheets(); }
        const y = e.target.closest('[data-sheet-sync]');
        if (y) { try { await post('sheet_refresh', { id: +y.dataset.sheetSync }, 'Planilla actualizada'); } catch { /* toast */ } loadSheets(); }
      });
      roleToggle($('#cuser-form'));
      view.addEventListener('change', (e) => { if (e.target.name === 'client_role') roleToggle(e.target.closest('form')); });
      $$('[data-edit-cuser]').forEach((b) => b.addEventListener('click', () => {
        const u = c.users.find((x) => x.id === +b.dataset.editCuser);
        const el = modal(`${modalHead('Rol y marcas', esc(u.name))}<form class="p-5 space-y-3" id="cuser-edit">${roleFields(u, c.children)}<div class="flex gap-2"><button class="btn btn-primary">Guardar</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button></div></form>`, 'sm');
        roleToggle($('#cuser-edit', el));
        $('#cuser-edit', el).addEventListener('change', (ev) => { if (ev.target.name === 'client_role') roleToggle(ev.currentTarget); });
        $('#cuser-edit', el).addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const fd = new FormData(ev.target);
          await post('client_user_save', { id: u.id, client_role: fd.get('client_role'), brands: fd.getAll('brands').map(Number) }, 'Acceso actualizado');
          closeModal(); render({ silent: true });
        });
      }));
      $('#edit-client')?.addEventListener('click', () => clientForm(c));
      $('#del-client')?.addEventListener('click', async () => {
        if (!confirm(`¿Eliminar a ${c.name}? Se borran sus accesos, novedades, ventas cargadas y planillas vinculadas (no afecta nada en Meta ni en Google).${c.children.length ? ` Sus ${c.children.length} marcas quedan sin cliente (no se borran).` : ''}`)) return;
        await post('client_delete', { id: c.id }, 'Cliente eliminado');
        location.hash = '#/clients';
      });
      const pass = $('#cuser-form [name=password]');
      if (pass) pass.value = genPassword();
      $('#gen-pass')?.addEventListener('click', () => { pass.value = genPassword(); });
      $('#cuser-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const data = Object.fromEntries(fd);
        try {
          await post('user_add', { ...data, brands: fd.getAll('brands').map(Number), role: 'client', client_id: c.id });
          credentialsModal(data.username, data.password, portalUrl);
          render({ silent: true });
        } catch { /* toast */ }
      });
      view.addEventListener('click', async (e) => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.delUser && confirm('¿Quitar este acceso?')) { await post('user_delete', { id: +t.dataset.delUser }, 'Acceso eliminado'); render({ silent: true }); }
        if (t.dataset.reset) {
          const p = genPassword();
          if (!confirm(`Se generará una nueva contraseña para ${t.dataset.user}. ¿Continuar?`)) return;
          await post('user_reset_password', { id: +t.dataset.reset, password: p });
          credentialsModal(t.dataset.user, p, portalUrl);
        }
        if (t.dataset.delUpdate && confirm('¿Eliminar esta novedad?')) { await post('update_delete', { id: +t.dataset.delUpdate }, 'Novedad eliminada'); render({ silent: true }); }
        if (t.dataset.editUpdate) {
          const u = c.updates.find((x) => x.id === +t.dataset.editUpdate);
          const el = modal(modalHead('Editar novedad') + `<div class="p-5">${updateForm(c.id, u, true)}</div>`, 'sm');
          bindUpdateForm($('form', el), c.id, u);
        }
      });
      bindUpdateForm($('#update-form'), c.id);
      mountSalesPane(c);
    },
  };
}

function updateForm(clientId, u = null, bare = false) {
  return `<form id="update-form" class="${bare ? '' : 'card p-5'} space-y-3">
    ${bare ? '' : '<div class="h2">Publicar novedad</div><p class="t3 text-xs -mt-1">El cliente la ve en su portal y en el reporte.</p>'}
    <div class="grid grid-cols-2 gap-3">
      <label class="field"><span>Tipo</span><select class="select" name="kind">${Object.entries(state.boot.kinds).map(([k, l]) => `<option value="${k}" ${u?.kind === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      <label class="field"><span>Estado</span><select class="select" name="status">${Object.entries(state.boot.statuses).map(([k, l]) => `<option value="${k}" ${(u?.status || 'en_curso') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
    </div>
    <label class="field"><span>Título</span><input class="input" name="title" value="${esc(u?.title || '')}" placeholder="Ej: Nueva campaña de Hot Sale" required></label>
    <label class="field"><span>Detalle</span><textarea class="input" name="body" rows="4" placeholder="Qué se hizo, por qué y qué sigue">${esc(u?.body || '')}</textarea></label>
    <div class="grid grid-cols-2 gap-3">
      <label class="field"><span>Fecha de entrega</span><input class="input" type="date" name="due_date" value="${esc(u?.due_date || '')}"></label>
      <label class="field"><span>Link (Drive, Canva…)</span><input class="input" name="link" value="${esc(u?.link || '')}" placeholder="https://"></label>
    </div>
    <button class="btn btn-accent w-full">${u ? 'Guardar cambios' : 'Publicar'}</button>
  </form>`;
}

function bindUpdateForm(form, clientId, u = null) {
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await post('update_save', { ...Object.fromEntries(new FormData(form)), client_id: clientId, id: u?.id }, u ? 'Novedad actualizada' : 'Novedad publicada');
      closeModal();
      render({ silent: true });
    } catch { /* toast */ }
  });
}

function credentialsModal(user, pass, url) {
  const text = `Acceso a tu panel de resultados\n${url}\nUsuario: ${user}\nContraseña: ${pass}`;
  const el = modal(`${modalHead('Acceso listo', 'Copialo y envíaselo al cliente. La contraseña no se vuelve a mostrar.')}
    <div class="p-5 space-y-4">
      <div class="sunk rounded-xl p-4 mono text-[13px] leading-7 t1 whitespace-pre-wrap">${esc(text)}</div>
      <div class="flex gap-2"><button class="btn btn-primary" id="copy-cred">${icon('copy', 14)}Copiar</button>
        <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(text)}">Enviar por WhatsApp</a><div class="flex-1"></div><button class="btn btn-ghost" data-close>Listo</button></div>
    </div>`, 'sm');
  $('#copy-cred', el).addEventListener('click', () => copyText(text));
}

// =========================================================================
// Admin · Actividad
// =========================================================================
async function viewActivity() {
  crumbs([{ label: 'Actividad' }]);
  const ev = await api('activity', { limit: 100 });
  const ic = { feed: 'page', comments: 'social', mentions: 'bell', messages: 'social' };
  return {
    html: `${pageHead('Actividad en tiempo real', 'Comentarios, menciones y publicaciones que Meta notifica por webhook.')}
      ${ev.length ? `<div class="card divide">${ev.map((e) => `
        <div class="px-5 py-3.5 flex gap-3">
          <span class="w-8 h-8 rounded-lg sunk grid place-items-center t2 shrink-0">${icon(ic[e.field] || 'bell', 15)}</span>
          <div class="flex-1 min-w-0"><div class="t1"><b class="font-medium">${esc(e.asset)}</b> <span class="t3">· ${esc(e.field)}${e.item ? ' · ' + esc(e.item) : ''}${e.from ? ' · ' + esc(e.from) : ''}</span></div>
            ${e.text ? `<div class="t2 truncate mt-0.5">${esc(e.text)}</div>` : ''}</div>
          <span class="t3 text-xs whitespace-nowrap">${fmt.ago(e.received_at)}</span>
        </div>`).join('')}</div>` : empty('Todavía no llegaron eventos', 'Configurá el webhook en tu app de Meta (Configuración muestra la URL) y vas a ver comentarios y menciones al instante.', '', 'bell')}`,
  };
}

// =========================================================================
// Accesos de usuarios internos (equipo / solo lectura)
// =========================================================================
const SCOPE_LABELS = { business: 'portfolio', ad_account: 'cuenta', page: 'página', ig_account: 'Instagram', client: 'cliente' };
function scopeSummary(scope) {
  if (!scope || !scope.length) return '<span class="t-warn">ve todo (sin asignar)</span>';
  const n = {};
  scope.forEach((x) => { n[x.kind] = (n[x.kind] || 0) + 1; });
  return Object.entries(n).map(([k, v]) => `${v} ${SCOPE_LABELS[k]}${v > 1 ? (k === 'ig_account' ? '' : k === 'business' ? 's' : k === 'client' ? 's' : 's') : ''}`).join(' · ');
}

function scopeModal(user, s, assets) {
  const has = new Set((user.scope || []).map((x) => `${x.kind}:${x.ref}`));
  const accByBiz = {};
  assets.ad_accounts.forEach((a) => { const b = a.business_id || a.data.business?.id; if (b) accByBiz[b] = (accByBiz[b] || 0) + 1; });
  const section = (kind, title, items, sub) => `
    <div class="card overflow-hidden flex flex-col">
      <div class="card-h" style="min-height:46px"><div class="h2 text-[14px]">${title}</div><span class="t3 text-xs" data-scount="${kind}"></span><div class="flex-1"></div>
        <button type="button" class="link text-xs" data-sall="${kind}">Todos</button><button type="button" class="link text-xs ml-2" data-snone="${kind}">Ninguno</button></div>
      <div class="max-h-[230px] overflow-y-auto divide">${items.map((it) => `
        <label class="flex items-center gap-3 px-4 py-2 cursor-pointer hover:bg-[var(--hover)]" data-sname="${esc((it.name || '').toLowerCase())}">
          <input type="checkbox" class="check" data-skind="${kind}" value="${esc(it.id)}" ${has.has(`${kind}:${it.id}`) ? 'checked' : ''}>
          <div class="min-w-0 flex-1"><div class="t1 truncate text-[13px]">${esc(it.name)}</div>${sub ? `<div class="t3 text-[11px] truncate">${sub(it)}</div>` : ''}</div>
        </label>`).join('') || '<div class="p-4 t3 text-[13px]">Nada para asignar.</div>'}</div>
    </div>`;
  const el = modal(`${modalHead(`Accesos de ${user.name}`, 'Elegí qué ve. Si no marcás nada, ve todo.')}
    <div class="p-5 space-y-4">
      <div class="flex flex-wrap gap-3 items-center">
        <div class="seg" id="scope-role"><button type="button" data-r="member" class="${user.role === 'member' ? 'on' : ''}">Equipo (puede editar)</button><button type="button" data-r="viewer" class="${user.role === 'viewer' ? 'on' : ''}">Solo lectura</button></div>
        <div class="flex-1"></div><input class="input select-sm w-56" placeholder="Filtrar" id="scope-filter">
      </div>
      <p class="t3 text-xs">Un <b>portfolio comercial</b> incluye todas sus cuentas publicitarias. Un <b>cliente</b> incluye sus cuentas, páginas e Instagram. Una <b>página</b> incluye su Instagram vinculado.</p>
      <div class="grid md:grid-cols-2 gap-4">
        ${section('business', 'Portfolios comerciales', s.businesses.map((b) => ({ id: b.external_id, name: b.name })), (it) => `${accByBiz[it.id] || 0} cuentas publicitarias`)}
        ${section('client', 'Clientes / marcas', s.clients.map((c) => ({ id: String(c.id), name: c.name })))}
        ${section('ad_account', 'Cuentas publicitarias', assets.ad_accounts.map((a) => ({ id: a.external_id, name: a.name, biz: a.data.business?.name })), (it) => esc(it.biz || 'Sin portfolio'))}
        ${section('page', 'Páginas de Facebook', assets.pages.map((p) => ({ id: p.external_id, name: p.name })))}
        ${section('ig_account', 'Instagram', assets.ig_accounts.map((p) => ({ id: p.external_id, name: p.name })))}
      </div>
      <div class="flex gap-2 pt-1"><button class="btn btn-primary" id="scope-save">Guardar accesos</button><button class="btn btn-ghost" data-close>Cancelar</button></div>
    </div>`);
  const counts = () => Object.keys(SCOPE_LABELS).forEach((k) => { const c = $(`[data-scount="${k}"]`, el); if (c) c.textContent = `${$$(`[data-skind="${k}"]:checked`, el).length} marcados`; });
  counts();
  el.addEventListener('change', counts);
  el.addEventListener('click', (e) => {
    const r = e.target.closest('[data-r]');
    if (r) $$('#scope-role [data-r]', el).forEach((x) => x.classList.toggle('on', x === r));
    const all = e.target.closest('[data-sall]'), none = e.target.closest('[data-snone]');
    if (all || none) {
      const k = (all || none).dataset.sall || (all || none).dataset.snone;
      $$(`[data-skind="${k}"]`, el).forEach((x) => { if (x.closest('label').style.display !== 'none') x.checked = !!all; });
      counts();
    }
  });
  $('#scope-filter', el).addEventListener('input', (e) => { const q = e.target.value.toLowerCase(); $$('[data-sname]', el).forEach((x) => { x.style.display = x.dataset.sname.includes(q) ? '' : 'none'; }); });
  $('#scope-save', el).addEventListener('click', async () => {
    const scope = $$('[data-skind]:checked', el).map((x) => ({ kind: x.dataset.skind, ref: x.value }));
    const role = $('#scope-role .on', el)?.dataset.r || user.role;
    try { await post('user_scope_save', { user_id: user.id, role, scope }, `Accesos guardados (${scope.length})`); closeModal(); render({ silent: true }); } catch { /* toast */ }
  });
}

// =========================================================================
// Admin · Configuración
// =========================================================================
async function viewSettings() {
  crumbs([{ label: 'Configuración' }]);
  const [s, assets] = await Promise.all([api('settings'), api('assets', { all: 1 })]);
  const conn = s.connections.map((c) => `
    <div class="px-5 py-4 flex flex-wrap items-start gap-4">
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2"><span class="t1 font-medium">${esc(c.label)}</span><span class="pill ${c.token_source === 'system_user' ? 'blue' : 'gray'} plain">${{ oauth: 'Login con Facebook', system_user: 'System User', manual: 'Token manual' }[c.token_source] || c.token_source}</span></div>
        <div class="t3 text-xs mt-1">${esc(c.meta_user_name || '')} · ${esc(c.meta_user_id || '')} · sincronizado ${c.last_sync_at ? fmt.ago(c.last_sync_at) : 'nunca'}</div>
        <div class="text-xs mt-1 ${c.days_left !== null && c.days_left < 10 ? 't-bad' : 't2'}">${c.days_left === null ? 'Token sin vencimiento' : `El token vence en ${c.days_left} días`}</div>
        <div class="t3 text-xs mt-1">${c.assets.map((a) => `${a.total} ${{ business: 'portfolios', ad_account: 'cuentas', page: 'páginas', ig_account: 'Instagram' }[a.type]}`).join(' · ')}</div>
        ${c.last_error ? `<div class="t-warn text-xs mt-2">${esc(c.last_error)}</div>` : ''}
      </div>
      <div class="flex gap-2"><button class="btn btn-sm" data-sync="${c.id}">${icon('refresh', 14)}Sincronizar</button><button class="btn btn-sm btn-ghost btn-danger" data-del-conn="${c.id}">Quitar</button></div>
    </div>`).join('');

  const assetRows = [
    ...assets.ad_accounts.map((x) => ({ ...x, kind: 'Cuenta publicitaria' })), ...assets.pages.map((x) => ({ ...x, kind: 'Página' })),
    ...assets.ig_accounts.map((x) => ({ ...x, kind: 'Instagram' })),
  ];
  const copyRow = (label, v) => `<div><div class="t3 text-xs mb-1">${label}</div><div class="flex gap-2 items-center"><code class="mono text-xs break-all flex-1 sunk rounded-lg px-2.5 py-2">${esc(v)}</code><button class="btn btn-sm" data-copy="${esc(v)}">${icon('copy', 13)}</button></div></div>`;

  const html = `
    ${pageHead('Configuración')}
    <div class="grid xl:grid-cols-3 gap-6">
      <div class="xl:col-span-2 space-y-6">
        <section class="card">
          <div class="card-h"><div class="h2">Conexiones con Meta</div><div class="flex-1"></div>
            ${s.oauth_ready ? `<a class="btn btn-primary btn-sm" href="index.php?r=oauth-start">Conectar con Facebook</a>` : ''}
            <button class="btn btn-sm" data-sync="all">${icon('refresh', 14)}Sincronizar todo</button></div>
          <div class="divide">${conn || '<div class="p-5 t2">Todavía no hay conexiones. Usá “Conectar con Facebook” o pegá un token de System User.</div>'}</div>
          ${!s.oauth_ready ? '<div class="px-5 pb-4 t-warn text-xs">Completá meta.app_id y meta.app_secret en config.php para habilitar el login con Facebook.</div>' : ''}
        </section>
        <section class="card">
          <div class="card-h"><div class="h2">Agregar token manualmente</div></div>
          <form class="p-5 grid sm:grid-cols-2 gap-3" id="token-form">
            <input class="input" name="label" placeholder="Nombre (ej: System User agencia)">
            <select class="select" name="source"><option value="system_user">Token de System User (no vence)</option><option value="manual">Token de usuario (se extiende a 60 días)</option></select>
            <textarea class="input sm:col-span-2 mono text-xs" name="token" rows="3" placeholder="EAAG…" required></textarea>
            <div class="sm:col-span-2 flex items-center gap-3"><button class="btn btn-primary">Guardar y sincronizar</button><span class="t3 text-xs">Se guarda cifrado con AES-256.</span></div>
          </form>
        </section>
        ${tableHtml('assets', [
          { key: 'name', label: 'Activo', left: true, value: (r) => r.name, html: (r) => `<div>${esc(r.name)}</div><div class="sub mono">${esc(r.external_id)}</div>` },
          { key: 'kind', label: 'Tipo', value: (r) => r.kind },
          { key: 'is_hidden', label: 'En mi panel', value: (r) => (r.is_hidden ? 0 : 1),
            html: (r) => `<label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" class="check" data-asset-vis="${r.id}" ${r.is_hidden ? '' : 'checked'}><span class="t2">${r.is_hidden ? 'Oculto' : 'Visible'}</span></label>` },
        ], assetRows, { title: 'Activos sincronizados', sort: 'kind', dir: 'asc', maxHeight: '440px', filters: [{ label: 'Todos' }, { label: 'Cuentas', fn: (r) => r.type === 'ad_account' }, { label: 'Páginas', fn: (r) => r.type === 'page' }, { label: 'Instagram', fn: (r) => r.type === 'ig_account' }] })}
      </div>

      <div class="space-y-6">
        <form class="card p-5 space-y-3" id="tax-form">
          <div class="h2">Impuestos sobre el gasto en Meta</div>
          <p class="t3 text-xs">Se suman al gasto en presupuestos, costo por venta y ROAS real. En Argentina suele rondar el 35% (IVA + percepciones).</p>
          <div class="flex gap-2 items-center"><input class="input num w-28" name="tax_rate" value="${esc(String(s.tax_rate))}" inputmode="decimal"><span class="t2">%</span><div class="flex-1"></div><button class="btn btn-primary">Guardar</button></div>
        </form>
        <section class="card p-5 space-y-4">
          <div class="h2">Datos para tu app de Meta</div>
          ${copyRow('URI de redirección OAuth', s.redirect_uri)}
          ${copyRow('URL del webhook', s.webhook_url)}
          <div class="t3 text-xs">Graph API ${esc(s.graph_version)}</div>
        </section>
        <section class="card">
          <div class="card-h"><div class="h2">Equipo y gerencia</div></div>
          <div class="divide">${s.users.map((u) => `<div class="px-5 py-3 flex items-center gap-3"><div class="mono-avatar w-8 h-8 text-xs" style="background:var(--sunk);color:var(--t2)">${esc(initials(u.name))}</div>
            <div class="flex-1 min-w-0"><div class="t1 truncate">${esc(u.name)} ${{ viewer: '<span class="pill blue plain">Solo lectura</span>', member: '<span class="pill accent plain">Equipo</span>' }[u.role] || ''}</div>
              <div class="t3 text-xs truncate"><span class="mono">${esc(u.username)}</span>${u.role !== 'admin' ? ` · ${scopeSummary(u.scope)}` : ''}</div></div>
            ${u.role !== 'admin' ? `<button class="btn btn-sm" data-scope-user="${u.id}">${icon('key', 13)}Accesos</button>` : ''}
            ${u.id !== state.boot.user.id ? `<button class="btn btn-sm btn-ghost btn-danger" data-del-user="${u.id}">${icon('x', 14)}</button>` : '<span class="pill plain">Vos</span>'}</div>`).join('')}</div>
          <form class="p-5 space-y-2 hair-t" id="admin-form">
            <input class="input" name="name" placeholder="Nombre" required><input class="input mono" name="username" placeholder="Usuario o email" autocapitalize="none" required>
            <input class="input" name="password" type="password" minlength="8" placeholder="Contraseña (mín. 8)" required>
            <select class="select" name="role"><option value="member">Equipo: ve lo que le asignes y carga presupuestos, objetivos y ventas</option><option value="viewer">Solo lectura (jefes / gerencia): no edita</option><option value="admin">Administrador: ve y edita todo</option></select>
            <button class="btn w-full">Agregar usuario</button>
            <p class="t3 text-xs">Para clientes usá la sección Clientes.</p>
          </form>
        </section>
        <section class="card">
          <div class="card-h"><div class="h2">Mi contraseña</div></div>
          <form class="p-5 space-y-2" id="pass-form"><input class="input" type="password" name="current" placeholder="Actual" required><input class="input" type="password" name="new" minlength="8" placeholder="Nueva (mín. 8)" required><button class="btn w-full">Actualizar</button></form>
        </section>
        <section class="card">
          <div class="card-h"><div class="h2">Mantenimiento</div><div class="flex-1"></div><button class="btn btn-sm" id="flush">Vaciar caché</button></div>
          <ul class="text-xs divide max-h-72 overflow-y-auto">${s.log.map((l) => `<li class="px-5 py-2.5"><div class="flex items-center gap-2"><span class="pill ${l.status === 'ok' ? 'green' : 'red'}">${esc(l.job)}</span><span class="t3">${fmt.ago(l.created_at)}</span></div><div class="t2 truncate mt-1" title="${esc(l.message)}">${esc(l.message)}</div></li>`).join('') || '<li class="px-5 py-4 t3">El cron todavía no corrió.</li>'}</ul>
        </section>
      </div>
    </div>`;

  const reload = async () => { state.boot = await api('bootstrap'); renderNav(); render(); };
  return {
    html,
    mount() {
      mountTable('assets');
      const view = $('#view');
      view.addEventListener('click', async (e) => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.sync) {
          t.disabled = true; t.lastChild.textContent = 'Sincronizando…';
          try { await post('sync', t.dataset.sync === 'all' ? {} : { connection_id: +t.dataset.sync }, 'Sincronización completa'); await reload(); } catch { t.disabled = false; }
        } else if (t.dataset.delConn) {
          if (confirm('¿Quitar esta conexión? Se borran sus activos del panel (no afecta nada en Meta).')) { await post('connection_delete', { id: +t.dataset.delConn }, 'Conexión eliminada'); await reload(); }
        } else if (t.dataset.delUser) {
          if (confirm('¿Eliminar este usuario?')) { await post('user_delete', { id: +t.dataset.delUser }, 'Usuario eliminado'); render(); }
        } else if (t.dataset.scopeUser) {
          scopeModal(s.users.find((u) => u.id === +t.dataset.scopeUser), s, assets);
        } else if (t.dataset.copy) {
          copyText(t.dataset.copy);
        } else if (t.id === 'flush') {
          await post('cache_flush', {}, 'Caché vaciada');
        }
      });
      view.addEventListener('change', async (e) => {
        if (e.target.dataset.assetVis) {
          await post('asset_toggle', { id: +e.target.dataset.assetVis, hidden: !e.target.checked });
          e.target.nextElementSibling.textContent = e.target.checked ? 'Visible' : 'Oculto';
          state.boot = await api('bootstrap'); renderNav();
        }
      });
      const form = (id, action, msg, after, extra = {}) => {
        const f = $('#' + id);
        f?.addEventListener('submit', async (e) => {
          e.preventDefault();
          const btn = $('button', f); btn.disabled = true;
          try { await post(action, { ...Object.fromEntries(new FormData(f)), ...extra }, msg); f.reset(); if (after) await after(); } catch { /* toast */ } finally { btn.disabled = false; }
        });
      };
      form('token-form', 'connection_add_token', 'Token guardado y activos sincronizados', reload);
      form('admin-form', 'user_add', 'Usuario creado', () => render());
      form('tax-form', 'settings_save', 'Impuestos actualizados', async () => { state.boot = await api('bootstrap'); render(); });
      form('pass-form', 'password_change', 'Contraseña actualizada');
    },
  };
}

// =========================================================================
// Ventas propias (Excel / Google Sheets / carga manual)
// =========================================================================
const salesFmt = (cur) => ({ money: (v) => fmt.moneyShort(v, cur), moneyFull: (v) => fmt.money(v, cur) });
const lower = (s) => String(s || '').toLowerCase();
/** Misma normalización que el servidor para cruzar nombres de campañas. */
const normName = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[\s\-_|·]+/g, ' ').trim();
function campaignMatches(patterns, id, name) {
  const n = normName(name), compact = n.replace(/ /g, '');
  return patterns.some((p) => (/^\d+$/.test(p) ? p === id : (normName(p) && (n.includes(normName(p)) || compact.includes(normName(p).replace(/ /g, ''))))));
}

/** Tarjeta de proyección del mes. */
function projectionCard(mo, L, cur) {
  if (!mo) return '';
  const { money } = salesFmt(cur);
  const monthName = new Date(mo.month + '-15').toLocaleDateString(LOCALE, { month: 'long' });
  const target = mo.target_orders;
  const pct = target ? Math.min(100, (mo.orders / target) * 100) : (mo.projected_orders ? (mo.orders / mo.projected_orders) * 100 : 0);
  const projPct = target ? Math.min(100, (mo.projected_orders / target) * 100) : 100;
  const vsPrev = mo.prev_month_orders ? ((mo.projected_orders - mo.prev_month_orders) / mo.prev_month_orders) * 100 : null;
  return `
    <div class="card p-5 flex flex-col">
      <div class="flex items-center gap-2"><div class="h2">Proyección de ${esc(monthName)}</div><span class="t3 text-xs">día ${mo.elapsed} de ${mo.days_in_month}</span></div>
      <div class="flex items-end gap-6 mt-4 flex-wrap">
        <div><div class="t2 text-[12.5px]">Acumulado</div><div class="hero-num t1 mt-1" style="font-size:32px">${fmt.int(mo.orders)}</div><div class="t3 text-xs mt-1">${esc(lower(L.sales))}${mo.revenue ? ' · ' + money(mo.revenue) : ''}</div></div>
        <div><div class="t2 text-[12.5px]">Cierre estimado</div><div class="hero-num t-accent mt-1" style="font-size:32px">≈ ${fmt.int(mo.projected_orders)}</div><div class="t3 text-xs mt-1">${mo.projected_revenue ? money(mo.projected_revenue) + ' · ' : ''}${vsPrev !== null ? `${vsPrev >= 0 ? '+' : ''}${fmt.dec(vsPrev, 1)}% vs. mes anterior` : 'al ritmo actual'}</div></div>
        ${target ? `<div><div class="t2 text-[12.5px]">Objetivo</div><div class="hero-num t1 mt-1" style="font-size:32px">${fmt.int(target)}</div><div class="t3 text-xs mt-1">${mo.needed_per_day !== null ? `Hacen falta ${fmt.dec(mo.needed_per_day, 1)} por día` : ''}</div></div>` : ''}
      </div>
      <div class="mt-5">
        <div class="relative h-2.5 rounded-full sunk overflow-hidden">
          <div class="absolute inset-y-0 left-0 rounded-full" style="width:${projPct}%;background:var(--accent-soft)"></div>
          <div class="absolute inset-y-0 left-0 rounded-full" style="width:${pct}%;background:var(--accent)"></div>
        </div>
        <div class="flex text-[11.5px] t3 mt-1.5"><span>${target ? `${fmt.dec((mo.orders / target) * 100, 0)}% del objetivo` : 'Acumulado vs. estimado'}</span><span class="flex-1"></span><span>Mismo día del mes anterior: ${fmt.int(mo.prev_same_orders)}</span></div>
      </div>
    </div>`;
}

/** Cruce con Meta Ads: costo por venta y ROAS reales. */
function crossoverCard(ads, L, tot) {
  if (!ads) return '';
  const money = (v) => fmt.money(v, ads.currency);
  return `
    <div class="card p-5">
      <div class="flex items-center gap-2"><div class="h2">Publicidad vs. ${esc(lower(L.sales))} reales</div><span class="pill accent plain">Meta Ads</span></div>
      <div class="grid grid-cols-2 gap-4 mt-4">
        <div><div class="t2 text-[12.5px]">Inversión + imp. (${taxPct()})</div><div class="num text-xl font-semibold t1 mt-1">${money(ads.spend_tax)}</div><div class="t3 text-xs">${money(ads.spend)} en Meta</div></div>
        <div><div class="t2 text-[12.5px]">Costo por ${esc(lower(L.sales).replace(/s$/, ''))}</div><div class="num text-xl font-semibold t1 mt-1">${money(ads.cost_per_order)}</div></div>
        <div><div class="t2 text-[12.5px]">ROAS real</div><div class="num text-xl font-semibold t1 mt-1">${fmt.x(ads.roas_real)}</div></div>
        <div><div class="t2 text-[12.5px]">Atribuidas por Meta</div><div class="num text-xl font-semibold t1 mt-1">${fmt.int(ads.meta_results)} <span class="t3 text-sm font-normal">de ${fmt.int(tot.orders)}</span></div></div>
      </div>
      ${ads.meta_share !== null ? `<div class="bar mt-4"><i style="width:${ads.meta_share}%"></i></div><div class="t3 text-xs mt-1.5">Meta registra el ${fmt.dec(ads.meta_share, 0)}% de las ${esc(lower(L.sales))}. El resto llega por otros canales o sin atribución.</div>` : ''}
    </div>`;
}

function salesKpis(d) {
  const L = d.labels, t = d.totals, p = d.previous, cur = d.currency;
  const { money } = salesFmt(cur);
  const s = (k) => sparkline(d.daily.map((x) => x[k]));
  const vs = `vs. ${d.compared_days} días anteriores`;
  const items = [
    [L.sales, fmt.int(t.orders), delta(t.orders, p.orders), s('orders')],
    t.revenue > 0 && ['Facturación', money(t.revenue), delta(t.revenue, p.revenue), s('revenue')],
    t.avg_ticket && ['Ticket promedio', money(t.avg_ticket), delta(t.avg_ticket, p.avg_ticket), ''],
    [`Promedio diario`, fmt.dec(t.per_day, 1), delta(t.per_day, p.per_day), ''],
    d.has_leads && [L.leads, fmt.int(t.leads), delta(t.leads, p.leads), s('leads')],
    d.has_leads && ['Tasa de conversión', fmt.pct(t.conv_rate), delta(t.conv_rate, p.conv_rate), ''],
  ].filter(Boolean);
  const cols = items.length >= 6 ? 'lg:grid-cols-6' : items.length === 5 ? 'lg:grid-cols-5' : 'lg:grid-cols-4';
  return `<div class="kpi-row grid-cols-2 sm:grid-cols-3 ${cols} mb-6" style="display:grid">${items.map(([l, v, dl, sp]) => kpi(l, v, { d: dl, spark: sp, vs })).join('')}</div>`;
}

const freshness = (d) => {
  if (!d.last_day) return '';
  const days = Math.round((Date.now() - new Date(d.last_day + 'T12:00:00').getTime()) / 86400000);
  const cls = days <= 1 ? 'green' : days <= 3 ? 'amber' : 'red';
  return `<span class="pill ${cls}">Datos hasta el ${fmt.date(d.last_day)}${days > 1 ? ` · hace ${days} días` : ''}</span>`;
};

/** Mes a mes: lo cargado en la planilla contra lo real de Meta, con el cotejo de diferencias. */
async function monthlyBlock(box, cid) {
  if (!box) return;
  let d;
  try { d = await api('sales_monthly', { client: cid, months: 6 }); } catch (e) { box.innerHTML = errorBox(e.message, false); return; }
  const cur = d.client.currency;
  const m = (v) => fmt.money(v, cur);
  const L = d.client.sales_label || 'Ventas';
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  const rows = d.months;
  if (!rows.some((r) => r.days || r.meta_spend)) { box.innerHTML = ''; return; }
  const name = (k) => cap(new Date(k + '-15').toLocaleDateString(LOCALE, { month: 'long', year: 'numeric' }));
  const check = (diff) => (diff === null || diff === undefined ? '<span class="t3">—</span>'
    : Math.abs(diff) <= 5 ? `<span class="pill green plain">${icon('check', 11)}Coincide</span>`
    : `<span class="pill ${Math.abs(diff) <= 15 ? 'amber' : 'red'} plain">${diff > 0 ? '+' : ''}${fmt.dec(diff, 0)}%</span>`);
  const hasSheetCheck = rows.some((r) => r.sheet_spend !== null || r.sheet_leads !== null);
  const hasMeta = rows.some((r) => r.meta_spend);
  box.innerHTML = `<div class="card overflow-hidden">
    <div class="card-h"><div><div class="h2">Mes a mes</div><div class="t3 text-xs mt-0.5">${esc(L)} de las planillas${hasMeta ? ` · inversión real de Meta +${tax}` : ''}${hasSheetCheck ? ' · cotejo de lo anotado en la planilla contra Meta' : ''}</div></div></div>
    ${rows.filter((r) => r.orders).length > 1 ? `<div class="p-4 h-[240px] hair-b"><canvas id="monthly-chart"></canvas></div>` : ''}
    <div class="scroll-x"><table class="tbl"><thead><tr>
      <th style="text-align:left">Mes</th><th>${esc(L)}</th><th>Facturación</th>${hasMeta ? `<th>Inversión +${tax}</th><th>Costo por ${esc(lower(L).replace(/s$/, ''))}</th><th>Conversaciones</th><th>Conversión</th>` : ''}
      ${hasSheetCheck ? '<th>Costo anotado vs Meta</th><th>Interacciones anotadas vs Meta</th>' : ''}
    </tr></thead><tbody>
    ${[...rows].reverse().map((r) => `<tr class="clickable" data-month="${r.month}" title="Ver día por día">
      <td style="text-align:left"><div class="font-medium flex items-center gap-1.5">${esc(name(r.month))}<span class="t3">${icon('down', 12)}</span></div><div class="sub">${r.partial ? `en curso · ${r.days} días cargados` : `${r.days} días cargados`}</div></td>
      <td class="font-semibold">${fmt.int(r.orders)}</td>
      <td>${r.revenue ? m(r.revenue) : '—'}</td>
      ${hasMeta ? `<td>${r.meta_spend_tax !== null ? m(r.meta_spend_tax) : '—'}</td><td class="font-semibold">${r.cost_per_sale ? m(r.cost_per_sale) : '—'}</td><td>${r.meta_messages !== null ? fmt.int(r.meta_messages) : '—'}</td><td>${fmt.pct(r.conversion)}</td>` : ''}
      ${hasSheetCheck ? `<td><div class="flex items-center justify-end gap-2">${check(r.spend_diff)}</div><div class="sub">${r.sheet_spend !== null ? `${m(r.sheet_spend)} vs ${m(r.check_spend)}` : 'sin costo anotado'}</div></td>
        <td><div class="flex items-center justify-end gap-2">${check(r.leads_diff)}</div><div class="sub">${r.sheet_leads !== null ? `${fmt.int(r.sheet_leads)} vs ${fmt.int(r.check_messages)}` : 'sin interacciones anotadas'}${r.check_until && r.sheet_spend !== null && r.check_until < (r.month + '-31') && r.partial ? ` · al ${fmt.date(r.check_until)}` : ''}</div></td>` : ''}
    </tr>`).join('')}</tbody></table></div>
    <div id="month-days"></div>
    ${hasSheetCheck ? `<div class="px-5 py-3 hair-t t3 text-xs">Tocá un mes para ver el detalle día por día. El cotejo compara lo que el equipo anota en la planilla (costo de campaña sin impuestos e interacciones) con lo que informa Meta para las campañas de esta marca, en los mismos días (del 1 al último día anotado). Verde: coincide (±5%). Amarillo: hasta 15% de diferencia. Rojo: revisar la carga.</div>` : ''}
  </div>`;
  hydrateIcons(box);
  box.addEventListener('click', (e) => { const tr = e.target.closest('[data-month]'); if (tr) monthDays($('#month-days', box), cid, tr.dataset.month, name(tr.dataset.month)); });
  const withData = rows.filter((r) => r.days || r.meta_spend);
  if ($('#monthly-chart')) {
    chart($('#monthly-chart'), withData.map((r) => cap(new Date(r.month + '-15').toLocaleDateString(LOCALE, { month: 'short' }))), [
      { label: L, data: withData.map((r) => r.orders), type: 'bar', fmt: fmt.int, color: cssVar('--accent'), alpha: 'cc' },
      ...(hasMeta ? [{ label: `Costo por ${lower(L).replace(/s$/, '')}`, data: withData.map((r) => r.cost_per_sale), axis: 'y1', fmt: m, color: cssVar('--c2') }] : []),
    ], { fmtY: fmt.int, fmtY1: (v) => fmt.moneyShort(v, cur) });
  }
}

/** Detalle día por día de un mes: planilla contra Meta, para revisar la carga. */
async function monthDays(box, cid, month, title) {
  if (!box) return;
  if (box.dataset.month === month) { box.innerHTML = ''; box.dataset.month = ''; return; }
  box.dataset.month = month;
  box.innerHTML = '<div class="p-5 hair-t"><div class="sk h-48"></div></div>';
  let d;
  try { d = await api('sales_days', { client: cid, month }); } catch (e) { box.innerHTML = `<div class="p-5 hair-t t-bad">${esc(e.message)}</div>`; return; }
  const m = (v) => (v === null || v === undefined ? '—' : fmt.money(v, d.currency));
  const n = (v) => (v === null || v === undefined ? '—' : fmt.int(v));
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  const sum = (k) => d.days.reduce((t, r) => t + (r[k] || 0), 0);
  const L = d.sales_label || 'Ventas';
  const dup = d.days.some((r) => r.sources > 1);
  box.innerHTML = `<div class="hair-t">
    <div class="px-5 py-3 flex flex-wrap items-center gap-2"><div class="h2">${esc(title)} · día por día</div><div class="flex-1"></div>
      <span class="t3 text-xs">Fuentes: ${esc(d.sources.map((x) => x.name).join(' · ') || '—')}</span></div>
    ${dup ? '<div class="mx-5 mb-3 split-banner text-[13px]">Hay días que vienen de <b>dos planillas distintas</b> y se están sumando. Revisá en Clientes → Ventas que el mismo mes no esté en dos archivos.</div>' : ''}
    <div class="scroll-x max-h-[520px] overflow-y-auto"><table class="tbl sheet-tbl"><thead><tr>
      <th style="text-align:left">Día</th><th>${esc(L)}</th><th>Interacciones anotadas</th><th>Conversaciones Meta</th><th>Costo anotado</th><th>Gasto Meta</th><th>Gasto Meta +${tax}</th><th>Costo por ${esc(lower(L).replace(/s$/, ''))}</th>
    </tr></thead><tbody>
    ${d.days.map((r) => `<tr class="${r.orders === null ? 'opacity-60' : ''}">
      <td style="text-align:left">${new Date(r.day + 'T12:00:00').toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' })}${r.sources > 1 ? ' <span class="pill amber plain">duplicado</span>' : ''}</td>
      <td class="font-semibold">${n(r.orders)}</td><td>${n(r.sheet_leads)}</td><td>${n(r.meta_messages)}</td>
      <td>${m(r.sheet_spend)}</td><td>${m(r.meta_spend)}</td><td>${m(r.meta_spend_tax)}</td>
      <td>${r.orders && r.meta_spend_tax ? m(r.meta_spend_tax / r.orders) : '—'}</td></tr>`).join('')}
    <tr class="font-semibold"><td style="text-align:left">Total</td><td>${n(sum('orders'))}</td><td>${n(sum('sheet_leads'))}</td><td>${n(sum('meta_messages'))}</td><td>${m(sum('sheet_spend'))}</td><td>${m(sum('meta_spend'))}</td><td>${m(sum('meta_spend_tax'))}</td><td>${sum('orders') ? m(sum('meta_spend_tax') / sum('orders')) : '—'}</td></tr>
    </tbody></table></div></div>`;
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function viewSales(cid) {
  cid = +cid || portalClient();
  const brand = await clientBrand(cid);
  setAccent(brand.color);
  const d = await api('sales', rp({ client: cid }));
  const L = d.labels, cur = d.currency;
  const { money, moneyFull } = salesFmt(cur);
  if (!d.has_data) {
    return { html: previewBanner(cid, brand.name) + empty(`Todavía no hay ${lower(L.sales)} cargadas`, isClient() ? 'La agencia va a conectar la planilla de ventas y la vas a ver acá día a día.' : 'Cargalas desde Clientes → Ventas: subí el Excel, conectá un Google Sheets o cargá el día a mano.', isClient() ? '' : `<a class="btn btn-primary" href="#/clients/${cid}">Ir a cargar ventas</a>`, 'report') };
  }
  const daily = d.daily;
  const cols = [
    { key: 'date', label: 'Día', left: true, value: (r) => r.date, html: (r) => `${new Date(r.date + 'T12:00:00').toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' })}` },
    { key: 'orders', label: L.sales, value: (r) => r.orders, total: (rows) => fmt.int(rows.reduce((x, r) => x + (r.orders || 0), 0)) },
    ...(d.totals.revenue > 0 ? [{ key: 'revenue', label: 'Facturación', value: (r) => r.revenue, html: (r) => moneyFull(r.revenue), total: (rows) => moneyFull(rows.reduce((x, r) => x + (r.revenue || 0), 0)) },
      { key: 'ticket', label: 'Ticket prom.', value: (r) => (r.orders ? r.revenue / r.orders : null), html: (r) => moneyFull(r.orders ? r.revenue / r.orders : null) }] : []),
    ...(d.has_leads ? [{ key: 'leads', label: L.leads, value: (r) => r.leads, total: (rows) => fmt.int(rows.reduce((x, r) => x + (r.leads || 0), 0)) },
      { key: 'conv', label: 'Conversión', value: (r) => (r.leads ? (r.orders / r.leads) * 100 : null), html: (r) => fmt.pct(r.leads ? (r.orders / r.leads) * 100 : null) }] : []),
    ...(d.ads ? [{ key: 'spend', label: `Inversión + ${taxPct()}`, value: (r) => d.ads.spend_daily[r.date] ?? null, html: (r) => fmt.money(d.ads.spend_daily[r.date], d.ads.currency) },
      { key: 'cpo', label: 'Costo/venta', value: (r) => (r.orders && d.ads.spend_daily[r.date] ? d.ads.spend_daily[r.date] / r.orders : null), html: (r) => fmt.money(r.orders && d.ads.spend_daily[r.date] ? d.ads.spend_daily[r.date] / r.orders : null, d.ads.currency) }] : []),
  ];
  const last = d.last;
  const best = [...daily].filter((x) => x.orders != null).sort((a, b) => b.orders - a.orders)[0];

  const html = `
    ${previewBanner(cid, brand.name)}
    ${isClient() ? backLink('#/', 'Inicio') : ''}
    ${pageHead(esc(L.sales), `${esc(brand.name)} · ${fmt.dateLong(d.range.since)} – ${fmt.dateLong(d.range.until)}`, `<div class="flex items-center gap-2">${freshness(d)}<a class="btn" href="${state.preview ? `#/preview/${cid}/report` : '#/report'}">${icon('report', 15)}Reporte</a></div>`)}
    ${salesKpis(d)}
    <div id="sales-monthly" class="mb-6"><div class="sk h-64"></div></div>
    <div class="grid xl:grid-cols-3 gap-6 mb-6">
      ${projectionCard(d.month, L, cur).replace('class="card p-5 flex flex-col"', 'class="card p-5 flex flex-col xl:col-span-2"')}
      <div class="card p-5">
        <div class="h2">Último día cargado</div>
        <div class="t3 text-xs mt-0.5">${last ? new Date(last.day + 'T12:00:00').toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long' }) : ''}</div>
        <div class="hero-num t1 mt-4">${fmt.int(last?.orders)}</div><div class="t2 mt-1">${esc(lower(L.sales))}${last?.revenue ? ' · ' + money(last.revenue) : ''}</div>
        ${best ? `<div class="hair-t mt-5 pt-4 t2 text-[13px]">Mejor día del período: <b class="t1">${fmt.date(best.date)}</b> con ${fmt.int(best.orders)}</div>` : ''}
      </div>
    </div>
    ${d.ads ? `<div class="mb-6">${crossoverCard(d.ads, L, d.totals)}</div>` : ''}
    ${campaignSalesBlock(d.ads, L, cur)}
    <div class="card mb-6"><div class="card-h"><div class="h2">${esc(L.sales)} por día</div><div class="flex-1"></div>
      ${d.totals.revenue > 0 || d.ads ? `<span class="t3 text-xs">vs.</span><select class="select select-sm" id="sales-line">${d.totals.revenue > 0 ? '<option value="revenue">Facturación</option>' : ''}${d.has_leads ? `<option value="leads">${esc(L.leads)}</option>` : ''}${d.ads ? `<option value="spend">Inversión + ${taxPct()}</option>` : ''}<option value="">—</option></select>` : ''}</div>
      <div class="p-4 h-[300px]"><canvas id="sales-chart"></canvas></div></div>
    <div class="grid xl:grid-cols-2 gap-6 mb-6">
      <div class="card"><div class="card-h"><div class="h2">¿Qué días rinden más?</div><span class="t3 text-xs">promedio, últimos 90 días</span></div><div class="p-4 h-[260px]"><canvas id="wd-chart"></canvas></div></div>
      ${d.dims.length ? `<div class="card overflow-hidden"><div class="card-h"><div class="h2">Por ${esc(lower(L.dim))}</div></div>
        <div class="scroll-x max-h-[300px]"><table class="tbl"><thead><tr><th style="text-align:left">${esc(L.dim)}</th><th>${esc(L.sales)}</th><th>Participación</th>${d.totals.revenue > 0 ? '<th>Facturación</th>' : ''}${d.has_leads ? '<th>Conversión</th>' : ''}</tr></thead><tbody>
        ${d.dims.map((x) => `<tr><td style="text-align:left">${esc(x.label)}</td><td>${fmt.int(x.orders)}</td><td style="min-width:120px"><div class="flex items-center gap-2"><div class="bar flex-1"><i style="width:${(x.orders / d.totals.orders) * 100}%"></i></div><span class="t3 text-xs num w-10">${fmt.dec((x.orders / d.totals.orders) * 100, 0)}%</span></div></td>${d.totals.revenue > 0 ? `<td>${money(x.revenue)}</td>` : ''}${d.has_leads ? `<td>${fmt.pct(x.leads ? (x.orders / x.leads) * 100 : null)}</td>` : ''}</tr>`).join('')}
        </tbody></table></div></div>` : `<div class="card p-5 t3 text-[13px] flex items-center">Si la planilla tiene una columna de ${esc(lower(L.dim))}, canal o producto, acá vas a ver el desglose.</div>`}
    </div>
    ${tableHtml('sales-days', cols, [...daily].reverse().filter((x) => x.orders !== null), { title: 'Detalle diario', csvName: 'ventas-' + brand.name, search: false })}
    ${!isClient() && d.sources.some((x) => x.last_error) ? `<div class="t-warn text-xs mt-3">Una fuente tuvo un error al sincronizar: ${esc(d.sources.find((x) => x.last_error).last_error)}</div>` : ''}`;

  return {
    html,
    mount() {
      monthlyBlock($('#sales-monthly'), cid);
      mountTable('sales-days');
      $('#view').addEventListener('click', (e) => {
        const b = e.target.closest('[data-copy-name]');
        if (b) return copyText(b.dataset.copyName);
        const g = e.target.closest('[data-goal]');
        if (g) { const r = d.ads.campaigns.rows.find((x) => x.id === g.dataset.goal); return goalModal(r, d.ads.currency, () => render({ silent: true })); }
        const tr = e.target.closest('tr[data-go]');
        if (tr) location.hash = tr.dataset.go;
      });
      const sel = $('#sales-line');
      let c;
      const draw = () => {
        dropChart(c);
        const ds = [{ label: L.sales, data: daily.map((x) => x.orders), type: 'bar', color: cssVar('--accent'), alpha: 'bb' }];
        const k = sel?.value;
        if (k === 'revenue') ds.push({ label: 'Facturación', data: daily.map((x) => x.revenue), axis: 'y1', fmt: (v) => fmt.money(v, cur), color: cssVar('--c2') });
        if (k === 'leads') ds.push({ label: L.leads, data: daily.map((x) => x.leads), axis: 'y1', color: cssVar('--c3') });
        if (k === 'spend') ds.push({ label: `Inversión + ${taxPct()}`, data: daily.map((x) => d.ads.spend_daily[x.date] ?? null), axis: 'y1', fmt: (v) => fmt.money(v, d.ads.currency), color: cssVar('--c2') });
        c = chart($('#sales-chart'), daily.map((x) => fmt.date(x.date)), ds);
      };
      sel?.addEventListener('change', draw);
      draw();
      chart($('#wd-chart'), d.weekday.map((w) => w.label), [{ label: `${L.sales} promedio`, data: d.weekday.map((w) => w.orders), type: 'bar', fmt: (v) => fmt.dec(v, 1), color: cssVar('--accent') }]);
    },
  };
}

/** Bloque compacto de ventas para el inicio del cliente. */
async function mountSalesHome(boxId, cid) {
  const box = $('#' + boxId);
  if (!box) return;
  try {
    const d = await api('sales', rp({ client: cid }));
    if (!d.has_data) { box.remove(); return; }
    const L = d.labels;
    box.innerHTML = `
      <div class="flex items-center mb-3 gap-2"><div class="h2">${esc(L.sales)}</div>${freshness(d)}<div class="flex-1"></div><a class="link text-[13px]" href="${state.preview ? `#/preview/${cid}/sales` : '#/sales'}">Ver tablero completo</a></div>
      ${salesKpis(d)}
      <div class="grid xl:grid-cols-3 gap-6 mb-6">
        <div class="card xl:col-span-2"><div class="card-h"><div class="h2">${esc(L.sales)} por día</div></div><div class="p-4 h-[260px]"><canvas id="home-sales-chart"></canvas></div></div>
        ${projectionCard(d.month, L, d.currency)}
      </div>
      ${d.ads ? `<div class="mb-6">${crossoverCard(d.ads, L, d.totals)}</div>` : ''}`;
    chart($('#home-sales-chart'), d.daily.map((x) => fmt.date(x.date)), [{ label: L.sales, data: d.daily.map((x) => x.orders), type: 'bar', color: cssVar('--accent') }]);
  } catch (e) {
    box.innerHTML = errorBox(e.message, false);
  }
}

// ---------- Admin: carga de ventas de un cliente ----------
function salesPane(c) {
  const kinds = { upload: 'Archivo', gsheet: 'Google Sheets', manual: 'Carga manual' };
  return `
    <div class="grid xl:grid-cols-5 gap-6">
      <div class="xl:col-span-3 space-y-6">
        <div class="card">
          <div class="card-h"><div class="h2">Fuentes de ${esc(lower(c.sales_label))}</div><div class="flex-1"></div><a class="btn btn-sm" href="#/preview/${c.id}/sales">${icon('eye', 14)}Ver tablero</a></div>
          <div class="divide">${c.sales_sources.map((s) => `
            <div class="px-5 py-3.5 flex flex-wrap items-center gap-3">
              <span class="w-8 h-8 rounded-lg sunk grid place-items-center t2">${icon(s.kind === 'gsheet' ? 'link' : s.kind === 'manual' ? 'calendar' : 'report', 15)}</span>
              <div class="min-w-0 flex-1"><div class="t1 font-medium">${esc(s.name)} <span class="pill plain">${kinds[s.kind] || s.kind}</span></div>
                <div class="t3 text-xs mt-0.5">${fmt.int(s.rows_count)} filas · ${s.min_day ? `${fmt.date(s.min_day)} → ${fmt.date(s.max_day)}` : 'sin datos'} · actualizado ${s.last_sync_at ? fmt.ago(s.last_sync_at) : 'nunca'}</div>
                ${s.last_error ? `<div class="t-bad text-xs mt-1">${esc(s.last_error)}</div>` : ''}</div>
              ${s.kind === 'gsheet' ? `<button class="btn btn-sm" data-sales-sync="${s.id}">${icon('refresh', 14)}Sincronizar</button>` : ''}
              ${s.kind === 'upload' ? `<button class="btn btn-sm" data-sales-reupload="${s.id}" data-name="${esc(s.name)}">${icon('download', 14)}Subir actualización</button>` : ''}
              <button class="btn btn-sm btn-ghost btn-danger" data-sales-del="${s.id}">${icon('x', 14)}</button>
            </div>`).join('') || `<div class="p-5 t2">Sin fuentes. Conectá un Google Sheets (se actualiza solo), subí el Excel o cargá los días a mano.</div>`}</div>
          ${c.sales_sources.length > 1 ? '<div class="px-5 py-3 hair-t t3 text-xs">Las fuentes se suman entre sí: no cargues el mismo período en dos fuentes distintas.</div>' : ''}
        </div>
        <div class="card" id="sales-wizard">
          <div class="card-h"><div class="h2">Importar planilla</div><div class="flex-1"></div><a class="link text-[13px]" href="assets/plantilla-ventas.csv" download>Descargar plantilla</a></div>
          <div class="p-5 space-y-4" data-step="1">
            <div class="seg" id="imp-kind"><button class="on" data-k="gsheet">Google Sheets (automático)</button><button data-k="upload">Excel / CSV</button></div>
            <div data-k-pane="gsheet" class="space-y-2">
              <textarea class="input !h-20 py-2" id="imp-url" placeholder="Pegá el link de la planilla. Si los meses son pestañas del mismo archivo, alcanza con UN link. Si son archivos distintos, uno por renglón."></textarea>
              <p class="t3 text-xs">1) En Google Sheets: <b>Compartir → “Cualquier persona con el enlace” → Lector</b>. 2) Pegá el link y tocá <b>Conectar</b>: la fecha y las ventas se detectan solas, en <b>todas las pestañas</b> (agregás la del mes nuevo y entra sola). Se vuelve a leer cada 5 minutos.</p>
            </div>
            <div data-k-pane="upload" class="hidden space-y-2">
              <input type="file" class="input !h-auto py-2" id="imp-file" accept=".xlsx,.csv,.txt">
              <p class="t3 text-xs">.xlsx o .csv. Si volvés a subir el archivo, se reemplazan sólo las fechas que trae (ideal para cargar el día).</p>
            </div>
            <input type="hidden" id="imp-source">
            <div class="flex flex-wrap items-center gap-3"><button class="btn btn-primary" id="imp-connect">${icon('link', 14)}Conectar</button><button class="btn btn-ghost hidden" id="imp-preview">Elegir columnas a mano</button></div>
            <div id="imp-result"></div>
          </div>
          <div class="p-5 space-y-4 hidden" data-step="2"></div>
        </div>
      </div>
      <div class="xl:col-span-2 space-y-6">
        <div class="card" id="camp-names-card"><div class="card-h"><div class="h2">Nombres exactos de campañas</div><span class="t3 text-xs">para la columna “Campaña”</span></div>
          <div class="p-4 t3 text-[13px]" data-names>${(c.assets.some((a) => a.asset_type === 'ad_account') || Object.keys(c.rules || {}).length > 0) ? 'Cargando…' : 'Este cliente no tiene cuentas publicitarias asignadas.'}</div></div>
        <form class="card p-5 space-y-3" id="sales-entry">
          <div class="h2">Cargar un día a mano</div>
          <div class="grid grid-cols-2 gap-3">
            <label class="field"><span>Fecha</span><input class="input" type="date" name="day" value="${new Date(Date.now() - 864e5).toISOString().slice(0, 10)}" max="${new Date().toISOString().slice(0, 10)}" required></label>
            <label class="field"><span>${esc(c.sales_label)}</span><input class="input num" name="orders" inputmode="decimal" required></label>
            <label class="field"><span>Facturación (${esc(c.currency)})</span><input class="input num" name="revenue" inputmode="decimal" placeholder="opcional"></label>
            <label class="field"><span>${esc(c.leads_label)}</span><input class="input num" name="leads" inputmode="decimal" placeholder="opcional"></label>
          </div>
          <label class="field"><span>Campaña de Meta (opcional)</span><input class="input" name="campaign" list="camp-names" placeholder="Elegí o escribí el nombre exacto"></label>
          <datalist id="camp-names"></datalist>
          <label class="field"><span>${esc(c.dim_label)} (opcional)</span><input class="input" name="dim"></label>
          <button class="btn btn-accent w-full">Guardar día</button>
          <p class="t3 text-xs">Si el día ya existe en la carga manual, se reemplaza.</p>
        </form>
        <div class="card overflow-hidden"><div class="card-h"><div class="h2">Últimos días</div></div>
          <div class="max-h-[420px] overflow-y-auto"><table class="tbl"><thead><tr><th style="text-align:left">Día</th><th>${esc(c.sales_label)}</th><th>Facturación</th><th style="text-align:left">Campaña · ${esc(c.dim_label)}</th></tr></thead><tbody>
            ${c.sales_recent.map((r) => `<tr><td style="text-align:left">${fmt.date(r.day)}</td><td>${fmt.int(+r.orders)}</td><td>${fmt.money(+r.revenue, c.currency)}</td><td style="text-align:left" class="t3 max-w-[220px] truncate">${esc([r.campaign, r.dim].filter(Boolean).join(' · ') || '—')}</td></tr>`).join('') || '<tr><td colspan="4" class="t3 !py-6" style="text-align:center">Sin datos</td></tr>'}
          </tbody></table></div></div>
      </div>
    </div>`;
}

function mountSalesPane(c) {
  const wiz = $('#sales-wizard');
  if (!wiz) return;
  if ((c.assets.some((a) => a.asset_type === 'ad_account') || Object.keys(c.rules || {}).length > 0)) {
    api('campaign_names', { id: c.id }).then((names) => {
      const box = $('#camp-names-card [data-names]');
      if (!box) return;
      $('#camp-names').innerHTML = names.map((n) => `<option value="${esc(n.name)}">`).join('');
      box.innerHTML = names.length ? `<div class="space-y-1.5 max-h-[260px] overflow-y-auto">${names.map((n) => `
        <button class="w-full flex items-center gap-2 text-left px-2 py-1.5 rounded-lg hover:bg-[var(--hover)]" data-copy-name="${esc(n.name)}">${statusPill(n.status)}<span class="t1 truncate flex-1">${esc(n.name)}</span><span class="t3">${icon('copy', 13)}</span></button>`).join('')}</div>
        <div class="t3 text-xs mt-2">Tocá para copiar y pegalo tal cual en la planilla.</div>` : 'No hay campañas activas ni con gasto en los últimos 90 días.';
    }).catch(() => {});
  }
  let kind = 'gsheet';
  $('#imp-kind').addEventListener('click', (e) => {
    const b = e.target.closest('[data-k]');
    if (!b) return;
    kind = b.dataset.k;
    $$('#imp-kind [data-k]').forEach((x) => x.classList.toggle('on', x === b));
    $$('[data-k-pane]', wiz).forEach((p) => p.classList.toggle('hidden', p.dataset.kPane !== kind));
    $('#imp-connect').classList.toggle('hidden', kind !== 'gsheet');
    $('#imp-preview').classList.toggle('hidden', kind === 'gsheet');
    $('#imp-preview').textContent = kind === 'gsheet' ? 'Elegir columnas a mano' : 'Leer columnas';
    $('#imp-preview').classList.toggle('btn-primary', kind !== 'gsheet');
    $('#imp-preview').classList.toggle('btn-ghost', kind === 'gsheet');
  });
  const step2 = $('[data-step="2"]', wiz);
  // Un paso: pegar el link y listo (detecta fecha y ventas solas, en todas las pestañas).
  $('#imp-connect').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const urls = [...new Set(($('#imp-url').value.match(/https:\/\/docs\.google\.com\/spreadsheets\/[^\s,;]+/g) || []))];
    if (!urls.length) return toast('Pegá el link de la planilla de Google Sheets.', 'error');
    btn.disabled = true;
    const out = [];
    for (const [i, url] of urls.entries()) {
      btn.textContent = urls.length > 1 ? `Conectando ${i + 1} de ${urls.length}…` : 'Conectando…';
      try {
        const r = await api('sales_connect', {}, { client_id: c.id, url });
        if (r.needs_mapping) {
          if (urls.length === 1) {
            btn.disabled = false; btn.innerHTML = `${icon('link', 14)}Conectar`;
            toast('No pudimos reconocer solas las columnas: elegilas una vez y queda guardado.', 'error');
            $('#imp-preview').classList.remove('hidden');
            return showStep2(r);
          }
          out.push(`<li class="t-warn">Planilla ${i + 1}: no se reconocieron las columnas. Conectala sola para elegirlas.</li>`);
          continue;
        }
        out.push(`<li>${r.updated ? '<span class="pill blue plain">Mismo archivo: se actualizó</span> ' : ''}<b class="t1">${fmt.int(r.orders)} ${esc(lower(c.sales_label))}</b> en ${r.days} días (${fmt.date(r.min)} → ${fmt.date(r.max)}) · ${esc(Object.entries(r.columns).map(([k, v]) => `${k}: “${v}”`).join(' · '))}${r.tabs?.length > 1 ? ` · ${r.tabs.length} pestañas` : ''}</li>`);
      } catch (err) { out.push(`<li class="t-bad">Planilla ${i + 1}: ${esc(err.message)}</li>`); }
    }
    btn.disabled = false; btn.innerHTML = `${icon('link', 14)}Conectar`;
    $('#imp-result').innerHTML = `<div class="split-banner mt-1" style="background:var(--pos-soft);border-color:color-mix(in srgb, var(--pos) 35%, transparent)"><div class="font-semibold t1">${urls.length > 1 ? `${urls.length} planillas procesadas` : 'Planilla conectada'}</div>
      <ul class="t2 text-[13px] mt-1 space-y-1 list-disc pl-5">${out.join('')}</ul><div class="t3 text-xs mt-2">Un archivo con varias pestañas (julio, agosto…) se conecta <b>una sola vez</b>: se leen todas. Se actualizan solas cada 5 minutos.</div></div>`;
    hydrateIcons($('#imp-result'));
    setTimeout(() => render({ silent: true }), 4000);
  });
  $('#imp-preview').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    let p;
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Leyendo…';
    try {
      if (kind === 'gsheet') {
        p = await api('sales_preview', {}, { url: $('#imp-url').value.trim() });
      } else {
        const f = $('#imp-file').files[0];
        if (!f) throw new Error('Elegí un archivo.');
        const fd = new FormData(); fd.append('file', f);
        p = await api('sales_preview', {}, fd);
      }
    } catch (err) { toast(err.message, 'error'); return; } finally { btn.disabled = false; btn.textContent = label; }
    showStep2(p);
  });
  function showStep2(p) {
    const opt = (field) => `<option value="">— ninguna —</option>${p.headers.map((h, i) => `<option value="${i}" ${p.suggest[field] === i ? 'selected' : ''}>${esc(h)}</option>`).join('')}`;
    const fields = [['date', 'Fecha', true], ['orders', `${c.sales_label} (cantidad)`], ['revenue', 'Facturación / monto'], ['campaign', 'Campaña de Meta'], ['leads', `${c.leads_label}`], ['dim', `${c.dim_label} / canal`]];
    step2.innerHTML = `
      <div class="flex items-center gap-2"><div class="h2">Revisá las columnas</div><span class="t3 text-xs">${fmt.int(p.total)} filas leídas${p.tabs && p.tabs.length > 1 ? ` en ${p.tabs.length} pestañas (${esc(p.tabs.join(', '))})` : ''}${p.detected ? ` · ${p.detected.days} días con datos (${fmt.date(p.detected.min)} → ${fmt.date(p.detected.max)})` : ''}</span></div>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">${fields.map(([f, l, req]) => `<label class="field"><span>${esc(l)}${req ? ' *' : ''}</span><select class="select" data-map="${f}">${opt(f)}</select></label>`).join('')}</div>
      <p class="t3 text-xs">Si no elegís la columna de cantidad, <b>cada fila cuenta como 1</b> (sirve si la planilla tiene una fila por venta o por turno).</p>
      <div class="scroll-x rounded-xl" style="border:1px solid var(--line)"><table class="tbl"><thead><tr>${p.headers.map((h) => `<th style="text-align:left">${esc(h)}</th>`).join('')}</tr></thead><tbody>${p.sample.map((r) => `<tr>${p.headers.map((_, i) => `<td style="text-align:left">${esc(r[i] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>
      <div class="flex flex-wrap gap-3 items-end"><label class="field flex-1 min-w-[200px]"><span>Nombre de la fuente</span><input class="input" id="imp-name" value="${esc($('#imp-source').dataset.name || (kind === 'gsheet' ? 'Planilla de ventas (Sheets)' : 'Planilla de ventas'))}"></label>
        <button class="btn btn-accent" id="imp-go">Importar</button><button class="btn btn-ghost" id="imp-cancel">Cancelar</button></div>`;
    $('[data-step="1"]', wiz).classList.add('hidden');
    step2.classList.remove('hidden');
    $('#imp-cancel').onclick = () => render({ silent: true });
    $('#imp-go').onclick = async () => {
      const mapping = Object.fromEntries($$('[data-map]', step2).map((s) => [s.dataset.map, s.value === '' ? null : +s.value]));
      try {
        const r = await post('sales_import', { client_id: c.id, token: p.token, mapping, kind, url: $('#imp-url').value.trim(), name: $('#imp-name').value, source_id: $('#imp-source').value || null });
        toast(`Importado: ${r.days} días (${fmt.date(r.min)} → ${fmt.date(r.max)})${r.skipped ? ` · ${r.skipped} filas sin fecha válida` : ''}`);
        render({ silent: true });
      } catch { /* toast */ }
    };
  }
  $('#sales-entry').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await post('sales_entry', { ...Object.fromEntries(new FormData(e.target)), client_id: c.id }, 'Día guardado'); render({ silent: true }); } catch { /* toast */ }
  });
  $('#view').addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.copyName) copyText(b.dataset.copyName);
    if (b.dataset.salesSync) { b.disabled = true; try { await post('sales_sync', { id: +b.dataset.salesSync }, 'Planilla sincronizada'); render({ silent: true }); } catch { b.disabled = false; } }
    if (b.dataset.salesDel && confirm('¿Eliminar esta fuente y todos sus datos?')) { await post('sales_source_delete', { id: +b.dataset.salesDel }, 'Fuente eliminada'); render({ silent: true }); }
    if (b.dataset.salesReupload) {
      $('#imp-source').value = b.dataset.salesReupload; $('#imp-source').dataset.name = b.dataset.name;
      $('#imp-kind [data-k=upload]').click();
      wiz.scrollIntoView({ behavior: 'smooth', block: 'center' });
      toast('Elegí el archivo actualizado y leé las columnas');
    }
  });
}

// =========================================================================
// Presupuestos (planificado vs. gastado con impuestos)
// =========================================================================
const BUDGET_STATUS = { en_ritmo: ['En ritmo', 'green'], excede: ['Se pasa del presupuesto', 'red'], subejecuta: ['Por debajo de lo planificado', 'amber'], sin_plan: ['Sin presupuesto cargado', 'gray'] };
const taxPct = () => fmt.dec((state.boot.tax_rate || 0) * 100, 0) + '%';

function budgetBar(spent, planned, projected) {
  if (!planned) return '';
  const pct = Math.min(100, (spent / planned) * 100);
  const proj = Math.min(100, (projected / planned) * 100);
  const over = projected > planned * 1.05;
  return `<div class="relative h-2.5 rounded-full sunk overflow-hidden mt-3">
      <div class="absolute inset-y-0 left-0 rounded-full" style="width:${proj}%;background:${over ? 'var(--bad-soft)' : 'var(--accent-soft)'}"></div>
      <div class="absolute inset-y-0 left-0 rounded-full" style="width:${pct}%;background:${spent > planned ? 'var(--bad)' : 'var(--accent)'}"></div>
    </div>`;
}

/** Objetivo del mes en la tabla de presupuestos (el avance se ve en Ventas). */
function budgetGoalCell(c, a) {
  const g = c.goal;
  const parts = g ? [g.target_orders ? `${fmt.int(g.target_orders)} ventas` : '', g.target_revenue ? fmt.moneyShort(g.target_revenue, a.currency) : ''].filter(Boolean) : [];
  const edit = canEdit() ? `<button class="btn btn-sm ${parts.length ? 'btn-ghost' : ''} !h-7" data-bgoal="${esc(a.id)}|${esc(c.id)}">${icon(parts.length ? 'sliders' : 'plus', 13)}${parts.length ? '' : 'Objetivo'}</button>` : '';
  return `<div class="flex items-center justify-end gap-1.5">${parts.length ? `<span class="num font-medium">${parts.join(' · ')}</span>` : (edit ? '' : '<span class="t3">—</span>')}${edit}</div>`;
}

const brandPill = (b) => (b ? `<span class="pill plain" style="background:color-mix(in srgb, ${esc(b.color)} 14%, transparent);color:${esc(b.color)}">${esc(b.name)}</span>` : '');

async function viewBudgets() {
  crumbs([{ label: 'Presupuestos' }]);
  const d = await api('budgets');
  const monthName = new Date(d.month + '-15').toLocaleDateString(LOCALE, { month: 'long', year: 'numeric' });
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  if (!d.accounts.length) return { html: empty('Sin cuentas publicitarias', 'Conectá Meta desde Configuración.', '', 'target') };

  const totals = d.totals.map((t) => {
    const m = (v) => fmt.moneyShort(v, t.currency);
    return `<div class="kpi-row grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 mb-6" style="display:grid">
      ${kpi(`Presupuesto planificado · ${t.currency}`, t.planned ? m(t.planned) : '—', { spark: '' })}
      ${kpi(`Gastado en ${monthName.split(' ')[0]} (+${tax})`, m(t.spent_mtd_tax), { d: `<span class="t3 text-xs">${m(t.spent_mtd)} en Meta</span>`, vs: '' })}
      ${kpi('Disponible', t.planned ? m(t.planned - t.spent_mtd_tax) : '—', { d: t.planned ? `<span class="t3 text-xs">${fmt.dec((t.spent_mtd_tax / t.planned) * 100, 0)}% consumido</span>` : '', vs: '' })}
      ${kpi(`Gasto de hoy (+${tax})`, m(t.spent_today_tax), { d: `<span class="t3 text-xs">${m(t.active_daily_tax)}/día activo</span>`, vs: '' })}
      ${kpi('Cierre estimado del mes', m(t.projected_budgets), { d: t.planned ? `<span class="${t.projected_budgets > t.planned * 1.05 ? 't-bad' : 't3'} text-xs">${t.projected_budgets > t.planned ? 'Supera' : 'Dentro de'} lo planificado</span>` : '', vs: '' })}
    </div>`;
  }).join('');

  const brandCard = (b) => {
    const m = (v) => fmt.money(v, b.currency);
    const [stLabel, stCls] = BUDGET_STATUS[b.status] || BUDGET_STATUS.sin_plan;
    return `
      <div class="card p-5" data-acc="${esc(b.name)}">
        <div class="flex items-start gap-3">
          <div class="mono-avatar w-9 h-9 text-xs" style="background:${esc(b.color)};color:#fff">${esc(initials(b.name))}</div>
          <div class="min-w-0 flex-1"><a class="h2 hover:underline" href="#/preview/${b.client_id}">${esc(b.name)}</a><div class="t3 text-xs mt-0.5 truncate">${b.active} activas de ${b.campaigns} · ${esc(b.accounts.join(', '))}</div></div>
          <span class="pill ${stCls}">${stLabel}</span>
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 mt-4">
          <div><div class="t3 text-xs">Presupuesto del mes</div>
            ${canEdit() ? `<div class="flex gap-1.5 mt-1"><input class="input select-sm num !w-full" data-bplan="${b.client_id}" value="${b.planned ?? ''}" placeholder="Cargar" inputmode="decimal"><button class="btn btn-sm" data-bplan-save="${b.client_id}">${icon('check', 14)}</button></div>`
              : `<div class="num text-lg font-semibold t1 mt-0.5">${b.planned ? m(b.planned) : '—'}</div>`}</div>
          <div><div class="t3 text-xs">Gastado (+${tax})</div><div class="num text-lg font-semibold t1 mt-0.5">${m(b.spent_mtd_tax)}</div></div>
          <div><div class="t3 text-xs">Hoy (+${tax})</div><div class="num font-semibold t1">${m(b.spent_today_tax)}</div><div class="t3 text-[11px]">${m(b.active_daily_tax)}/día activo</div></div>
          <div><div class="t3 text-xs">Cierre estimado</div><div class="num font-semibold t1">${m(b.projected)}</div>${b.suggested_daily !== null ? `<div class="t-accent text-[11px]">Sugerido en Meta: ${m(b.suggested_daily)}/día</div>` : ''}</div>
        </div>
        ${budgetBar(b.spent_mtd_tax, b.planned, b.projected)}
      </div>`;
  };

  const accountCard = (a) => {
    const m = (v) => fmt.money(v, a.currency);
    const [stLabel, stCls] = BUDGET_STATUS[a.status] || BUDGET_STATUS.sin_plan;
    const rows = a.campaigns.map((c) => `
      <tr>
        <td style="text-align:left"><div class="font-medium max-w-[340px] truncate" title="${esc(c.name)}">${esc(c.name)}</div><div class="mt-1 flex gap-2 items-center flex-wrap">${statusPill(c.effective_status)}${(c.brands || []).map((id) => brandPill(d.brand_names[id])).join('')}<span class="sub">${esc(c.budget_type || '')}</span></div></td>
        <td>${c.daily !== null ? m(c.daily) : (c.lifetime ? `${m(c.lifetime)} <span class="sub">total</span>` : '—')}</td>
        <td class="font-medium">${c.daily_tax !== null ? m(c.daily_tax) : '—'}</td>
        <td>${m(c.spent_today_tax)}${c.today_pct !== null ? `<div class="bar mt-1.5 w-20 ml-auto"><i style="width:${Math.min(100, c.today_pct)}%"></i></div>` : ''}</td>
        <td>${m(c.spent_mtd)}</td>
        <td class="font-medium">${m(c.spent_mtd_tax)}</td>
        <td>${c.remaining ? m(c.remaining) : '—'}${c.stop_time ? `<div class="sub">hasta ${fmt.date(c.stop_time)}</div>` : ''}</td>
        <td>${budgetGoalCell(c, a)}</td>
      </tr>`).join('');
    return `
      <div class="card mb-5" data-acc="${esc(a.id)}">
        <div class="p-5">
          <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1"><a class="h2 hover:underline" href="#/ads/${esc(a.id)}">${esc(a.name)}</a><div class="t3 text-xs mt-0.5">${esc(a.business || 'Sin portfolio')} · ${esc(a.currency)}</div></div>
            <span class="pill ${stCls}">${stLabel}</span>
          </div>
          ${a.error ? `<div class="t-warn text-xs mt-2">${esc(a.error)}</div>` : ''}
          <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mt-5">
            <div><div class="t3 text-xs">Presupuesto del mes</div>
              ${canEdit() ? `<div class="flex gap-1.5 mt-1"><input class="input select-sm num !w-full" data-plan="${esc(a.id)}" value="${a.planned ?? ''}" placeholder="Cargar" inputmode="decimal"><button class="btn btn-sm" data-plan-save="${esc(a.id)}">${icon('check', 14)}</button></div>`
                : `<div class="num text-lg font-semibold t1 mt-0.5">${a.planned ? m(a.planned) : '—'}</div>`}
              <div class="t3 text-[11px] mt-1">con impuestos</div></div>
            <div><div class="t3 text-xs">Gastado (+${tax})</div><div class="num text-lg font-semibold t1 mt-0.5">${m(a.spent_mtd_tax)}</div><div class="t3 text-[11px]">${m(a.spent_mtd)} en Meta</div></div>
            <div><div class="t3 text-xs">Disponible</div><div class="num text-lg font-semibold ${a.remaining !== null && a.remaining < 0 ? 't-bad' : 't1'} mt-0.5">${a.remaining !== null ? m(a.remaining) : '—'}</div><div class="t3 text-[11px]">${a.pct !== null ? fmt.dec(a.pct, 0) + '% consumido' : ''}</div></div>
            <div><div class="t3 text-xs">Hoy (+${tax})</div><div class="num text-lg font-semibold t1 mt-0.5">${m(a.spent_today_tax)}</div><div class="t3 text-[11px]">activo: ${m(a.active_daily_tax)}/día</div></div>
            <div><div class="t3 text-xs">Cierre estimado</div><div class="num text-lg font-semibold t1 mt-0.5">${m(a.projected_budgets)}</div><div class="t3 text-[11px]">al ritmo: ${m(a.projected_pace)}</div></div>
            <div><div class="t3 text-xs">Diario sugerido en Meta</div><div class="num text-lg font-semibold t-accent mt-0.5">${a.suggested_daily !== null ? m(a.suggested_daily) : '—'}</div><div class="t3 text-[11px]">${a.suggested_daily !== null ? `para cerrar en lo planificado (${d.days_left} días)` : 'cargá el presupuesto'}</div></div>
          </div>
          ${budgetBar(a.spent_mtd_tax, a.planned, a.projected_budgets)}
        </div>
        ${a.campaigns.length ? `<details class="hair-t" ${a.campaigns.length <= 8 ? 'open' : ''}><summary class="px-5 py-3 cursor-pointer t2 text-[13px] select-none">Campañas del mes (${a.campaigns.length})</summary>
          <div class="scroll-x"><table class="tbl"><thead><tr><th style="text-align:left">Campaña</th><th>Diario Meta</th><th>Diario +${tax}</th><th>Hoy +${tax}</th><th>Mes Meta</th><th>Mes +${tax}</th><th>Restante (total)</th><th>Objetivo del mes</th></tr></thead><tbody>${rows}</tbody></table></div></details>` : ''}
      </div>`;
  };

  return {
    html: `${pageHead(`Presupuestos · ${esc(monthName)}`, `Día ${d.day} de ${d.days_in_month} · todos los montos “+${tax}” incluyen impuestos sobre el gasto en Meta`, isAdmin() ? `<a class="btn" href="#/settings">${icon('sliders', 14)}Impuestos: ${tax}</a>` : `<span class="pill plain">Impuestos ${tax}</span>`)}
      ${totals}
      <div id="plan-box"><div class="sk h-64 mb-8"></div></div>
      ${d.brands.length ? `<div class="flex items-center gap-2 mb-3"><div class="h2">Por marca</div><span class="t3 text-xs">${d.has_brands ? 'suma sus campañas aunque compartan cuenta publicitaria' : 'todavía no elegiste tus marcas: se muestran todos los clientes'}</span></div>
        <div class="grid md:grid-cols-2 2xl:grid-cols-3 gap-4 mb-8" id="brand-list">${d.brands.map(brandCard).join('')}</div>` : ''}
      <div class="flex items-center gap-3 mb-3"><div class="h2">Por cuenta publicitaria</div><div class="flex-1"></div><input class="input select-sm w-56" placeholder="Buscar cuenta" id="bud-search"></div>
      <div id="bud-list">${d.accounts.map(accountCard).join('')}</div>`,
    mount() {
      planSection($('#plan-box'));
      $('#bud-search').addEventListener('input', (e) => { const q = e.target.value.toLowerCase(); $$('#bud-list [data-acc]').forEach((el) => { el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none'; }); });
      $('#view').addEventListener('click', async (e) => {
        const g = e.target.closest('[data-bgoal]');
        if (g) {
          const [accId, campId] = g.dataset.bgoal.split('|');
          const acc = d.accounts.find((x) => x.id === accId);
          return goalModal({ ...acc.campaigns.find((x) => x.id === campId), account: acc.id }, acc.currency, () => render({ silent: true }));
        }
        const bp = e.target.closest('[data-bplan-save]');
        if (bp) {
          const cid = bp.dataset.bplanSave;
          await post('budget_save', { client_id: +cid, amount: $(`[data-bplan="${cid}"]`).value }, 'Presupuesto de la marca guardado');
          return render({ silent: true });
        }
        const b = e.target.closest('[data-plan-save]');
        if (!b) return;
        const acc = b.dataset.planSave;
        await post('budget_save', { account: acc, amount: $(`[data-plan="${acc}"]`).value }, 'Presupuesto guardado');
        render({ silent: true });
      });
      $('#view').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.dataset.plan) $(`[data-plan-save="${e.target.dataset.plan}"]`).click(); });
    },
  };
}

// =========================================================================
// Plan de presupuesto por objetivos (dentro de Presupuestos)
// =========================================================================
const PLAN_STATUS = {
  cumplido: ['Objetivo cumplido', 'green'], en_camino: ['En camino', 'green'], ajustar: ['Hay que ajustar', 'amber'], en_riesgo: ['En riesgo', 'red'],
  sin_objetivo: ['Sin objetivo', 'gray'], sin_datos: ['Sin ventas para calcular', 'gray'], plan: ['Planificado', 'blue'],
};
const GRAN = { month: ['Mes', 'por mes'], week: ['Semana', 'por semana'], day: ['Día', 'por día'] };

async function planSection(box) {
  const cfg = { month: 'next', basis: 'avg3', gran: 'month', ...store.get('planCfg', {}) };
  const load = async () => {
    store.set('planCfg', cfg);
    box.querySelector('[data-plan-body]')?.classList.add('opacity-50');
    let d;
    try { d = await api('budget_plan', { month: cfg.month, basis: cfg.basis, parent: cfg.parent || '' }); } catch (e) { box.innerHTML = errorBox(e.message, false); return; }
    box.innerHTML = planHtml(d, cfg);
    hydrateIcons(box);
  };
  box.addEventListener('click', async (e) => {
    const s = e.target.closest('[data-plan-cfg]');
    if (s) { cfg[s.dataset.planCfg] = s.dataset.v; return load(); }
    if (e.target.closest('[data-brands-edit]')) return brandsModal(() => render({ silent: true }));
    const u = e.target.closest('[data-plan-apply]');
    if (u) {
      const [cid, month, amount] = u.dataset.planApply.split('|');
      await post('budget_save', { client_id: +cid, month, amount: Math.round(+amount) }, 'Presupuesto planificado actualizado');
      return cfg.month === 'current' ? render({ silent: true }) : load();
    }
  });
  box.addEventListener('change', async (e) => {
    if (e.target.id === 'plan-basis') { cfg.basis = e.target.value; return load(); }
    if (e.target.id === 'plan-parent') { cfg.parent = e.target.value; return load(); }
    const g = e.target.closest('[data-plan-goal]');
    if (g) {
      const [cid, month] = g.dataset.planGoal.split('|');
      await post('brand_goal_save', { client_id: +cid, month, target_orders: g.value }, 'Objetivo guardado');
      load();
    }
  });
  box.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.dataset.planGoal) e.target.blur(); });
  await load();
}

/** Marcas y de qué cuentas / campañas es cada una. */
async function brandsModal(after) {
  const el = modal(`${modalHead('Marcas y cuentas publicitarias', 'Indicá de qué marca es cada cuenta. Si una cuenta tiene campañas de varias marcas, elegí la marca campaña por campaña.')}<div class="p-5" id="bm"><div class="sk h-64"></div></div>`);
  let d;
  const load = async () => {
    try { d = await api('brand_map'); } catch (e) { $('#bm', el).innerHTML = errorBox(e.message, false); return; }
    d.accounts.forEach((a) => { if (a.mode === 'none' && a.suggest) { a.mode = 'single'; a.brand_id = a.suggest; a.suggested = true; } });
    paint();
    d.accounts.forEach((a, i) => { if (a.mode === 'multi') loadCamps(i); });
  };
  const loadCamps = async (i) => {
    const a = d.accounts[i];
    if (a.campaigns) return;
    a.loading = true;
    try { const r = await api('brand_map_campaigns', { account: a.id }); a.campaigns = r.campaigns; a.campaign_brand = { ...r.campaign_brand, ...(a.campaign_brand || {}) }; } catch (e) { a.error = e.message; a.campaigns = []; }
    a.loading = false;
    paint();
  };
  const brandOpts = (sel, empty = '— Sin marca —') => `<option value="">${empty}</option>${d.brands.map((b) => `<option value="${b.id}" ${+sel === b.id ? 'selected' : ''}>${esc(b.name)}</option>`).join('')}`;
  const accRow = (a, i) => {
    const val = a.mode === 'multi' ? 'multi' : a.mode === 'single' ? String(a.brand_id) : '';
    return `<div class="card mb-3 overflow-hidden" data-acc-i="${i}">
      <div class="px-4 py-3 flex flex-wrap items-center gap-3">
        <div class="min-w-0 flex-1"><div class="font-medium t1 truncate">${esc(a.name)} <span class="t3 font-normal mono text-xs">${esc(a.id)}</span></div>
          <div class="t3 text-xs mt-0.5">${a.business ? esc(a.business) : 'Sin portfolio'} · histórico ${fmt.moneyShort(a.lifetime, a.currency)}${a.clients.length ? ` · cliente: ${esc(a.clients.join(', '))}` : ''}${a.suggested ? ' · <span class="t-accent">sugerida por el nombre</span>' : ''}</div></div>
        <select class="select select-sm w-60" data-acc-mode="${i}">
          <option value="" ${val === '' ? 'selected' : ''}>No es de ninguna marca</option>
          ${d.brands.map((b) => `<option value="${b.id}" ${val === String(b.id) ? 'selected' : ''}>Toda la cuenta: ${esc(b.name)}</option>`).join('')}
          <option value="multi" ${val === 'multi' ? 'selected' : ''}>Varias marcas (elegir por campaña)</option>
        </select>
      </div>
      ${a.mode === 'multi' ? `<div class="hair-t">${!a.campaigns ? '<div class="p-4"><div class="sk h-16"></div></div>' : a.campaigns.length ? a.campaigns.map((c) => `
        <div class="px-4 py-2 flex flex-wrap items-center gap-3 hair-b">
          <div class="min-w-0 flex-1"><div class="truncate t1 text-[13px]" title="${esc(c.name)}">${esc(c.name)}</div><div class="sub">${statusPill(c.status)} <span class="ml-1">${fmt.moneyShort(c.spend, a.currency)}</span></div></div>
          <select class="select select-sm w-48" data-camp="${i}|${esc(c.id)}">${brandOpts((a.campaign_brand || {})[c.id])}</select>
        </div>`).join('') : '<div class="px-4 py-3 t3 text-xs">Sin campañas con actividad en los últimos 90 días.</div>'}
        <div class="px-4 py-2 t3 text-xs">Las campañas nuevas de esta cuenta se asignan solas si su nombre tiene el de la marca o sus palabras; si no, aparecen en el plan como “sin marca”.</div></div>` : ''}
      ${a.error ? `<div class="px-4 pb-3 t-warn text-xs">${esc(a.error)}</div>` : ''}
    </div>`;
  };
  const paint = () => {
    $('#bm', el).innerHTML = `
      <div class="mb-5"><div class="flex flex-wrap items-center gap-2 mb-2"><div class="eyebrow">1 · Tus marcas y de qué cliente son</div><div class="flex-1"></div>
          ${d.parents.length && d.brands.length ? `<span class="t3 text-xs">Todas son de</span><select class="select select-sm w-48" id="bm-parent-all"><option value="">Elegí el cliente…</option>${d.parents.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>` : ''}</div>
        ${d.brands.length ? `<div class="card divide mb-3">${d.brands.map((b) => `
          <div class="px-4 py-2 flex flex-wrap items-center gap-3">
            <span class="mono-avatar w-7 h-7 text-[10px]" style="background:${esc(b.color)};color:#fff">${esc(initials(b.name))}</span>
            <div class="font-medium t1 min-w-[110px]">${esc(b.name)}</div>
            <input class="input select-sm flex-1 min-w-[160px]" data-alias="${b.id}" value="${esc(b.aliases || '')}" placeholder="Otras palabras de sus campañas (ej. activa pos)" title="Las campañas nuevas que contengan el nombre de la marca o estas palabras se asignan solas">
            <span class="t3 text-xs">Cliente</span>
            <select class="select select-sm w-48" data-parent="${b.id}"><option value="">— Sin cliente —</option>${d.parents.map((p) => `<option value="${p.id}" ${b.parent_id === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select>
            <button class="btn btn-ghost btn-icon btn-sm" data-unbrand="${b.id}" title="No es marca">${icon('x', 13)}</button>
          </div>`).join('')}</div>` : ''}
        <form class="flex flex-wrap gap-2" id="bm-add"><input class="input select-sm w-52" name="name" placeholder="Agregar marca (ej. Clover)" list="bm-clients" required><datalist id="bm-clients">${d.clients.map((n) => `<option value="${esc(n)}">`).join('')}</datalist><button class="btn btn-sm">${icon('plus', 13)}Agregar</button></form>
        <p class="t3 text-xs mt-2"><b>Campañas nuevas automáticas:</b> en las cuentas compartidas, una campaña nueva que contenga el nombre de la marca o sus palabras (ej. Clover: “activa pos”) se suma sola a esa marca. Las que asignás a mano abajo siempre mandan. El <b>cliente</b> es la empresa (ej. PayLab Gestión). Las <b>marcas</b> son lo que vende y con lo que se nombra cada planilla de ventas (ej. Clover, aunque la cuenta se llame ActivaPost).${d.parents.length ? '' : ' Para asignarles un cliente, creálo primero en Clientes.'}</p></div>
      <div class="flex flex-wrap items-center gap-2 mb-2"><div class="eyebrow">2 · De qué marca es cada cuenta publicitaria</div><div class="flex-1"></div><input class="input select-sm w-56" id="bm-q" placeholder="Buscar cuenta" value="${esc(q)}"></div>
      ${d.brands.length ? d.accounts.map(accRow).join('') : '<div class="t3 text-sm mb-3">Agregá primero tus marcas.</div>'}
      <div class="flex gap-2 mt-4 sticky bottom-0 py-3" style="background:var(--panel)"><button class="btn btn-primary" id="bm-save" ${d.brands.length ? '' : 'disabled'}>Guardar</button><button class="btn btn-ghost" data-close>Cancelar</button></div>`;
    hydrateIcons(el);
    filter();
  };
  let q = '';
  const filter = () => $$('[data-acc-i]', el).forEach((r) => { const a = d.accounts[+r.dataset.accI]; r.style.display = !q || (a.name + ' ' + a.id + ' ' + (a.business || '')).toLowerCase().includes(q) ? '' : 'none'; });
  $('#bm', el).addEventListener('input', (e) => { if (e.target.id === 'bm-q') { q = e.target.value.toLowerCase(); filter(); } });
  $('#bm', el).addEventListener('change', (e) => {
    const m = e.target.closest('[data-acc-mode]');
    if (m) {
      const a = d.accounts[+m.dataset.accMode];
      a.mode = m.value === 'multi' ? 'multi' : m.value ? 'single' : 'none';
      a.brand_id = a.mode === 'single' ? +m.value : null;
      a.suggested = false;
      paint();
      if (a.mode === 'multi') loadCamps(+m.dataset.accMode);
      return;
    }
    const c = e.target.closest('[data-camp]');
    if (c) { const [i, id] = c.dataset.camp.split('|'); d.accounts[+i].campaign_brand[id] = c.value ? +c.value : null; return; }
    const al = e.target.closest('[data-alias]');
    if (al) { d.brands.find((b) => b.id === +al.dataset.alias).aliases = al.value; return; }
    const pa = e.target.closest('[data-parent]');
    if (pa) { d.brands.find((b) => b.id === +pa.dataset.parent).parent_id = pa.value ? +pa.value : null; return; }
    if (e.target.id === 'bm-parent-all' && e.target.value) { d.brands.forEach((b) => { b.parent_id = +e.target.value; }); paint(); }
  });
  $('#bm', el).addEventListener('submit', async (e) => {
    if (e.target.id !== 'bm-add') return;
    e.preventDefault();
    const name = new FormData(e.target).get('name').trim();
    const ex = (await api('clients')).find((c) => c.name.toLowerCase() === name.toLowerCase());
    try {
      if (ex) await post('client_kind_save', { id: ex.id, kind: 'marca' }, `${ex.name} ahora es marca`);
      else await post('client_save', { name, kind: 'marca', color: ['#0e7c66', '#d9822b', '#3a6fd8', '#b9468b', '#6b5bd6', '#2c9db0'][d.brands.length % 6] }, 'Marca creada');
      const keep = d.accounts;
      const keepBrands = d.brands;
      d = await api('brand_map');
      // conservar lo que ya se eligió en pantalla
      d.accounts = d.accounts.map((a) => ({ ...a, ...(keep.find((k) => k.id === a.id) || {}) }));
      d.brands.forEach((b) => { const k = keepBrands.find((x) => x.id === b.id); if (k) b.parent_id = k.parent_id; else if (keepBrands.length && keepBrands.every((x) => x.parent_id === keepBrands[0].parent_id)) b.parent_id = keepBrands[0].parent_id; });
      paint();
    } catch { /* toast */ }
  });
  $('#bm', el).addEventListener('click', async (e) => {
    const ub = e.target.closest('[data-unbrand]');
    if (ub) { await post('client_kind_save', { id: +ub.dataset.unbrand, kind: 'cliente' }, 'Ya no es marca'); return load(); }
    if (e.target.id !== 'bm-save') return;
    e.target.disabled = true;
    const accounts = d.accounts.map((a) => ({ account: a.id, mode: a.mode, brand_id: a.brand_id,
      campaigns: Object.fromEntries(Object.entries(a.campaign_brand || {}).filter(([, b]) => b)) }))
      // una cuenta compartida cuyas campañas no se llegaron a cargar no se toca
      .filter((x, i) => !(x.mode === 'multi' && !d.accounts[i].campaigns));
    const brands = d.brands.map((b) => ({ id: b.id, parent_id: b.parent_id, aliases: b.aliases || '' }));
    try { await post('brand_map_save', { accounts, brands }, 'Marcas y cuentas guardadas'); closeModal(); after(); } catch { e.target.disabled = false; }
  });
  await load();
}

function planHtml(d, cfg) {
  if (!d.has_brands) d.brands = d.brands.filter((b) => b.has_ads || b.goal_orders !== null); // sin marcas definidas: ocultar clientes sin campañas
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  const cur = d.which === 'current';
  const div = cfg.gran === 'day' ? d.month.days : cfg.gran === 'week' ? d.month.days / 7 : 1;
  const per = (v) => (v === null || v === undefined ? null : v / div);
  const monthTitle = `${cap(d.month.name)} ${d.month.year}`;
  const monthOf = (w) => { const t = new Date(); t.setDate(1); if (w === 'next') t.setMonth(t.getMonth() + 1); return t.toLocaleDateString(LOCALE, { month: 'long' }); };
  const label = (b) => esc(lower(b.sales_label || 'Ventas'));

  const goalInput = (b) => canEdit()
    ? `<input class="input select-sm num !w-24 text-right" data-plan-goal="${b.id}|${d.month.key}" value="${b.target_orders !== null && b.goal_from === 'mes' ? +b.target_orders : ''}" placeholder="${b.goal_from === 'cliente' ? fmt.int(b.target_orders) : 'Cargar'}" inputmode="decimal" title="${b.goal_from === 'cliente' ? 'Toma el objetivo fijo de la ficha del cliente. Escribí uno para este mes.' : 'Objetivo de ' + esc(d.month.name)}">`
    : `<span class="num font-semibold">${b.goal_orders !== null ? fmt.int(b.goal_orders) : '—'}</span>`;
  const costCell = (b) => {
    const m = (v) => fmt.money(v, b.currency);
    if (b.cost === null) return '<span class="t3">—</span>';
    const note = b.cost_from !== d.basis ? `<div class="sub">usa ${esc(lower(d.bases[b.cost_from]))}</div>` : '';
    const cheaper = b.cheaper_pct !== null && Math.abs(b.cheaper_pct) >= 5 && d.basis !== 'current'
      ? `<div class="sub ${b.cheaper_pct > 0 ? 't-pos' : 't-bad'}">este mes ${b.cheaper_pct > 0 ? '−' : '+'}${fmt.dec(Math.abs(b.cheaper_pct), 0)}%</div>` : '';
    return `<span class="num">${m(b.cost)}</span>${note}${cheaper}`;
  };
  const brandCell = (b) => `<div class="flex items-center gap-2.5"><div class="mono-avatar w-7 h-7 text-[10px] shrink-0" style="background:${esc(b.color)};color:#fff">${b.logo_url ? `<img src="${esc(b.logo_url)}" class="w-full h-full object-contain rounded-[8px] bg-white" alt="">` : esc(initials(b.name))}</div>
    <div class="min-w-0"><a class="font-medium hover:underline" href="#/preview/${b.id}">${esc(b.name)}</a>${!b.has_ads ? '<div class="sub t-warn">sin cuenta asignada</div>' : b.goal_from === 'cliente' ? '<div class="sub">objetivo fijo de la ficha</div>' : ''}</div></div>`;
  const st = (b) => { const [l, c] = PLAN_STATUS[b.status] || PLAN_STATUS.plan; return `<span class="pill ${c}">${l}</span>`; };
  const apply = (b) => (canEdit() && b.required ? `<button class="btn btn-sm btn-ghost !h-7" data-plan-apply="${b.id}|${d.month.key}|${b.required}" title="Guardar ${fmt.money(b.required, b.currency)} como presupuesto planificado de ${esc(d.month.name)}">${icon('check', 13)}${b.planned && Math.abs(b.planned - b.required) < 1 ? 'Aplicado' : 'Usar'}</button>` : '');

  const rows = d.brands.map((b) => {
    const m = (v) => fmt.money(v, b.currency);
    const req = `<div class="num font-semibold t1">${m(per(b.required))}</div><div class="sub">en Meta ${m(per(b.required) !== null ? per(b.required) / (1 + d.tax_rate) : null)}</div>`;
    if (cur) {
      const t = b.to_date || {};
      const pct = b.progress ?? 0;
      const bar = b.goal_orders ? `<div class="relative h-1.5 rounded-full sunk overflow-hidden mt-1.5 w-28 ml-auto"><div class="absolute inset-y-0 left-0 rounded-full" style="width:${Math.min(100, pct)}%;background:var(--${b.status === 'en_riesgo' ? 'bad' : b.status === 'ajustar' ? 'warn' : 'accent'})"></div><div class="absolute inset-y-0 w-px" style="left:${Math.min(100, b.expected_progress)}%;background:var(--t2)"></div></div>` : '';
      const dailyGap = b.daily_needed !== null ? b.daily_needed - b.active_daily_tax : null;
      return `<tr class="${b.efficient ? 'row-win' : ''}">
        <td style="text-align:left">${brandCell(b)}<div class="flex items-center gap-1 mt-1.5 ml-[38px]">${st(b)}${apply(b)}</div></td>
        <td>${goalInput(b)}</td>
        <td><div class="num font-semibold">${fmt.int(t.orders)}${b.goal_orders ? ` <span class="t3 font-normal">· ${fmt.dec(pct, 0)}%</span>` : ''}</div>${bar}</td>
        <td><div class="num font-semibold ${b.goal_orders && b.projected_orders !== null ? (b.projected_orders >= b.goal_orders ? 't-pos' : 't-bad') : ''}">${b.projected_orders !== null ? fmt.int(b.projected_orders) : '—'}</div><div class="sub">al ritmo actual</div></td>
        <td>${costCell(b)}</td>
        <td>${req}</td>
        <td><div class="num font-semibold">${b.remaining_orders !== null ? fmt.int(b.remaining_orders) : '—'}</div><div class="sub">${b.needed_rest !== null ? m(b.needed_rest) + ' +' + tax : ''}</div></td>
        <td><div class="num font-semibold t-accent">${b.daily_needed !== null ? m(b.daily_needed) : '—'}</div><div class="sub">${b.daily_needed !== null ? 'en Meta ' + m(b.daily_needed / (1 + d.tax_rate)) : ''}</div>
          <div class="sub">activo ${m(b.active_daily_tax)}${dailyGap !== null && Math.abs(dailyGap) > 1 ? ` · <span class="${dailyGap > 0 ? 't-warn' : 't-pos'}">${dailyGap > 0 ? 'subir' : 'sobra'} ${m(Math.abs(dailyGap))}</span>` : ''}</div></td>
      </tr>`;
    }
    return `<tr class="${b.efficient ? 'row-win' : ''}">
      <td style="text-align:left">${brandCell(b)}</td>
      <td>${goalInput(b)}</td>
      <td>${costCell(b)}</td>
      <td>${req}</td>
      <td><div class="num">${m(per(b.pace_month))}</div><div class="sub">${b.pace_orders !== null ? '≈ ' + fmt.int(b.pace_orders) + ' ' + label(b) : ''}</div></td>
      <td>${b.delta_daily !== null ? `<div class="num font-semibold ${b.delta_daily > 0 ? 't-warn' : 't-pos'}">${b.delta_daily > 0 ? '+' : '−'}${m(Math.abs(b.delta_daily))}<span class="t3 font-normal">/día</span></div><div class="sub">${b.delta_daily > 0 ? 'subir' : 'se puede bajar'} · en Meta ${m(Math.abs(b.delta_daily) / (1 + d.tax_rate))}</div>` : '<span class="t3">—</span>'}</td>
      <td>${b.planned ? `<div class="num">${m(b.planned)}</div>` : '<span class="t3">—</span>'}</td>
      <td><div class="flex items-center justify-end gap-1.5">${st(b)}${apply(b)}</div></td>
    </tr>`;
  }).join('');

  // Totales (sólo si todas las marcas usan la misma moneda).
  const curs = [...new Set(d.brands.map((b) => b.currency))];
  const sum = (k) => d.brands.reduce((s, b) => s + (b[k] || 0), 0);
  const totals = curs.length === 1 && d.brands.some((b) => b.required) ? (() => {
    const m = (v) => fmt.money(v, curs[0]);
    const req = sum('required');
    return `<div class="kpi-row grid-cols-2 lg:grid-cols-4" style="display:grid">
      ${kpi(`Inversión necesaria ${GRAN[cfg.gran][1]} (+${tax})`, m(req / div), { spark: '', d: `<span class="t3 text-xs">${m(req)} en ${esc(d.month.name)}</span>`, vs: '' })}
      ${kpi(`En Meta ${GRAN[cfg.gran][1]} (sin imp.)`, m(req / div / (1 + d.tax_rate)), { spark: '', d: `<span class="t3 text-xs">${m(req / d.month.days / (1 + d.tax_rate))}/día a cargar</span>`, vs: '' })}
      ${kpi(`Objetivo total`, fmt.int(sum('goal_orders')), { spark: '', d: `<span class="t3 text-xs">${d.brands.filter((b) => b.goal_orders).length} marcas con objetivo</span>`, vs: '' })}
      ${kpi(`Ritmo actual ${GRAN[cfg.gran][1]}`, m(sum('active_daily_tax') * d.month.days / div), { spark: '', d: `<span class="t3 text-xs">${m(sum('active_daily_tax'))}/día activo +${tax}</span>`, vs: '' })}
    </div>`;
  })() : '';

  const wins = d.brands.filter((b) => b.efficient);
  const winHtml = wins.length ? `<div class="px-5 pb-4 grid sm:grid-cols-2 xl:grid-cols-3 gap-3">${wins.map((b) => {
    const m = (v) => fmt.money(v, b.currency);
    const txt = cur
      ? `Va a cerrar en <b>${fmt.int(b.projected_orders)}</b> ${label(b)} (objetivo ${fmt.int(b.goal_orders)}) invirtiendo ${b.savings ? `<b>${m(b.savings)}</b> menos` : 'menos'} de lo estimado.`
      : `Este mes su costo por venta está <b>${fmt.dec(b.cheaper_pct, 0)}% más bajo</b> que la base: con el mismo presupuesto vende más. Buena candidata para escalar.`;
    return `<div class="win-card"><div class="flex items-center gap-2"><span class="win-ic">${icon('up', 14)}</span><b class="t1">${esc(b.name)}</b><span class="pill green plain">${cur ? 'Supera con menos presupuesto' : 'Eficiente'}</span></div><div class="t2 text-[13px] mt-1.5">${txt}</div></div>`;
  }).join('')}</div>` : '';

  const head = cur
    ? `<th style="text-align:left">Marca</th><th>Objetivo</th><th>Vendidas</th><th>Proyección</th><th>Costo por venta</th><th>Inversión ${GRAN[cfg.gran][1]}</th><th>Faltan</th><th>Diario necesario</th>`
    : `<th style="text-align:left">Marca</th><th>Objetivo</th><th>Costo por venta</th><th>Inversión ${GRAN[cfg.gran][1]} +${tax}</th><th>Al ritmo actual</th><th>Ajuste diario</th><th>Planificado</th><th></th>`;

  const loose = d.unassigned || [];
  const warn = (title, text, btn) => `<div class="mx-5 mb-5 split-banner"><div class="flex flex-wrap items-start gap-3">
      <span class="t-warn mt-0.5">${icon('alert', 16)}</span><div class="flex-1 min-w-[220px]"><div class="font-semibold t1">${title}</div><div class="t2 text-[13px] mt-0.5">${text}</div></div>
      ${d.can_split ? `<button class="btn btn-primary" data-brands-edit>${icon('users', 14)}${btn}</button>` : '<span class="t3 text-xs">Pedile al administrador que lo configure.</span>'}</div></div>`;
  const banner = !d.has_brands
    ? warn('Definí tus marcas y de qué cuentas son', 'El plan se arma por marca (ej. Go Celular, GetNet, Osacra, Clover). Indicá qué cuenta publicitaria es de cada marca y, en las cuentas compartidas, qué campaña es de cuál. Mientras tanto se muestran todos los clientes.', 'Marcas y cuentas')
    : loose.length ? warn(`${loose.length} campaña${loose.length > 1 ? 's' : ''} activa${loose.length > 1 ? 's' : ''} sin marca en cuentas compartidas`, `${loose.slice(0, 4).map((c) => `<b>${esc(c.name)}</b> (${esc(c.account)})`).join(' · ')}${loose.length > 4 ? ' …' : ''}. Su gasto no suma a ninguna marca hasta que la asignes.`, 'Asignar') : '';

  return `<div class="card mb-8 overflow-hidden" data-plan-body>
    <div class="p-5 flex flex-wrap items-end gap-4">
      <div class="w-full"><div class="h2 flex items-center gap-2">Plan por objetivos · ${esc(monthTitle)}${d.has_brands && d.can_split ? `<button class="btn btn-sm btn-ghost !h-7" data-brands-edit>${icon('sliders', 13)}Marcas y cuentas</button>` : ''}</div>
        <div class="t3 text-xs mt-0.5">Cargá cuántas ${esc('ventas')} querés por marca: calculamos cuánto invertir con su costo por venta real (Meta +${tax} ÷ ventas de la planilla).</div></div>
      <div><div class="t3 text-xs mb-1.5">Mes</div><div class="seg">${[['current', 'En curso'], ['next', 'Siguiente']].map(([v, l]) => `<button data-plan-cfg="month" data-v="${v}" class="${cfg.month === v ? 'on' : ''}">${l} · ${esc(monthOf(v))}</button>`).join('')}</div></div>
      ${(d.parents || []).length ? `<label class="field"><span>Cliente</span><select class="input select-sm" id="plan-parent">${d.parents.length > 1 ? '<option value="">Todos</option>' : ''}${d.parents.map((p) => `<option value="${p.id}" ${String(cfg.parent || '') === String(p.id) || d.parents.length === 1 ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>` : ''}
      <label class="field"><span>Costo por venta según</span><select class="input select-sm" id="plan-basis">${Object.entries(d.bases).map(([k, l]) => `<option value="${k}" ${d.basis === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
      <div><div class="t3 text-xs mb-1.5">Ver</div><div class="seg">${Object.entries(GRAN).map(([k, [l]]) => `<button data-plan-cfg="gran" data-v="${k}" class="${cfg.gran === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
    </div>
    ${banner}
    ${totals ? `<div class="px-5 pb-5">${totals}</div>` : ''}
    ${winHtml}
    ${d.brands.length ? `<div class="scroll-x hair-t"><table class="tbl"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table></div>`
      : '<div class="p-5 t3 hair-t">No hay marcas asignadas. Creá los clientes y asignales sus campañas.</div>'}
    <div class="px-5 py-3 hair-t t3 text-xs">Inversión = objetivo × costo por venta (con ${tax} de impuestos). “En Meta” es lo que se carga como presupuesto en el Administrador de anuncios. ${cur ? `Quedan ${d.remaining_days} días: lo que falta se calcula con el costo del mes.` : ''} Las marcas en verde superan el objetivo con menos presupuesto.</div>
  </div>`;
}

/** Estado de un objetivo mensual de campaña. */
function goalInfo(g) {
  if (!g || (!g.target_orders && !g.target_revenue)) return null;
  const byOrders = !!g.target_orders;
  const target = byOrders ? g.target_orders : g.target_revenue;
  const done = byOrders ? g.mtd_orders : g.mtd_revenue;
  const proj = byOrders ? g.projected_orders : g.projected_revenue;
  const pct = (done / target) * 100;
  const [label, cls] = pct >= 100 ? ['Cumplido', 'green'] : proj >= target ? ['En camino', 'green'] : proj >= target * 0.85 ? ['Ajustado', 'amber'] : ['Atrasado', 'red'];
  return { byOrders, target, done, proj, pct, label, cls, expected: g.expected_pct };
}

function goalBar(gi) {
  return `<div class="relative h-2 rounded-full sunk overflow-hidden mt-1.5">
    <div class="absolute inset-y-0 left-0 rounded-full" style="width:${Math.min(100, gi.pct)}%;background:var(--${gi.cls === 'red' ? 'bad' : gi.cls === 'amber' ? 'warn' : 'accent'})"></div>
    <div class="absolute inset-y-0 w-px" style="left:${Math.min(100, gi.expected)}%;background:var(--t2)" title="Ritmo esperado a hoy"></div></div>`;
}

function goalCell(r, L, money) {
  const gi = goalInfo(r.goal);
  const edit = canEdit() ? `<button class="btn btn-sm btn-ghost !h-6 !px-1.5" data-goal="${esc(r.id)}" title="Editar objetivo">${icon(gi ? 'sliders' : 'plus', 13)}${gi ? '' : 'Objetivo'}</button>` : '';
  if (!gi) return `<div class="flex justify-end">${edit || '<span class="t3">—</span>'}</div>`;
  const f = gi.byOrders ? fmt.int : money;
  return `<div class="min-w-[170px]"><div class="flex items-center justify-end gap-1.5"><span class="num font-medium">${f(gi.done)} / ${f(gi.target)}</span><span class="pill ${gi.cls} plain">${gi.label}</span>${edit}</div>
    ${goalBar(gi)}<div class="sub mt-1">Cierre est. ${f(gi.proj)}${gi.byOrders ? ' ' + esc(lower(L.sales)) : ''}</div></div>`;
}

function goalsStrip(rows, L, money) {
  const list = rows.map((r) => [r, goalInfo(r.goal)]).filter(([, g]) => g).sort((a, b) => a[1].pct - b[1].pct);
  if (!list.length) return '';
  const monthName = new Date().toLocaleDateString(LOCALE, { month: 'long' });
  return `<div class="mb-6"><div class="flex items-center gap-2 mb-3"><div class="h2">Objetivos de ${esc(monthName)} por campaña</div><span class="t3 text-xs">la línea marca dónde deberías estar hoy</span></div>
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">${list.map(([r, g]) => {
      const f = g.byOrders ? fmt.int : money;
      return `<div class="card p-4">
        <div class="flex items-start gap-2"><div class="t1 font-medium truncate flex-1" title="${esc(r.name)}">${esc(r.name)}</div><span class="pill ${g.cls}">${g.label}</span></div>
        <div class="flex items-end gap-2 mt-3"><span class="hero-num t1" style="font-size:28px">${f(g.done)}</span><span class="t3 mb-1">de ${f(g.target)} ${g.byOrders ? esc(lower(L.sales)) : ''}</span><span class="flex-1"></span><span class="num font-semibold t2 mb-1">${fmt.dec(g.pct, 0)}%</span></div>
        ${goalBar(g)}
        <div class="flex text-xs t3 mt-2"><span>Cierre estimado: <b class="t1">${f(g.proj)}</b></span><span class="flex-1"></span><span>${r.cost_per_order ? 'Costo/venta ' + money(r.cost_per_order) : ''}</span></div>
      </div>`;
    }).join('')}</div></div>`;
}

function goalModal(r, cur, after) {
  const el = modal(`${modalHead('Objetivo del mes', esc(r.name))}
    <form class="p-5 space-y-3" id="goal-form">
      <p class="t3 text-xs">Se compara con las ventas cargadas en la planilla para esta campaña (mes en curso). Podés usar uno o los dos.</p>
      <div class="grid grid-cols-2 gap-3">
        <label class="field"><span>Ventas (cantidad)</span><input class="input num" name="target_orders" inputmode="decimal" value="${esc(r.goal?.target_orders ?? '')}"></label>
        <label class="field"><span>Facturación (${esc(cur)})</span><input class="input num" name="target_revenue" inputmode="decimal" value="${esc(r.goal?.target_revenue ?? '')}"></label>
      </div>
      <div class="flex gap-2 pt-1"><button class="btn btn-primary">Guardar</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button><div class="flex-1"></div>
        ${r.goal?.target_orders || r.goal?.target_revenue ? '<button type="button" class="btn btn-ghost btn-danger" id="goal-clear">Quitar objetivo</button>' : ''}</div>
    </form>`, 'sm');
  const save = async (data) => { await post('campaign_target_save', { campaign_id: r.id, account: r.account, ...data }, 'Objetivo guardado'); closeModal(); after(); };
  $('#goal-form', el).addEventListener('submit', (e) => { e.preventDefault(); save(Object.fromEntries(new FormData(e.target))).catch(() => {}); });
  $('#goal-clear', el)?.addEventListener('click', () => save({ target_orders: '', target_revenue: '' }).catch(() => {}));
}

/** Tabla de ventas reales por campaña (cruce con Meta, gasto con impuestos). */
function campaignSalesBlock(ads, L, cur) {
  const cs = ads?.campaigns;
  if (!cs) return '';
  const m = (v) => fmt.money(v, ads.currency);
  const tax = taxPct();
  if (!cs.enabled) {
    return `<div class="card p-5 mb-6"><div class="h2">${esc(L.sales)} por campaña</div>
      <p class="t2 mt-1 text-[13px]">Agregá una columna <b>“Campaña”</b> a la planilla con el nombre exacto de la campaña de Meta y vas a ver el ROAS y el costo por ${esc(lower(L.sales).replace(/s$/, ''))} reales de cada una.</p>
      ${cs.names.length ? `<div class="mt-3 flex flex-wrap gap-2">${cs.names.slice(0, 12).map((n) => `<button class="pill plain" style="cursor:copy" data-copy-name="${esc(n)}" title="Copiar">${esc(n)}</button>`).join('')}</div><div class="t3 text-xs mt-2">Tocá un nombre para copiarlo.</div>` : ''}</div>`;
  }
  const rows = cs.rows;
  const sum = (k) => rows.reduce((s, r) => s + (r[k] || 0), 0);
  const tSpendTax = sum('spend_tax'), tRev = sum('revenue'), tOrd = sum('orders');
  return `
    ${goalsStrip(rows, L, m)}
    <div class="card overflow-hidden mb-6">
      <div class="card-h"><div class="h2">Rendimiento real por campaña</div><span class="t3 text-xs">${esc(L.sales)} de la planilla × gasto en Meta +${tax}</span></div>
      <div class="scroll-x"><table class="tbl"><thead><tr>
        <th style="text-align:left">Campaña</th><th>Objetivo del mes</th><th>Gasto +${tax}</th><th>${esc(L.sales)} reales</th><th>Facturación</th><th>Costo por ${esc(lower(L.sales).replace(/s$/, ''))}</th><th>ROAS real</th><th>Resultado neto</th><th>Ticket</th><th>Conv. clic→venta</th><th>Resultados Meta</th><th>ROAS Meta</th><th>CTR</th>
      </tr></thead><tbody>
      ${rows.map((r) => `<tr class="clickable" data-go="#/ads/${esc(r.account)}/c/${esc(r.id)}">
        <td style="text-align:left"><div class="font-medium max-w-[320px] truncate" title="${esc(r.name)}">${esc(r.name)}</div><div class="mt-1 flex gap-2 items-center">${statusPill(r.effective_status)}${r.matched ? '' : `<span class="pill amber plain">Sin ${esc(lower(L.sales))} cargadas</span>`}</div></td>
        <td>${goalCell(r, L, m)}</td>
        <td>${m(r.spend_tax)}</td><td class="font-semibold">${fmt.int(r.orders)}</td><td>${m(r.revenue)}</td>
        <td class="font-semibold">${m(r.cost_per_order)}</td>
        <td class="font-semibold ${r.roas_real !== null ? (r.roas_real >= 1 ? 't-pos' : 't-bad') : ''}">${fmt.x(r.roas_real)}</td>
        <td class="${r.profit >= 0 ? 't-pos' : 't-bad'}">${r.revenue ? m(r.profit) : '—'}</td>
        <td>${m(r.ticket)}</td><td>${fmt.pct(r.conv_rate)}</td>
        <td>${fmt.int(r.meta_results)} <span class="sub">${esc(r.result_label)}</span></td><td>${fmt.x(r.roas_meta)}</td><td>${fmt.pct(r.link_ctr)}</td>
      </tr>`).join('') || `<tr><td colspan="13" style="text-align:center" class="t3 !py-8">Sin campañas con gasto en el período</td></tr>`}
      </tbody>${rows.length > 1 ? `<tfoot><tr><td style="text-align:left">Total campañas</td><td></td><td>${m(tSpendTax)}</td><td>${fmt.int(tOrd)}</td><td>${m(tRev)}</td><td>${m(tOrd ? tSpendTax / tOrd : null)}</td><td>${fmt.x(tSpendTax ? tRev / tSpendTax : null)}</td><td>${m(tRev - tSpendTax)}</td><td colspan="5"></td></tr></tfoot>` : ''}</table></div>
      ${cs.no_campaign ? `<div class="px-5 py-3 hair-t t2 text-[13px]">Además hay <b>${fmt.int(cs.no_campaign.orders)}</b> ${esc(lower(L.sales))} sin campaña asignada${cs.no_campaign.revenue ? ` (${m(cs.no_campaign.revenue)})` : ''}: orgánicas, otros canales o filas sin completar.</div>` : ''}
      ${cs.unmatched.length ? `<div class="px-5 py-3 hair-t"><div class="t-warn text-[13px] font-medium flex items-center gap-1.5">${icon('alert', 14)}Nombres de la planilla que no coinciden con ninguna campaña de Meta</div>
        <ul class="mt-2 space-y-1 text-[13px]">${cs.unmatched.map((u) => `<li class="t2">“${esc(u.name)}” · ${fmt.int(u.orders)} ${esc(lower(L.sales))}${u.suggestion ? ` → ¿quisiste decir <button class="link" data-copy-name="${esc(u.suggestion)}">“${esc(u.suggestion)}”</button>?` : ''}</li>`).join('')}</ul>
        <div class="t3 text-xs mt-2">Corregí el nombre en la planilla (mayúsculas y tildes no importan).</div></div>` : ''}
    </div>`;
}

// =========================================================================
// Reportes de agencia en formato presentación (16:9, listo para PDF)
// =========================================================================
const DECK_COLORS = ['#1d2862', '#9aa5bd', '#29a8e0', '#2e9c6a', '#f0a93b', '#7b61c9', '#d1495b', '#5b8c2a'];
const cap = (s) => String(s || '').charAt(0).toUpperCase() + String(s || '').slice(1);
const moneyM = (v) => (bad(v) ? '—' : `$${fmt.dec(v / 1e6, Math.abs(v) >= 1e7 ? 1 : 2)} M`);
const moneyAR = (v) => (bad(v) ? '—' : '$' + fmt.int(v));
const pctTxt = (cur, prev) => (!prev ? null : ((cur - prev) / prev) * 100);
const pctBadge = (p) => (p === null || bad(p) ? '' : `<em class="${p >= 0 ? 's-up' : 's-down'}">${p >= 0 ? '↑' : '↓'} ${fmt.dec(Math.abs(p), Math.abs(p) >= 100 ? 0 : 1)}%</em>`);
const dm = (iso) => { const [, m, d] = iso.split('-'); return `${+d}/${+m}`; };

/** Plugin: escribe el valor arriba (o al costado) de cada barra, como en las presentaciones. */
const barLabels = {
  id: 'barLabels',
  afterDatasetsDraw(chart, _a, opts) {
    if (!opts || !opts.fmt) return;
    const { ctx } = chart;
    ctx.save();
    ctx.font = '600 12px Geist, Inter, sans-serif';
    ctx.fillStyle = '#1d2862';
    const max = Math.max(1, ...chart.data.datasets.flatMap((d) => d.data.map((v) => Math.abs(v || 0))));
    chart.data.datasets.forEach((ds, i) => {
      const meta = chart.getDatasetMeta(i);
      if (meta.hidden || ds.noLabels) return;
      meta.data.forEach((el, j) => {
        const v = ds.data[j];
        if (v === null || v === undefined || Math.abs(v) < max * 0.01) return;
        const txt = opts.fmt(v);
        if (chart.options.indexAxis === 'y') {
          if (opts.stackTotal && i !== chart.data.datasets.length - 1) return;
          ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
          ctx.fillText(opts.stackTotal ? opts.fmt(opts.stackTotal[j]) : txt, el.x + 6, el.y);
        } else {
          ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
          ctx.fillText(txt, el.x, el.y - 4);
        }
      });
    });
    ctx.restore();
  },
};

function deckChart(canvas, spec) {
  if (!canvas) return;
  const c = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: spec.labels,
      datasets: spec.datasets.map((d, i) => ({
        label: d.label, data: d.data, backgroundColor: d.colors || d.color || DECK_COLORS[i % DECK_COLORS.length],
        borderRadius: 2, maxBarThickness: spec.horizontal ? 38 : 64, stack: spec.stacked ? 's' : undefined, noLabels: d.noLabels,
      })),
    },
    options: {
      animation: false, devicePixelRatio: 2, responsive: true, maintainAspectRatio: false, indexAxis: spec.horizontal ? 'y' : 'x',
      layout: { padding: { top: 22, right: spec.horizontal ? 70 : 8 } },
      plugins: {
        legend: { display: spec.datasets.length > 1, position: 'bottom', labels: { color: '#2b3550', boxWidth: 10, boxHeight: 10, font: { size: 12 } } },
        tooltip: { enabled: false },
        barLabels: { fmt: spec.fmt || fmt.int, stackTotal: spec.stackTotal },
      },
      scales: spec.horizontal
        ? { x: { stacked: !!spec.stacked, display: false, grid: { display: false } }, y: { stacked: !!spec.stacked, grid: { display: false }, ticks: { color: '#2b3550', font: { size: 12 } } } }
        : { x: { grid: { display: false }, ticks: { color: '#2b3550', font: { size: 12 } }, border: { color: '#5a6582' } }, y: { display: false, beginAtZero: true, grid: { display: false } } },
    },
    plugins: [barLabels],
  });
  state.charts.push(c);
}

function deckContext(d, cfg) {
  const notes = d.notes || {};
  const editable = canEdit();
  const logo = state.boot.report?.logo;
  const company = cfg.company || state.boot.report?.company || state.boot.agency_name;
  const ed = (block, def, cls = '') => `<div class="ed ${cls}" data-note="${esc(block)}" contenteditable="${editable}" spellcheck="false">${esc(notes[block] ?? def)}</div>`;
  const val = (block, def) => notes[block] ?? def;
  const charts = [];
  let n = 0;
  const logoHtml = logo ? `<img class="s-logo" src="${esc(logo)}" alt="">` : `<div class="s-logo-txt">${esc(company)}</div>`;
  const slide = (title, body, foot, sub = '') => {
    n++;
    return `<section class="slide"><div class="s-top"></div><div class="s-title">${title}</div>${logoHtml}
      <div class="s-body">${sub ? `<div class="s-sub">${sub}</div>` : ''}${body}</div>
      <div class="s-foot"><span>${esc(foot)}</span><span>${n}</span></div></section>`;
  };
  const cover = (title, sub, note) => {
    n++;
    return `<section class="slide cover"><div class="c-logo">${logo ? `<img src="${esc(logo)}" alt="">` : `<span>${esc(company)}</span>`}</div>
      <div class="c-title">${title}</div><div class="c-sub">${sub}</div><div class="c-note">${note}</div><div class="s-bottom"></div></section>`;
  };
  const chartBox = (spec, title = '') => { const id = 'dc' + charts.length; charts.push([id, spec]); return `<div class="s-chart flex flex-col">${title ? `<div class="s-chart-title">${esc(title)}</div>` : ''}<div class="relative flex-1 min-h-0"><canvas id="${id}"></canvas></div></div>`; };
  const banner = (k, t, dsc) => `<div class="s-banner">${ed(k + '_t', t, 'ed-strong')}${dsc !== undefined ? ed(k + '_d', dsc) : ''}</div>`;
  return { ed, val, slide, cover, chartBox, banner, charts, company, notes, editable };
}

function projectionDeck(d, cfg) {
  const X = deckContext(d, cfg);
  const B = d.brands;
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  const mName = cap(d.month.name), nName = cap(d.next.name);
  const asOf = dm(d.as_of);
  const short = (s) => cap(s).slice(0, 3);
  const foot = `${X.company} · Proyección ${short(d.month.name)}–${short(d.next.name)} ${d.next.year} · Valores con ${tax} de impuestos incluido`;
  const sum = (f) => B.reduce((s, b) => s + (f(b) || 0), 0);
  const mLabels = d.months.map((m) => m.label);
  const out = [];

  out.push(X.cover(X.ed('title', 'Proyección de inversión y ventas'), `${mName} y ${d.next.name} de ${d.next.year}`, `Valores con ${tax} de impuestos incluido`));

  // 2. Cómo venimos
  const salesByMonth = d.months.map((_, i) => sum((b) => b.history[i].orders));
  const growth = B.map((b) => ({ b, from: b.history[0].orders, to: b.history[3].orders })).sort((a, z) => (z.to - z.from) - (a.to - a.from))[0];
  const trendUp = salesByMonth[3] / Math.max(1, d.month.days - d.month.remaining) >= salesByMonth[2] / 30;
  out.push(X.slide(X.ed('s2_title', trendUp ? 'Cómo venimos: creciendo' : 'Cómo venimos: repuntando'), `
    <div class="s-row">
      <div class="flex-1 flex">${X.chartBox({ labels: mLabels, datasets: [{ label: 'Ventas', data: salesByMonth, colors: salesByMonth.map((_, i) => (i === 3 ? '#f0a93b' : '#29a8e0')) }] }, 'Ventas por mes · todas las marcas')}</div>
      <div class="s-col w-[42%]">
        <div class="s-card flex-1"><h4>Qué pasó</h4>${X.ed('s2_paso', trendUp ? 'Las ventas vienen subiendo mes a mes y sostuvimos el ritmo de inversión.' : 'Las ventas bajaron contra el mes anterior y estuvimos repuntando las cuentas.')}</div>
        <div class="s-card flex-1"><h4>Hoy</h4>${X.ed('s2_hoy', 'Estamos encaminados, con perspectivas positivas ante los nuevos avances.')}</div>
      </div>
    </div>
    ${X.banner('s2', growth ? `${growth.b.name} pasó de ${fmt.int(growth.from)} ventas en ${d.months[0].label.toLowerCase()} a ${fmt.int(growth.to)} en ${d.month.name} (al ${asOf}).` : 'Resumen del período', `Entre las ${B.length} marcas llevamos ${fmt.int(salesByMonth[3])} ventas en ${d.month.name}.`)}`, foot));

  // 3. Inversión proyectada
  const invested = sum((b) => b.invested), projected = sum((b) => b.projected_month), prevSpend = sum((b) => b.history[2].spend_tax);
  const growthSpend = pctTxt(projected, prevSpend);
  const sortedB = [...B].sort((a, z) => z.projected_month - a.projected_month);
  out.push(X.slide(`${mName}: inversión proyectada`, `
    <div class="s-row">
      <div class="flex-1 flex">${X.chartBox({ horizontal: true, stacked: true, fmt: moneyM, stackTotal: sortedB.map((b) => b.projected_month),
        labels: sortedB.map((b) => `${b.name} (${moneyM(b.projected_month)})`),
        datasets: [{ label: `Invertido al ${asOf}`, data: sortedB.map((b) => b.invested), color: '#1d2862' }, { label: `Estimado ${dm(d.week.from)}–${d.month.days}/${d.month.key.slice(5) * 1}`, data: sortedB.map((b) => b.estimated_rest), color: '#29a8e0' }] },
      `Por marca ($, proyectado a fin de ${d.month.name})`)}</div>
      <div class="s-col w-[30%]">
        <div class="s-kpi"><b>${moneyAR(invested)}</b><span>invertido al ${asOf}</span></div>
        <div class="s-kpi"><b>${moneyAR(projected)}</b><span>proyectado a fin de ${d.month.name}</span></div>
        <div class="s-kpi"><b>${growthSpend === null ? '—' : `${growthSpend >= 0 ? '+' : ''}${fmt.dec(growthSpend, 0)}%`}</b><span>contra ${d.months[2].label.toLowerCase()}</span></div>
      </div>
    </div>
    ${X.banner('s3', `Valores con ${tax} de impuestos incluido.`, `Proyección = invertido al ${asOf} + presupuesto diario actual × ${d.month.remaining} días (${dm(d.week.from)}–${d.month.days}/${+d.month.key.slice(5)}), con impuestos.`)}`, foot));

  // 4. Contra meses anteriores
  const spendSeries = B.map((b) => ({ label: b.name, data: b.history.map((h, i) => (i === 3 ? b.projected_month : h.spend_tax)) }));
  const monthTotals = d.months.map((_, i) => spendSeries.reduce((s, x) => s + x.data[i], 0));
  const vsPrev = pctTxt(monthTotals[3], monthTotals[2]);
  out.push(X.slide(`${mName} contra meses anteriores`, `
    <div class="flex-1 flex">${X.chartBox({ fmt: moneyM, labels: d.months.map((m, i) => (i === 3 ? `${cap(d.month.name)} (proy.)` : m.label)), datasets: spendSeries }, 'Inversión por mes y marca ($, con impuestos)')}</div>
    ${X.banner('s4', `Total: ${d.months.map((m, i) => `${i === 3 ? `${cap(d.month.name)} proyectado` : m.label} ${moneyM(monthTotals[i])}`).join(' · ')}`,
      vsPrev === null ? '' : `${mName} proyectado ${vsPrev >= 0 ? 'supera' : 'queda por debajo de'} ${d.months[2].label.toLowerCase()} en un ${fmt.dec(Math.abs(vsPrev), 0)}%.`)}`, foot));

  // 5. Ventas y costo por venta
  const costCards = [...B].filter((b) => b.cost_avg).slice(0, 4);
  const worst = [...B].filter((b) => b.cost_month && b.cost_avg).sort((a, z) => z.cost_month / z.cost_avg - a.cost_month / a.cost_avg)[0];
  out.push(X.slide('Ventas y costo por venta', `
    <div class="s-row">
      <div class="flex-1 flex">${X.chartBox({ labels: mLabels, datasets: B.map((b) => ({ label: b.name, data: b.history.map((h) => h.orders) })) }, 'Ventas brutas por mes')}</div>
      <div class="s-col w-[30%]">${costCards.map((b) => `<div class="s-kpi"><b>${moneyAR(b.cost_avg)}</b><span>${esc(b.name)} · costo por venta prom.</span></div>`).join('') || '<div class="s-kpi"><span>Cargá las ventas en las planillas para ver el costo por venta.</span></div>'}</div>
    </div>
    ${X.banner('s5', worst ? `${worst.name}: el costo por venta de ${d.month.name} es ${moneyAR(worst.cost_month)} contra ${moneyAR(worst.cost_avg)} de promedio.` : 'Costo por venta por marca.', 'Costo por venta promedio = inversión con impuestos ÷ ventas de la planilla de cada marca.')}`, foot));

  // 6. Cierre estimado del mes
  const rows6 = B.map((b) => [b.name, b.orders_to_date, b.orders_rest]);
  const t6 = rows6.reduce((s, r) => [s[0] + r[1], s[1] + (r[2] || 0)], [0, 0]);
  out.push(X.slide(`${mName}: ventas al ${d.as_of.slice(8) * 1} y cierre estimado`, `
    <table class="s-tbl${B.length > 4 ? ' dense' : ''}"><thead><tr><th>Marca</th><th>Ventas al ${asOf}</th><th>Estimado ${dm(d.week.from)} al ${d.month.days}/${+d.month.key.slice(5)}</th><th>Cierre de ${d.month.name}</th></tr></thead><tbody>
      ${rows6.map((r) => `<tr><td>${esc(r[0])}</td><td><b>${fmt.int(r[1])}</b></td><td>${r[2] === null ? '—' : '+' + fmt.int(r[2])}</td><td class="hl">${fmt.int(r[1] + (r[2] || 0))}</td></tr>`).join('')}
      <tr class="tot"><td>Total</td><td>${fmt.int(t6[0])}</td><td>+${fmt.int(t6[1])}</td><td>${fmt.int(t6[0] + t6[1])}</td></tr></tbody></table>
    <div class="flex-1"></div>
    ${X.banner('s6', `${mName} cerraría cerca de ${fmt.int(t6[0] + t6[1])} ventas, desde ${fmt.int(t6[0])} al ${asOf}.`, `Estimado: presupuesto diario × ${d.month.remaining} días con impuestos ÷ costo por venta de ${d.month.name} de cada marca.`)}`,
  foot, 'Ventas actuales de cada marca y cuántas sumamos hasta fin de mes con el presupuesto activo.'));

  // 7. Próxima semana
  const t7 = [sum((b) => b.next_week_spend), sum((b) => b.next_week_sales_avg), sum((b) => b.next_week_sales_month)];
  out.push(X.slide(`Próxima semana: ${dm(d.week.from)} al ${dm(d.week.to)}`, `
    <table class="s-tbl${B.length > 4 ? ' dense' : ''}"><thead><tr><th>Marca</th><th>Inversión estimada</th><th>Ventas esperadas (costo promedio)</th><th>Ventas esperadas (costo de ${d.month.name})</th></tr></thead><tbody>
      ${B.map((b) => `<tr><td>${esc(b.name)}</td><td>${moneyAR(b.next_week_spend)}</td><td>${b.next_week_sales_avg === null ? '—' : fmt.int(b.next_week_sales_avg)}</td><td>${b.next_week_sales_month === null ? '—' : fmt.int(b.next_week_sales_month)}</td></tr>`).join('')}
      <tr class="tot-sky"><td>Total</td><td>${moneyAR(t7[0])}</td><td>${fmt.int(t7[1])}</td><td>${fmt.int(t7[2])}</td></tr></tbody></table>
    <div class="flex-1"></div>
    ${X.banner('s7', `Entre ${fmt.int(Math.min(t7[1], t7[2]))} y ${fmt.int(Math.max(t7[1], t7[2]))} ventas esperadas, según el costo por venta que se repita.`, `Promedio: inversión total ÷ ventas de los últimos 4 meses. ${mName}: costo por venta de este mes.`)}`,
  foot, 'Presupuesto diario actual × 7 días, con impuestos. Ventas esperadas según el costo por venta.'));

  // 8. Escenarios del mes siguiente
  const scen = [1, 1.15, 1.3];
  const salesRange = (budget, b) => { const a = b.cost_avg ? budget / b.cost_avg : null, m = b.cost_month ? budget / b.cost_month : null; return [a, m].filter((x) => x !== null); };
  const totRange = (k) => { const v = B.flatMap((b) => [salesRange(b.next_month_base * k, b)]); const lo = v.reduce((s, r) => s + (r.length ? Math.min(...r) : 0), 0), hi = v.reduce((s, r) => s + (r.length ? Math.max(...r) : 0), 0); return `${fmt.int(lo)} a ${fmt.int(hi)}`; };
  out.push(X.slide(`${nName}: presupuesto y ventas`, `
    <table class="s-tbl${B.length > 4 ? ' dense' : ''}"><thead><tr><th>Marca</th><th>Mantener ritmo</th><th>${X.val('s8_s2', 'Escalar +15%')}</th><th>${X.val('s8_s3', 'Escalar +30%')}</th></tr></thead><tbody>
      ${B.map((b) => `<tr><td>${esc(b.name)}</td>${scen.map((k) => `<td>${moneyAR(b.next_month_base * k)}</td>`).join('')}</tr>`).join('')}
      <tr class="tot-sky"><td>Total presupuesto</td>${scen.map((k) => `<td>${moneyAR(sum((b) => b.next_month_base) * k)}</td>`).join('')}</tr>
      <tr class="tot"><td>Ventas esperadas</td>${scen.map((k) => `<td>${totRange(k)}</td>`).join('')}</tr></tbody></table>
    <div class="flex-1"></div>
    ${X.banner('s8', `${cap(d.next.name)}, ${d.next.days} días, con impuestos incluidos. Elegimos el escenario según cuánto queremos escalar.`, `Ventas: entre el costo por venta de ${d.month.name} y el costo promedio. Escalar puede encarecer la venta.`)}`,
  foot, X.ed('s8_sub', 'Tres escenarios con el presupuesto diario actual, con costo por venta constante.')));

  // 9. Objetivos del mes siguiente (editables, el presupuesto se recalcula)
  const goal = (b, kind) => {
    const saved = X.notes[`goal_${kind}_${b.id}`];
    if (saved !== undefined && saved !== null && saved !== '') return +saved;
    const base = b.target_orders || (b.cost_avg ? Math.round(b.next_month_base / b.cost_avg / 10) * 10 : 0);
    return kind === 'base' ? base : Math.round(base * 1.2 / 10) * 10;
  };
  const rows9 = B.map((b) => ({ b, base: goal(b, 'base'), meta: goal(b, 'meta') }));
  const t9 = rows9.reduce((s, r) => [s[0] + r.base, s[1] + r.base * (r.b.cost_avg || 0), s[2] + r.meta, s[3] + r.meta * (r.b.cost_avg || 0)], [0, 0, 0, 0]);
  const inp = (b, kind, v) => (X.editable ? `<input class="s-in" data-goal-in="${kind}_${b.id}" data-cost="${b.cost_avg || 0}" value="${v}" inputmode="numeric">` : fmt.int(v));
  out.push(X.slide(`${nName}: objetivos y presupuesto`, `
    <table class="s-tbl${B.length > 4 ? ' dense' : ''}" id="goals-tbl"><thead><tr><th>Marca</th><th>Objetivo base</th><th>Presupuesto base</th><th>Meta superadora</th><th>Presupuesto meta</th></tr></thead><tbody>
      ${rows9.map((r) => `<tr><td>${esc(r.b.name)}</td><td><b>${inp(r.b, 'base', r.base)}</b></td><td data-gb="base_${r.b.id}">${moneyAR(r.base * (r.b.cost_avg || 0))}</td><td class="hl">${inp(r.b, 'meta', r.meta)}</td><td data-gb="meta_${r.b.id}">${moneyAR(r.meta * (r.b.cost_avg || 0))}</td></tr>`).join('')}
      <tr class="tot"><td>Total</td><td data-gt="base">${fmt.int(t9[0])}</td><td data-gt="base_b">${moneyAR(t9[1])}</td><td data-gt="meta">${fmt.int(t9[2])}</td><td data-gt="meta_b">${moneyAR(t9[3])}</td></tr></tbody></table>
    <div class="flex-1"></div>
    ${X.banner('s9', 'Objetivos alcanzables si sostenemos el costo por venta.', `Presupuesto = objetivo × costo por venta histórico, con impuestos. Presupuesto a ritmo actual: ${moneyAR(sum((b) => b.next_month_base))}.`)}`,
  foot, 'Objetivo base y meta superadora por marca, con el costo por venta histórico. Con impuestos incluidos.'));

  // 10. Cómo vamos a llegar
  const steps = [['Menos campañas, más creativos', 'Una campaña por objetivo y ángulos creativos distintos.'], ['Equipo de marketing en marcha', 'Más presencia y visualizaciones, con diseño y edición más ágiles.'],
    ['Presupuesto escalonado', 'Subimos de a poco en cada cuenta, solo si el costo por venta se sostiene.'], ['Seguimiento semanal', 'Cada semana revisamos ventas contra objetivo y ajustamos.']];
  out.push(X.slide('Cómo vamos a llegar a estos objetivos', `
    <div class="grid grid-cols-2 gap-[1.6cqw] flex-1">${steps.map(([t, s], i) => `<div class="s-card flex gap-[1.4cqw]"><div class="s-num">${i + 1}</div><div><h4>${X.ed(`s10_${i}_t`, t)}</h4>${X.ed(`s10_${i}_d`, s)}</div></div>`).join('')}</div>
    ${X.banner('s10', 'Primero el objetivo base, y la meta superadora si el costo se sostiene.', 'El foco es sostener el ritmo y mejorar semana a semana.')}`, foot));

  return { html: out.join(''), charts: X.charts };
}

function progressDeck(d, cfg) {
  const X = deckContext(d, cfg);
  const B = d.brands;
  const tax = fmt.dec(d.tax_rate * 100, 0) + '%';
  const foot = `${X.company} · Equipo de marketing · Interno`;
  const periodName = { semanal: 'la semana', quincenal: 'la quincena', mensual: 'el mes' }[d.period];
  const out = [];
  out.push(X.cover(X.ed('title', 'Avances del equipo de marketing'), `Interno · ${esc(d.label)}`, X.ed('cover_note', 'Lo que logramos, lo que falta y cómo lo ajustamos')));

  // Presencia orgánica
  const social = B.filter((b) => b.has_social);
  if (social.length) {
    const views = (b) => (b.social.fb_views || 0) + (b.social.ig_views || 0);
    const viewsPrev = (b) => (b.social.fb_views_prev || 0) + (b.social.ig_views_prev || 0);
    const total = social.reduce((s, b) => s + views(b), 0);
    for (let i = 0; i < social.length; i += 4) {
      const chunk = social.slice(i, i + 4);
      const metric = (label, v, prev) => (v === null ? '' : `<div class="s-metric"><small>${label}</small><b>${fmt.compact(v)}</b>${pctBadge(pctTxt(v, prev))}</div>`);
      out.push(X.slide('Más presencia y visualizaciones', `
        <div class="flex flex-col gap-[1.1cqw] flex-1">${chunk.map((b) => {
          const p = pctTxt(views(b), viewsPrev(b));
          const inter = (b.social.fb_engagement || 0) + (b.social.ig_interactions || 0), interPrev = (b.social.fb_engagement_prev || 0) + (b.social.ig_interactions_prev || 0);
          return `<div class="s-brandrow"><div class="text-[1.8cqw] font-bold">${esc(b.name)}</div>
            <div class="grid grid-cols-4 gap-[.8cqw]">${metric('Visualizaciones Facebook', b.social.fb_views, b.social.fb_views_prev)}${metric('Visualizaciones Instagram', b.social.ig_views, b.social.ig_views_prev)}${metric('Interacciones', inter, interPrev)}${metric('Reproducciones de video', b.social.fb_video, null)}</div>
            <div class="text-right">${p === null ? '<div class="text-[2.6cqw] font-extrabold s-up">Nuevo</div><div class="text-[1.2cqw] text-[#5a6582]">sin período anterior</div>' : `<div class="text-[2.6cqw] font-extrabold ${p >= 0 ? 's-up' : 's-down'}">${p >= 0 ? '+' : ''}${fmt.dec(p, 1)}%</div><div class="text-[1.2cqw] text-[#5a6582]">visualizaciones</div>`}</div></div>`;
        }).join('')}</div>`, foot, i === 0 ? `Facebook e Instagram, ${esc(d.label)}, contra el período anterior. En total, ${fmt.compact(total)} visualizaciones entre las ${social.length} marcas.` : ''));
    }
  }

  // Campañas
  const ads = B.filter((b) => b.has_ads);
  if (ads.length) {
    const conv = ads.map((b) => b.ads.messages), convPrev = ads.reduce((s, b) => s + b.ads_prev.messages, 0), convTot = conv.reduce((s, v) => s + v, 0);
    const pc = pctTxt(convTot, convPrev);
    out.push(X.slide('Cómo venimos en campañas', `
      <div class="s-row">
        <div class="flex-1 flex">${X.chartBox({ labels: ads.map((b) => b.name), datasets: [{ label: 'Conversaciones', data: conv, colors: ads.map((_, i) => DECK_COLORS[i % DECK_COLORS.length]) }] }, `Conversaciones de ${periodName} (${dm(d.range.since)}–${dm(d.range.until)})`)}</div>
        <div class="s-col w-[30%]">
          <div class="s-kpi"><b>${fmt.int(convTot)}</b><span>conversaciones en ${periodName}${pc === null ? '' : ` · ${pc >= 0 ? '+' : ''}${fmt.dec(pc, 0)}%`}</span></div>
          <div class="s-kpi"><b>${fmt.int(ads.reduce((s, b) => s + b.ads.active_campaigns, 0))}</b><span>campañas activas</span></div>
          <div class="s-kpi"><b>${ads.filter((b) => b.ads.active_campaigns > 0).length}</b><span>marcas con campañas en marcha</span></div>
        </div>
      </div>
      ${X.banner('p3', pc === null || pc >= 0 ? 'Venimos bien: ordenamos las campañas y hay movimiento todas las semanas.' : 'Bajaron las conversaciones: estamos revisando creativos y presupuestos.', 'Está bien, pero puede estar mejor: ahí están los ajustes.')}`, foot));

    // Inversión y ventas
    const rows = ads.map((b) => ({ b, inv: b.ads.spend_tax, s: b.sales.orders, sp: b.sales_prev.orders, cps: b.cost_per_sale, cv: b.ads.messages }));
    const tInv = rows.reduce((s, r) => s + r.inv, 0), tS = rows.reduce((s, r) => s + r.s, 0), tCv = rows.reduce((s, r) => s + r.cv, 0);
    out.push(X.slide(`Inversión y ventas de ${periodName}`, `
      <table class="s-tbl${B.length > 4 ? ' dense' : ''}"><thead><tr><th>Marca</th><th>Inversión (+${tax})</th><th>Ventas</th><th>vs. anterior</th><th>Costo por venta</th><th>Conversaciones</th><th>Costo por conversación</th></tr></thead><tbody>
        ${rows.map((r) => `<tr><td>${esc(r.b.name)}</td><td>${moneyAR(r.inv)}</td><td><b>${fmt.int(r.s)}</b></td><td>${pctBadge(pctTxt(r.s, r.sp)) || '—'}</td><td class="hl">${moneyAR(r.cps)}</td><td>${fmt.int(r.cv)}</td><td>${moneyAR(r.cv ? r.inv / r.cv : null)}</td></tr>`).join('')}
        <tr class="tot"><td>Total</td><td>${moneyAR(tInv)}</td><td>${fmt.int(tS)}</td><td></td><td>${moneyAR(tS ? tInv / tS : null)}</td><td>${fmt.int(tCv)}</td><td>${moneyAR(tCv ? tInv / tCv : null)}</td></tr></tbody></table>
      <div class="flex-1"></div>
      ${X.banner('p4', `${fmt.int(tS)} ventas con ${moneyAR(tInv)} invertidos (con impuestos).`, 'Ventas según las planillas de cada marca; inversión y conversaciones según Meta.')}`, foot, `${esc(cap(d.label))}, contra el período anterior.`));
  }

  // Lo que hicimos
  const done = B.filter((b) => b.done.length);
  if (done.length) {
    out.push(X.slide('Lo que hicimos', `
      <div class="grid grid-cols-2 gap-[1.4cqw] flex-1 content-start">${done.slice(0, 4).map((b) => `<div class="s-card"><h4>${esc(b.name)}</h4>${b.done.slice(0, 5).map((u) => `<p>• ${esc(u.title)}</p>`).join('')}</div>`).join('')}</div>`, foot));
  }

  // Ajustes
  const adj = [['Más creatividad', 'Ideas y ángulos nuevos para cada marca, en formatos distintos: reels, carruseles y estáticas. Meta premia la variedad.'],
    ['Menos tiempos', 'Achicar el tiempo de diseño, de creación de contenido y de edición de videos, sin bajar la calidad.'],
    ['Más autonomía', 'Tener iniciativa: proponer y resolver sin esperar que nos digan, y consultar lo importante.']];
  out.push(X.slide('Qué tenemos que ajustar', `
    <div class="grid grid-cols-3 gap-[1.6cqw] flex-1">${adj.map(([t, s], i) => `<div class="s-card"><div class="s-num mb-[1.6cqw]">${i + 1}</div><h4 class="!text-[2cqw]">${X.ed(`adj_${i}_t`, t)}</h4>${X.ed(`adj_${i}_d`, s)}</div>`).join('')}</div>
    ${X.banner('p6', 'Nunca está perfecto: está bien, pero puede estar mejor. Hay que seguir creando y creciendo.')}`, foot));

  // Objetivos
  const goals = [['Más ideas creativas', 'Llevar ideas y ángulos nuevos para cada marca todas las semanas'], ['Más variedad de formatos', 'Sumar reels, carruseles y estáticas, no solo gráficas'],
    ['Menos tiempo de producción', 'Medir cuánto tarda cada diseño, contenido y video, y bajarlo semana a semana'], ['Más autonomía', 'Resolver y proponer antes de preguntar'], ['Más presencia orgánica', 'Que el contenido también crezca solo, no solo con anuncios']];
  out.push(X.slide('Objetivos que seguimos persiguiendo', `
    <table class="s-tbl${B.length > 4 ? ' dense' : ''}"><thead><tr><th style="width:35%">Objetivo</th><th style="text-align:left">Cómo avanzamos</th></tr></thead><tbody>
      ${goals.map(([a, b], i) => `<tr><td>${X.ed(`obj_${i}_a`, a)}</td><td style="text-align:left">${X.ed(`obj_${i}_b`, b)}</td></tr>`).join('')}</tbody></table>`, foot, X.ed('p7_sub', 'Vamos por buen camino y siempre hay algo para mejorar.')));

  return { html: out.join(''), charts: X.charts };
}

async function viewReports() {
  crumbs([{ label: 'Reportes' }]);
  const clients = await api('clients');
  const brandIds = clients.some((c) => c.kind === 'marca') ? clients.filter((c) => c.kind === 'marca').map((c) => c.id) : clients.map((c) => c.id);
  const cfg = { type: 'progress', period: 'semanal', until: '', as_of: '', clients: brandIds, company: '', ...store.get('reportCfg', {}) };
  cfg.clients = cfg.clients.filter((id) => clients.some((c) => c.id === id));
  if (!cfg.clients.length) cfg.clients = brandIds;
  const yest = new Date(Date.now() - 864e5).toISOString().slice(0, 10);
  const report = state.boot.report || {};
  if (!clients.length) return { html: empty('Primero creá tus clientes / marcas', 'Los reportes se arman por marca (Go Celular, Osacra, GetNet, Clover…). Creálas en Clientes y asignales sus campañas, páginas y ventas.', isAdmin() ? '<a class="btn btn-primary" href="#/clients">Ir a Clientes</a>' : '', 'report') };

  const html = `
    ${pageHead('Reportes', 'Formato presentación, con los datos de Meta y de las planillas de ventas. Los textos se editan haciendo clic.', `<button class="btn btn-primary" id="deck-pdf">${icon('download', 15)}Descargar PDF</button>`)}
    <div class="card p-5 mb-6 no-print">
      <div class="flex flex-wrap gap-4 items-end">
        <div><div class="t3 text-xs mb-1.5">Reporte</div><div class="seg" id="r-type"><button data-v="progress" class="${cfg.type === 'progress' ? 'on' : ''}">Avances del equipo</button><button data-v="projection" class="${cfg.type === 'projection' ? 'on' : ''}">Proyección de inversión y ventas</button></div></div>
        <div data-for="progress" class="${cfg.type === 'progress' ? '' : 'hidden'}"><div class="t3 text-xs mb-1.5">Período</div><div class="seg" id="r-period">${['semanal', 'quincenal', 'mensual'].map((p) => `<button data-v="${p}" class="${cfg.period === p ? 'on' : ''}">${cap(p)}</button>`).join('')}</div></div>
        <label data-for="progress" class="field ${cfg.type === 'progress' ? '' : 'hidden'}"><span>Hasta el día</span><input type="date" class="input select-sm" id="r-until" value="${esc(cfg.until || yest)}" max="${yest}"></label>
        <label data-for="projection" class="field ${cfg.type === 'projection' ? '' : 'hidden'}"><span>Datos al día</span><input type="date" class="input select-sm" id="r-asof" value="${esc(cfg.as_of || yest)}" max="${yest}"></label>
        <div class="flex-1"></div>
        <button class="btn btn-accent" id="r-go">${icon('refresh', 14)}Generar</button>
      </div>
      <div class="mt-4"><div class="t3 text-xs mb-1.5">Marcas</div><div class="flex flex-wrap gap-2" id="r-brands">${clients.map((c) => `<button class="pill plain !h-7 !px-3 !text-[12.5px]" data-c="${c.id}" style="${cfg.clients.includes(c.id) ? `background:${esc(c.color)};color:#fff` : 'background:var(--sunk);color:var(--t3)'}">${esc(c.name)}</button>`).join('')}</div></div>
      ${isAdmin() ? `<div class="mt-4 pt-4 hair-t flex flex-wrap gap-3 items-end">
        <label class="field"><span>Empresa en el pie</span><input class="input select-sm w-56" id="r-company" value="${esc(report.company || '')}" placeholder="Ej: Paylab Gestión"></label>
        <div><div class="t3 text-xs mb-1.5">Logo del reporte</div><div class="flex items-center gap-2">${report.logo ? `<img src="${esc(report.logo)}" class="h-8 rounded border bd bg-white px-1" alt="">` : '<span class="t3 text-xs">Sin logo</span>'}<label class="btn btn-sm cursor-pointer">${icon('plus', 13)}Subir logo<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" id="r-logo"></label></div></div>
      </div>` : ''}
    </div>
    <div id="deck" class="deck"><div class="sk aspect-video"></div></div>`;

  return {
    html,
    mount() {
      const save = () => store.set('reportCfg', cfg);
      const gen = async () => {
        save();
        const box = $('#deck');
        box.innerHTML = '<div class="sk" style="aspect-ratio:16/9"></div>';
        try {
          const params = { type: cfg.type, clients: cfg.clients.join(','), period: cfg.period, until: cfg.until || yest, as_of: cfg.as_of || yest };
          const d = await api('report', params);
          const deck = d.type === 'projection' ? projectionDeck(d, cfg) : progressDeck(d, cfg);
          destroyCharts();
          box.innerHTML = deck.html;
          deck.charts.forEach(([id, spec]) => deckChart($('#' + id), spec));
          box.dataset.key = d.key;
        } catch (e) { box.innerHTML = errorBox(e.message, false); }
      };
      $('#r-type').addEventListener('click', (e) => { const b = e.target.closest('[data-v]'); if (!b) return; cfg.type = b.dataset.v; $$('#r-type [data-v]').forEach((x) => x.classList.toggle('on', x === b)); $$('[data-for]').forEach((x) => x.classList.toggle('hidden', x.dataset.for !== cfg.type)); gen(); });
      $('#r-period').addEventListener('click', (e) => { const b = e.target.closest('[data-v]'); if (!b) return; cfg.period = b.dataset.v; $$('#r-period [data-v]').forEach((x) => x.classList.toggle('on', x === b)); gen(); });
      $('#r-until').addEventListener('change', (e) => { cfg.until = e.target.value; gen(); });
      $('#r-asof').addEventListener('change', (e) => { cfg.as_of = e.target.value; gen(); });
      $('#r-brands').addEventListener('click', (e) => {
        const b = e.target.closest('[data-c]'); if (!b) return;
        const id = +b.dataset.c, c = clients.find((x) => x.id === id);
        cfg.clients = cfg.clients.includes(id) ? cfg.clients.filter((x) => x !== id) : [...cfg.clients, id];
        b.style.cssText = cfg.clients.includes(id) ? `background:${c.color};color:#fff` : 'background:var(--sunk);color:var(--t3)';
      });
      $('#r-go').addEventListener('click', gen);
      $('#r-company')?.addEventListener('change', async (e) => { await post('report_brand_save', { company: e.target.value }, 'Empresa guardada'); state.boot = await api('bootstrap'); gen(); });
      $('#r-logo')?.addEventListener('change', async (e) => {
        const f = e.target.files[0]; if (!f) return;
        const fd = new FormData(); fd.append('file', f); fd.append('target', 'report');
        try { await post('logo_upload', fd, 'Logo cargado'); state.boot = await api('bootstrap'); render({ silent: true }); } catch { /* toast */ }
      });
      // Textos editables: se guardan al salir del campo.
      $('#deck').addEventListener('focusout', async (e) => {
        const el = e.target.closest('[data-note]');
        if (!el || !canEdit()) return;
        try { await api('report_note_save', {}, { key: `${$('#deck').dataset.key}:${el.dataset.note}`, value: el.innerText.trim() }); } catch (err) { toast(err.message, 'error'); }
      });
      $('#deck').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.closest('[data-note]') && !e.shiftKey) { e.preventDefault(); e.target.blur(); } });
      // Objetivos: recalcula presupuestos y guarda.
      $('#deck').addEventListener('change', async (e) => {
        const inp = e.target.closest('[data-goal-in]');
        if (!inp) return;
        const v = Math.max(0, Math.round(SalesNum(inp.value)));
        inp.value = v;
        $(`[data-gb="${inp.dataset.goalIn}"]`).textContent = moneyAR(v * +inp.dataset.cost);
        const tot = (k) => $$(`[data-goal-in^="${k}_"]`).reduce((s, x) => s + (+x.value || 0), 0);
        const totB = (k) => $$(`[data-goal-in^="${k}_"]`).reduce((s, x) => s + (+x.value || 0) * +x.dataset.cost, 0);
        ['base', 'meta'].forEach((k) => { $(`[data-gt="${k}"]`).textContent = fmt.int(tot(k)); $(`[data-gt="${k}_b"]`).textContent = moneyAR(totB(k)); });
        try { await api('report_note_save', {}, { key: `${$('#deck').dataset.key}:goal_${inp.dataset.goalIn}`, value: String(v) }); } catch (err) { toast(err.message, 'error'); }
      });
      $('#deck-pdf').addEventListener('click', () => {
        document.body.classList.add('printing-deck');
        setTimeout(() => { window.print(); document.body.classList.remove('printing-deck'); }, 150);
      });
      gen();
    },
  };
}
const SalesNum = (s) => { const v = parseFloat(String(s).replace(/\./g, '').replace(',', '.')); return Number.isFinite(v) ? v : 0; };

// =========================================================================
// Biblioteca de anuncios (investigación por nicho + referencias guardadas)
// =========================================================================
const libUrl = (q, country = 'AR') => 'https://www.facebook.com/ads/library/?' + new URLSearchParams({ active_status: 'active', ad_type: 'all', country, is_targeted_country: 'false', media_type: 'all', q, search_type: 'keyword_unordered' });
const NICHE_PRESETS = [
  { name: 'Celulares y tecnología', keywords: 'celulares en cuotas\ncelular libre\niphone\nsamsung galaxy\nplan canje celular\nmotorola' },
  { name: 'Cobros y terminales (posnet)', keywords: 'posnet\nterminal de cobro\ncobrar con tarjeta\nlector de tarjetas\ncobrar con QR\npunto de venta' },
  { name: 'VTV / ITV', keywords: 'VTV\nverificación técnica vehicular\nturno VTV\nITV' },
];

async function viewLibrary() {
  crumbs([{ label: 'Biblioteca de anuncios' }]);
  const [d, clients] = await Promise.all([api('adlib'), api('clients').catch(() => [])]);
  const f = { tab: 'niches', niche: '', client: '', format: '', ...store.get('libCfg', {}) };
  const save = () => store.set('libCfg', { tab: f.tab, niche: f.niche, client: f.client, format: f.format });
  const countryOpts = (sel) => Object.entries(d.countries).map(([k, l]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${esc(l)}</option>`).join('');

  const nicheCard = (n) => `
    <div class="card p-5 flex flex-col gap-4">
      <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1"><div class="h2">${esc(n.name)}</div><div class="t3 text-xs mt-0.5">${esc(d.countries[n.country] || n.country)} · anuncios activos · ${n.saved} ${n.saved === 1 ? 'referencia' : 'referencias'}</div></div>
        ${canEdit() ? `<button class="btn btn-sm btn-ghost btn-icon" data-niche-edit="${n.id}" title="Editar">${icon('sliders', 14)}</button>` : ''}
      </div>
      <div><div class="t3 text-xs mb-2">Palabras clave · abren la Biblioteca de Meta filtrada</div>
        <div class="flex flex-wrap gap-2">${n.links.map((l) => `<a class="kw-chip" href="${esc(l.url)}" target="_blank" rel="noopener">${icon('search', 13)}${esc(l.q)}</a>`).join('') || '<span class="t3 text-xs">Sin palabras clave</span>'}</div></div>
      <div><div class="t3 text-xs mb-2">Competidores</div>
        ${n.competitors.length ? `<div class="divide-y bd border rounded-xl">${n.competitors.map((c) => `
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
            <div class="mono-avatar w-7 h-7 text-[10px]">${esc(initials(c.name))}</div>
            <div class="min-w-0 flex-1"><div class="font-medium truncate">${esc(c.name)}</div><div class="sub">${c.page_id ? 'ID de página ' + esc(c.page_id) : 'búsqueda por nombre'}</div></div>
            ${c.url ? `<a class="btn btn-sm btn-ghost btn-icon" href="${esc(c.url)}" target="_blank" rel="noopener" title="Perfil">${icon('external', 13)}</a>` : ''}
            <a class="btn btn-sm" href="${esc(c.library_url)}" target="_blank" rel="noopener">${icon('eye', 13)}Ver sus anuncios</a>
            ${canEdit() ? `<button class="btn btn-sm btn-ghost btn-icon" data-comp-del="${c.id}" title="Quitar">${icon('x', 13)}</button>` : ''}
          </div>`).join('')}</div>` : '<div class="t3 text-xs">Todavía no cargaste competidores.</div>'}
        ${canEdit() ? `<form class="flex flex-wrap gap-2 mt-2" data-comp-form="${n.id}">
          <input class="input select-sm flex-1 min-w-[120px]" name="name" placeholder="Nombre (ej: Personal)">
          <input class="input select-sm flex-[2] min-w-[160px]" name="ref" placeholder="ID de página o link de la Biblioteca">
          <button class="btn btn-sm">${icon('plus', 13)}Agregar</button></form>` : ''}
      </div>
      ${n.notes ? `<div class="t2 text-[13px] whitespace-pre-line hair-t pt-3">${esc(n.notes)}</div>` : ''}
    </div>`;

  const refCard = (r) => `
    <div class="card ref-card">
      <div class="ref-thumb">${r.image_url ? `<img src="${esc(r.image_url)}" alt="" loading="lazy">` : `<div class="t3">${icon('layers', 26)}</div>`}</div>
      <div class="p-4 flex flex-col gap-2 flex-1">
        <div class="flex flex-wrap gap-1.5">${r.format ? `<span class="pill plain">${esc(d.formats[r.format] || r.format)}</span>` : ''}${r.niche_name ? `<span class="pill blue plain">${esc(r.niche_name)}</span>` : ''}${r.client_name ? brandPill({ name: r.client_name, color: r.client_color }) : ''}</div>
        <div class="font-semibold t1 leading-snug">${esc(r.title)}</div>
        ${r.advertiser ? `<div class="t3 text-xs">${esc(r.advertiser)}</div>` : ''}
        ${r.hook ? `<div class="ref-hook">“${esc(r.hook)}”</div>` : ''}
        ${r.notes ? `<div class="t2 text-[13px] whitespace-pre-line">${esc(r.notes)}</div>` : ''}
        <div class="flex-1"></div>
        <div class="flex items-center gap-2 pt-2 hair-t">
          <span class="t3 text-[11px]">${fmt.date(r.created_at)}</span><div class="flex-1"></div>
          ${r.url ? `<a class="btn btn-sm" href="${esc(r.url)}" target="_blank" rel="noopener">${icon('external', 13)}Ver anuncio</a>` : ''}
          ${canEdit() ? `<button class="btn btn-sm btn-ghost btn-icon" data-ref-edit="${r.id}" title="Editar">${icon('sliders', 13)}</button>` : ''}
        </div>
      </div>
    </div>`;

  const refs = () => d.saved.filter((r) => (!f.niche || String(r.niche_id) === f.niche) && (!f.client || String(r.client_id) === f.client) && (!f.format || r.format === f.format));
  const sel = (id, all, opts, v) => `<select class="input select-sm" id="${id}"><option value="">${all}</option>${opts.map(([k, l]) => `<option value="${esc(k)}" ${String(k) === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  const body = () => f.tab === 'niches'
    ? (d.niches.length ? `<div class="grid lg:grid-cols-2 gap-4 lib-grid">${d.niches.map(nicheCard).join('')}</div>`
      : empty('Armá tu primer nicho', 'Un nicho agrupa las palabras clave y los competidores que querés seguir (ej: “Celulares en cuotas”, “Posnet y cobros”). Cada palabra abre la Biblioteca de Meta ya filtrada por Argentina y anuncios activos.',
        canEdit() ? `<div class="flex flex-wrap gap-2 justify-center"><button class="btn btn-primary" data-niche-new>${icon('plus', 14)}Nuevo nicho</button><button class="btn" id="lib-presets">Cargar nichos sugeridos</button></div>` : '', 'search'))
    : `<div class="flex flex-wrap gap-2 mb-4">${sel('lib-f-niche', 'Todos los nichos', d.niches.map((n) => [n.id, n.name]), f.niche)}${sel('lib-f-client', 'Todas las marcas', clients.map((c) => [c.id, c.name]), f.client)}${sel('lib-f-format', 'Todos los formatos', Object.entries(d.formats), f.format)}</div>
      ${refs().length ? `<div class="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-4">${refs().map(refCard).join('')}</div>`
        : empty('Sin referencias', 'Cuando veas un anuncio bueno en la Biblioteca, guardalo acá con su captura, el gancho y por qué funciona. Así el equipo arma las campañas sobre lo que ya probó el mercado.', canEdit() ? `<button class="btn btn-primary" data-ref-new>${icon('plus', 14)}Guardar referencia</button>` : '', 'layers')}`;

  const html = `
    ${pageHead('Biblioteca de anuncios', 'Qué está pautando cada nicho y la competencia, y las ideas que guarda el equipo para las campañas.',
      canEdit() ? `<div class="flex gap-2"><button class="btn" data-niche-new>${icon('plus', 14)}Nuevo nicho</button><button class="btn btn-primary" data-ref-new>${icon('plus', 14)}Guardar referencia</button></div>` : '')}
    <div class="card p-5 mb-6">
      <form class="flex flex-wrap gap-3 items-end" id="lib-search">
        <label class="field flex-1 min-w-[220px]"><span>Buscar en la Biblioteca de anuncios de Meta</span><input class="input" name="q" placeholder="Producto, marca o frase (ej: celulares en cuotas sin interés)" required></label>
        <label class="field"><span>País</span><select class="input" name="country">${countryOpts('AR')}</select></label>
        <button class="btn btn-accent">${icon('search', 14)}Buscar anuncios activos</button>
      </form>
      <div class="t3 text-xs mt-3 flex gap-2 items-start">${icon('alert', 14)}<span>Meta sólo entrega por API los anuncios comerciales de Europa; para Argentina la búsqueda se abre en la Biblioteca oficial (pestaña nueva), ya filtrada por país y anuncios activos. Los anuncios que más días llevan activos suelen ser los que mejor funcionan.</span></div>
    </div>
    <div class="seg mb-5" id="lib-tab"><button data-v="niches" class="${f.tab === 'niches' ? 'on' : ''}">Nichos y competidores (${d.niches.length})</button><button data-v="saved" class="${f.tab === 'saved' ? 'on' : ''}">Referencias guardadas (${d.saved.length})</button></div>
    <div id="lib-body">${body()}</div>`;

  return {
    html,
    mount() {
      const view = $('#view');
      const reload = () => render({ silent: true });
      const paint = () => { save(); $('#lib-body').innerHTML = body(); hydrateIcons($('#lib-body')); $$('#lib-tab button').forEach((b) => b.classList.toggle('on', b.dataset.v === f.tab)); };
      $('#lib-search').addEventListener('submit', (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        window.open(libUrl(fd.get('q'), fd.get('country')), '_blank', 'noopener');
      });
      view.addEventListener('change', (e) => {
        const k = { 'lib-f-niche': 'niche', 'lib-f-client': 'client', 'lib-f-format': 'format' }[e.target.id];
        if (k) { f[k] = e.target.value; paint(); }
      });
      view.addEventListener('submit', async (e) => {
        const form = e.target.closest('[data-comp-form]');
        if (!form) return;
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(form));
        try { await post('adlib_competitor_add', { niche_id: +form.dataset.compForm, ...fd }, 'Competidor agregado'); reload(); } catch { /* toast */ }
      });
      view.addEventListener('click', async (e) => {
        const t = e.target.closest('#lib-tab button');
        if (t) { f.tab = t.dataset.v; return paint(); }
        if (e.target.closest('[data-niche-new]')) return nicheModal(null, d, reload);
        const ne = e.target.closest('[data-niche-edit]');
        if (ne) return nicheModal(d.niches.find((n) => n.id === +ne.dataset.nicheEdit), d, reload);
        if (e.target.closest('[data-ref-new]')) return refModal(null, d, clients, reload);
        const re = e.target.closest('[data-ref-edit]');
        if (re) return refModal(d.saved.find((r) => +r.id === +re.dataset.refEdit), d, clients, reload);
        const cd = e.target.closest('[data-comp-del]');
        if (cd) { await post('adlib_competitor_delete', { id: +cd.dataset.compDel }, 'Competidor quitado'); return reload(); }
        if (e.target.closest('#lib-presets')) {
          for (const p of NICHE_PRESETS) await post('adlib_niche_save', { ...p, country: 'AR' });
          toast('Nichos sugeridos cargados: editalos a gusto');
          return reload();
        }
      });
    },
  };
}

function nicheModal(n, d, after) {
  const el = modal(`${modalHead(n ? 'Editar nicho' : 'Nuevo nicho', 'Palabras clave y país para buscar en la Biblioteca de Meta')}
    <form class="p-5 space-y-3" id="niche-form">
      <div class="grid grid-cols-3 gap-3">
        <label class="field col-span-2"><span>Nombre</span><input class="input" name="name" required value="${esc(n?.name || '')}" placeholder="Ej: Celulares en cuotas"></label>
        <label class="field"><span>País</span><select class="input" name="country">${Object.entries(d.countries).map(([k, l]) => `<option value="${k}" ${k === (n?.country || 'AR') ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
      </div>
      <label class="field"><span>Palabras clave (una por línea)</span><textarea class="input !h-32 py-2" name="keywords" placeholder="celulares en cuotas&#10;iphone 15&#10;plan canje">${esc((n?.keywords || []).join('\n'))}</textarea></label>
      <label class="field"><span>Notas / aprendizajes del nicho</span><textarea class="input !h-24 py-2" name="notes" placeholder="Qué ganchos y ofertas se repiten, precios, formatos…">${esc(n?.notes || '')}</textarea></label>
      <div class="flex gap-2 pt-1"><button class="btn btn-primary">Guardar</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button><div class="flex-1"></div>
        ${n ? '<button type="button" class="btn btn-ghost btn-danger" id="niche-del">Eliminar</button>' : ''}</div>
    </form>`);
  $('#niche-form', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await post('adlib_niche_save', { id: n?.id, ...Object.fromEntries(new FormData(e.target)) }, 'Nicho guardado'); closeModal(); after(); } catch { /* toast */ }
  });
  $('#niche-del', el)?.addEventListener('click', async () => {
    if (!confirm(`¿Eliminar el nicho “${n.name}”? Sus referencias guardadas se conservan.`)) return;
    await post('adlib_niche_delete', { id: n.id }, 'Nicho eliminado'); closeModal(); after();
  });
}

function refModal(r, d, clients, after) {
  const opt = (list, v) => list.map(([k, l]) => `<option value="${esc(k)}" ${String(k) === String(v ?? '') ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const el = modal(`${modalHead(r ? 'Editar referencia' : 'Guardar referencia', 'Un anuncio que te sirve de inspiración para las campañas')}
    <form class="p-5 space-y-3" id="ref-form">
      <div class="flex gap-4">
        <div class="w-28 shrink-0"><div class="ref-thumb rounded-xl border bd" id="ref-prev">${r?.image_url ? `<img src="${esc(r.image_url)}" alt="">` : `<div class="t3">${icon('layers', 22)}</div>`}</div>
          <label class="btn btn-sm w-full mt-2 cursor-pointer justify-center">${icon('plus', 13)}Captura<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" id="ref-file"></label></div>
        <div class="flex-1 space-y-3">
          <label class="field"><span>Título</span><input class="input" name="title" required value="${esc(r?.title || '')}" placeholder="Ej: Carrusel con cuotas sin interés"></label>
          <label class="field"><span>Anunciante</span><input class="input" name="advertiser" value="${esc(r?.advertiser || '')}" placeholder="Ej: Personal"></label>
        </div>
      </div>
      <label class="field"><span>Link del anuncio (Biblioteca de anuncios)</span><input class="input" name="url" type="url" value="${esc(r?.url || '')}" placeholder="https://www.facebook.com/ads/library/?id=…"></label>
      <input type="hidden" name="image_url" value="${esc(r?.image_url || '')}">
      <div class="grid grid-cols-3 gap-3">
        <label class="field"><span>Formato</span><select class="input" name="format"><option value="">—</option>${opt(Object.entries(d.formats), r?.format)}</select></label>
        <label class="field"><span>Nicho</span><select class="input" name="niche_id"><option value="">—</option>${opt(d.niches.map((n) => [n.id, n.name]), r?.niche_id)}</select></label>
        <label class="field"><span>Para la marca</span><select class="input" name="client_id"><option value="">—</option>${opt(clients.map((c) => [c.id, c.name]), r?.client_id)}</select></label>
      </div>
      <label class="field"><span>Gancho (hook) / texto principal</span><textarea class="input !h-20 py-2" name="hook" placeholder="La primera frase o el titular que engancha">${esc(r?.hook || '')}</textarea></label>
      <label class="field"><span>Por qué funciona / cómo lo adaptamos</span><textarea class="input !h-20 py-2" name="notes">${esc(r?.notes || '')}</textarea></label>
      <div class="flex gap-2 pt-1"><button class="btn btn-primary">Guardar</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button><div class="flex-1"></div>
        ${r ? '<button type="button" class="btn btn-ghost btn-danger" id="ref-del">Eliminar</button>' : ''}</div>
    </form>`, 'lg');
  $('#ref-file', el).addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    try {
      const res = await post('adlib_upload', fd, 'Captura subida');
      $('[name=image_url]', el).value = res.url;
      $('#ref-prev', el).innerHTML = `<img src="${esc(res.url)}" alt="">`;
    } catch { /* toast */ }
  });
  $('#ref-form', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await post('adlib_save', { id: r?.id, ...Object.fromEntries(new FormData(e.target)) }, 'Referencia guardada'); closeModal(); after(); } catch { /* toast */ }
  });
  $('#ref-del', el)?.addEventListener('click', async () => {
    if (!confirm('¿Eliminar esta referencia?')) return;
    await post('adlib_delete', { id: r.id }, 'Referencia eliminada'); closeModal(); after();
  });
}

// =========================================================================
// Accesos: panel general (dueña del sistema) y del cliente (gestor de accesos)
// =========================================================================
const ALL_BRANDS_ROLES = ['dueno', 'gerente_ops', 'gerente'];
const AGENCY_ROLES = { admin: 'Administrador', member: 'Equipo', viewer: 'Solo lectura' };
const portalUrl = () => location.origin + location.pathname.replace(/index\.php$/, '');
const brandsText = (u, brands) => (ALL_BRANDS_ROLES.includes(u.client_role) || !u.brands.length ? 'Todas las marcas' : u.brands.map((id) => brands.find((b) => b.id === id)?.name).filter(Boolean).join(', ') || '—');

/** Formulario de persona del cliente: rol, marcas y (sólo la dueña del sistema) permiso de gestionar accesos. */
function personFields(u, brands, { manage = false, orgName = '' } = {}) {
  return `${brands.length ? roleFields(u, brands) : `<label class="field"><span>Rol</span><select class="input" name="client_role">${Object.entries(state.boot.client_roles || {}).map(([k, l]) => `<option value="${k}" ${k === (u?.client_role || 'usuario') ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>`}
    ${manage ? `<label class="flex items-start gap-2.5 cursor-pointer sunk rounded-xl p-3"><input type="checkbox" name="can_manage_users" class="mt-0.5" ${u?.can_manage_users ? 'checked' : ''}>
      <span><b class="t1">Puede gestionar los accesos${orgName ? ' de ' + esc(orgName) : ''}</b><br><span class="t3 text-xs">Crea, edita y da de baja usuarios de su empresa desde su portal. No ve tu panel, ni a tu equipo, ni a otros clientes, y no puede dar este permiso a nadie.</span></span></label>` : ''}`;
}

async function viewAccess() {
  crumbs([{ label: 'Accesos' }]);
  const d = await api('users_all');
  const byId = Object.fromEntries(d.clients.map((c) => [c.id, c]));
  const tops = d.clients.filter((c) => c.kind !== 'marca' || !c.parent_id);
  const f = { q: '', type: '', client: '', ...store.get('accessF', {}) };
  const rows = () => d.users.filter((u) => {
    if (f.type === 'agency' && u.role === 'client') return false;
    if (f.type === 'client' && u.role !== 'client') return false;
    if (f.client && String(u.client_id) !== f.client) return false;
    return !f.q || `${u.name} ${u.username} ${byId[u.client_id]?.name || ''}`.toLowerCase().includes(f.q);
  });
  const row = (u) => {
    const c = byId[u.client_id];
    return `<tr>
      <td style="text-align:left"><div class="font-medium">${esc(u.name)}${u.id === d.me ? ' <span class="pill plain">Vos</span>' : ''}</div><div class="sub mono">${esc(u.username)}</div></td>
      <td style="text-align:left">${u.role === 'client' ? `<span class="pill plain" style="background:color-mix(in srgb, ${esc(c?.color || '#888')} 14%, transparent);color:${esc(c?.color || 'var(--t2)')}">${esc(c?.name || 'Cliente borrado')}</span>` : '<span class="pill blue plain">Agencia</span>'}</td>
      <td style="text-align:left"><div>${esc(u.role === 'client' ? d.roles[u.client_role] || 'Usuario' : AGENCY_ROLES[u.role] || u.role)}</div>
        ${u.role === 'client' && c?.children?.length ? `<div class="sub">${esc(brandsText(u, c.children))}</div>` : ''}${u.role !== 'client' && u.scope.length ? `<div class="sub">${u.scope.length} asignaciones</div>` : ''}</td>
      <td>${u.can_manage_users ? '<span class="pill green plain">Gestiona accesos</span>' : '<span class="t3">—</span>'}</td>
      <td>${u.last_login_at ? fmt.ago(u.last_login_at) : '<span class="t3">Nunca</span>'}</td>
      <td><div class="flex justify-end gap-1">
        <button class="btn btn-sm btn-ghost" data-u-edit="${u.id}">${icon('sliders', 13)}Editar</button>
        <button class="btn btn-sm btn-ghost" data-u-pass="${u.id}" title="Nueva clave">${icon('key', 13)}</button>
        ${u.id === d.me ? '' : `<button class="btn btn-sm btn-ghost btn-danger" data-u-del="${u.id}" title="Eliminar">${icon('x', 13)}</button>`}</div></td>
    </tr>`;
  };
  const table = () => { const list = rows(); return `<div class="card overflow-hidden"><div class="scroll-x"><table class="tbl"><thead><tr><th style="text-align:left">Persona</th><th style="text-align:left">De</th><th style="text-align:left">Rol y alcance</th><th>Permiso</th><th>Último ingreso</th><th></th></tr></thead>
    <tbody>${list.map(row).join('') || '<tr><td colspan="6" class="t3">No hay accesos con ese filtro.</td></tr>'}</tbody></table></div></div>`; };
  const counts = { agency: d.users.filter((u) => u.role !== 'client').length, client: d.users.filter((u) => u.role === 'client').length };
  const html = `
    ${pageHead('Accesos', 'Todas las personas que entran al sistema: tu equipo y los usuarios de cada cliente. Esta pantalla es sólo tuya: los clientes no la ven ni saben quién más tiene acceso.', `<button class="btn btn-primary" id="u-new">${icon('plus', 15)}Nuevo acceso</button>`)}
    <div class="kpi-row grid-cols-3 mb-5" style="display:grid">${kpi('Personas', fmt.int(d.users.length), { spark: '' })}${kpi('Equipo de la agencia', fmt.int(counts.agency), { spark: '' })}${kpi('De clientes', fmt.int(counts.client), { spark: '' })}</div>
    <div class="flex flex-wrap gap-2 mb-4">
      <input class="input w-64" id="u-q" placeholder="Buscar persona, usuario o cliente" value="${esc(f.q)}">
      <select class="select !w-auto" id="u-type"><option value="">Todos</option><option value="agency" ${f.type === 'agency' ? 'selected' : ''}>Equipo de la agencia</option><option value="client" ${f.type === 'client' ? 'selected' : ''}>De clientes</option></select>
      <select class="select !w-auto" id="u-client"><option value="">Todos los clientes</option>${tops.map((c) => `<option value="${c.id}" ${f.client === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
    </div>
    <div id="u-table">${table()}</div>`;
  return {
    html,
    mount() {
      const repaint = () => { store.set('accessF', f); $('#u-table').innerHTML = table(); hydrateIcons($('#u-table')); };
      $('#u-q').addEventListener('input', (e) => { f.q = e.target.value.toLowerCase(); repaint(); });
      $('#u-type').addEventListener('change', (e) => { f.type = e.target.value; repaint(); });
      $('#u-client').addEventListener('change', (e) => { f.client = e.target.value; repaint(); });
      $('#u-new').addEventListener('click', () => newAccessModal(d, tops));
      $('#u-table').addEventListener('click', async (e) => {
        const ed = e.target.closest('[data-u-edit]');
        if (ed) {
          const u = d.users.find((x) => x.id === +ed.dataset.uEdit);
          if (u.role !== 'client') { // equipo de la agencia: alcance en Configuración
            const [s, assets] = await Promise.all([api('settings'), api('assets', { all: 1 })]);
            return scopeModal(s.users.find((x) => x.id === u.id) || u, s, assets);
          }
          const c = byId[u.client_id];
          const el = modal(`${modalHead('Acceso de ' + u.name, esc(c?.name || ''))}<form class="p-5 space-y-3" id="u-form">${personFields(u, c?.children || [], { manage: true, orgName: c?.name })}
            <div class="flex gap-2"><button class="btn btn-primary">Guardar</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button></div></form>`, 'sm');
          roleToggle($('#u-form', el));
          $('#u-form', el).addEventListener('change', (ev) => { if (ev.target.name === 'client_role') roleToggle(ev.currentTarget); });
          $('#u-form', el).addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const fd = new FormData(ev.target);
            await post('client_user_save', { id: u.id, client_role: fd.get('client_role'), brands: fd.getAll('brands').map(Number), can_manage_users: fd.get('can_manage_users') ? 1 : 0 }, 'Acceso actualizado');
            closeModal(); render({ silent: true });
          });
          return;
        }
        const pw = e.target.closest('[data-u-pass]');
        if (pw) {
          const u = d.users.find((x) => x.id === +pw.dataset.uPass);
          if (!confirm(`¿Generar una contraseña nueva para ${u.name}? La anterior deja de funcionar.`)) return;
          const pass = genPassword();
          await post('user_reset_password', { id: u.id, password: pass });
          return credentialsModal(u.username, pass, portalUrl());
        }
        const del = e.target.closest('[data-u-del]');
        if (del) {
          const u = d.users.find((x) => x.id === +del.dataset.uDel);
          if (!confirm(`¿Eliminar el acceso de ${u.name}? No podrá volver a entrar.`)) return;
          await post('user_delete', { id: u.id }, 'Acceso eliminado');
          render({ silent: true });
        }
      });
    },
  };
}

function newAccessModal(d, tops) {
  const el = modal(`${modalHead('Nuevo acceso')}
    <form class="p-5 space-y-3" id="na-form">
      <div class="seg" id="na-kind"><button type="button" class="on" data-k="client">Persona de un cliente</button><button type="button" data-k="agency">Equipo de la agencia</button></div>
      <label class="field" data-for="client"><span>Cliente</span><select class="input" name="client_id">${tops.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></label>
      <label class="field hidden" data-for="agency"><span>Rol en la agencia</span><select class="input" name="role"><option value="member">Equipo (edita lo asignado)</option><option value="viewer">Solo lectura</option><option value="admin">Administrador (ve y edita todo)</option></select></label>
      <div class="grid grid-cols-2 gap-3">
        <label class="field"><span>Nombre</span><input class="input" name="name" required placeholder="Ej: David"></label>
        <label class="field"><span>Usuario</span><input class="input mono" name="username" required autocapitalize="none" pattern="[a-zA-Z0-9._@+\\-]{3,}" placeholder="david.ops"></label>
      </div>
      <label class="field"><span>Contraseña</span><input class="input mono" name="password" minlength="8" required value="${genPassword()}"></label>
      <div data-for="client" id="na-person"></div>
      <div class="flex gap-2 pt-1"><button class="btn btn-primary">Crear acceso</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button></div>
    </form>`, 'sm');
  let kind = 'client';
  const person = () => {
    const c = tops.find((x) => String(x.id) === $('[name=client_id]', el).value);
    $('#na-person', el).innerHTML = personFields({ client_role: 'gerente_ops' }, c?.children || [], { manage: true, orgName: c?.name });
    roleToggle($('#na-form', el));
  };
  person();
  $('[name=client_id]', el).addEventListener('change', person);
  $('#na-form', el).addEventListener('change', (e) => { if (e.target.name === 'client_role') roleToggle(e.currentTarget); });
  $('#na-kind', el).addEventListener('click', (e) => {
    const b = e.target.closest('[data-k]'); if (!b) return;
    kind = b.dataset.k;
    $$('#na-kind [data-k]', el).forEach((x) => x.classList.toggle('on', x === b));
    $$('[data-for]', el).forEach((x) => x.classList.toggle('hidden', x.dataset.for !== kind));
  });
  $('#na-form', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = { name: fd.get('name'), username: fd.get('username'), password: fd.get('password') };
    if (kind === 'client') Object.assign(body, { role: 'client', client_id: +fd.get('client_id'), client_role: fd.get('client_role'), brands: fd.getAll('brands').map(Number), can_manage_users: fd.get('can_manage_users') ? 1 : 0 });
    else body.role = fd.get('role');
    try { await post('user_add', body, 'Acceso creado'); credentialsModal(body.username, body.password, portalUrl()); render({ silent: true }); } catch { /* toast */ }
  });
}

/** Portal del cliente: el gestor de accesos (ej. gerente de operaciones) administra a su gente. */
async function viewPortalAccess() {
  crumbs([{ label: 'Accesos' }]);
  const d = await api('portal_users');
  const locked = (u) => u.id === d.me || u.can_manage_users || u.client_role === 'dueno';
  const html = `
    ${pageHead('Accesos', `Personas de <b class="t1">${esc(d.org)}</b> que entran a este panel. Podés crear accesos, cambiar qué marcas ve cada uno y darlos de baja.`, `<button class="btn btn-primary" id="pa-new">${icon('plus', 15)}Nuevo acceso</button>`)}
    <div class="card overflow-hidden"><div class="scroll-x"><table class="tbl"><thead><tr><th style="text-align:left">Persona</th><th style="text-align:left">Rol</th><th style="text-align:left">Marcas</th><th>Último ingreso</th><th></th></tr></thead><tbody>
      ${d.users.map((u) => `<tr>
        <td style="text-align:left"><div class="font-medium">${esc(u.name)}${u.id === d.me ? ' <span class="pill plain">Vos</span>' : ''}</div><div class="sub mono">${esc(u.username)}</div></td>
        <td style="text-align:left">${esc(d.roles[u.client_role] || 'Usuario')}</td>
        <td style="text-align:left" class="t2">${esc(brandsText(u, d.brands))}</td>
        <td>${u.last_login_at ? fmt.ago(u.last_login_at) : '<span class="t3">Nunca</span>'}</td>
        <td><div class="flex justify-end gap-1">${locked(u) ? '<span class="t3 text-xs">Lo administra la agencia</span>' : `
          <button class="btn btn-sm btn-ghost" data-pa-edit="${u.id}">${icon('sliders', 13)}Editar</button>
          <button class="btn btn-sm btn-ghost" data-pa-pass="${u.id}" title="Nueva clave">${icon('key', 13)}</button>
          <button class="btn btn-sm btn-ghost btn-danger" data-pa-del="${u.id}" title="Dar de baja">${icon('x', 13)}</button>`}</div></td>
      </tr>`).join('')}</tbody></table></div></div>
    <p class="t3 text-xs mt-3">Dueño, Gerente de operaciones y Gerente ven todas las marcas. Supervisor, Asesor y Usuario ven sólo las marcas tildadas.</p>`;
  const form = (u) => `<form class="p-5 space-y-3" id="pa-form">
      ${u ? '' : `<div class="grid grid-cols-2 gap-3"><label class="field"><span>Nombre</span><input class="input" name="name" required></label><label class="field"><span>Usuario</span><input class="input mono" name="username" required autocapitalize="none" pattern="[a-zA-Z0-9._@+\\-]{3,}"></label></div>
      <label class="field"><span>Contraseña</span><input class="input mono" name="password" minlength="8" required value="${genPassword()}"></label>`}
      ${personFields(u || { client_role: 'supervisor' }, d.brands).replace(/<option value="dueno"[^>]*>[^<]*<\/option>/, '')}
      <div class="flex gap-2"><button class="btn btn-primary">${u ? 'Guardar' : 'Crear acceso'}</button><button type="button" class="btn btn-ghost" data-close>Cancelar</button></div></form>`;
  const open = (u) => {
    const el = modal(`${modalHead(u ? 'Acceso de ' + u.name : 'Nuevo acceso', esc(d.org))}${form(u)}`, 'sm');
    const fm = $('#pa-form', el);
    roleToggle(fm);
    fm.addEventListener('change', (e) => { if (e.target.name === 'client_role') roleToggle(fm); });
    fm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(fm);
      const body = { client_role: fd.get('client_role'), brands: fd.getAll('brands').map(Number) };
      try {
        if (u) { await post('portal_user_save', { id: u.id, ...body }, 'Acceso actualizado'); closeModal(); }
        else { await post('portal_user_add', { ...body, name: fd.get('name'), username: fd.get('username'), password: fd.get('password') }, 'Acceso creado'); credentialsModal(fd.get('username'), fd.get('password'), portalUrl()); }
        render({ silent: true });
      } catch { /* toast */ }
    });
  };
  return {
    html,
    mount() {
      $('#pa-new').addEventListener('click', () => open(null));
      $('#view').addEventListener('click', async (e) => {
        const ed = e.target.closest('[data-pa-edit]'); if (ed) return open(d.users.find((x) => x.id === +ed.dataset.paEdit));
        const pw = e.target.closest('[data-pa-pass]');
        if (pw) {
          const u = d.users.find((x) => x.id === +pw.dataset.paPass);
          if (!confirm(`¿Generar una contraseña nueva para ${u.name}?`)) return;
          const pass = genPassword();
          await post('portal_user_reset', { id: u.id, password: pass });
          return credentialsModal(u.username, pass, portalUrl());
        }
        const del = e.target.closest('[data-pa-del]');
        if (del) {
          const u = d.users.find((x) => x.id === +del.dataset.paDel);
          if (!confirm(`¿Dar de baja a ${u.name}? No podrá volver a entrar.`)) return;
          await post('portal_user_delete', { id: u.id }, 'Acceso dado de baja');
          render({ silent: true });
        }
      });
    },
  };
}

// =========================================================================
// Navegación
// =========================================================================
const ADMIN_NAV = [
  ['Rendimiento', [['overview', '#/', 'grid', 'Resumen'], ['budgets', '#/budgets', 'calendar', 'Presupuestos'], ['ads', '#/ads', 'target', 'Campañas', 'ad_account'], ['pages', '#/pages', 'page', 'Facebook', 'page'], ['ig', '#/ig', 'instagram', 'Instagram', 'ig_account'], ['reports', '#/reports', 'report', 'Reportes']]],
  ['Estrategia', [['library', '#/library', 'library', 'Biblioteca de anuncios']]],
  ['Agencia', [['clients', '#/clients', 'users', 'Clientes'], ['access', '#/access', 'key', 'Accesos'], ['activity', '#/activity', 'bell', 'Actividad'], ['settings', '#/settings', 'sliders', 'Configuración']]],
];
const CLIENT_NAV = [['home', '#/', 'home', 'Inicio'], ['ads', '#/ads', 'target', 'Campañas'], ['social', '#/social', 'social', 'Redes'], ['work', '#/work', 'briefcase', 'Trabajo'], ['report', '#/report', 'report', 'Reporte']];

function renderNav() {
  if (isClient()) {
    const c0 = state.boot.client || {};
    const cn = state.boot.counts || {};
    const items = CLIENT_NAV.filter(([k]) => (k !== 'ads' || c0.has_ads) && (k !== 'social' || cn.page || cn.ig_account));
    if (c0.has_sales) items.splice(1, 0, ['sales', '#/sales', 'layers', c0.sales_label || 'Ventas']);
    if (c0.has_sheets) items.splice(items.length - 1, 0, ['sheets', '#/sheets', 'grid', 'Planillas']);
    if (state.boot.user.can_manage_users) items.push(['access', '#/access', 'key', 'Accesos']);
    const html = items.map(([k, href, ic, l]) => `<a href="${href}" data-nav="${k}">${icon(ic)}${esc(l)}</a>`).join('');
    $('#nav').innerHTML = html;
    $('#nav-mobile').innerHTML = html;
    const c = state.boot.client;
    const o = state.boot.org;
    $('#client-brand').innerHTML = `
      <div class="mono-avatar w-8 h-8 text-xs" style="background:var(--accent);color:var(--on-accent)">${c?.logo_url ? `<img src="${esc(c.logo_url)}" class="w-full h-full object-cover rounded-[10px]" alt="">` : esc(initials(c?.name))}</div>
      <div class="leading-tight min-w-[90px] max-w-[220px] hidden ${o ? '2xl:block' : 'sm:block'}"><div class="font-semibold t1 truncate">${esc(c?.name || '')}</div><div class="t3 text-[11px] truncate">${o && c?.id !== o.id ? esc(o.name) : 'por ' + esc(state.boot.agency_name)}</div></div>
      ${o ? `<select class="select select-sm w-40" id="brand-switch" title="Cambiar de marca"><option value="org" ${c?.id === o.id || location.hash === '#/org' ? 'selected' : ''}>Todas las marcas</option>${o.brands.map((b) => `<option value="${b.id}" ${c?.id === b.id && location.hash !== '#/org' ? 'selected' : ''}>${esc(b.name)}</option>`).join('')}</select>` : ''}`;
    $('#brand-switch')?.addEventListener('change', (e) => {
      if (e.target.value !== 'org') return switchBrand(+e.target.value);
      if (o.can_open) return switchBrand(o.id);
      location.hash = '#/org'; // ve sólo algunas marcas: resumen de ésas
    });
    return;
  }
  const counts = state.boot.counts;
  const nav = ADMIN_NAV.map(([sec, items]) => [sec, !isAdmin() ? items.filter(([k]) => !['settings', 'access'].includes(k)) : items]);
  $('#nav').innerHTML = nav.map(([sec, items]) => `<div class="nav-sec">${sec}</div>${items.map(([k, href, ic, l, cnt]) => `
    <a href="${href}" data-nav="${k}" class="nav-link">${icon(ic)}${l}${cnt && counts[cnt] ? `<span class="nav-count">${counts[cnt]}</span>` : ''}</a>`).join('')}`).join('');
}

function markNav(key) { $$('[data-nav]').forEach((a) => a.classList.toggle('active', a.dataset.nav === key)); }

// =========================================================================
// Buscador rápido (Ctrl/Cmd + K)
// =========================================================================
let paletteItems = null;
async function openPalette() {
  const root = $('#palette');
  root.innerHTML = `<div class="palette"><input placeholder="Ir a una cuenta, página, Instagram o cliente…" autofocus><ul class="py-2 max-h-[52vh] overflow-y-auto"></ul><div class="hair-t px-4 py-2 t3 text-[11px] flex gap-3"><span><kbd>↑</kbd> <kbd>↓</kbd> navegar</span><span><kbd>Enter</kbd> abrir</span><span><kbd>Esc</kbd> cerrar</span></div></div>`;
  root.classList.remove('hidden');
  const input = $('input', root), ul = $('ul', root);
  const nav = (isClient() ? CLIENT_NAV.map(([, h, ic, l]) => [l, h, ic, 'Sección']) : ADMIN_NAV.flatMap(([, it]) => it.map(([, h, ic, l]) => [l, h, ic, 'Sección'])));
  let items = nav;
  let sel = 0;
  const draw = () => {
    const q = input.value.trim().toLowerCase();
    const list = (q ? items.filter(([l, , , t]) => (l + ' ' + t).toLowerCase().includes(q)) : items).slice(0, 40);
    sel = Math.min(sel, Math.max(0, list.length - 1));
    ul.innerHTML = list.map(([l, h, ic, t], i) => `<li data-h="${esc(h)}" class="${i === sel ? 'sel' : ''}">${icon(ic)}<span class="flex-1 truncate">${esc(l)}</span><span class="t3 text-xs">${esc(t)}</span></li>`).join('') || '<li class="t3">Sin resultados</li>';
    return list;
  };
  draw();
  input.focus();
  if (!paletteItems) {
    try {
      const a = await api('assets', { client: portalClient() });
      paletteItems = [
        ...a.ad_accounts.map((x) => [x.name, `#/ads/${x.external_id}`, 'target', 'Cuenta publicitaria']),
        ...a.pages.map((x) => [x.name, `#/pages/${x.external_id}`, 'page', 'Facebook']),
        ...a.ig_accounts.map((x) => [x.name, `#/ig/${x.external_id}`, 'instagram', 'Instagram']),
        ...(isClient() ? [] : (await api('clients')).map((c) => [c.name, `#/clients/${c.id}`, 'users', 'Cliente'])),
      ];
    } catch { paletteItems = []; }
  }
  items = [...nav, ...paletteItems];
  draw();
  const go = (h) => { root.classList.add('hidden'); if (h) location.hash = h; };
  input.addEventListener('input', () => { sel = 0; draw(); });
  input.addEventListener('keydown', (e) => {
    const list = draw();
    if (e.key === 'ArrowDown') { sel = Math.min(sel + 1, list.length - 1); draw(); e.preventDefault(); }
    if (e.key === 'ArrowUp') { sel = Math.max(sel - 1, 0); draw(); e.preventDefault(); }
    if (e.key === 'Enter' && list[sel]) go(list[sel][1]);
    if (e.key === 'Escape') go();
  });
  ul.onclick = (e) => { const li = e.target.closest('li[data-h]'); if (li) go(li.dataset.h); };
  root.onclick = (e) => { if (e.target === root) go(); };
}

// =========================================================================
// Router
// =========================================================================
const ADMIN_ROUTES = [
  [/^\/?$/, viewOverview, 'overview'],
  [/^\/budgets$/, viewBudgets, 'budgets'],
  [/^\/reports$/, viewReports, 'reports'],
  [/^\/library$/, viewLibrary, 'library'],
  [/^\/ads$/, viewAdAccounts, 'ads'],
  [/^\/ads\/top$/, viewTopAds, 'ads'],
  [/^\/ads\/(act_\d+)$/, viewAccount, 'ads'],
  [/^\/ads\/(act_\d+)\/c\/(\d+)$/, viewCampaign, 'ads'],
  [/^\/ads\/(act_\d+)\/c\/(\d+)\/s\/(\d+)$/, viewAdset, 'ads'],
  [/^\/pages$/, viewPages, 'pages'],
  [/^\/pages\/(\d+)$/, viewPage, 'pages'],
  [/^\/ig$/, viewIgList, 'ig'],
  [/^\/ig\/(\d+)$/, viewIg, 'ig'],
  [/^\/social$/, viewSocial, 'ig'],
  [/^\/clients$/, viewClients, 'clients'],
  [/^\/clients\/(\d+)$/, viewClient, 'clients'],
  [/^\/preview\/(\d+)$/, viewClientHome, 'clients', true],
  [/^\/preview\/(\d+)\/work$/, viewWork, 'clients', true],
  [/^\/preview\/(\d+)\/sales$/, viewSales, 'clients', true],
  [/^\/preview\/(\d+)\/report$/, viewReport, 'clients', true],
  [/^\/preview\/(\d+)\/sheets$/, viewSheets, 'clients', true],
  [/^\/activity$/, viewActivity, 'activity'],
  [/^\/access$/, viewAccess, 'access'],
  [/^\/settings$/, viewSettings, 'settings'],
];
const CLIENT_ROUTES = [
  [/^\/?$/, () => viewClientHome(), 'home'],
  [/^\/ads$/, viewAdAccounts, 'ads'],
  [/^\/ads\/top$/, viewTopAds, 'ads'],
  [/^\/ads\/(act_\d+)$/, viewAccount, 'ads'],
  [/^\/ads\/(act_\d+)\/c\/(\d+)$/, viewCampaign, 'ads'],
  [/^\/ads\/(act_\d+)\/c\/(\d+)\/s\/(\d+)$/, viewAdset, 'ads'],
  [/^\/social$/, viewSocial, 'social'],
  [/^\/pages\/(\d+)$/, viewPage, 'social'],
  [/^\/ig\/(\d+)$/, viewIg, 'social'],
  [/^\/work$/, () => viewWork(), 'work'],
  [/^\/sales$/, () => viewSales(), 'sales'],
  [/^\/report$/, () => viewReport(), 'report'],
  [/^\/sheets$/, () => viewSheets(), 'sheets'],
  [/^\/org$/, () => viewOrgHome(portalClient(), state.boot.org), 'home'],
  [/^\/access$/, viewPortalAccess, 'access'],
];
const NO_LIVE = /^#\/(settings|clients)/;

async function render({ silent = false } = {}) {
  const path = location.hash.replace(/^#/, '') || '/';
  const id = ++state.renderId;
  if ((isViewer() && /^\/(settings|access|clients\/\d+)/.test(path)) || (isMember() && /^\/(settings|access)/.test(path))) {
    const m2 = path.match(/^\/clients\/(\d+)/);
    location.replace(m2 ? `#/preview/${m2[1]}` : '#/');
    return;
  }
  const routes = isClient() ? CLIENT_ROUTES : ADMIN_ROUTES;
  const match = routes.map(([re, fn, nav, preview]) => [path.match(re), fn, nav, preview]).find(([m]) => m);
  if (!match) { location.hash = '#/'; return; }
  const [m, fn, nav, preview] = match;
  if (!isClient()) {
    state.preview = preview ? +m[1] : null;
    if (!preview) setAccent(null);
  }
  markNav(nav);
  closeMenu();
  const scroll = window.scrollY;
  if (!silent) { $('#view').innerHTML = skeleton(); window.scrollTo(0, 0); }
  try {
    const out = await fn(...m.slice(1));
    if (id !== state.renderId) return;
    destroyCharts();
    // Reemplazamos #view para soltar los listeners de la vista anterior.
    const fresh = $('#view').cloneNode(false);
    $('#view').replaceWith(fresh);
    fresh.innerHTML = out.html;
    hydrateIcons(fresh);
    await out.mount?.();
    if (silent) window.scrollTo(0, scroll);
  } catch (e) {
    if (id !== state.renderId) return;
    if (!silent) $('#view').innerHTML = errorBox(e.message);
    else toast('No se pudo actualizar: ' + e.message, 'error');
  } finally {
    state.force = false;
    $('#refresh-btn svg')?.classList.remove('animate-spin');
  }
}

// =========================================================================
// Controles: rango de fechas, en vivo, tema, menú
// =========================================================================
const QUICK = [['today', 'Hoy'], ['last_7d', '7 días'], ['last_30d', '30 días'], ['this_month', 'Este mes']];

function renderRange() {
  const r = state.range;
  const box = $('#range-ctl');
  box.innerHTML = `
    <div class="seg hidden ${isClient() ? '2xl' : 'xl'}:inline-flex">${QUICK.map(([k, l]) => `<button data-p="${k}" class="${r.preset === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <select class="select select-sm" id="preset">${Object.entries(state.boot.presets).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}<option value="custom">Personalizado…</option></select>
    <div id="custom-range" class="${r.preset === 'custom' ? 'flex' : 'hidden'} items-center gap-1">
      <input type="date" id="since" class="input select-sm w-[136px]" value="${esc(r.since || '')}"><span class="t3">–</span><input type="date" id="until" class="input select-sm w-[136px]" value="${esc(r.until || '')}">
    </div>`;
  const preset = $('#preset');
  preset.value = r.preset;
  const today = new Date().toISOString().slice(0, 10);
  $('#since').max = $('#until').max = today;
  const setPreset = (p) => {
    if (p === 'custom') { $('#custom-range').classList.replace('hidden', 'flex'); preset.value = 'custom'; return; }
    state.range = { preset: p }; store.set('range', state.range); renderRange(); render();
  };
  $$('[data-p]', box).forEach((b) => b.addEventListener('click', () => setPreset(b.dataset.p)));
  preset.addEventListener('change', () => setPreset(preset.value));
  const applyCustom = () => {
    const s = $('#since').value, u = $('#until').value;
    if (s && u && s <= u) { state.range = { preset: 'custom', since: s, until: u }; store.set('range', state.range); render(); }
  };
  $('#since').addEventListener('change', applyCustom);
  $('#until').addEventListener('change', applyCustom);
}

function initControls() {
  renderRange();
  hydrateIcons();
  paintThemeButtons();
  $$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => setTheme(theme() === 'dark' ? 'light' : 'dark')));
  $('#refresh-btn').addEventListener('click', () => { state.force = true; $('#refresh-btn svg')?.classList.add('animate-spin'); paletteItems = null; render({ silent: true }); });
  const live = $('#live-btn');
  const setLive = (on) => {
    state.live = on; store.set('live', on);
    live.classList.toggle('live-on', on);
    clearInterval(state.timer);
    if (on) {
      state.timer = setInterval(() => {
        const busy = !$('#modal').classList.contains('hidden') || !$('#palette').classList.contains('hidden') || document.activeElement?.matches('input,textarea,select');
        if (document.visibilityState === 'visible' && !busy && !NO_LIVE.test(location.hash)) render({ silent: true });
      }, Math.max(60, state.boot.refresh_sec) * 1000);
    }
  };
  live.addEventListener('click', () => { setLive(!state.live); toast(state.live ? 'Actualización automática activada' : 'Actualización automática en pausa'); });
  setLive(state.live);

  $('#menu-btn')?.addEventListener('click', () => { $('#sidebar').classList.remove('-translate-x-full'); $('#backdrop').classList.remove('hidden'); });
  $('#backdrop')?.addEventListener('click', closeMenu);
  $('#palette-btn')?.addEventListener('click', openPalette);
  $('#account-btn')?.addEventListener('click', accountMenu);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeModal(); $('#palette').classList.add('hidden'); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); }
  });
}

function closeMenu() {
  if (window.innerWidth < 1024 && $('#sidebar')) { $('#sidebar').classList.add('-translate-x-full'); $('#backdrop').classList.add('hidden'); }
}

(async function init() {
  applyChartTheme();
  try {
    state.boot = await api('bootstrap');
    if (!isClient() && state.brand) { state.brand = null; store.set('brand', null); state.boot = await api('bootstrap'); }
  } catch (e) {
    $('#view').innerHTML = errorBox(e.message);
    return;
  }
  $$('#user-name').forEach((el) => { el.textContent = state.boot.user.name; });
  if ($('#user-initials')) $('#user-initials').textContent = initials(state.boot.user.name);
  if (isClient()) setAccent(state.boot.client?.color);
  renderNav();
  initControls();
  if (window.FLASH) toast(window.FLASH.text, window.FLASH.type === 'error' ? 'error' : 'info');
  window.addEventListener('hashchange', () => render());
  render();
})();
