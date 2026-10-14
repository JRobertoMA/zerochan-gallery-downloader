/**
 * Lógica del frontend de zerochan-gallery-downloader (script clásico: sus funciones y `state` son globales).
 * Depende del markup de index.php (ids #form, #grid, #modal, #lmodal…) y de los endpoints JSON de api/*.php.
 */
const $ = s => document.querySelector(s);
const form = $('#form'), grid = $('#grid'), statusEl = $('#status'), pager = $('#pager');
let state = { tab: 'search', page: 1, items: [], saved: new Set(), savedFilter: { tag: '', q: '', sort: 'date', p: 1 }, jobs: [], jobsTimer: null, selectMode: false, sel: new Set(), local: null, fromHistory: false };

const fmtBytes = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b > 1024 ? (b / 1024).toFixed(0) + ' KB' : b + ' B';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
function toast(msg) { const t = $('#toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2200); }
async function api(url, body) {
  const r = body ? await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) }) : await fetch(url);
  const d = await r.json().catch(() => ({ error: 'respuesta inválida' }));
  if (d.error) throw new Error(d.error);
  return d;
}

function formParams() {
  const fd = new FormData(form), p = new URLSearchParams();
  for (const [k, v] of fd) if (v !== '') p.set(k, v);
  return p;
}
/** Rellena el formulario de Explorar desde un objeto {tags, s, t, d, c, ecchi, min, l, strict}. */
function fillForm(s) {
  for (const el of form.elements) { if (!el.name) continue; if (el.type === 'checkbox') el.checked = !!s[el.name]; else el.value = s[el.name] ?? (el.tagName === 'SELECT' ? el.value : ''); }
  if (!s.l) form.elements.l.value = '40';
}
// localStorage es el respaldo cuando se abre la URL base: guarda filtros, página y pestaña
function saveState() { try { localStorage.setItem('zc.form', JSON.stringify({ ...Object.fromEntries(formParams()), p: state.page, tab: state.tab, saved: state.savedFilter })); } catch { } }
function restoreState() {
  try {
    const s = JSON.parse(localStorage.getItem('zc.form') || '{}');
    fillForm(s); state.page = Math.max(1, +s.p || 1);
    if (s.saved) { Object.assign(state.savedFilter, s.saved); syncSavedBar(); }
    return ['search', 'saved', 'log'].includes(s.tab) ? s.tab : 'search';
  } catch { return 'search'; }
}
function syncSavedBar() { $('#sq').value = state.savedFilter.q; $('#ssort').value = state.savedFilter.sort; }

// ---- estado ⇄ URL (?tags=..&p=N | ?tab=saved&tag=..&p=N | ?tab=log) ----
const SAVED_KEYS = ['tag', 'q', 'sort'], SAVED_SORTS = ['date', 'size', 'id'];
function urlFromState() {
  const p = new URLSearchParams();
  if (state.tab === 'saved') {
    p.set('tab', 'saved'); const f = state.savedFilter;
    SAVED_KEYS.forEach(k => { if (f[k] && !(k === 'sort' && f[k] === 'date')) p.set(k, f[k]); });
    if (f.p > 1) p.set('p', f.p);
  } else if (state.tab === 'log') {
    p.set('tab', 'log');
  } else {
    for (const [k, v] of formParams()) p.set(k, v);
    if (state.page > 1) p.set('p', state.page);
  }
  const q = p.toString(); return q ? '?' + q : location.pathname;
}
/** Empuja la URL actual al historial (o la reemplaza si venimos de popstate / carga inicial). */
function syncUrl() {
  const url = urlFromState(), cur = location.search || location.pathname;
  if (url !== cur) history[state.fromHistory ? 'replaceState' : 'pushState'](null, '', url);
  state.fromHistory = false;
}
/** Lee la URL; devuelve la pestaña si trae estado, null si está vacía. */
function applyUrl() {
  const q = new URLSearchParams(location.search);
  if (![...q.keys()].length) return null;
  const tab = q.get('tab');
  if (tab === 'log') return 'log';
  if (tab === 'saved') {
    Object.assign(state.savedFilter, { tag: q.get('tag') || '', q: q.get('q') || '', sort: SAVED_SORTS.includes(q.get('sort')) ? q.get('sort') : 'date', p: Math.max(1, +q.get('p') || 1) });
    syncSavedBar(); return 'saved';
  }
  fillForm(Object.fromEntries(q)); state.page = Math.max(1, +q.get('p') || 1);
  return 'search';
}
window.addEventListener('popstate', () => { state.fromHistory = true; const t = applyUrl(); if (!t) state.page = 1; switchTab(t ?? 'search'); });

