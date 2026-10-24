/* Meta Hub — panel de métricas (vanilla JS, sin build). */
'use strict';

// ---------- utilidades ----------
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
  lastMeta: null,
};

// ---------- formato ----------
const LOCALE = 'es-AR';
const nf0 = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });
const fmt = {
  int: (v) => (v == null || isNaN(v) ? '—' : nf0.format(Math.round(v))),
  dec: (v, d = 2) => (v == null || isNaN(v) ? '—' : new Intl.NumberFormat(LOCALE, { minimumFractionDigits: d, maximumFractionDigits: d }).format(v)),
  pct: (v) => (v == null || isNaN(v) ? '—' : fmt.dec(v, 2) + '%'),
  x: (v) => (v == null || isNaN(v) ? '—' : fmt.dec(v, 2) + 'x'),
  money(v, cur = 'USD') {
    if (v == null || isNaN(v)) return '—';
    try {
      return new Intl.NumberFormat(LOCALE, { style: 'currency', currency: cur, maximumFractionDigits: Math.abs(v) >= 1000 ? 0 : 2 }).format(v);
    } catch { return cur + ' ' + fmt.dec(v); }
  },
  compact: (v) => (v == null || isNaN(v) ? '—' : new Intl.NumberFormat(LOCALE, { notation: 'compact', maximumFractionDigits: 1 }).format(v)),
  date: (s) => (s ? new Date(s.length === 10 ? s + 'T12:00:00' : s).toLocaleDateString(LOCALE, { day: '2-digit', month: 'short' }) : '—'),
  datetime: (s) => (s ? new Date(s).toLocaleString(LOCALE, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'),
  ago(iso) {
    if (!iso) return '';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'hace instantes';
    if (s < 3600) return `hace ${Math.round(s / 60)} min`;
    if (s < 86400) return `hace ${Math.round(s / 3600)} h`;
    return `hace ${Math.round(s / 86400)} d`;
  },
  ms: (v) => (v == null ? '—' : fmt.dec(v / 1000, 1) + ' s'),
};

function delta(cur, prev, inverse = false) {
  if (cur == null || prev == null || prev === 0) return '<span class="delta flat">—</span>';
  const d = ((cur - prev) / Math.abs(prev)) * 100;
  if (Math.abs(d) < 0.5) return '<span class="delta flat">0%</span>';
  const good = inverse ? d < 0 : d > 0;
  return `<span class="delta ${good ? 'up' : 'down'}">${d > 0 ? '▲' : '▼'} ${fmt.dec(Math.abs(d), Math.abs(d) >= 100 ? 0 : 1)}%</span>`;
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

// ---------- API ----------
async function api(action, params = {}, body = null) {
  const q = new URLSearchParams({ action, ...params });
  if (state.force && !body) q.set('refresh', '1');
  const opts = body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) } : {};
  const res = await fetch('api.php?' + q.toString(), opts);
  const json = await res.json().catch(() => ({ ok: false, error: `Respuesta inválida del servidor (HTTP ${res.status})` }));
  if (json.auth === false) { location.href = 'index.php?r=login'; throw new Error('Sesión vencida'); }
  if (!json.ok) throw new Error(json.error || 'Error desconocido');
  if (json.meta) setMeta(json.meta);
  return json.data;
}
const rp = (extra = {}) => ({ ...state.range, ...extra });

function setMeta(meta) {
  state.lastMeta = meta;
  const el = $('#updated-at');
  if (meta.cached_at) {
    el.textContent = `Datos de Meta ${fmt.ago(meta.cached_at)} (${fmt.datetime(meta.cached_at)})${meta.stale ? ' · ⚠ Meta no respondió, mostrando último dato guardado' : ''}`;
  }
  $('#usage').textContent = meta.usage != null ? `Uso de API: ${meta.usage}%` : '';
}

// ---------- UI helpers ----------
function toast(text, type = 'info') {
  const colors = { info: 'border-blue-500/40', success: 'border-green-500/40', error: 'border-red-500/40' };
  const el = document.createElement('div');
  el.className = `card px-4 py-3 text-sm shadow-xl ${colors[type] || ''}`;
  el.textContent = text;
  $('#toasts').append(el);
  setTimeout(() => el.remove(), type === 'error' ? 9000 : 5000);
}

function modal(html) {
  const m = $('#modal');
  m.firstElementChild.innerHTML = html;
  m.classList.remove('hidden');
  m.onclick = (e) => { if (e.target === m || e.target.closest('[data-close]')) m.classList.add('hidden'); };
}

const skeleton = (n = 4) => `
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">${'<div class="skeleton h-24"></div>'.repeat(n)}</div>
  <div class="skeleton h-72 mb-6"></div><div class="skeleton h-96"></div>`;

const errorBox = (msg, retry = true) => `
  <div class="card p-6 border-red-500/30">
    <div class="text-red-300 font-medium mb-1">No pudimos cargar los datos</div>
    <div class="text-sm text-slate-400 mb-4">${esc(msg)}</div>
    ${retry ? '<button class="btn" onclick="render()">Reintentar</button>' : ''}
    <a class="btn ml-2" href="#/settings">Ir a Configuración</a>
  </div>`;

const empty = (title, text = '', action = '') => `
  <div class="card p-10 text-center">
    <div class="text-4xl mb-3">🗂️</div>
    <div class="font-semibold text-slate-100">${esc(title)}</div>
    <div class="text-sm text-slate-400 mt-1 max-w-lg mx-auto">${text}</div>
    ${action ? `<div class="mt-5">${action}</div>` : ''}
  </div>`;

const kpi = (label, value, d = '', hint = '') => `
  <div class="kpi" ${hint ? `title="${esc(hint)}"` : ''}>
    <div class="lbl">${esc(label)}</div>
    <div class="val">${value}</div>
    <div class="mt-1 h-5">${d}</div>
  </div>`;

function crumbs(items) {
  $('#breadcrumbs').innerHTML = items.map((c, i) => (c.href && i < items.length - 1
    ? `<a class="hover:text-white" href="${c.href}">${esc(c.label)}</a>`
    : `<span class="${i === items.length - 1 ? 'text-slate-100 font-medium' : ''}">${esc(c.label)}</span>`)).join('<span class="mx-2 text-slate-600">/</span>');
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

// ---------- tablas ordenables ----------
/**
 * cols: [{key, label, value:(r)=>num|string, html:(r)=>string, csv:(r)=>any, left:bool, total:(rows)=>string}]
 * opts: {onClick:(r)=>void, sort:'key', dir:'desc', csvName, search:true, filters:[{label, fn}]}
 */
function tableHtml(id, cols, rows, opts = {}) {
  state.tables[id] = { cols, rows, opts, sort: opts.sort || null, dir: opts.dir || 'desc', q: '', filter: 0 };
  const filters = opts.filters || [];
  return `
    <div class="card overflow-hidden" id="tbl-${id}">
      <div class="card-h">
        <h2>${esc(opts.title || '')}</h2>
        ${filters.length ? `<div class="tabs" data-filters>${filters.map((f, i) => `<button class="tab ${i === 0 ? 'active' : ''}" data-f="${i}">${esc(f.label)}</button>`).join('')}</div>` : ''}
        <div class="flex-1"></div>
        ${opts.search !== false ? '<input class="ctl w-48" placeholder="Buscar…" data-search>' : ''}
        ${opts.csvName ? '<button class="btn" data-csv>⬇ CSV</button>' : ''}
      </div>
      <div class="scroll-x max-h-[70vh]"><table class="tbl"><thead></thead><tbody></tbody><tfoot></tfoot></table></div>
    </div>`;
}

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

const cellText = (v) => (typeof v === 'number' ? (Number.isInteger(v) ? fmt.int(v) : fmt.dec(v)) : esc(v ?? '—'));

function mountTable(id) {
  const t = state.tables[id];
  const root = $(`#tbl-${id}`);
  if (!t || !root) return;
  const draw = () => {
    const rows = tableVisibleRows(t);
    $('thead', root).innerHTML = '<tr>' + t.cols.map((c) => `<th data-k="${c.key}" class="${t.sort === c.key ? 'sorted' : ''}" style="${c.left ? 'text-align:left' : ''}">${esc(c.label)}${t.sort === c.key ? (t.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('') + '</tr>';
    $('tbody', root).innerHTML = rows.length
      ? rows.map((r, i) => `<tr data-i="${i}" class="${t.opts.onClick ? 'clickable' : ''}">${t.cols.map((c) => `<td style="${c.left ? 'text-align:left' : ''}">${c.html ? c.html(r) : cellText(c.value ? c.value(r) : r[c.key])}</td>`).join('')}</tr>`).join('')
      : `<tr><td colspan="${t.cols.length}" class="text-center text-slate-500 py-8" style="text-align:center">Sin datos para este período o filtro</td></tr>`;
    $('tfoot', root).innerHTML = t.cols.some((c) => c.total) && rows.length
      ? '<tr>' + t.cols.map((c, i) => `<td style="${c.left ? 'text-align:left' : ''}">${c.total ? c.total(rows) : (i === 0 ? 'Total' : '')}</td>`).join('') + '</tr>'
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
    if (e.target.closest('a,button')) return;
    const tr = e.target.closest('tbody tr[data-i]');
    if (tr && t.opts.onClick) t.opts.onClick(t.visible[+tr.dataset.i]);
  });
  const s = $('[data-search]', root);
  if (s) s.addEventListener('input', () => { t.q = s.value.trim(); draw(); });
  draw();
}

// ---------- gráficos ----------
const PALETTE = ['#3b82f6', '#d946ef', '#22c55e', '#f59e0b', '#06b6d4', '#ef4444', '#a855f7', '#84cc16'];
Chart.defaults.color = '#94a3b8';
Chart.defaults.borderColor = '#1e293b';
Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
Chart.defaults.font.size = 11;

function destroyCharts() {
  state.charts.forEach((c) => c.destroy());
  state.charts = [];
}

/** datasets: [{label, data, type:'bar'|'line', axis:'y'|'y1', fmt}] */
function chart(canvas, labels, datasets, { fmtY, fmtY1, stacked = false, horizontal = false } = {}) {
  if (!canvas) return null;
  const hasY1 = datasets.some((d) => d.axis === 'y1');
  const c = new Chart(canvas, {
    data: {
      labels,
      datasets: datasets.map((d, i) => {
        const color = d.color || PALETTE[i % PALETTE.length];
        return {
          type: d.type || 'line', label: d.label, data: d.data, yAxisID: d.axis || 'y',
          borderColor: color, backgroundColor: d.type === 'bar' ? color + 'b3' : color + '22',
          fill: d.type !== 'bar' && datasets.length === 1, tension: 0.35, pointRadius: labels.length > 45 ? 0 : 2.5,
          borderWidth: 2, borderRadius: 4, order: d.type === 'bar' ? 2 : 1, _fmt: d.fmt,
        };
      }),
    },
    options: {
      indexAxis: horizontal ? 'y' : 'x',
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } },
        tooltip: {
          backgroundColor: '#0b1222', borderColor: '#334155', borderWidth: 1, padding: 10,
          callbacks: { label: (ctx) => `${ctx.dataset.label}: ${(ctx.dataset._fmt || fmt.int)(ctx.parsed[horizontal ? 'x' : 'y'])}` },
        },
      },
      scales: horizontal
        ? { x: { stacked, ticks: { callback: (v) => (fmtY || fmt.compact)(v) }, grid: { color: '#131c2e' } }, y: { stacked, grid: { display: false } } }
        : {
          x: { stacked, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
          y: { stacked, beginAtZero: true, ticks: { callback: (v) => (fmtY || fmt.compact)(v) }, grid: { color: '#131c2e' } },
          ...(hasY1 ? { y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { callback: (v) => (fmtY1 || fmt.compact)(v) } } } : {}),
        },
    },
  });
  state.charts.push(c);
  return c;
}

/** Selector de métrica + gráfico diario para Ads. */
const AD_CHART_METRICS = {
  results: ['Resultados', fmt.int], cost_per_result: ['Costo por resultado', null], purchases: ['Compras', fmt.int],
  purchase_value: ['Valor de compras', null], roas: ['ROAS', fmt.x], messages: ['Conversaciones', fmt.int], leads: ['Leads', fmt.int],
  impressions: ['Impresiones', fmt.int], reach: ['Alcance', fmt.int], link_clicks: ['Clics en enlace', fmt.int],
  link_ctr: ['CTR enlace', fmt.pct], cpc: ['CPC', null], cpm: ['CPM', null], frequency: ['Frecuencia', (v) => fmt.dec(v)],
};

function adsChartBlock(id) {
  return `
    <div class="card mb-6">
      <div class="card-h"><h2>Evolución diaria</h2><div class="flex-1"></div>
        <span class="text-xs text-slate-500">Inversión vs.</span>
        <select class="ctl" id="${id}-metric">${Object.entries(AD_CHART_METRICS).map(([k, [l]]) => `<option value="${k}">${l}</option>`).join('')}</select>
      </div>
      <div class="p-4 h-72"><canvas id="${id}"></canvas></div>
    </div>`;
}

function mountAdsChart(id, daily, currency) {
  const sel = $(`#${id}-metric`);
  if (!sel) return;
  sel.value = store.get('chartMetric', 'results');
  let c;
  const draw = () => {
    if (c) { c.destroy(); state.charts = state.charts.filter((x) => x !== c); }
    const m = sel.value;
    const [label, f] = AD_CHART_METRICS[m];
    const money = (v) => fmt.money(v, currency);
    c = chart($('#' + id), daily.map((d) => fmt.date(d.date)), [
      { label: 'Inversión', data: daily.map((d) => d.spend), type: 'bar', fmt: money, color: '#3b82f6' },
      { label, data: daily.map((d) => d[m]), axis: 'y1', fmt: f || money, color: '#d946ef' },
    ], { fmtY: (v) => fmt.compact(v), fmtY1: f ? undefined : (v) => fmt.compact(v) });
  };
  sel.addEventListener('change', () => { store.set('chartMetric', sel.value); draw(); });
  draw();
}

// ---------- columnas y KPIs de Ads ----------
function sumMetrics(rows) {
  const keys = ['spend', 'impressions', 'reach', 'clicks', 'link_clicks', 'purchases', 'purchase_value', 'messages', 'leads', 'results', 'add_to_cart', 'initiate_checkout'];
  const t = Object.fromEntries(keys.map((k) => [k, rows.reduce((s, r) => s + (r[k] || 0), 0)]));
  const div = (a, b) => (b > 0 ? a / b : null);
  return {
    ...t, cost_per_result: div(t.spend, t.results), link_ctr: div(t.link_clicks * 100, t.impressions), cpc: div(t.spend, t.link_clicks),
    cpm: div(t.spend * 1000, t.impressions), roas: div(t.purchase_value, t.spend), frequency: div(t.impressions, t.reach),
    cost_per_purchase: div(t.spend, t.purchases), cost_per_message: div(t.spend, t.messages), cost_per_lead: div(t.spend, t.leads),
  };
}

/** Columnas de métricas para tablas de campañas/conjuntos/anuncios. get(r) devuelve el objeto de métricas. */
function metricCols(cur, get = (r) => r.metrics) {
  const money = (v) => fmt.money(v, cur);
  const col = (key, label, f, opts = {}) => ({
    key, label, value: (r) => get(r)[key], html: (r) => f(get(r)[key]),
    total: opts.noTotal ? null : (rows) => f(sumMetrics(rows.map(get))[key]), ...opts,
  });
  return [
    col('spend', 'Inversión', money),
    { key: 'results', label: 'Resultados', value: (r) => get(r).results,
      html: (r) => `${fmt.int(get(r).results)} <span class="text-slate-500 text-[10px]">${esc(get(r).result_label || '')}</span>`,
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
}

function adsKpis(m, p, cur) {
  const money = (v) => fmt.money(v, cur);
  const P = p || {};
  const items = [
    ['Inversión', money(m.spend), delta(m.spend, P.spend)],
    ['Resultados', fmt.int(m.results), delta(m.results, P.results)],
    ['Costo por resultado', money(m.cost_per_result), delta(m.cost_per_result, P.cost_per_result, true)],
    ['Compras', fmt.int(m.purchases), delta(m.purchases, P.purchases)],
    ['Facturación (Meta)', money(m.purchase_value), delta(m.purchase_value, P.purchase_value)],
    ['ROAS', fmt.x(m.roas), delta(m.roas, P.roas)],
    ['Conversaciones', fmt.int(m.messages), delta(m.messages, P.messages)],
    ['Leads', fmt.int(m.leads), delta(m.leads, P.leads)],
    ['Alcance', fmt.compact(m.reach), delta(m.reach, P.reach)],
    ['Impresiones', fmt.compact(m.impressions), delta(m.impressions, P.impressions)],
    ['CTR enlace', fmt.pct(m.link_ctr), delta(m.link_ctr, P.link_ctr)],
    ['CPM', money(m.cpm), delta(m.cpm, P.cpm, true)],
  ];
  return `<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6 gap-3 mb-6">${items.map(([l, v, d]) => kpi(l, v, p ? d : '')).join('')}</div>`;
}

const alertsBlock = (alerts) => (alerts && alerts.length ? `
  <div class="card mb-6"><div class="card-h"><h2>Alertas y oportunidades</h2><span class="pill amber">${alerts.length}</span></div>
    <ul class="divide-y divide-slate-800 text-sm max-h-64 overflow-y-auto">${alerts.map((a) => `
      <li class="px-5 py-2.5 flex items-start gap-3">
        <span>${{ danger: '🔴', warning: '🟠', info: '🔵' }[a.level] || '•'}</span>
        <span class="flex-1">${esc(a.text)}</span>
        ${a.account ? `<a class="text-blue-400 text-xs hover:underline" href="#/ads/${esc(a.account)}">Ver</a>` : ''}
      </li>`).join('')}</ul></div>` : '');

// ---------- Vista: resumen general ----------
async function viewOverview() {
  crumbs([{ label: 'Resumen general' }]);
  if (!state.boot.connections) {
    return { html: empty('Conectá tu cuenta de Meta', 'Para ver campañas, páginas e Instagram primero conectá un usuario de Facebook o un token de System User con acceso a tus portfolios comerciales.', '<a class="btn btn-primary" href="#/settings">Ir a Configuración</a>') };
  }
  const d = await api('overview', rp());
  if (!d.accounts.length) {
    return { html: empty('No hay cuentas publicitarias visibles', 'Sincronizá la conexión o revisá en Configuración si las ocultaste.', '<a class="btn" href="#/settings">Configuración</a>') };
  }
  const currencies = d.totals.map((t) => t.currency);
  let cur = store.get('ovCurrency', currencies[0]);
  if (!currencies.includes(cur)) cur = currencies[0];
  const total = d.totals.find((t) => t.currency === cur);

  const cols = [
    { key: 'name', label: 'Cuenta', left: true, value: (r) => r.name,
      html: (r) => `<div class="font-medium text-slate-100">${esc(r.name)}</div><div class="text-[11px] text-slate-500">${esc(r.business || 'Sin portfolio')} · ${esc(r.currency)}</div>` },
    { key: 'status', label: 'Estado', value: (r) => r.status_label, html: (r) => `<span class="pill ${[1, 201].includes(r.status) ? 'green' : 'red'}">${esc(r.status_label)}</span>` },
    { key: 'active_campaigns', label: 'Campañas activas', value: (r) => r.active_campaigns, html: (r) => fmt.int(r.active_campaigns) },
    { key: 'spend', label: 'Inversión', value: (r) => r.metrics.spend, html: (r) => `${fmt.money(r.metrics.spend, r.currency)} ${delta(r.metrics.spend, r.previous.spend)}` },
    { key: 'results', label: 'Resultados', value: (r) => r.metrics.results, html: (r) => `${fmt.int(r.metrics.results)} <span class="text-[10px] text-slate-500">${esc(r.metrics.result_label)}</span>` },
    { key: 'cpr', label: 'Costo/resultado', value: (r) => r.metrics.cost_per_result, html: (r) => `${fmt.money(r.metrics.cost_per_result, r.currency)} ${delta(r.metrics.cost_per_result, r.previous.cost_per_result, true)}` },
    { key: 'purchases', label: 'Compras', value: (r) => r.metrics.purchases, html: (r) => fmt.int(r.metrics.purchases) },
    { key: 'roas', label: 'ROAS', value: (r) => r.metrics.roas, html: (r) => fmt.x(r.metrics.roas) },
    { key: 'messages', label: 'Conversaciones', value: (r) => r.metrics.messages, html: (r) => fmt.int(r.metrics.messages) },
    { key: 'ctr', label: 'CTR enlace', value: (r) => r.metrics.link_ctr, html: (r) => fmt.pct(r.metrics.link_ctr) },
    { key: 'cpm', label: 'CPM', value: (r) => r.metrics.cpm, html: (r) => fmt.money(r.metrics.cpm, r.currency) },
  ];

  const html = `
    <div class="flex flex-wrap items-end gap-3 mb-5">
      <div><h1 class="text-xl font-semibold text-white">Resumen general</h1>
        <p class="text-sm text-slate-400">${d.accounts.length} cuentas publicitarias · ${esc(d.range.since)} → ${esc(d.range.until)} · comparado con los ${d.range.days} días anteriores</p></div>
      <div class="flex-1"></div>
      ${currencies.length > 1 ? `<div class="tabs" id="cur-tabs">${currencies.map((c) => `<button class="tab ${c === cur ? 'active' : ''}" data-cur="${esc(c)}">${esc(c)}</button>`).join('')}</div>` : ''}
    </div>
    ${adsKpis(total.metrics, total.previous, cur)}
    <div class="grid xl:grid-cols-3 gap-6 mb-6">
      <div class="card xl:col-span-2"><div class="card-h"><h2>Inversión y resultados por día (${esc(cur)})</h2></div><div class="p-4 h-72"><canvas id="ov-chart"></canvas></div></div>
      <div class="card"><div class="card-h"><h2>Distribución de inversión</h2></div><div class="p-4 h-72"><canvas id="ov-pie"></canvas></div></div>
    </div>
    ${alertsBlock(d.alerts)}
    ${tableHtml('ov', cols, d.accounts, { title: 'Cuentas publicitarias', sort: 'spend', csvName: 'cuentas', onClick: (r) => (location.hash = `#/ads/${r.id}`),
      filters: [{ label: 'Todas' }, { label: 'Con inversión', fn: (r) => r.metrics.spend > 0 }, { label: 'Con problemas', fn: (r) => ![1, 201].includes(r.status) || r.error }] })}
    <div class="grid sm:grid-cols-2 gap-3 mt-6">
      <a href="#/pages" class="card p-5 hover:border-blue-500/50 transition"><div class="text-sm text-slate-400">Páginas de Facebook</div><div class="text-2xl font-bold text-white mt-1">${state.boot.counts.page}</div></a>
      <a href="#/ig" class="card p-5 hover:border-fuchsia-500/50 transition"><div class="text-sm text-slate-400">Cuentas de Instagram</div><div class="text-2xl font-bold text-white mt-1">${state.boot.counts.ig_account}</div></a>
    </div>`;

  return {
    html,
    mount() {
      mountTable('ov');
      const series = d.daily[cur] || {};
      const days = Object.keys(series);
      chart($('#ov-chart'), days.map(fmt.date), [
        { label: 'Inversión', data: days.map((k) => series[k].spend), type: 'bar', fmt: (v) => fmt.money(v, cur) },
        { label: 'Resultados', data: days.map((k) => series[k].results), axis: 'y1', color: '#d946ef' },
        { label: 'Facturación', data: days.map((k) => series[k].purchase_value), fmt: (v) => fmt.money(v, cur), color: '#22c55e' },
      ]);
      const top = d.accounts.filter((a) => a.currency === cur && a.metrics.spend > 0).slice(0, 7);
      const rest = d.accounts.filter((a) => a.currency === cur && a.metrics.spend > 0).slice(7).reduce((s, a) => s + a.metrics.spend, 0);
      const labels = [...top.map((a) => a.name), ...(rest ? ['Otras'] : [])];
      const values = [...top.map((a) => a.metrics.spend), ...(rest ? [rest] : [])];
      if (values.length) {
        state.charts.push(new Chart($('#ov-pie'), {
          type: 'doughnut',
          data: { labels, datasets: [{ data: values, backgroundColor: PALETTE, borderColor: '#0f172a', borderWidth: 2 }] },
          options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } },
            tooltip: { callbacks: { label: (c) => `${c.label}: ${fmt.money(c.parsed, cur)}` } } } },
        }));
      }
      $$('#cur-tabs [data-cur]').forEach((b) => b.addEventListener('click', () => { store.set('ovCurrency', b.dataset.cur); render({ silent: true }); }));
    },
  };
}

// ---------- Vista: listado de cuentas publicitarias ----------
async function viewAdAccounts() {
  crumbs([{ label: 'Campañas y anuncios' }]);
  const a = await api('assets');
  if (!a.ad_accounts.length) return { html: empty('Sin cuentas publicitarias', 'Conectá Meta o sincronizá desde Configuración.', '<a class="btn btn-primary" href="#/settings">Configuración</a>') };
  const groups = {};
  a.ad_accounts.forEach((acc) => {
    const b = acc.data.business?.name || 'Sin portfolio comercial';
    (groups[b] ||= []).push(acc);
  });
  const statusOk = (s) => [1, 201].includes(+s);
  const html = `
    <div class="flex flex-wrap items-center gap-3 mb-5">
      <h1 class="text-xl font-semibold text-white">Cuentas publicitarias</h1><span class="text-sm text-slate-500">${a.ad_accounts.length} cuentas en ${Object.keys(groups).length} portfolios</span>
      <div class="flex-1"></div><input class="ctl w-64" placeholder="Buscar cuenta…" id="acc-search">
    </div>
    ${Object.entries(groups).sort(([x], [y]) => x.localeCompare(y)).map(([biz, list]) => `
      <section class="mb-7" data-group>
        <h2 class="text-xs uppercase tracking-wider text-slate-500 mb-2">🏢 ${esc(biz)}</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3">${list.map((acc) => `
          <a href="#/ads/${esc(acc.external_id)}" data-name="${esc(acc.name.toLowerCase())} ${esc(acc.external_id)}" class="card p-4 hover:border-blue-500/50 transition block">
            <div class="flex items-start gap-2"><div class="font-medium text-slate-100 flex-1 line-clamp-2">${esc(acc.name)}</div>
              <span class="pill ${statusOk(acc.data.account_status) ? 'green' : 'red'}">${statusOk(acc.data.account_status) ? 'Activa' : 'Revisar'}</span></div>
            <div class="text-xs text-slate-500 mt-2">${esc(acc.external_id)} · ${esc(acc.data.currency || '')} · ${esc(acc.data.timezone_name || '')}</div>
            <div class="text-xs text-slate-400 mt-1">Gasto histórico: ${fmt.money((acc.data.amount_spent || 0) / 100, acc.data.currency)}</div>
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

// ---------- desgloses ----------
const BREAKDOWNS = { age_gender: 'Edad y género', age: 'Edad', gender: 'Género', platform: 'Plataforma', placement: 'Ubicación', device: 'Dispositivo', region: 'Provincia/región', country: 'País', hour: 'Hora del día' };

function breakdownBlock(id) {
  return `
    <div class="card mb-6" id="${id}">
      <div class="card-h"><h2>Desgloses</h2><div class="tabs">${Object.entries(BREAKDOWNS).map(([k, l], i) => `<button class="tab ${i === 0 ? 'active' : ''}" data-bd="${k}">${l}</button>`).join('')}</div></div>
      <div class="grid lg:grid-cols-5 gap-0">
        <div class="lg:col-span-2 p-4 h-80 border-b lg:border-b-0 lg:border-r border-slate-800"><canvas></canvas></div>
        <div class="lg:col-span-3 scroll-x max-h-80" data-bd-table><div class="p-6 text-sm text-slate-500">Cargando…</div></div>
      </div>
    </div>`;
}

function mountBreakdown(id, objectId, accountId, currency, objective) {
  const root = $('#' + id);
  if (!root) return;
  let c;
  const load = async (type) => {
    $$('[data-bd]', root).forEach((b) => b.classList.toggle('active', b.dataset.bd === type));
    $('[data-bd-table]', root).innerHTML = '<div class="p-6 text-sm text-slate-500">Cargando…</div>';
    try {
      const rows = await api('breakdown', rp({ id: objectId, account: accountId, type, objective: objective || '' }));
      if (type === 'hour') rows.sort((a, b) => a.label.localeCompare(b.label));
      if (type === 'age' || type === 'age_gender') rows.sort((a, b) => a.label.localeCompare(b.label));
      if (c) { c.destroy(); state.charts = state.charts.filter((x) => x !== c); }
      const top = type === 'hour' || type.startsWith('age') ? rows : rows.slice(0, 12);
      c = chart($('canvas', root), top.map((r) => r.label), [
        { label: 'Inversión', data: top.map((r) => r.spend), type: 'bar', fmt: (v) => fmt.money(v, currency) },
        { label: 'Resultados', data: top.map((r) => r.results), type: 'bar', axis: type === 'hour' ? 'y1' : undefined, color: '#d946ef' },
      ], { horizontal: type !== 'hour' });
      const money = (v) => fmt.money(v, currency);
      $('[data-bd-table]', root).innerHTML = `<table class="tbl"><thead><tr><th>Segmento</th><th>Inversión</th><th>Resultados</th><th>Costo/res.</th><th>Impresiones</th><th>CTR enlace</th><th>CPM</th><th>ROAS</th></tr></thead><tbody>
        ${rows.map((r) => `<tr><td>${esc(r.label)}</td><td>${money(r.spend)}</td><td>${fmt.int(r.results)}</td><td>${money(r.cost_per_result)}</td><td>${fmt.int(r.impressions)}</td><td>${fmt.pct(r.link_ctr)}</td><td>${money(r.cpm)}</td><td>${fmt.x(r.roas)}</td></tr>`).join('') || '<tr><td colspan="8" style="text-align:center" class="py-6 text-slate-500">Sin datos</td></tr>'}
      </tbody></table>`;
    } catch (e) {
      $('[data-bd-table]', root).innerHTML = `<div class="p-6 text-sm text-red-300">${esc(e.message)}</div>`;
    }
  };
  root.addEventListener('click', (e) => { const b = e.target.closest('[data-bd]'); if (b) load(b.dataset.bd); });
  load('age_gender');
}

// ---------- Vista: cuenta publicitaria ----------
const entityName = (r, sub = '') => `<div class="font-medium text-slate-100 max-w-[340px] truncate" title="${esc(r.name)}">${esc(r.name)}</div><div class="text-[11px] text-slate-500 flex gap-2 items-center">${statusPill(r.effective_status)} ${sub}</div>`;
const budgetText = (r, cur) => (r.budget ? `${fmt.money(r.budget, cur)} <span class="text-[10px] text-slate-500">${esc(r.budget_type)}</span>` : '<span class="text-slate-600">—</span>');
const liveFilters = [
  { label: 'Todas' },
  { label: 'Activas', fn: (r) => r.effective_status === 'ACTIVE' },
  { label: 'Con inversión', fn: (r) => r.metrics.spend > 0 },
  { label: 'Con problemas', fn: (r) => ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_BILLING_INFO'].includes(r.effective_status) },
];

async function viewAccount(accountId) {
  const d = await api('account', rp({ id: accountId }));
  const a = d.account;
  const cur = a.currency;
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: a.name }]);
  const cols = [
    { key: 'name', label: 'Campaña', left: true, value: (r) => r.name, html: (r) => entityName(r, esc(OBJECTIVES[r.objective] || r.objective || '')) },
    { key: 'budget', label: 'Presupuesto', value: (r) => r.budget, html: (r) => budgetText(r, cur) },
    ...metricCols(cur),
  ];
  const capPct = a.spend_cap ? Math.min(100, (a.amount_spent / a.spend_cap) * 100) : null;
  const html = `
    <div class="flex flex-wrap items-start gap-4 mb-5">
      <div class="min-w-0">
        <h1 class="text-xl font-semibold text-white">${esc(a.name)}</h1>
        <div class="text-sm text-slate-400 mt-1 flex flex-wrap gap-x-4 gap-y-1">
          <span class="pill ${[1, 201].includes(a.status) ? 'green' : 'red'}">${esc(a.status_label)}</span>
          <span>${esc(a.id)}</span><span>${esc(a.business || 'Sin portfolio')}</span><span>${esc(cur)} · ${esc(a.timezone || '')}</span>
          ${a.payment ? `<span>💳 ${esc(a.payment)}</span>` : ''}
        </div>
      </div>
      <div class="flex-1"></div>
      <a class="btn" target="_blank" rel="noopener" href="https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=${esc(a.id.replace('act_', ''))}">Abrir en Ads Manager ↗</a>
    </div>
    ${capPct !== null ? `<div class="card p-4 mb-6"><div class="flex text-xs text-slate-400 mb-2"><span>Límite de gasto de la cuenta</span><span class="flex-1"></span><span>${fmt.money(a.amount_spent, cur)} / ${fmt.money(a.spend_cap, cur)}</span></div><div class="h-2 bg-slate-800 rounded-full overflow-hidden"><div class="h-full ${capPct > 90 ? 'bg-red-500' : 'bg-blue-500'}" style="width:${capPct}%"></div></div></div>` : ''}
    ${adsKpis(d.metrics, d.previous, cur)}
    ${adsChartBlock('acc-chart')}
    ${alertsBlock(d.alerts)}
    ${tableHtml('camps', cols, d.campaigns, { title: `Campañas (${d.campaigns.length})`, sort: 'spend', csvName: 'campanas', filters: liveFilters,
      onClick: (r) => (location.hash = `#/ads/${accountId}/c/${r.id}`) })}
    <div class="mt-6">${breakdownBlock('acc-bd')}</div>`;
  return { html, mount() { mountTable('camps'); mountAdsChart('acc-chart', d.daily, cur); mountBreakdown('acc-bd', accountId, accountId, cur); } };
}

// ---------- Vista: campaña ----------
async function viewCampaign(accountId, campaignId) {
  const [acc, adsets] = await Promise.all([api('account', rp({ id: accountId })), api('adsets', rp({ id: campaignId, account: accountId }))]);
  const camp = acc.campaigns.find((c) => c.id === campaignId);
  if (!camp) return { html: empty('Campaña no encontrada', 'Puede estar archivada o eliminada.') };
  const cur = acc.account.currency;
  const daily = await api('timeseries', rp({ id: campaignId, account: accountId, objective: camp.objective || '' }));
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: acc.account.name, href: `#/ads/${accountId}` }, { label: camp.name }]);
  const cols = [
    { key: 'name', label: 'Conjunto de anuncios', left: true, value: (r) => r.name, html: (r) => entityName(r, `<span class="truncate max-w-[220px]" title="${esc(r.targeting)}">${esc(r.targeting)}</span>`) },
    { key: 'budget', label: 'Presupuesto', value: (r) => r.budget, html: (r) => budgetText(r, cur) },
    ...metricCols(cur),
  ];
  const html = `
    ${entityHeader(camp, cur, `Objetivo: ${OBJECTIVES[camp.objective] || camp.objective || '—'}`)}
    ${adsKpis(camp.metrics, null, cur)}
    ${adsChartBlock('camp-chart')}
    ${tableHtml('adsets', cols, adsets, { title: `Conjuntos de anuncios (${adsets.length})`, sort: 'spend', csvName: 'conjuntos', filters: liveFilters,
      onClick: (r) => (location.hash = `#/ads/${accountId}/c/${campaignId}/s/${r.id}`) })}
    <div class="mt-6" id="camp-ads"></div>
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

// ---------- Vista: conjunto de anuncios ----------
async function viewAdset(accountId, campaignId, adsetId) {
  const [acc, adsets] = await Promise.all([api('account', rp({ id: accountId })), api('adsets', rp({ id: campaignId, account: accountId }))]);
  const set = adsets.find((s) => s.id === adsetId);
  const camp = acc.campaigns.find((c) => c.id === campaignId);
  if (!set) return { html: empty('Conjunto no encontrado') };
  const cur = acc.account.currency;
  const daily = await api('timeseries', rp({ id: adsetId, account: accountId, objective: set.objective || '' }));
  crumbs([{ label: 'Campañas', href: '#/ads' }, { label: acc.account.name, href: `#/ads/${accountId}` }, { label: camp ? camp.name : 'Campaña', href: `#/ads/${accountId}/c/${campaignId}` }, { label: set.name }]);
  const html = `
    ${entityHeader(set, cur, set.targeting)}
    ${adsKpis(set.metrics, null, cur)}
    ${adsChartBlock('set-chart')}
    <div id="set-ads"></div>
    <div class="mt-6">${breakdownBlock('set-bd')}</div>`;
  return {
    html,
    mount() {
      mountAdsChart('set-chart', daily, cur);
      mountBreakdown('set-bd', adsetId, accountId, cur, set.objective);
      loadAds('set-ads', adsetId, accountId, cur);
    },
  };
}

function entityHeader(e, cur, sub) {
  return `
    <div class="flex flex-wrap items-start gap-4 mb-5">
      <div class="min-w-0"><h1 class="text-xl font-semibold text-white">${esc(e.name)}</h1>
        <div class="text-sm text-slate-400 mt-1 flex flex-wrap gap-x-4 gap-y-1 items-center">${statusPill(e.effective_status)}
          <span>${esc(sub || '')}</span>
          ${e.budget ? `<span>Presupuesto ${budgetText(e, cur)}</span>` : ''}
          ${e.start_time ? `<span>Inicio ${fmt.date(e.start_time)}</span>` : ''}${e.stop_time ? `<span>Fin ${fmt.date(e.stop_time)}</span>` : ''}
          <span class="text-slate-600">ID ${esc(e.id)}</span>
        </div></div>
    </div>`;
}

// ---------- anuncios (creativos) ----------
async function loadAds(containerId, parentId, accountId, cur) {
  const box = $('#' + containerId);
  box.innerHTML = '<div class="skeleton h-64"></div>';
  let ads;
  try { ads = await api('ads', rp({ id: parentId, account: accountId })); } catch (e) { box.innerHTML = errorBox(e.message, false); return; }
  const sortKeys = { spend: 'Inversión', results: 'Resultados', cost_per_result: 'Costo/resultado', link_ctr: 'CTR', roas: 'ROAS' };
  let sortBy = store.get('adsSort', 'spend');
  let viewMode = store.get('adsView', 'cards');
  const money = (v) => fmt.money(v, cur);
  const cols = [
    { key: 'name', label: 'Anuncio', left: true, value: (r) => r.name, html: (r) => `<div class="flex items-center gap-3">${r.creative.thumbnail ? `<img src="${esc(r.creative.thumbnail)}" class="w-10 h-10 rounded object-cover" loading="lazy" alt="">` : ''}${entityName(r)}</div>` },
    ...metricCols(cur),
  ];
  const draw = () => {
    const sorted = [...ads].sort((a, b) => {
      const va = a.metrics[sortBy] ?? (sortBy === 'cost_per_result' ? Infinity : -Infinity);
      const vb = b.metrics[sortBy] ?? (sortBy === 'cost_per_result' ? Infinity : -Infinity);
      return sortBy === 'cost_per_result' ? va - vb : vb - va;
    });
    const header = `
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <h2 class="font-semibold text-slate-100">Anuncios (${ads.length})</h2><div class="flex-1"></div>
        <span class="text-xs text-slate-500">Ordenar por</span>
        <select class="ctl" data-sort>${Object.entries(sortKeys).map(([k, l]) => `<option value="${k}" ${k === sortBy ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <div class="tabs"><button class="tab ${viewMode === 'cards' ? 'active' : ''}" data-mode="cards">Tarjetas</button><button class="tab ${viewMode === 'table' ? 'active' : ''}" data-mode="table">Tabla</button></div>
      </div>`;
    if (viewMode === 'table') {
      box.innerHTML = header + tableHtml('ads-' + containerId, cols, sorted, { csvName: 'anuncios', filters: liveFilters, onClick: (r) => adModal(r, cur) });
      mountTable('ads-' + containerId);
    } else {
      box.innerHTML = header + (sorted.length ? `<div class="grid sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">${sorted.map((ad, i) => `
        <div class="card overflow-hidden flex flex-col cursor-pointer hover:border-blue-500/50 transition" data-ad="${i}">
          <div class="relative bg-slate-800 aspect-square">${ad.creative.thumbnail ? `<img src="${esc(ad.creative.thumbnail)}" class="w-full h-full object-cover" loading="lazy" alt="">` : '<div class="grid place-items-center h-full text-slate-600">Sin vista previa</div>'}
            <div class="absolute top-2 left-2">${statusPill(ad.effective_status)}</div>${i < 3 && ad.metrics.spend > 0 ? `<div class="absolute top-2 right-2 pill blue">#${i + 1}</div>` : ''}</div>
          <div class="p-4 flex-1 flex flex-col">
            <div class="font-medium text-slate-100 text-sm line-clamp-2">${esc(ad.name)}</div>
            ${ad.creative.body ? `<div class="text-xs text-slate-500 mt-1 line-clamp-2">${esc(ad.creative.body)}</div>` : ''}
            <div class="grid grid-cols-3 gap-2 mt-3 text-center">
              <div><div class="text-[10px] text-slate-500 uppercase">Inversión</div><div class="text-sm font-semibold">${money(ad.metrics.spend)}</div></div>
              <div><div class="text-[10px] text-slate-500 uppercase">${esc(ad.metrics.result_label)}</div><div class="text-sm font-semibold">${fmt.int(ad.metrics.results)}</div></div>
              <div><div class="text-[10px] text-slate-500 uppercase">Costo/res.</div><div class="text-sm font-semibold">${money(ad.metrics.cost_per_result)}</div></div>
              <div><div class="text-[10px] text-slate-500 uppercase">CTR</div><div class="text-sm">${fmt.pct(ad.metrics.link_ctr)}</div></div>
              <div><div class="text-[10px] text-slate-500 uppercase">CPM</div><div class="text-sm">${money(ad.metrics.cpm)}</div></div>
              <div><div class="text-[10px] text-slate-500 uppercase">ROAS</div><div class="text-sm">${fmt.x(ad.metrics.roas)}</div></div>
            </div>
          </div>
        </div>`).join('')}</div>` : empty('Sin anuncios en este período'));
      $$('[data-ad]', box).forEach((el) => el.addEventListener('click', () => adModal(sorted[+el.dataset.ad], cur)));
    }
    $('[data-sort]', box).addEventListener('change', (e) => { sortBy = e.target.value; store.set('adsSort', sortBy); draw(); });
    $$('[data-mode]', box).forEach((b) => b.addEventListener('click', () => { viewMode = b.dataset.mode; store.set('adsView', viewMode); draw(); }));
  };
  draw();
}

function adModal(ad, cur) {
  const m = ad.metrics;
  const money = (v) => fmt.money(v, cur);
  const rows = [
    ['Inversión', money(m.spend)], [m.result_label, fmt.int(m.results)], ['Costo por resultado', money(m.cost_per_result)],
    ['Alcance', fmt.int(m.reach)], ['Impresiones', fmt.int(m.impressions)], ['Frecuencia', fmt.dec(m.frequency)],
    ['Clics en enlace', fmt.int(m.link_clicks)], ['CTR enlace', fmt.pct(m.link_ctr)], ['CPC', money(m.cpc)], ['CPM', money(m.cpm)],
    ['Visitas a landing', fmt.int(m.landing_page_views)], ['Agregados al carrito', fmt.int(m.add_to_cart)], ['Pagos iniciados', fmt.int(m.initiate_checkout)],
    ['Compras', fmt.int(m.purchases)], ['Valor de compras', money(m.purchase_value)], ['ROAS', fmt.x(m.roas)],
    ['Conversaciones', fmt.int(m.messages)], ['Leads', fmt.int(m.leads)], ['Reproducciones (3s)', fmt.int(m.video_views)],
  ];
  const postUrl = ad.creative.post_id ? `https://www.facebook.com/${ad.creative.post_id}` : null;
  modal(`
    <div class="card-h"><h2 class="flex-1">${esc(ad.name)}</h2>${statusPill(ad.effective_status)}<button class="btn" data-close>✕</button></div>
    <div class="grid md:grid-cols-2 gap-6 p-5">
      <div>
        ${ad.creative.thumbnail ? `<img src="${esc(ad.creative.thumbnail)}" class="w-full rounded-xl bg-slate-800" alt="">` : ''}
        ${ad.creative.title ? `<div class="mt-3 font-semibold text-slate-100">${esc(ad.creative.title)}</div>` : ''}
        ${ad.creative.body ? `<p class="mt-2 text-sm text-slate-300 whitespace-pre-line">${esc(ad.creative.body)}</p>` : ''}
        ${ad.creative.cta ? `<div class="mt-2 text-xs text-slate-500">CTA: ${esc(ad.creative.cta)}</div>` : ''}
        ${ad.review_feedback ? `<div class="mt-3 text-xs text-red-300 bg-red-500/10 rounded-lg p-3">Revisión: ${esc(JSON.stringify(ad.review_feedback))}</div>` : ''}
        <div class="flex flex-wrap gap-2 mt-4">
          ${ad.preview_link ? `<a class="btn" target="_blank" rel="noopener" href="${esc(ad.preview_link)}">Vista previa ↗</a>` : ''}
          ${postUrl ? `<a class="btn" target="_blank" rel="noopener" href="${esc(postUrl)}">Publicación en Facebook ↗</a>` : ''}
          ${ad.creative.ig_link ? `<a class="btn" target="_blank" rel="noopener" href="${esc(ad.creative.ig_link)}">Ver en Instagram ↗</a>` : ''}
        </div>
      </div>
      <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm content-start">${rows.map(([k, v]) => `<dt class="text-slate-500">${esc(k)}</dt><dd class="text-right font-medium text-slate-100">${v}</dd>`).join('')}</dl>
    </div>`);
}

// ---------- Páginas de Facebook ----------
function assetGrid(list, hrefFn, sub) {
  return `<div class="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3">${list.map((x) => `
    <a href="${hrefFn(x)}" class="card p-4 flex items-center gap-3 hover:border-blue-500/50 transition" data-name="${esc(x.name.toLowerCase())}">
      ${x.data.picture || x.data.profile_picture_url ? `<img src="${esc(x.data.picture || x.data.profile_picture_url)}" class="w-12 h-12 rounded-full object-cover bg-slate-800" loading="lazy" alt="">` : '<div class="w-12 h-12 rounded-full bg-slate-800"></div>'}
      <div class="min-w-0"><div class="font-medium text-slate-100 truncate">${esc(x.name)}</div><div class="text-xs text-slate-500 truncate">${sub(x)}</div></div>
    </a>`).join('')}</div>`;
}

async function viewPages() {
  crumbs([{ label: 'Páginas de Facebook' }]);
  const a = await api('assets');
  if (!a.pages.length) return { html: empty('Sin páginas', 'El usuario conectado necesita rol en las páginas (o el System User tenerlas asignadas).', '<a class="btn" href="#/settings">Configuración</a>') };
  return {
    html: `<div class="flex items-center gap-3 mb-5"><h1 class="text-xl font-semibold text-white">Páginas de Facebook</h1><span class="text-sm text-slate-500">${a.pages.length}</span><div class="flex-1"></div><input class="ctl w-64" placeholder="Buscar…" id="pg-search"></div>
      ${assetGrid(a.pages, (p) => `#/pages/${p.external_id}`, (p) => `${fmt.int(p.data.followers_count)} seguidores · ${esc(p.data.category || '')}`)}`,
    mount: () => searchCards('#pg-search'),
  };
}

function searchCards(sel) {
  $(sel).addEventListener('input', (e) => { const q = e.target.value.toLowerCase(); $$('[data-name]').forEach((el) => { el.style.display = el.dataset.name.includes(q) ? '' : 'none'; }); });
}

function seriesChartBlock(id, title, series, labels, preferred) {
  const keys = Object.keys(series);
  if (!keys.length) return '';
  const sel = preferred.filter((k) => keys.includes(k)).slice(0, 2);
  return `<div class="card mb-6"><div class="card-h"><h2>${esc(title)}</h2><div class="flex-1"></div>
    <select class="ctl" id="${id}-a">${keys.map((k) => `<option value="${k}" ${k === sel[0] ? 'selected' : ''}>${esc(labels[k] || k)}</option>`).join('')}</select>
    <select class="ctl" id="${id}-b"><option value="">—</option>${keys.map((k) => `<option value="${k}" ${k === sel[1] ? 'selected' : ''}>${esc(labels[k] || k)}</option>`).join('')}</select>
    </div><div class="p-4 h-72"><canvas id="${id}"></canvas></div></div>`;
}

function mountSeriesChart(id, series, labels) {
  const a = $(`#${id}-a`), b = $(`#${id}-b`);
  if (!a) return;
  let c;
  const draw = () => {
    if (c) { c.destroy(); state.charts = state.charts.filter((x) => x !== c); }
    const days = [...new Set(Object.values(series).flatMap((s) => Object.keys(s)))].sort();
    const ds = [{ label: labels[a.value] || a.value, data: days.map((d) => series[a.value]?.[d] ?? null), type: 'bar' }];
    if (b.value) ds.push({ label: labels[b.value] || b.value, data: days.map((d) => series[b.value]?.[d] ?? null), axis: 'y1', color: '#d946ef' });
    c = chart($('#' + id), days.map(fmt.date), ds);
  };
  a.addEventListener('change', draw);
  b.addEventListener('change', draw);
  draw();
}

function followersBlock(id, history) {
  if (!history || history.length < 2) {
    return `<div class="card p-4 mb-6 text-xs text-slate-500">📈 La evolución de seguidores se arma con la foto diaria del cron. Aparecerá a partir del segundo día de funcionamiento.</div>`;
  }
  return `<div class="card mb-6"><div class="card-h"><h2>Evolución de seguidores</h2><span class="text-xs text-slate-500">${history.length} días registrados</span></div><div class="p-4 h-60"><canvas id="${id}"></canvas></div></div>`;
}

function mountFollowers(id, history) {
  if (!$('#' + id)) return;
  chart($('#' + id), history.map((h) => fmt.date(h.date)), [{ label: 'Seguidores', data: history.map((h) => h.followers), color: '#22c55e' }]);
  state.charts.at(-1).options.scales.y.beginAtZero = false;
  state.charts.at(-1).update();
}

async function viewPage(pageId) {
  const [d, posts] = await Promise.all([api('page', rp({ id: pageId })), api('page_posts', rp({ id: pageId }))]);
  const p = d.page;
  crumbs([{ label: 'Páginas', href: '#/pages' }, { label: p.name }]);
  const t = d.totals;
  const pick = (...keys) => keys.map((k) => t[k]).find((v) => v != null);
  const interactionsPosts = posts.reduce((s, x) => s + x.interactions, 0);
  const kpis = [
    ['Seguidores', fmt.int(p.followers)],
    ['Visualizaciones', fmt.compact(pick('page_media_view', 'page_impressions'))],
    ['Espectadores / alcance', fmt.compact(pick('page_total_media_view_unique', 'page_impressions_unique'))],
    ['Interacciones', fmt.int(pick('page_post_engagements') ?? interactionsPosts)],
    ['Nuevos seguidores', fmt.int(t.page_daily_follows_unique)],
    ['Dejaron de seguir', fmt.int(t.page_daily_unfollows_unique)],
    ['Visitas a la página', fmt.int(t.page_views_total)],
    ['Posteos en el período', fmt.int(posts.length)],
  ].filter(([, v]) => v !== '—');

  const cols = [
    { key: 'message', label: 'Publicación', left: true, value: (r) => r.message,
      html: (r) => `<div class="flex items-center gap-3 max-w-[420px]">${r.picture ? `<img src="${esc(r.picture)}" class="w-12 h-12 rounded object-cover shrink-0" loading="lazy" alt="">` : '<div class="w-12 h-12 rounded bg-slate-800 shrink-0"></div>'}<div class="min-w-0"><div class="truncate text-slate-100">${esc(r.message || '(sin texto)')}</div><div class="text-[11px] text-slate-500">${fmt.datetime(r.created_time)} · ${r.permalink ? `<a class="text-blue-400 hover:underline" target="_blank" rel="noopener" href="${esc(r.permalink)}">ver ↗</a>` : ''}</div></div></div>` },
    { key: 'views', label: 'Visualizaciones', value: (r) => r.views, html: (r) => fmt.int(r.views) },
    { key: 'reach', label: 'Alcance', value: (r) => r.reach, html: (r) => fmt.int(r.reach) },
    { key: 'reactions', label: 'Reacciones', value: (r) => r.reactions, html: (r) => fmt.int(r.reactions), total: (rows) => fmt.int(rows.reduce((s, r) => s + r.reactions, 0)) },
    { key: 'comments', label: 'Comentarios', value: (r) => r.comments, html: (r) => fmt.int(r.comments), total: (rows) => fmt.int(rows.reduce((s, r) => s + r.comments, 0)) },
    { key: 'shares', label: 'Compartidos', value: (r) => r.shares, html: (r) => fmt.int(r.shares), total: (rows) => fmt.int(rows.reduce((s, r) => s + r.shares, 0)) },
    { key: 'clicks', label: 'Clics', value: (r) => r.clicks, html: (r) => fmt.int(r.clicks) },
    { key: 'video_views', label: 'Reproducciones', value: (r) => r.video_views, html: (r) => fmt.int(r.video_views) },
    { key: 'engagement_rate', label: 'Engagement', value: (r) => r.engagement_rate, html: (r) => fmt.pct(r.engagement_rate) },
  ];

  const html = `
    <div class="flex items-center gap-4 mb-5">
      ${p.picture ? `<img src="${esc(p.picture)}" class="w-14 h-14 rounded-full" alt="">` : ''}
      <div><h1 class="text-xl font-semibold text-white">${esc(p.name)}</h1><div class="text-sm text-slate-400">${esc(p.category || '')}</div></div>
      <div class="flex-1"></div>${p.link ? `<a class="btn" target="_blank" rel="noopener" href="${esc(p.link)}">Abrir página ↗</a>` : ''}
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">${kpis.map(([l, v]) => kpi(l, v)).join('')}</div>
    ${seriesChartBlock('pg-chart', 'Métricas diarias de la página', d.series, d.labels, ['page_media_view', 'page_impressions', 'page_post_engagements', 'page_daily_follows_unique'])}
    ${followersBlock('pg-followers', d.followers_history)}
    ${tableHtml('posts', cols, posts, { title: `Publicaciones (${posts.length})`, sort: 'reach', csvName: 'posteos-facebook', onClick: (r) => r.permalink && window.open(r.permalink, '_blank', 'noopener') })}`;
  return { html, mount() { mountSeriesChart('pg-chart', d.series, d.labels); mountFollowers('pg-followers', d.followers_history); mountTable('posts'); } };
}

// ---------- Instagram ----------
async function viewIgList() {
  crumbs([{ label: 'Instagram' }]);
  const a = await api('assets');
  if (!a.ig_accounts.length) return { html: empty('Sin cuentas de Instagram', 'Las cuentas deben ser profesionales (empresa o creador) y estar vinculadas a una página de Facebook.', '<a class="btn" href="#/settings">Configuración</a>') };
  return {
    html: `<div class="flex items-center gap-3 mb-5"><h1 class="text-xl font-semibold text-white">Instagram</h1><span class="text-sm text-slate-500">${a.ig_accounts.length}</span><div class="flex-1"></div><input class="ctl w-64" placeholder="Buscar…" id="ig-search"></div>
      ${assetGrid(a.ig_accounts, (x) => `#/ig/${x.external_id}`, (x) => `${fmt.int(x.data.followers_count)} seguidores · ${fmt.int(x.data.media_count)} posteos`)}`,
    mount: () => searchCards('#ig-search'),
  };
}

function mediaGrid(items, kind) {
  if (!items.length) return empty(kind === 'STORY' ? 'No hay historias activas' : 'Sin publicaciones en este período', kind === 'STORY' ? 'Meta sólo expone las historias de las últimas 24 h.' : '');
  return `<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6 gap-3">${items.map((m) => `
    <a href="${esc(m.permalink || '#')}" target="_blank" rel="noopener" class="card overflow-hidden media-card hover:border-fuchsia-500/50 transition block">
      <div class="relative">${m.thumbnail ? `<img src="${esc(m.thumbnail)}" loading="lazy" alt="">` : '<div class="aspect-square bg-slate-800 grid place-items-center text-slate-600 text-xs">Sin miniatura</div>'}
        <span class="absolute top-2 left-2 pill ${m.type === 'REELS' ? 'blue' : m.type === 'STORY' ? 'amber' : 'gray'}">${{ REELS: 'Reel', STORY: 'Historia', FEED: m.media_type === 'CAROUSEL_ALBUM' ? 'Carrusel' : 'Post' }[m.type]}</span></div>
      <div class="p-3">
        <div class="text-[11px] text-slate-500">${fmt.datetime(m.timestamp)}</div>
        <div class="text-xs text-slate-300 line-clamp-2 h-8 mt-1">${esc(m.caption)}</div>
        <div class="grid grid-cols-3 gap-1 mt-2 text-center text-[11px]">
          <div><div class="text-slate-500">Alcance</div><div class="font-semibold text-slate-100">${fmt.compact(m.reach)}</div></div>
          <div><div class="text-slate-500">Vistas</div><div class="font-semibold text-slate-100">${fmt.compact(m.views)}</div></div>
          <div><div class="text-slate-500">ER</div><div class="font-semibold text-slate-100">${fmt.pct(m.engagement_rate)}</div></div>
          ${kind === 'STORY'
            ? `<div><div class="text-slate-500">Respuestas</div><div>${fmt.int(m.replies)}</div></div><div><div class="text-slate-500">Compart.</div><div>${fmt.int(m.shares)}</div></div><div><div class="text-slate-500">Interac.</div><div>${fmt.int(m.interactions)}</div></div>`
            : `<div><div class="text-slate-500">❤</div><div>${fmt.int(m.likes)}</div></div><div><div class="text-slate-500">💬</div><div>${fmt.int(m.comments)}</div></div><div><div class="text-slate-500">🔖 / ↗</div><div>${fmt.int(m.saved)} / ${fmt.int(m.shares)}</div></div>`}
        </div>
      </div>
    </a>`).join('')}</div>`;
}

const IG_COLS = [
  { key: 'timestamp', label: 'Fecha', left: true, value: (r) => r.timestamp, html: (r) => fmt.datetime(r.timestamp) },
  { key: 'type', label: 'Tipo', value: (r) => r.type },
  { key: 'caption', label: 'Texto', value: (r) => (r.caption || '').slice(0, 80) },
  { key: 'reach', label: 'Alcance', value: (r) => r.reach }, { key: 'views', label: 'Visualizaciones', value: (r) => r.views },
  { key: 'likes', label: 'Me gusta', value: (r) => r.likes }, { key: 'comments', label: 'Comentarios', value: (r) => r.comments },
  { key: 'saved', label: 'Guardados', value: (r) => r.saved }, { key: 'shares', label: 'Compartidos', value: (r) => r.shares },
  { key: 'interactions', label: 'Interacciones', value: (r) => r.interactions }, { key: 'engagement_rate', label: 'Engagement %', value: (r) => r.engagement_rate },
  { key: 'permalink', label: 'Link', value: (r) => r.permalink },
];

async function viewIg(igId) {
  const d = await api('ig', rp({ id: igId }));
  const a = d.account;
  const t = d.totals;
  crumbs([{ label: 'Instagram', href: '#/ig' }, { label: '@' + (a.username || igId) }]);
  const kpis = [
    ['Seguidores', fmt.int(a.followers)], ['Visualizaciones', fmt.compact(t.views)], ['Alcance', fmt.compact(t.reach)],
    ['Cuentas que interactuaron', fmt.int(t.accounts_engaged)], ['Interacciones', fmt.int(t.total_interactions)],
    ['Me gusta', fmt.int(t.likes)], ['Comentarios', fmt.int(t.comments)], ['Compartidos', fmt.int(t.shares)], ['Guardados', fmt.int(t.saves)],
    ['Seguidores netos', fmt.int(t.follows_and_unfollows)], ['Toques en enlaces', fmt.int(t.profile_links_taps)], ['Visitas al perfil', fmt.int(t.profile_views)],
  ].filter(([, v]) => v !== '—');
  const tabs = { FEED: 'Publicaciones', REELS: 'Reels', STORY: 'Historias activas', AUD: 'Audiencia', TABLE: 'Tabla y CSV' };

  const html = `
    <div class="flex flex-wrap items-center gap-4 mb-5">
      ${a.picture ? `<img src="${esc(a.picture)}" class="w-16 h-16 rounded-full ring-2 ring-fuchsia-500/60" alt="">` : ''}
      <div class="min-w-0"><h1 class="text-xl font-semibold text-white">@${esc(a.username)}</h1>
        <div class="text-sm text-slate-400">${esc(a.name || '')} · ${fmt.int(a.followers)} seguidores · ${fmt.int(a.follows)} seguidos · ${fmt.int(a.media_count)} publicaciones</div>
        ${a.biography ? `<div class="text-xs text-slate-500 mt-1 max-w-2xl line-clamp-2">${esc(a.biography)}</div>` : ''}</div>
      <div class="flex-1"></div><a class="btn" target="_blank" rel="noopener" href="https://instagram.com/${esc(a.username)}">Abrir perfil ↗</a>
    </div>
    ${d.totals_capped ? '<div class="text-xs text-amber-300/80 mb-3">ℹ Instagram calcula los totales de cuenta sobre un máximo de 30 días: se muestran los últimos 30 días del rango.</div>' : ''}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">${kpis.map(([l, v]) => kpi(l, v)).join('')}</div>
    ${seriesChartBlock('ig-chart', 'Métricas diarias', d.series, d.labels, ['reach', 'follower_count'])}
    ${followersBlock('ig-followers', d.followers_history)}
    <div class="card"><div class="card-h"><div class="tabs" id="ig-tabs">${Object.entries(tabs).map(([k, l], i) => `<button class="tab ${i === 0 ? 'active' : ''}" data-t="${k}">${l}</button>`).join('')}</div><div class="flex-1"></div>
      <select class="ctl" id="ig-sort"><option value="timestamp">Más recientes</option><option value="reach">Mayor alcance</option><option value="views">Más vistas</option><option value="interactions">Más interacciones</option><option value="engagement_rate">Mayor engagement</option><option value="saved">Más guardados</option><option value="shares">Más compartidos</option></select></div>
      <div class="p-4" id="ig-body"><div class="skeleton h-64"></div></div></div>`;

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
        sortSel.style.display = tab === 'AUD' ? 'none' : '';
        try {
          if (tab === 'AUD') {
            body.innerHTML = '<div class="skeleton h-64"></div>';
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
          if (tab === 'TABLE') {
            body.innerHTML = tableHtml('igt', IG_COLS, sorted(media), { csvName: 'instagram-' + a.username, sort: null });
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
  const has = Object.values(aud).some((l) => l.length);
  if (!has) return empty('Sin datos demográficos', 'Instagram los muestra sólo para cuentas con más de 100 seguidores.');
  return `<div class="grid md:grid-cols-2 gap-6">
    <div><h3 class="text-sm text-slate-400 mb-2">Edad</h3><div class="h-56"><canvas id="aud-age"></canvas></div></div>
    <div><h3 class="text-sm text-slate-400 mb-2">Género</h3><div class="h-56"><canvas id="aud-gender"></canvas></div></div>
    <div><h3 class="text-sm text-slate-400 mb-2">Ciudades principales</h3><div class="h-72"><canvas id="aud-city"></canvas></div></div>
    <div><h3 class="text-sm text-slate-400 mb-2">Países</h3><div class="h-72"><canvas id="aud-country"></canvas></div></div></div>`;
}

function mountAudience(aud) {
  const bar = (id, rows, horizontal) => rows.length && chart($('#' + id), rows.map((r) => r.label), [{ label: 'Seguidores', data: rows.map((r) => r.value), type: 'bar' }], { horizontal });
  bar('aud-age', aud.age || [], false);
  bar('aud-city', (aud.city || []).slice(0, 10), true);
  bar('aud-country', (aud.country || []).slice(0, 10), true);
  if ((aud.gender || []).length) {
    state.charts.push(new Chart($('#aud-gender'), { type: 'doughnut', data: { labels: aud.gender.map((g) => g.label), datasets: [{ data: aud.gender.map((g) => g.value), backgroundColor: PALETTE, borderColor: '#0f172a' }] }, options: { maintainAspectRatio: false, cutout: '60%' } }));
  }
}

// ---------- Actividad (webhooks) ----------
async function viewActivity() {
  crumbs([{ label: 'Actividad' }]);
  const ev = await api('activity', { limit: 100 });
  const icon = { feed: '📝', comments: '💬', mentions: '📣', messages: '✉️', story_insights: '📸', ratings: '⭐' };
  return {
    html: `<h1 class="text-xl font-semibold text-white mb-1">Actividad en tiempo real</h1>
      <p class="text-sm text-slate-400 mb-5">Comentarios, menciones y publicaciones que Meta notifica por webhook (configuralo en Configuración → Webhook).</p>
      ${ev.length ? `<div class="card divide-y divide-slate-800">${ev.map((e) => `
        <div class="px-5 py-3 flex gap-3 text-sm">
          <span class="text-lg">${icon[e.field] || '🔔'}</span>
          <div class="flex-1 min-w-0"><div class="text-slate-200"><b>${esc(e.asset)}</b> · ${esc(e.field)}${e.item ? ' · ' + esc(e.item) : ''}${e.from ? ' · de ' + esc(e.from) : ''}</div>
            ${e.text ? `<div class="text-slate-400 truncate">${esc(e.text)}</div>` : ''}</div>
          <span class="text-xs text-slate-500 whitespace-nowrap">${fmt.datetime(e.received_at.replace(' ', 'T'))}</span>
        </div>`).join('')}</div>` : empty('Todavía no llegaron eventos', 'Cuando configures el webhook en tu app de Meta, acá vas a ver comentarios y menciones al instante.')}`,
  };
}

// ---------- Configuración ----------
async function viewSettings() {
  crumbs([{ label: 'Configuración' }]);
  const [s, assets] = await Promise.all([api('settings'), api('assets', { all: 1 })]);
  const isAdmin = state.boot.user.role === 'admin';
  const conn = s.connections.map((c) => `
    <div class="card p-4">
      <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
          <div class="font-medium text-slate-100">${esc(c.label)} <span class="pill ${c.token_source === 'system_user' ? 'blue' : 'gray'}">${{ oauth: 'Login Facebook', system_user: 'System User', manual: 'Token manual' }[c.token_source] || c.token_source}</span></div>
          <div class="text-xs text-slate-500 mt-1">${esc(c.meta_user_name || '')} (${esc(c.meta_user_id || '')}) · Última sincronización: ${c.last_sync_at ? esc(c.last_sync_at) : 'nunca'}</div>
          <div class="text-xs mt-1 ${c.days_left !== null && c.days_left < 10 ? 'text-red-300' : 'text-slate-400'}">${c.days_left === null ? '✅ Token sin vencimiento' : `Token vence en ${c.days_left} días (${esc(c.expires_at)})`}</div>
          <div class="text-xs text-slate-500 mt-1">${c.assets.map((a) => `${a.total} ${{ business: 'portfolios', ad_account: 'cuentas pub.', page: 'páginas', ig_account: 'Instagram' }[a.type]}`).join(' · ')}</div>
          ${c.last_error ? `<div class="text-xs text-amber-300 mt-2">⚠ ${esc(c.last_error)}</div>` : ''}
          ${c.scopes.length ? `<details class="mt-2 text-xs text-slate-500"><summary class="cursor-pointer">Permisos (${c.scopes.length})</summary>${c.scopes.map(esc).join(', ')}</details>` : ''}
        </div>
        <div class="flex gap-2"><button class="btn" data-sync="${c.id}">⟳ Sincronizar</button>${isAdmin ? `<button class="btn btn-danger" data-del-conn="${c.id}">Quitar</button>` : ''}</div>
      </div>
    </div>`).join('');

  const assetRows = [
    ...assets.ad_accounts.map((x) => ({ ...x, kind: 'Cuenta publicitaria' })), ...assets.pages.map((x) => ({ ...x, kind: 'Página' })),
    ...assets.ig_accounts.map((x) => ({ ...x, kind: 'Instagram' })),
  ];
  const copy = (v) => `<button class="btn !py-1 !px-2 text-xs" data-copy="${esc(v)}">Copiar</button>`;

  const html = `
    <h1 class="text-xl font-semibold text-white mb-5">Configuración</h1>
    <div class="grid xl:grid-cols-3 gap-6">
      <div class="xl:col-span-2 space-y-6">
        <section class="card">
          <div class="card-h"><h2>Conexiones con Meta</h2><div class="flex-1"></div>
            ${isAdmin && s.oauth_ready ? '<a class="btn btn-primary" href="index.php?r=oauth-start">Conectar con Facebook</a>' : ''}
            <button class="btn" data-sync="all">⟳ Sincronizar todo</button></div>
          <div class="p-4 space-y-3">${conn || '<div class="text-sm text-slate-400">Todavía no hay conexiones. Usá “Conectar con Facebook” o pegá un token de System User abajo.</div>'}
            ${!s.oauth_ready ? '<div class="text-xs text-amber-300">Completá meta.app_id y meta.app_secret en config.php para habilitar el login con Facebook.</div>' : ''}</div>
        </section>
        ${isAdmin ? `<section class="card">
          <div class="card-h"><h2>Agregar token manualmente</h2></div>
          <form class="p-4 grid sm:grid-cols-2 gap-3" id="token-form">
            <input class="input" name="label" placeholder="Nombre (ej: System User Agencia)">
            <select class="input" name="source"><option value="system_user">Token de System User (recomendado, no vence)</option><option value="manual">Token de usuario (se extiende a 60 días)</option></select>
            <textarea class="input sm:col-span-2 font-mono text-xs" name="token" rows="3" placeholder="EAAG…" required></textarea>
            <div class="sm:col-span-2 flex items-center gap-3"><button class="btn btn-primary">Guardar y sincronizar</button><span class="text-xs text-slate-500">El token se guarda cifrado (AES-256).</span></div>
          </form>
        </section>` : ''}
        <section>${tableHtml('assets', [
          { key: 'name', label: 'Activo', left: true, value: (r) => r.name, html: (r) => `<div class="text-slate-100">${esc(r.name)}</div><div class="text-[11px] text-slate-500">${esc(r.external_id)}</div>` },
          { key: 'kind', label: 'Tipo', value: (r) => r.kind },
          { key: 'is_hidden', label: 'Visible en el panel', value: (r) => (r.is_hidden ? 0 : 1),
            html: (r) => (isAdmin ? `<label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" data-asset="${r.id}" ${r.is_hidden ? '' : 'checked'}> ${r.is_hidden ? 'Oculto' : 'Visible'}</label>` : (r.is_hidden ? 'Oculto' : 'Visible')) },
        ], assetRows, { title: 'Activos sincronizados', sort: 'kind', dir: 'asc', filters: [{ label: 'Todos' }, { label: 'Cuentas', fn: (r) => r.type === 'ad_account' }, { label: 'Páginas', fn: (r) => r.type === 'page' }, { label: 'Instagram', fn: (r) => r.type === 'ig_account' }] })}</section>
      </div>

      <div class="space-y-6">
        <section class="card p-4 space-y-3 text-sm">
          <h2 class="font-semibold text-slate-100">Datos para tu app de Meta</h2>
          <div><div class="text-xs text-slate-500 mb-1">URI de redirección OAuth válida</div><div class="flex gap-2 items-center"><code class="text-xs break-all flex-1">${esc(s.redirect_uri)}</code>${copy(s.redirect_uri)}</div></div>
          <div><div class="text-xs text-slate-500 mb-1">URL del webhook</div><div class="flex gap-2 items-center"><code class="text-xs break-all flex-1">${esc(s.webhook_url)}</code>${copy(s.webhook_url)}</div></div>
          <div class="text-xs text-slate-500">Graph API ${esc(s.graph_version)}</div>
        </section>
        <section class="card">
          <div class="card-h"><h2>Usuarios del panel</h2></div>
          <ul class="divide-y divide-slate-800 text-sm">${s.users.map((u) => `<li class="px-4 py-2.5 flex items-center gap-2"><div class="flex-1 min-w-0"><div class="text-slate-100 truncate">${esc(u.name)} <span class="pill gray">${esc(u.role)}</span></div><div class="text-xs text-slate-500 truncate">${esc(u.email)}</div></div>${isAdmin && u.id !== state.boot.user.id ? `<button class="text-xs text-red-300 hover:underline" data-del-user="${u.id}">Eliminar</button>` : ''}</li>`).join('')}</ul>
          ${isAdmin ? `<form class="p-4 space-y-2 border-t border-slate-800" id="user-form">
            <input class="input" name="name" placeholder="Nombre" required><input class="input" name="email" type="email" placeholder="Email" required>
            <input class="input" name="password" type="password" minlength="8" placeholder="Contraseña (mín. 8)" required>
            <select class="input" name="role"><option value="viewer">Solo lectura (ideal para clientes)</option><option value="admin">Administrador</option></select>
            <button class="btn btn-primary w-full justify-center">Agregar usuario</button></form>` : ''}
        </section>
        <section class="card">
          <div class="card-h"><h2>Cambiar mi contraseña</h2></div>
          <form class="p-4 space-y-2" id="pass-form"><input class="input" type="password" name="current" placeholder="Actual" required><input class="input" type="password" name="new" minlength="8" placeholder="Nueva (mín. 8)" required><button class="btn w-full justify-center">Actualizar</button></form>
        </section>
        <section class="card">
          <div class="card-h"><h2>Mantenimiento</h2><div class="flex-1"></div>${isAdmin ? '<button class="btn" id="flush">Vaciar caché</button>' : ''}</div>
          <ul class="text-xs divide-y divide-slate-800 max-h-72 overflow-y-auto">${s.log.map((l) => `<li class="px-4 py-2"><span class="pill ${l.status === 'ok' ? 'green' : 'red'}">${esc(l.job)}</span> <span class="text-slate-500">${esc(l.created_at)}</span><div class="text-slate-400 truncate" title="${esc(l.message)}">${esc(l.message)}</div></li>`).join('') || '<li class="px-4 py-3 text-slate-500">El cron todavía no corrió.</li>'}</ul>
        </section>
      </div>
    </div>`;

  const post = async (action, body, okMsg) => {
    try { const r = await api(action, {}, body); if (okMsg) toast(okMsg, 'success'); return r; } catch (e) { toast(e.message, 'error'); throw e; }
  };
  const reload = async () => { state.boot = await api('bootstrap'); updateCounts(); render(); };

  return {
    html,
    mount() {
      mountTable('assets');
      const view = $('#view');
      view.addEventListener('click', async (e) => {
        const t = e.target;
        if (t.dataset.sync) {
          t.disabled = true; t.textContent = 'Sincronizando…';
          try { await post('sync', t.dataset.sync === 'all' ? {} : { connection_id: +t.dataset.sync }, 'Sincronización completa'); await reload(); } catch { t.disabled = false; t.textContent = '⟳ Reintentar'; }
        } else if (t.dataset.delConn) {
          if (confirm('¿Quitar esta conexión? Se borran sus activos del panel (no afecta nada en Meta).')) { await post('connection_delete', { id: +t.dataset.delConn }, 'Conexión eliminada'); await reload(); }
        } else if (t.dataset.delUser) {
          if (confirm('¿Eliminar este usuario?')) { await post('user_delete', { id: +t.dataset.delUser }, 'Usuario eliminado'); render(); }
        } else if (t.dataset.copy) {
          navigator.clipboard?.writeText(t.dataset.copy); toast('Copiado', 'success');
        } else if (t.id === 'flush') {
          await post('cache_flush', {}, 'Caché vaciada');
        }
      });
      view.addEventListener('change', async (e) => {
        if (e.target.dataset.asset) { await post('asset_toggle', { id: +e.target.dataset.asset, hidden: !e.target.checked }); state.boot = await api('bootstrap'); updateCounts(); }
      });
      const form = (id, action, msg, after) => {
        const f = $('#' + id);
        if (!f) return;
        f.addEventListener('submit', async (e) => {
          e.preventDefault();
          const btn = $('button', f); btn.disabled = true;
          try { await post(action, Object.fromEntries(new FormData(f)), msg); f.reset(); if (after) await after(); } catch { /* toast ya mostrado */ } finally { btn.disabled = false; }
        });
      };
      form('token-form', 'connection_add_token', 'Token guardado y activos sincronizados', reload);
      form('user-form', 'user_add', 'Usuario creado', () => render());
      form('pass-form', 'password_change', 'Contraseña actualizada');
    },
  };
}

// ---------- router ----------
const ROUTES = [
  [/^\/?$/, viewOverview, 'overview'],
  [/^\/ads$/, viewAdAccounts, 'ads'],
  [/^\/ads\/(act_\d+)$/, viewAccount, 'ads'],
  [/^\/ads\/(act_\d+)\/c\/(\d+)$/, viewCampaign, 'ads'],
  [/^\/ads\/(act_\d+)\/c\/(\d+)\/s\/(\d+)$/, viewAdset, 'ads'],
  [/^\/pages$/, viewPages, 'pages'],
  [/^\/pages\/(\d+)$/, viewPage, 'pages'],
  [/^\/ig$/, viewIgList, 'ig'],
  [/^\/ig\/(\d+)$/, viewIg, 'ig'],
  [/^\/activity$/, viewActivity, 'activity'],
  [/^\/settings$/, viewSettings, 'settings'],
];

async function render({ silent = false } = {}) {
  const path = location.hash.replace(/^#/, '') || '/';
  const view = $('#view');
  const id = ++state.renderId;
  const match = ROUTES.map(([re, fn, nav]) => [path.match(re), fn, nav]).find(([m]) => m);
  if (!match) { location.hash = '#/'; return; }
  const [m, fn, nav] = match;
  $$('#nav [data-nav]').forEach((a) => a.classList.toggle('active', a.dataset.nav === nav));
  closeMenu();
  const scroll = window.scrollY;
  if (!silent) { view.innerHTML = skeleton(); window.scrollTo(0, 0); }
  // Reemplazamos #view para soltar listeners de la vista anterior.
  try {
    const out = await fn(...m.slice(1));
    if (id !== state.renderId) return;
    destroyCharts();
    const fresh = view.cloneNode(false);
    view.replaceWith(fresh);
    fresh.innerHTML = out.html;
    out.mount?.();
    if (silent) window.scrollTo(0, scroll);
  } catch (e) {
    if (id !== state.renderId) return;
    if (!silent) $('#view').innerHTML = errorBox(e.message);
    else toast('No se pudo actualizar: ' + e.message, 'error');
  } finally {
    state.force = false;
    $('#refresh-btn').classList.remove('animate-spin');
  }
}

// ---------- controles de fecha / en vivo ----------
function initControls() {
  const preset = $('#preset');
  preset.innerHTML = Object.entries(state.boot.presets).map(([k, l]) => `<option value="${k}">${l}</option>`).join('') + '<option value="custom">Personalizado…</option>';
  preset.value = state.range.preset;
  const custom = $('#custom-range');
  const since = $('#since'), until = $('#until');
  const today = new Date().toISOString().slice(0, 10);
  since.max = until.max = today;
  since.value = state.range.since || '';
  until.value = state.range.until || today;
  const showCustom = () => { custom.classList.toggle('hidden', preset.value !== 'custom'); custom.classList.toggle('flex', preset.value === 'custom'); };
  showCustom();
  preset.addEventListener('change', () => {
    showCustom();
    if (preset.value !== 'custom') { state.range = { preset: preset.value }; store.set('range', state.range); render(); }
  });
  const applyCustom = () => {
    if (since.value && until.value && since.value <= until.value) {
      state.range = { preset: 'custom', since: since.value, until: until.value };
      store.set('range', state.range);
      render();
    }
  };
  since.addEventListener('change', applyCustom);
  until.addEventListener('change', applyCustom);

  $('#refresh-btn').addEventListener('click', () => { state.force = true; $('#refresh-btn').classList.add('animate-spin'); render({ silent: true }); });
  const live = $('#live-btn');
  const setLive = (on) => {
    state.live = on; store.set('live', on);
    live.classList.toggle('live-on', on);
    clearInterval(state.timer);
    if (on) {
      const every = Math.max(60, state.boot.refresh_sec) * 1000;
      state.timer = setInterval(() => {
        const path = location.hash;
        if (document.visibilityState === 'visible' && !path.startsWith('#/settings') && $('#modal').classList.contains('hidden')) render({ silent: true });
      }, every);
    }
  };
  live.addEventListener('click', () => { setLive(!state.live); toast(state.live ? 'Actualización automática activada' : 'Actualización automática pausada'); });
  setLive(state.live);

  $('#menu-btn').addEventListener('click', () => { $('#sidebar').classList.remove('-translate-x-full'); $('#backdrop').classList.remove('hidden'); });
  $('#backdrop').addEventListener('click', closeMenu);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') $('#modal').classList.add('hidden'); });
}

function closeMenu() {
  if (window.innerWidth < 1024) { $('#sidebar').classList.add('-translate-x-full'); $('#backdrop').classList.add('hidden'); }
}

function updateCounts() {
  $$('[data-count]').forEach((el) => { el.textContent = state.boot.counts[el.dataset.count] || ''; });
}

(async function init() {
  try {
    state.boot = await api('bootstrap');
  } catch (e) {
    $('#view').innerHTML = errorBox(e.message);
    return;
  }
  $('#user-name').textContent = state.boot.user.name;
  updateCounts();
  initControls();
  if (window.FLASH) toast(window.FLASH.text, window.FLASH.type);
  window.addEventListener('hashchange', () => render());
  render();
})();