// ---- paginador común: « Primera  ← Anterior  [N] / total  Ir  Siguiente →  Última » ----
function renderPager({ page, pages = null, hasNext, go }) {
  const last = pages ?? Infinity;
  pager.innerHTML = `<button ${page <= 1 ? 'disabled' : ''} data-go="1" title="Primera">«</button><button ${page <= 1 ? 'disabled' : ''} data-go="${page - 1}">← Anterior</button>
    <input type="number" min="1" ${pages ? `max="${pages}"` : ''} value="${page}" title="Ir a página"><span class="kv">${pages ? `/ ${pages}` : ''}</span><button data-go="input">Ir</button>
    <button ${(hasNext ?? page < last) ? '' : 'disabled'} data-go="${page + 1}">Siguiente →</button>${pages ? `<button ${page >= pages ? 'disabled' : ''} data-go="${pages}" title="Última">»</button>` : ''}`;
  const input = pager.querySelector('input');
  const jump = n => { n = Math.max(1, Math.min(last, Math.floor(+n) || 1)); if (n !== page) go(n); else input.value = page; };
  pager.querySelectorAll('[data-go]').forEach(b => b.onclick = () => jump(b.dataset.go === 'input' ? input.value : b.dataset.go));
  input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); jump(input.value); } });
}

// ---- explorar ----
async function search(page = 1) {
  state.page = Math.max(1, page); saveState(); syncUrl();
  const p = formParams(); p.set('p', page);
  statusEl.textContent = 'Buscando…'; grid.innerHTML = ''; pager.innerHTML = '';
  try { renderSearch(await api('api/search.php?' + p)); }
  catch (e) { statusEl.innerHTML = `<span style="color:var(--err)">${esc(e.message)}</span>`; }
}

function renderSearch(d) {
  state.items = d.items;
  const hidden = (d.raw_count ?? d.items.length) - d.items.length;
  const filtros = [d.ecchi !== 'all' ? 'Ecchi' : null, d.min > 0 ? `≥${d.min}px` : null].filter(Boolean).join(', ');
  statusEl.innerHTML = `${d.items.length} resultados${hidden > 0 ? ` (${hidden} ocultos por ${filtros})` : ''} · página ${d.page} · <a href="${d.url}" target="_blank" rel="noopener">API</a>`
    + (d.repaired ? ` · <span style="color:var(--warn)" title="Zerochan está devolviendo el listado malformado; la respuesta se reparó en el servidor">⚠ respuesta reparada</span>` : '');
  if (d.items.length) {
    const btn = document.createElement('button'); btn.textContent = 'Descargar todo…'; btn.onclick = askBatch; statusEl.appendChild(btn);
  }
  grid.innerHTML = d.items.map(it => {
    const saved = it.saved || state.saved.has(it.id), dup = !saved && it.dup_of;
    return `
    <div class="card ${saved ? 'saved' : ''} ${dup ? 'dup' : ''}" data-id="${it.id}">
      <img src="${it.thumbnail}" loading="lazy" alt="" width="${it.width}" height="${it.height}">
      <button class="dl" data-dl="${it.id}" title="${dup ? 'Misma imagen ya descargada como #' + it.dup_of : saved ? 'Ya descargada' : 'Descargar full'}">${saved ? '✓' : dup ? '≈' : '⬇'}</button>
      <div class="meta"><b title="${esc(it.tag)}">${esc(it.tag)}</b><span>${(it.tags || []).includes('Ecchi') ? '<i class="badge" title="Ecchi">E</i>' : ''}${it.width}×${it.height}</span></div>
    </div>`;
  }).join('');
  // Paginar según lo que devolvió la API, no lo que quedó tras los filtros locales
  const hasNext = (d.raw_count ?? d.items.length) >= d.limit;
  renderPager({ page: d.page, hasNext, go: search });
}

// ---- descargados (índice local) ----
async function loadSaved() {
  const f = state.savedFilter; f.p = Math.max(1, f.p); saveState(); syncUrl();
  const p = new URLSearchParams({ tag: f.tag, q: f.q, sort: f.sort, p: f.p, l: 60 });
  statusEl.textContent = 'Cargando…'; grid.innerHTML = ''; pager.innerHTML = '';
  try { renderSaved(await api('api/downloads.php?' + p)); }
  catch (e) { statusEl.innerHTML = `<span style="color:var(--err)">${esc(e.message)}</span>`; }
}
function renderSaved(d) {
  const f = state.savedFilter;
  d.items.forEach(i => state.saved.add(i.id));
  statusEl.innerHTML = `${d.count} de ${d.stats.count} descargadas · ${fmtBytes(d.stats.bytes)} en <code>downloads/</code> · página ${d.page}`;
  $('#stagchip').innerHTML = f.tag ? `<span class="tag active">${esc(f.tag)} ×</span>` : '';
  $('#sfacet').innerHTML = d.tags.map(t => `<span class="tag" data-tag="${esc(t.tag)}">${esc(t.tag)}<small>${t.n}</small></span>`).join('');
  state.sel.clear(); updateSelBar();
  grid.innerHTML = d.items.map(it => `
    <div class="card saved" data-id="${it.id}" data-local="${it.url}">
      <img src="${it.url}" loading="lazy" alt="" width="${it.width}" height="${it.height}">
      <button class="dl del" data-del="${it.id}" title="Borrar del disco">🗑</button>
      <input type="checkbox" class="sel" data-sel="${it.id}" title="Seleccionar">
      <div class="meta"><b title="${esc(it.primary)}">${esc(it.primary)}</b><span>${(it.tags || []).includes('Ecchi') ? '<i class="badge" title="Ecchi">E</i>' : ''}${it.crops ? `<i class="badge crop" title="${it.crops} recorte${it.crops > 1 ? 's' : ''}">✂${it.crops}</i>` : ''}${fmtBytes(it.size)}</span></div>
    </div>`).join('');
  const pages = Math.ceil(d.count / d.limit);
  if (pages > 1) renderPager({ page: d.page, pages, go: n => { f.p = n; loadSaved(); } });
}
$('#sgo').onclick = () => { state.savedFilter.q = $('#sq').value.trim(); state.savedFilter.sort = $('#ssort').value; state.savedFilter.p = 1; loadSaved(); };
$('#sq').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#sgo').click(); } });
$('#sfacet').addEventListener('click', e => { const t = e.target.closest('[data-tag]'); if (!t) return; state.savedFilter.tag = t.dataset.tag; state.savedFilter.p = 1; loadSaved(); });
$('#stagchip').addEventListener('click', () => { state.savedFilter.tag = ''; state.savedFilter.p = 1; loadSaved(); });

// ---- borrar / selección múltiple ----
async function deleteLocal(ids) {
  const d = await api('api/local.php', { action: 'delete', ids: ids.join(',') });
  d.deleted.forEach(id => { state.saved.delete(id); state.sel.delete(id); document.querySelectorAll(`.card[data-id="${id}"]`).forEach(c => c.remove()); });
  toast(`${d.deleted.length} borrada${d.deleted.length !== 1 ? 's' : ''} · ${fmtBytes(d.freed)} liberados${d.missing.length ? ` · ${d.missing.length} no existían` : ''}`);
  return d;
}
function setSelectMode(on) {
  state.selectMode = on; state.sel.clear();
  grid.classList.toggle('select', on); $('#selbar').classList.toggle('open', on); $('#ssel').hidden = on;
  grid.querySelectorAll('.card').forEach(c => { c.classList.remove('picked'); const cb = c.querySelector('.sel'); if (cb) cb.checked = false; });
  updateSelBar();
}
function updateSelBar() { const n = state.sel.size; $('#sdel').textContent = `Borrar (${n})`; $('#sdel').disabled = !n; }
function toggleSel(card, on) {
  const id = +card.dataset.id; on ??= !state.sel.has(id);
  on ? state.sel.add(id) : state.sel.delete(id);
  card.classList.toggle('picked', on); const cb = card.querySelector('.sel'); if (cb) cb.checked = on;
  updateSelBar();
}
$('#ssel').onclick = () => setSelectMode(true);
$('#selCancel').onclick = () => setSelectMode(false);
$('#selAll').onclick = () => grid.querySelectorAll('.card').forEach(c => toggleSel(c, true));
$('#selNone').onclick = () => grid.querySelectorAll('.card').forEach(c => toggleSel(c, false));
$('#sdel').onclick = async () => {
  const ids = [...state.sel]; if (!ids.length) return;
  if (!confirm(`¿Borrar ${ids.length} imagen${ids.length > 1 ? 'es' : ''} del disco, con sus recortes? No se puede deshacer.`)) return;
  try { await deleteLocal(ids); setSelectMode(false); loadSaved(); } catch (e) { toast('Error: ' + e.message); }
};

// ---- log ----
async function loadLog() {
  statusEl.textContent = ''; grid.innerHTML = ''; pager.innerHTML = '';
  const box = $('#loglines'); box.hidden = false;
  try {
    const d = await api('api/log.php?n=200');
    statusEl.innerHTML = `${d.lines.length} líneas de <code>logs/zerochan.log</code> `;
    const b = document.createElement('button'); b.textContent = 'Refrescar'; b.onclick = loadLog; statusEl.appendChild(b);
    box.innerHTML = d.lines.length ? d.lines.slice().reverse().map(l => { const lvl = (l.match(/ (INFO|WARN|ERROR) /) || [])[1] || ''; return `<div class="${lvl}">${esc(l)}</div>`; }).join('') : '<span class="kv">Sin entradas todavía.</span>';
  } catch (e) { box.textContent = e.message; }
}

// ---- modal ----
async function openModal(id) {
  const m = $('#modal'); m.classList.add('open');
  $('#mImg').src = ''; $('#mTitle').textContent = 'Cargando…'; $('#mInfo').textContent = ''; $('#mChildren').textContent = ''; $('#mTags').innerHTML = ''; $('#mDlStatus').textContent = '';
  let it;
  try { it = await api('api/item.php?id=' + id); } catch (e) { $('#mTitle').textContent = e.message; return; }
  m.dataset.id = it.id;
  $('#mImg').src = it.large; $('#mTitle').textContent = it.primary;
  $('#mInfo').innerHTML = `${it.tags.includes('Ecchi') ? '<i class="badge" title="Ecchi">E</i>' : ''}<b>${it.width}×${it.height}</b> · ${fmtBytes(it.size)} · id ${it.id}`;
  $('#mChildren').innerHTML = it.children > 0 ? `<b>${it.children}</b> variante${it.children > 1 ? 's' : ''} · <a href="https://www.zerochan.net/${it.id}" target="_blank" rel="noopener">ver en Zerochan</a>` : '';
  const src = $('#mSource'); src.href = it.source || '#'; src.textContent = it.source ? new URL(it.source).hostname.replace(/^www\./, '') : 'sin fuente';
  $('#mZc').href = 'https://www.zerochan.net/' + it.id; $('#mFull').href = it.full;
  $('#mTags').innerHTML = it.tags.map(t => `<span class="tag">${esc(t)}</span>`).join('');
  const saved = state.saved.has(it.id) || state.items.find(i => i.id === it.id)?.saved;
  $('#mDl').disabled = !!saved; $('#mDlStatus').textContent = saved ? 'ya descargada' : '';
}
$('#close').onclick = () => $('#modal').classList.remove('open');
$('#modal').addEventListener('click', e => { if (e.target === $('#modal')) $('#modal').classList.remove('open'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { $('#modal').classList.remove('open'); $('#panel').classList.remove('open'); $('#batchbox')?.classList.remove('open'); $('#suggest').hidden = true; closeLocal(); if (state.selectMode) setSelectMode(false); } });
$('#mTags').addEventListener('click', e => {
  if (!e.target.classList.contains('tag')) return;
  const t = e.target.textContent, cur = $('#tags').value.split(',').map(s => s.trim()).filter(Boolean);
  if (e.shiftKey && !cur.includes(t)) cur.push(t); else cur.splice(0, cur.length, t);
  $('#tags').value = cur.join(', '); $('#modal').classList.remove('open'); state.page = 1; switchTab('search');
});
$('#mDl').onclick = async () => { await downloadOne(+$('#modal').dataset.id, $('#mDlStatus')); $('#mDl').disabled = true; };

async function downloadOne(id, statusNode) {
  if (statusNode) statusNode.textContent = 'descargando…';
  try {
    const d = await api('api/download.php', { id });
    state.saved.add(id);
    document.querySelectorAll(`.card[data-id="${id}"]`).forEach(c => { c.classList.add('saved'); c.classList.remove('dup'); const b = c.querySelector('.dl'); if (b) b.textContent = '✓'; });
    if (statusNode) statusNode.innerHTML = `${d.status === 'skipped' ? 'ya existía' : 'guardada'} → <a href="${d.url}" target="_blank">${esc(d.path)}</a>`;
    toast((d.status === 'skipped' ? 'Ya existía: ' : 'Guardada: ') + d.path);
  } catch (e) { if (statusNode) statusNode.textContent = e.message; toast('Error: ' + e.message); }
}

// ---- visor local (descargadas) ----
const lm = $('#lmodal');
async function openLocal(id) {
  lm.classList.add('open'); cropper.stop();
  $('#lImg').src = ''; $('#lTitle').textContent = 'Cargando…'; $('#lInfo').textContent = ''; $('#lCrops').innerHTML = ''; $('#lTags').innerHTML = '';
  let it;
  try { it = await api('api/local.php?id=' + id); } catch (e) { $('#lTitle').textContent = e.message; return; }
  state.local = it;
  $('#lImg').src = it.url; $('#lTitle').textContent = it.primary; $('#lOpen').href = it.url;
  $('#lInfo').innerHTML = `${it.tags.includes('Ecchi') ? '<i class="badge" title="Ecchi">E</i>' : ''}<b>${it.width}×${it.height}</b> · ${fmtBytes(it.size)} · <a href="https://www.zerochan.net/${it.id}" target="_blank" rel="noopener">#${it.id}</a>${it.downloaded_at ? ` · ${it.downloaded_at.slice(0, 10)}` : ''}`;
  $('#lTags').innerHTML = it.tags.map(t => `<span class="tag">${esc(t)}</span>`).join('');
  renderCrops(it.crops);
}
function closeLocal() { if (!lm.classList.contains('open')) return; lm.classList.remove('open'); cropper.stop(); state.local = null; }
function renderCrops(crops) {
  state.local.crops = crops;
  document.querySelectorAll(`.card[data-id="${state.local.id}"] .meta span`).forEach(s => {
    s.querySelector('.badge.crop')?.remove();
    if (crops.length) s.insertAdjacentHTML('afterbegin', `<i class="badge crop" title="${crops.length} recorte${crops.length > 1 ? 's' : ''}">✂${crops.length}</i>`);
  });
  $('#lCrops').innerHTML = crops.length ? `<div class="kv">${crops.length} recorte${crops.length > 1 ? 's' : ''}</div>` + crops.map(c => `
    <div class="row" data-name="${esc(c.name)}"><img src="${c.url}?t=${c.mtime}" alt="" data-open="${c.url}"><span><b>${c.width}×${c.height}</b><br>${fmtBytes(c.size)}</span>
      <span class="sp"><a href="${c.url}" target="_blank" rel="noopener"><button type="button" class="small">Abrir</button></a><button type="button" class="small" data-delcrop="${esc(c.name)}">🗑</button></span></div>`).join('') : '';
}
$('#lclose').onclick = closeLocal;
lm.addEventListener('click', e => { if (e.target === lm) closeLocal(); });
$('#lTags').addEventListener('click', e => {
  if (!e.target.classList.contains('tag')) return;
  state.savedFilter.tag = e.target.textContent; state.savedFilter.p = 1; closeLocal(); loadSaved();
});
$('#lDel').onclick = async () => {
  const id = state.local?.id; if (!id) return;
  if (!confirm(`¿Borrar #${id} del disco, con sus recortes? No se puede deshacer.`)) return;
  try { await deleteLocal([id]); closeLocal(); loadSaved(); } catch (e) { toast('Error: ' + e.message); }
};
$('#lCrops').addEventListener('click', async e => {
  const img = e.target.closest('[data-open]'); if (img) { window.open(img.dataset.open, '_blank'); return; }
  const b = e.target.closest('[data-delcrop]'); if (!b) return;
  if (!confirm(`¿Borrar el recorte ${b.dataset.delcrop}?`)) return;
  try { const d = await api('api/local.php', { action: 'delete_crop', id: state.local.id, name: b.dataset.delcrop }); renderCrops(d.crops); toast('Recorte borrado'); }
  catch (err) { toast('Error: ' + err.message); }
});

// ---- recorte: rectángulo normalizado (0..1) sobre la imagen mostrada; el servidor recorta el original ----
const cropper = (() => {
  const box = $('#cropbox'), img = $('#lImg'), tools = $('#cropTools');
  let r = { x: 0, y: 0, w: 1, h: 1 }, ratio = null, drag = null;   // ratio = ancho/alto en px, null = libre
  const W = () => img.naturalWidth || 1, H = () => img.naturalHeight || 1;
  const minW = () => 16 / W(), minH = () => 16 / H();
  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
  // Con ratio fijo, en unidades normalizadas h = w · (W/H) / ratio
  const hFromW = w => w * W() / H() / ratio, wFromH = h => h * H() / W() * ratio;
  function apply() {
    Object.assign(box.style, { left: r.x * 100 + '%', top: r.y * 100 + '%', width: r.w * 100 + '%', height: r.h * 100 + '%' });
    $('#cropInfo').textContent = `${Math.round(r.w * W())}×${Math.round(r.h * H())}`;
  }
  function reset() {
    if (ratio) { if (W() / H() > ratio) { r.h = 1; r.w = wFromH(1); } else { r.w = 1; r.h = hFromW(1); } }
    else { r.w = r.h = 1; }
    r.x = (1 - r.w) / 2; r.y = (1 - r.h) / 2; apply();
  }
  function readRatio() {
    const v = $('#ar').value; $('#arCustom').hidden = v !== 'custom';
    if (v === 'free') ratio = null;
    else { const [a, b] = v === 'custom' ? [+$('#arW').value, +$('#arH').value] : v.split(':').map(Number); ratio = a > 0 && b > 0 ? a / b : null; }
    reset();
  }
  function start() {
    if (!state.local) return;
    if (!img.naturalWidth) { toast('Esperando a que cargue la imagen…'); img.addEventListener('load', start, { once: true }); return; }
    tools.classList.add('open'); box.hidden = false; lm.classList.add('cropping'); readRatio();
  }
  function stop() { tools.classList.remove('open'); box.hidden = true; lm.classList.remove('cropping'); drag = null; }
  // Coordenadas del puntero normalizadas a la imagen
  const pt = e => { const b = img.getBoundingClientRect(); return { x: (e.clientX - b.left) / b.width, y: (e.clientY - b.top) / b.height }; };
  box.addEventListener('pointerdown', e => {
    e.preventDefault(); box.setPointerCapture(e.pointerId);
    const p = pt(e), h = e.target.dataset.h;
    drag = h ? { h, ax: h.includes('w') ? r.x + r.w : r.x, ay: h.includes('n') ? r.y + r.h : r.y }   // esquina ancla = opuesta a la que se arrastra
      : { h: null, dx: p.x - r.x, dy: p.y - r.y };
  });
  box.addEventListener('pointermove', e => {
    if (!drag) return;
    const p = pt(e);
    if (!drag.h) { r.x = clamp(p.x - drag.dx, 0, 1 - r.w); r.y = clamp(p.y - drag.dy, 0, 1 - r.h); apply(); return; }
    // Redimensionar: la esquina arrastrada sigue al puntero, la opuesta (ax, ay) queda fija
    const px = clamp(p.x, 0, 1), py = clamp(p.y, 0, 1);
    const left = drag.h.includes('w'), top = drag.h.includes('n');
    let w = Math.max(minW(), left ? drag.ax - px : px - drag.ax), h = Math.max(minH(), top ? drag.ay - py : py - drag.ay);
    if (ratio) {
      h = hFromW(w);
      const maxH = top ? drag.ay : 1 - drag.ay;             // hasta el borde de la imagen en vertical
      if (h > maxH) { h = maxH; w = wFromH(h); }
      if (h < minH()) { h = minH(); w = wFromH(h); }
    }
    w = Math.min(w, left ? drag.ax : 1 - drag.ax); if (ratio) h = hFromW(w);
    r.w = w; r.h = h; r.x = left ? drag.ax - w : drag.ax; r.y = top ? drag.ay - h : drag.ay; apply();
  });
  const end = () => { drag = null; };
  box.addEventListener('pointerup', end); box.addEventListener('pointercancel', end);
  $('#ar').onchange = readRatio; $('#arW').oninput = $('#arH').oninput = () => { if ($('#ar').value === 'custom') readRatio(); };
  $('#lCrop').onclick = () => tools.classList.contains('open') ? stop() : start();
  $('#cropCancel').onclick = stop;
  $('#cropSave').onclick = async () => {
    if (!state.local) return;
    const b = $('#cropSave'); b.disabled = true; b.textContent = 'Recortando…';
    try {
      const d = await api('api/local.php', { action: 'crop', id: state.local.id, x: r.x.toFixed(6), y: r.y.toFixed(6), w: r.w.toFixed(6), h: r.h.toFixed(6) });
      renderCrops(d.crops); toast(`Recorte guardado: ${d.crop.width}×${d.crop.height}`); stop();
    } catch (e) { toast('Error: ' + e.message); }
    finally { b.disabled = false; b.textContent = 'Guardar recorte'; }
  };
  return { start, stop };
})();

// ---- grid clicks ----
grid.addEventListener('click', e => {
  const dl = e.target.closest('[data-dl]');
  if (dl) { e.stopPropagation(); downloadOne(+dl.dataset.dl); return; }
  const del = e.target.closest('[data-del]');
  if (del) {
    e.stopPropagation();
    const id = +del.dataset.del;
    if (!confirm(`¿Borrar #${id} del disco, con sus recortes? No se puede deshacer.`)) return;
    deleteLocal([id]).then(() => loadSaved()).catch(err => toast('Error: ' + err.message));
    return;
  }
  const card = e.target.closest('.card'); if (!card) return;
  if (state.selectMode && card.dataset.local) { toggleSel(card, e.target.classList.contains('sel') ? e.target.checked : undefined); return; }
  if (card.dataset.local) openLocal(+card.dataset.id); else openModal(+card.dataset.id);
});

// ---- jobs (segundo plano) ----
// «Descargar todo…»: popover con dos opciones — solo esta página (IDs visibles, cola explícita) o N desde esta página
function askBatch(e) {
  let box = $('#batchbox');
  if (!box) {
    box = document.createElement('div'); box.id = 'batchbox'; statusEl.appendChild(box);
    box.addEventListener('click', ev => ev.stopPropagation());
    document.addEventListener('click', () => box.classList.remove('open'));
  }
  if (box.classList.contains('open')) { box.classList.remove('open'); return; }
  e?.stopPropagation();
  // Se omiten las ya descargadas (el worker las saltaría igual) y las duplicadas por md5 (misma imagen con otro id)
  const ids = state.items.filter(i => !(i.saved || state.saved.has(i.id)) && !i.dup_of).map(i => i.id);
  const skipped = state.items.length - ids.length;
  box.innerHTML = `<div class="kv">Descargar en segundo plano</div>
    <button type="button" class="primary" id="bPage" ${ids.length ? '' : 'disabled'}>Esta página: ${ids.length} ${ids.length === 1 ? 'imagen' : 'imágenes'} con los filtros actuales</button>
    ${skipped ? `<div class="kv">${skipped} omitida${skipped > 1 ? 's' : ''}: ya descargadas o duplicadas</div>` : ''}
    <div class="row"><input type="number" id="bMax" min="1" max="500" value="50"> imágenes a partir de la página ${state.page} <button type="button" id="bGo">Encolar</button></div>`;
  box.classList.add('open');
  const create = (extra, msg) => {
    const p = Object.fromEntries(formParams()); Object.assign(p, extra, { p: state.page, action: 'create' });
    api('api/jobs.php', p).then(d => { toast('Job ' + d.job.id + ' encolado · ' + msg); box.classList.remove('open'); openJobs(); pollJobs(true); }).catch(err => toast('Error: ' + err.message));
  };
  $('#bPage').onclick = () => create({ ids: ids.join(','), max: ids.length }, `${ids.length} imágenes de esta página`);
  $('#bGo').onclick = () => { const n = Math.max(1, Math.min(500, +$('#bMax').value || 50)); create({ max: n }, `${n} desde la página ${state.page}`); };
  $('#bMax').addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); $('#bGo').click(); } });
}
function openJobs() { $('#panel').classList.add('open'); }
$('#jobsbtn').onclick = () => { $('#panel').classList.toggle('open'); pollJobs(true); };
$('#pClose').onclick = () => $('#panel').classList.remove('open');
$('#pClear').onclick = () => api('api/jobs.php', { action: 'clear' }).then(() => pollJobs(true));

async function pollJobs(now = false) {
  clearTimeout(state.jobsTimer);
  try {
    const d = await api('api/jobs.php');
    const prevActive = state.jobs.filter(j => ['queued', 'collecting', 'running'].includes(j.status)).map(j => j.id);
    state.jobs = d.jobs;
    renderJobs(d);
    const active = d.jobs.some(j => ['queued', 'collecting', 'running'].includes(j.status));
    // Si un job terminó desde el último sondeo, refresca marcas de "descargada"
    if (prevActive.some(id => !d.jobs.find(j => j.id === id && ['queued', 'collecting', 'running'].includes(j.status))) && state.tab !== 'log') {
      state.tab === 'saved' ? loadSaved() : search(state.page);
    }
    $('#jobsbtn').classList.toggle('active', active);
    $('#jobsbtn').textContent = active ? `Descargas (${d.jobs.filter(j => ['queued', 'collecting', 'running'].includes(j.status)).length} activas)` : 'Descargas';
    state.jobsTimer = setTimeout(pollJobs, active ? 2000 : 15000);
  } catch (e) { state.jobsTimer = setTimeout(pollJobs, 10000); }
}
function renderJobs(d) {
  $('#pWorker').textContent = d.worker_running ? '· worker en marcha' : '· worker parado';
  if (!d.jobs.length) { $('#pJobs').innerHTML = '<div class="kv">Sin descargas. Usa "Descargar todo…" en una búsqueda.</div>'; return; }
  $('#pJobs').innerHTML = d.jobs.map(j => {
    const pct = j.total ? (100 * j.cursor / j.total) : 0;
    const active = ['queued', 'collecting', 'running'].includes(j.status);
    const cur = j.current ? `descargando #${j.current.id}${j.current.total ? ` (${fmtBytes(j.current.bytes)} / ${fmtBytes(j.current.total)})` : ''}` : (j.status === 'collecting' ? 'recopilando IDs…' : '');
    const f = j.params || {};
    const filtros = [f.d, f.s === 'fav' ? 'populares' : null, f.ecchi && f.ecchi !== 'all' ? 'ecchi:' + f.ecchi : null, f.min ? `≥${f.min}px` : null, f.strict ? 'strict' : null, f.p > 1 ? 'pág ' + f.p : null, f.page_only ? 'solo página' : null].filter(Boolean).join(' · ');
    return `<div class="job" data-id="${j.id}">
      <div class="head"><b title="${esc(j.tags.join(', '))}">${esc(j.tags.join(', ') || 'todo')} <span class="kv">× ${j.max}</span></b><span class="st ${j.status}">${j.status}</span></div>
      ${filtros ? `<div class="kv">${esc(filtros)}</div>` : ''}
      <div class="progress"><i style="width:${pct.toFixed(1)}%"></i></div>
      <div class="head"><span>${j.cursor} / ${j.total} · <span style="color:var(--ok)">${j.downloaded} nuevas</span> · ${j.skipped} omitidas · <span style="color:${j.errors ? 'var(--err)' : 'inherit'}">${j.errors} errores</span></span>
        <span>${active ? `<button class="small" data-act="cancel">Cancelar</button>` : ''}${j.errors && !active ? `<button class="small" data-act="retry">Reintentar fallidos</button>` : ''}</span></div>
      ${cur ? `<div class="cur">${esc(cur)}</div>` : ''}
      ${j.error ? `<div class="failed">${esc(j.error)}</div>` : ''}
    </div>`;
  }).join('');
}
$('#pJobs').addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const id = b.closest('.job').dataset.id;
  if (b.dataset.act === 'cancel' && !confirm('¿Cancelar este job? Se detiene tras la imagen en curso.')) return;
  api('api/jobs.php', { action: b.dataset.act, id }).then(() => pollJobs(true)).catch(err => toast('Error: ' + err.message));
});

// ---- autocompletar tags (api/suggest.php → Zerochan /suggest). Solo se completa el último tag tras la coma ----
(() => {
  const input = $('#tags'), list = $('#suggest'), memo = new Map();
  let timer = null, active = -1, items = [];
  const close = () => { list.hidden = true; list.innerHTML = ''; active = -1; items = []; };
  const lastPart = () => input.value.split(',').at(-1).trim();
  function render() {
    if (!items.length) return close();
    list.innerHTML = items.map((it, i) => `<div data-i="${i}" class="${i === active ? 'active' : ''}"><b>${esc(it.tag)}</b><small>${esc(it.type)}${it.parent ? ' · ' + esc(it.parent) : ''}</small></div>`).join('');
    list.hidden = false;
  }
  function pick(i) {
    const it = items[i]; if (!it) return;
    const parts = input.value.split(','); parts[parts.length - 1] = ' ' + it.tag;
    input.value = parts.join(',').replace(/^ /, ''); close(); input.focus();
  }
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = lastPart();
    if (q.length < 2) return close();
    timer = setTimeout(async () => {
      let res = memo.get(q.toLowerCase());
      if (!res) {
        try { res = (await api('api/suggest.php?q=' + encodeURIComponent(q))).items.slice(0, 10); } catch { res = []; }
        memo.set(q.toLowerCase(), res);
      }
      if (lastPart() !== q) return;          // el usuario siguió escribiendo: respuesta obsoleta
      items = res; active = -1; render();
    }, 250);
  });
  input.addEventListener('keydown', e => {
    if (list.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; render(); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(active); }
    else if (e.key === 'Tab') { e.preventDefault(); pick(active >= 0 ? active : 0); }
    else if (e.key === 'Escape') { e.stopPropagation(); close(); }
  });
  list.addEventListener('mousedown', e => { e.preventDefault(); const row = e.target.closest('[data-i]'); if (row) pick(+row.dataset.i); });
  input.addEventListener('blur', () => setTimeout(close, 120));
})();

// ---- tabs ----
function switchTab(tab) {
  state.tab = tab; $('#suggest').hidden = true;
  document.querySelectorAll('.tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  $('#savedbar').classList.toggle('open', tab === 'saved');
  $('#loglines').hidden = tab !== 'log';
  if (tab === 'saved') loadSaved(); else if (tab === 'log') { saveState(); syncUrl(); loadLog(); } else search(state.page);
}
document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => switchTab(b.dataset.tab));
form.addEventListener('submit', e => { e.preventDefault(); state.page = 1; switchTab('search'); });

const storedTab = restoreState();
state.fromHistory = true;
switchTab(applyUrl() ?? storedTab);
pollJobs(true);
