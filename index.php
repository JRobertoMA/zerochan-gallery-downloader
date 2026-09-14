<?php
/**
 * Galería + descargador de Zerochan. Frontend vanilla; los datos vienen de api/*.php.
 * Las descargas en lote corren en segundo plano (api/jobs.php + cli/worker.php); aquí solo se consulta el progreso.
 */
require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zerochan Gallery</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%237c9cff'/%3E%3Ctext x='16' y='23' font-size='20' font-family='sans-serif' font-weight='700' text-anchor='middle' fill='%230b0d12'%3EZ%3C/text%3E%3C/svg%3E">
<style>
:root{--bg:#0f1115;--panel:#181b22;--line:#262a33;--text:#e6e8ee;--muted:#8b91a0;--accent:#7c9cff;--ok:#5ad38a;--warn:#ffcc66;--err:#ff6b6b}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--accent)}
code{background:#0c0e12;padding:1px 5px;border-radius:4px}
header{position:sticky;top:0;z-index:10;background:var(--panel);border-bottom:1px solid var(--line);padding:10px 16px}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bar h1{font-size:16px;margin:0 12px 0 0;white-space:nowrap}
.bar input[type=text]{flex:1 1 260px;min-width:200px}
.bar input.num{width:92px}
input,select,button{background:#0c0e12;color:var(--text);border:1px solid var(--line);border-radius:6px;padding:7px 10px;font:inherit}
button{cursor:pointer;background:#20242e}
button:hover{border-color:var(--accent)}
button.primary{background:var(--accent);color:#0b0d12;border-color:var(--accent);font-weight:600}
button.small{padding:3px 8px;font-size:12px}
button:disabled{opacity:.5;cursor:default}
label.chk{display:flex;gap:6px;align-items:center;color:var(--muted)}
main{padding:16px}
.status{color:var(--muted);margin:0 0 12px;display:flex;gap:16px;flex-wrap:wrap;align-items:center}
.subbar{display:none;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.subbar.open{display:flex}
.grid{columns:6 200px;column-gap:10px}
.card{break-inside:avoid;margin:0 0 10px;background:var(--panel);border:1px solid var(--line);border-radius:8px;overflow:hidden;cursor:pointer;position:relative}
.card img{display:block;width:100%;height:auto;background:#000}
.card .meta{padding:6px 8px;font-size:12px;color:var(--muted);display:flex;justify-content:space-between;gap:6px}
.card .meta b{color:var(--text);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.card .meta span{white-space:nowrap}
.card .dl{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.6);border:0;padding:4px 8px;border-radius:6px;font-size:12px;opacity:0;transition:opacity .15s}
.card:hover .dl{opacity:1}
.card.saved .dl{opacity:1;background:var(--ok);color:#0b0d12}
.card.dup .dl{opacity:1;background:var(--warn);color:#0b0d12}
.card .del{right:auto;left:6px;background:rgba(200,50,50,.75)}
.card.saved .del{opacity:0;background:rgba(200,50,50,.75);color:#fff}
.card.saved:hover .del{opacity:1}
.card .sel{display:none;position:absolute;top:8px;right:8px;width:20px;height:20px;margin:0;accent-color:var(--accent);cursor:pointer}
.grid.select .card .sel{display:block}
.grid.select .card .del{display:none}
.card.picked{outline:3px solid var(--accent);outline-offset:-3px}
.badge{display:inline-block;background:#ff7aa2;color:#0b0d12;font-weight:700;font-size:10px;border-radius:4px;padding:0 4px;margin-right:4px;vertical-align:1px}
.badge.dup{background:var(--warn)}
.badge.crop{background:var(--accent)}
#selbar{display:none;gap:6px;align-items:center}
#selbar.open{display:flex}
.pager{display:flex;gap:8px;justify-content:center;margin:16px 0}
.tabs{display:flex;gap:4px;margin-left:auto}
.tabs button.active{border-color:var(--accent);color:var(--accent)}
.tags{display:flex;flex-wrap:wrap;gap:4px}
.tag{background:#20242e;border:1px solid var(--line);border-radius:999px;padding:2px 8px;font-size:12px;cursor:pointer}
.tag:hover,.tag.active{border-color:var(--accent)}
.tag small{color:var(--muted);margin-left:4px}
/* modal */
.modal{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;z-index:20;overflow:auto}
.modal.open{display:block}
.modal .box{max-width:1400px;margin:24px auto;background:var(--panel);border-radius:10px;display:grid;grid-template-columns:1fr 320px;min-height:60vh}
.modal .pic{display:flex;align-items:center;justify-content:center;background:#000;border-radius:10px 0 0 10px}
.modal .pic img{max-width:100%;max-height:90vh;object-fit:contain}
.modal .side{padding:16px;display:flex;flex-direction:column;gap:12px;font-size:13px}
.modal .side h2{margin:0;font-size:16px}
.kv{color:var(--muted)}
.kv b{color:var(--text);font-weight:500}
.close{position:fixed;top:12px;right:16px;font-size:28px;background:none;border:0;color:#fff}
@media(max-width:900px){.modal .box{grid-template-columns:1fr}.modal .pic{border-radius:10px 10px 0 0}}
/* visor local + recorte */
.modal .wrap{position:relative;display:inline-block;line-height:0;touch-action:none;user-select:none;max-width:100%}
.modal .wrap img{display:block}
.cropbox{position:absolute;border:1px solid #fff;box-shadow:0 0 0 9999px rgba(0,0,0,.55);cursor:move}
.cropbox i{position:absolute;width:14px;height:14px;background:#fff;border:1px solid #000;border-radius:2px}
.cropbox i[data-h=nw]{left:-7px;top:-7px;cursor:nwse-resize}.cropbox i[data-h=ne]{right:-7px;top:-7px;cursor:nesw-resize}
.cropbox i[data-h=sw]{left:-7px;bottom:-7px;cursor:nesw-resize}.cropbox i[data-h=se]{right:-7px;bottom:-7px;cursor:nwse-resize}
.modal.cropping .pic{overflow:hidden}
.btnrow{display:flex;gap:6px;flex-wrap:wrap}
.crops{display:flex;flex-direction:column;gap:6px}
.crops .row{display:flex;gap:8px;align-items:center;font-size:12px;color:var(--muted)}
.crops .row img{width:64px;height:48px;object-fit:cover;border-radius:4px;background:#000;cursor:pointer}
.crops .row b{color:var(--text);font-weight:500}
.crops .row .sp{margin-left:auto;display:flex;gap:4px}
#cropTools{border-top:1px solid var(--line);padding-top:12px;display:none;flex-direction:column;gap:8px}
#cropTools.open{display:flex}
#cropTools .ar{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
#cropTools input.num{width:64px}
/* jobs panel */
.panel{position:fixed;right:16px;bottom:16px;width:min(440px,calc(100% - 32px));background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px;display:none;z-index:15;box-shadow:0 8px 30px rgba(0,0,0,.5);max-height:70vh;overflow:auto}
.panel.open{display:block}
.panel h3{margin:0 0 8px;font-size:14px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.panel h3 .actions{display:flex;gap:4px;margin-left:auto}
.job{border-top:1px solid var(--line);padding:8px 0;font-size:12px}
.job .head{display:flex;justify-content:space-between;gap:8px;align-items:center}
.job .head b{font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.job .st{padding:1px 6px;border-radius:4px;background:#20242e;white-space:nowrap}
.job .st.running,.job .st.collecting{background:var(--accent);color:#0b0d12}
.job .st.done{background:var(--ok);color:#0b0d12}
.job .st.error{background:var(--err);color:#0b0d12}
.job .st.cancelled{background:var(--warn);color:#0b0d12}
.progress{height:6px;background:#0c0e12;border-radius:4px;overflow:hidden;margin:6px 0}
.progress i{display:block;height:100%;background:var(--accent);width:0;transition:width .3s}
.job .cur{color:var(--muted)}
.job .failed{color:var(--err);max-height:60px;overflow:auto;font:11px/1.4 ui-monospace,monospace}
.jobsbtn{position:fixed;right:16px;bottom:16px;z-index:14}
.jobsbtn.active{border-color:var(--accent);color:var(--accent)}
/* log tab */
.loglines{font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;word-break:break-all;background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:10px;max-height:75vh;overflow:auto}
.loglines .WARN{color:var(--warn)}.loglines .ERROR{color:var(--err)}.loglines .INFO{color:var(--muted)}
.toast{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:#20242e;border:1px solid var(--line);padding:8px 14px;border-radius:8px;opacity:0;transition:opacity .2s;pointer-events:none;z-index:30}
.toast.show{opacity:1}
</style>
</head>
<body>
<header>
  <form class="bar" id="form">
    <h1>Zerochan</h1>
    <input type="text" name="tags" id="tags" placeholder="Tags separados por coma, ej: Genshin Impact, Lumine" autocomplete="off">
    <select name="s" title="Orden"><option value="">Recientes</option><option value="fav">Populares</option></select>
    <select name="t" title="Ventana (solo populares)"><option value="">Siempre</option><option value="1">Última semana</option><option value="2">Último día</option></select>
    <select name="d" title="Dimensiones"><option value="">Cualquier tamaño</option><option value="large">Large</option><option value="huge">Huge</option><option value="landscape">Horizontal</option><option value="portrait">Vertical</option><option value="square">Cuadrada</option></select>
    <select name="c" title="Color dominante"><option value="">Cualquier color</option>
      <?php foreach (ZerochanClient::COLORS as $c) echo "<option value=\"$c\">" . ucfirst($c) . "</option>"; ?>
    </select>
    <select name="ecchi" title="Contenido sugerente (tag Ecchi)"><option value="">Todo</option><option value="hide">Sin Ecchi</option><option value="only">Solo Ecchi</option></select>
    <input type="number" class="num" name="min" min="0" max="10000" step="10" placeholder="mín px" title="Lado corto mínimo en píxeles (filtro local)">
    <select name="l" title="Por página"><option>20</option><option selected>40</option><option>80</option><option>120</option></select>
    <label class="chk"><input type="checkbox" name="strict" value="1"> strict</label>
    <button type="submit" class="primary">Buscar</button>
    <div class="tabs">
      <button type="button" data-tab="search" class="active">Explorar</button>
      <button type="button" data-tab="saved">Descargados</button>
      <button type="button" data-tab="log">Log</button>
    </div>
  </form>
</header>

<main>
  <div class="subbar" id="savedbar">
    <input type="text" id="sq" placeholder="Buscar en descargados (tag o personaje)">
    <select id="ssort"><option value="date">Más recientes</option><option value="size">Más pesadas</option><option value="id">Por id</option></select>
    <button type="button" id="sgo">Filtrar</button>
    <button type="button" id="ssel" title="Seleccionar varias para borrar">Seleccionar</button>
    <span id="selbar">
      <button type="button" class="small" id="selAll">Todas (página)</button>
      <button type="button" class="small" id="selNone">Ninguna</button>
      <button type="button" class="small primary" id="sdel" disabled>Borrar (0)</button>
      <button type="button" class="small" id="selCancel">Cancelar</button>
    </span>
    <span id="stagchip"></span>
    <div class="tags" id="sfacet"></div>
  </div>
  <p class="status" id="status"></p>
  <div class="grid" id="grid"></div>
  <div class="loglines" id="loglines" hidden></div>
  <div class="pager" id="pager"></div>
</main>

<div class="modal" id="modal">
  <button class="close" id="close">×</button>
  <div class="box">
    <div class="pic"><img id="mImg" alt=""></div>
    <div class="side">
      <h2 id="mTitle"></h2>
      <div class="kv" id="mInfo"></div>
      <div class="kv" id="mChildren"></div>
      <div><a id="mSource" target="_blank" rel="noopener"></a> · <a id="mZc" target="_blank" rel="noopener">zerochan</a> · <a id="mFull" target="_blank" rel="noopener">full</a></div>
      <div><button class="primary" id="mDl">Descargar full</button> <span id="mDlStatus" class="kv"></span></div>
      <div class="tags" id="mTags"></div>
    </div>
  </div>
</div>

<div class="modal" id="lmodal">
  <button class="close" id="lclose">×</button>
  <div class="box">
    <div class="pic"><div class="wrap"><img id="lImg" alt=""><div class="cropbox" id="cropbox" hidden><i data-h="nw"></i><i data-h="ne"></i><i data-h="sw"></i><i data-h="se"></i></div></div></div>
    <div class="side">
      <h2 id="lTitle"></h2>
      <div class="kv" id="lInfo"></div>
      <div class="btnrow"><a id="lOpen" target="_blank" rel="noopener"><button type="button">Abrir original</button></a><button type="button" id="lCrop">Recortar</button><button type="button" id="lDel" style="border-color:var(--err);color:var(--err)">Borrar</button></div>
      <div id="cropTools">
        <div class="ar"><label class="kv">Relación</label>
          <select id="ar"><option value="16:9">16:9</option><option value="4:3">4:3</option><option value="3:2">3:2</option><option value="1:1">1:1</option><option value="4:5">4:5</option><option value="9:16">9:16</option><option value="free">Libre</option><option value="custom">Personalizada</option></select>
          <span id="arCustom" hidden><input type="number" class="num" id="arW" min="1" step="1" value="21"> : <input type="number" class="num" id="arH" min="1" step="1" value="9"></span>
        </div>
        <div class="kv">Recorte: <b id="cropInfo"></b> px · arrastra el cuadro o sus esquinas</div>
        <div class="btnrow"><button type="button" class="primary" id="cropSave">Guardar recorte</button><button type="button" id="cropCancel">Cancelar</button></div>
      </div>
      <div class="crops" id="lCrops"></div>
      <div class="tags" id="lTags"></div>
    </div>
  </div>
</div>

<button class="jobsbtn" id="jobsbtn" type="button">Descargas</button>
<div class="panel" id="panel">
  <h3>Descargas en segundo plano <span class="kv" id="pWorker"></span>
    <span class="actions"><button class="small" id="pClear" type="button" title="Quitar terminados">Limpiar</button><button class="small" id="pClose" type="button">×</button></span>
  </h3>
  <div id="pJobs"></div>
</div>
<div class="toast" id="toast"></div>

<script>
const $ = s => document.querySelector(s);
const form = $('#form'), grid = $('#grid'), statusEl = $('#status'), pager = $('#pager');
let state = { tab: 'search', page: 1, items: [], saved: new Set(), savedFilter: { tag: '', q: '', sort: 'date', p: 1 }, jobs: [], jobsTimer: null, selectMode: false, sel: new Set(), local: null };

const fmtBytes = b => b > 1048576 ? (b/1048576).toFixed(1)+' MB' : b > 1024 ? (b/1024).toFixed(0)+' KB' : b+' B';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function toast(msg){ const t=$('#toast'); t.textContent=msg; t.classList.add('show'); clearTimeout(t._h); t._h=setTimeout(()=>t.classList.remove('show'),2200); }
async function api(url, body){
  const r = body ? await fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams(body) }) : await fetch(url);
  const d = await r.json().catch(() => ({ error: 'respuesta inválida' }));
  if (d.error) throw new Error(d.error);
  return d;
}

function formParams(){
  const fd = new FormData(form), p = new URLSearchParams();
  for (const [k,v] of fd) if (v !== '') p.set(k, v);
  return p;
}
function saveState(){ try{ localStorage.setItem('zc.form', JSON.stringify(Object.fromEntries(formParams()))); }catch{} }
function restoreState(){
  try{ const s = JSON.parse(localStorage.getItem('zc.form')||'{}');
    for (const el of form.elements) { if (!el.name) continue; if (el.type==='checkbox') el.checked = !!s[el.name]; else el.value = s[el.name] ?? (el.tagName==='SELECT' ? el.value : ''); }
    if (!s.l) form.elements.l.value = '40';
  }catch{}
}

// ---- explorar ----
async function search(page = 1){
  state.page = page; saveState();
  const p = formParams(); p.set('p', page);
  statusEl.textContent = 'Buscando…'; grid.innerHTML = ''; pager.innerHTML = '';
  try { renderSearch(await api('api/search.php?' + p)); }
  catch (e) { statusEl.innerHTML = `<span style="color:var(--err)">${esc(e.message)}</span>`; }
}

function renderSearch(d){
  state.items = d.items;
  const hidden = (d.raw_count ?? d.items.length) - d.items.length;
  const filtros = [d.ecchi !== 'all' ? 'Ecchi' : null, d.min > 0 ? `≥${d.min}px` : null].filter(Boolean).join(', ');
  statusEl.innerHTML = `${d.items.length} resultados${hidden>0?` (${hidden} ocultos por ${filtros})`:''} · página ${d.page} · <a href="${d.url}" target="_blank" rel="noopener">API</a>`;
  if (d.items.length) {
    const btn = document.createElement('button'); btn.textContent = 'Descargar todo…'; btn.onclick = askBatch; statusEl.appendChild(btn);
  }
  grid.innerHTML = d.items.map(it => {
    const saved = it.saved || state.saved.has(it.id), dup = !saved && it.dup_of;
    return `
    <div class="card ${saved?'saved':''} ${dup?'dup':''}" data-id="${it.id}">
      <img src="${it.thumbnail}" loading="lazy" alt="" width="${it.width}" height="${it.height}">
      <button class="dl" data-dl="${it.id}" title="${dup?'Misma imagen ya descargada como #'+it.dup_of:saved?'Ya descargada':'Descargar full'}">${saved?'✓':dup?'≈':'⬇'}</button>
      <div class="meta"><b title="${esc(it.tag)}">${esc(it.tag)}</b><span>${(it.tags||[]).includes('Ecchi')?'<i class="badge" title="Ecchi">E</i>':''}${it.width}×${it.height}</span></div>
    </div>`;
  }).join('');
  // Paginar según lo que devolvió la API, no lo que quedó tras los filtros locales
  const hasNext = (d.raw_count ?? d.items.length) >= d.limit;
  pager.innerHTML = `<button ${d.page<=1?'disabled':''} id="prev">← Anterior</button><span style="align-self:center;color:var(--muted)">${d.page}</span><button ${hasNext?'':'disabled'} id="next">Siguiente →</button>`;
  $('#prev')?.addEventListener('click', ()=>search(d.page-1));
  $('#next')?.addEventListener('click', ()=>search(d.page+1));
}

// ---- descargados (índice local) ----
async function loadSaved(){
  const f = state.savedFilter, p = new URLSearchParams({ tag: f.tag, q: f.q, sort: f.sort, p: f.p, l: 60 });
  statusEl.textContent = 'Cargando…'; grid.innerHTML = ''; pager.innerHTML = '';
  try { renderSaved(await api('api/downloads.php?' + p)); }
  catch (e) { statusEl.innerHTML = `<span style="color:var(--err)">${esc(e.message)}</span>`; }
}
function renderSaved(d){
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
      <div class="meta"><b title="${esc(it.primary)}">${esc(it.primary)}</b><span>${(it.tags||[]).includes('Ecchi')?'<i class="badge" title="Ecchi">E</i>':''}${it.crops?`<i class="badge crop" title="${it.crops} recorte${it.crops>1?'s':''}">✂${it.crops}</i>`:''}${fmtBytes(it.size)}</span></div>
    </div>`).join('');
  const pages = Math.ceil(d.count / d.limit);
  pager.innerHTML = pages > 1 ? `<button ${d.page<=1?'disabled':''} id="sprev">← Anterior</button><span style="align-self:center;color:var(--muted)">${d.page} / ${pages}</span><button ${d.page>=pages?'disabled':''} id="snext">Siguiente →</button>` : '';
  $('#sprev')?.addEventListener('click', ()=>{ f.p--; loadSaved(); });
  $('#snext')?.addEventListener('click', ()=>{ f.p++; loadSaved(); });
}
$('#sgo').onclick = () => { state.savedFilter.q = $('#sq').value.trim(); state.savedFilter.sort = $('#ssort').value; state.savedFilter.p = 1; loadSaved(); };
$('#sq').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#sgo').click(); } });
$('#sfacet').addEventListener('click', e => { const t = e.target.closest('[data-tag]'); if (!t) return; state.savedFilter.tag = t.dataset.tag; state.savedFilter.p = 1; loadSaved(); });
$('#stagchip').addEventListener('click', () => { state.savedFilter.tag = ''; state.savedFilter.p = 1; loadSaved(); });

// ---- borrar / selección múltiple ----
async function deleteLocal(ids){
  const d = await api('api/local.php', { action: 'delete', ids: ids.join(',') });
  d.deleted.forEach(id => { state.saved.delete(id); state.sel.delete(id); document.querySelectorAll(`.card[data-id="${id}"]`).forEach(c => c.remove()); });
  toast(`${d.deleted.length} borrada${d.deleted.length!==1?'s':''} · ${fmtBytes(d.freed)} liberados${d.missing.length?` · ${d.missing.length} no existían`:''}`);
  return d;
}
function setSelectMode(on){
  state.selectMode = on; state.sel.clear();
  grid.classList.toggle('select', on); $('#selbar').classList.toggle('open', on); $('#ssel').hidden = on;
  grid.querySelectorAll('.card').forEach(c => { c.classList.remove('picked'); const cb = c.querySelector('.sel'); if (cb) cb.checked = false; });
  updateSelBar();
}
function updateSelBar(){ const n = state.sel.size; $('#sdel').textContent = `Borrar (${n})`; $('#sdel').disabled = !n; }
function toggleSel(card, on){
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
  if (!confirm(`¿Borrar ${ids.length} imagen${ids.length>1?'es':''} del disco, con sus recortes? No se puede deshacer.`)) return;
  try { await deleteLocal(ids); setSelectMode(false); loadSaved(); } catch (e) { toast('Error: ' + e.message); }
};

// ---- log ----
async function loadLog(){
  statusEl.textContent = ''; grid.innerHTML = ''; pager.innerHTML = '';
  const box = $('#loglines'); box.hidden = false;
  try {
    const d = await api('api/log.php?n=200');
    statusEl.innerHTML = `${d.lines.length} líneas de <code>logs/zerochan.log</code> `;
    const b = document.createElement('button'); b.textContent = 'Refrescar'; b.onclick = loadLog; statusEl.appendChild(b);
    box.innerHTML = d.lines.length ? d.lines.slice().reverse().map(l => { const lvl = (l.match(/ (INFO|WARN|ERROR) /)||[])[1]||''; return `<div class="${lvl}">${esc(l)}</div>`; }).join('') : '<span class="kv">Sin entradas todavía.</span>';
  } catch (e) { box.textContent = e.message; }
}

// ---- modal ----
async function openModal(id){
  const m = $('#modal'); m.classList.add('open');
  $('#mImg').src = ''; $('#mTitle').textContent = 'Cargando…'; $('#mInfo').textContent=''; $('#mChildren').textContent=''; $('#mTags').innerHTML=''; $('#mDlStatus').textContent='';
  let it;
  try { it = await api('api/item.php?id=' + id); } catch (e) { $('#mTitle').textContent = e.message; return; }
  m.dataset.id = it.id;
  $('#mImg').src = it.large; $('#mTitle').textContent = it.primary;
  $('#mInfo').innerHTML = `${it.tags.includes('Ecchi')?'<i class="badge" title="Ecchi">E</i>':''}<b>${it.width}×${it.height}</b> · ${fmtBytes(it.size)} · id ${it.id}`;
  $('#mChildren').innerHTML = it.children > 0 ? `<b>${it.children}</b> variante${it.children>1?'s':''} · <a href="https://www.zerochan.net/${it.id}" target="_blank" rel="noopener">ver en Zerochan</a>` : '';
  const src = $('#mSource'); src.href = it.source || '#'; src.textContent = it.source ? new URL(it.source).hostname.replace(/^www\./,'') : 'sin fuente';
  $('#mZc').href = 'https://www.zerochan.net/' + it.id; $('#mFull').href = it.full;
  $('#mTags').innerHTML = it.tags.map(t => `<span class="tag">${esc(t)}</span>`).join('');
  const saved = state.saved.has(it.id) || state.items.find(i => i.id === it.id)?.saved;
  $('#mDl').disabled = !!saved; $('#mDlStatus').textContent = saved ? 'ya descargada' : '';
}
$('#close').onclick = () => $('#modal').classList.remove('open');
$('#modal').addEventListener('click', e => { if (e.target === $('#modal')) $('#modal').classList.remove('open'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { $('#modal').classList.remove('open'); $('#panel').classList.remove('open'); closeLocal(); if (state.selectMode) setSelectMode(false); } });
$('#mTags').addEventListener('click', e => {
  if (!e.target.classList.contains('tag')) return;
  const t = e.target.textContent, cur = $('#tags').value.split(',').map(s=>s.trim()).filter(Boolean);
  if (e.shiftKey && !cur.includes(t)) cur.push(t); else cur.splice(0, cur.length, t);
  $('#tags').value = cur.join(', '); $('#modal').classList.remove('open'); switchTab('search'); search(1);
});
$('#mDl').onclick = async () => { await downloadOne(+$('#modal').dataset.id, $('#mDlStatus')); $('#mDl').disabled = true; };

async function downloadOne(id, statusNode){
  if (statusNode) statusNode.textContent = 'descargando…';
  try {
    const d = await api('api/download.php', { id });
    state.saved.add(id);
    document.querySelectorAll(`.card[data-id="${id}"]`).forEach(c => { c.classList.add('saved'); c.classList.remove('dup'); const b=c.querySelector('.dl'); if(b) b.textContent='✓'; });
    if (statusNode) statusNode.innerHTML = `${d.status==='skipped'?'ya existía':'guardada'} → <a href="${d.url}" target="_blank">${esc(d.path)}</a>`;
    toast((d.status==='skipped'?'Ya existía: ':'Guardada: ') + d.path);
  } catch (e) { if (statusNode) statusNode.textContent = e.message; toast('Error: ' + e.message); }
}

// ---- visor local (descargadas) ----
const lm = $('#lmodal');
async function openLocal(id){
  lm.classList.add('open'); cropper.stop();
  $('#lImg').src = ''; $('#lTitle').textContent = 'Cargando…'; $('#lInfo').textContent = ''; $('#lCrops').innerHTML = ''; $('#lTags').innerHTML = '';
  let it;
  try { it = await api('api/local.php?id=' + id); } catch (e) { $('#lTitle').textContent = e.message; return; }
  state.local = it;
  $('#lImg').src = it.url; $('#lTitle').textContent = it.primary; $('#lOpen').href = it.url;
  $('#lInfo').innerHTML = `${it.tags.includes('Ecchi')?'<i class="badge" title="Ecchi">E</i>':''}<b>${it.width}×${it.height}</b> · ${fmtBytes(it.size)} · <a href="https://www.zerochan.net/${it.id}" target="_blank" rel="noopener">#${it.id}</a>${it.downloaded_at?` · ${it.downloaded_at.slice(0,10)}`:''}`;
  $('#lTags').innerHTML = it.tags.map(t => `<span class="tag">${esc(t)}</span>`).join('');
  renderCrops(it.crops);
}
function closeLocal(){ if (!lm.classList.contains('open')) return; lm.classList.remove('open'); cropper.stop(); state.local = null; }
function renderCrops(crops){
  state.local.crops = crops;
  document.querySelectorAll(`.card[data-id="${state.local.id}"] .meta span`).forEach(s => {
    s.querySelector('.badge.crop')?.remove();
    if (crops.length) s.insertAdjacentHTML('afterbegin', `<i class="badge crop" title="${crops.length} recorte${crops.length>1?'s':''}">✂${crops.length}</i>`);
  });
  $('#lCrops').innerHTML = crops.length ? `<div class="kv">${crops.length} recorte${crops.length>1?'s':''}</div>` + crops.map(c => `
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
  function apply(){
    Object.assign(box.style, { left: r.x*100+'%', top: r.y*100+'%', width: r.w*100+'%', height: r.h*100+'%' });
    $('#cropInfo').textContent = `${Math.round(r.w*W())}×${Math.round(r.h*H())}`;
  }
  function reset(){
    if (ratio) { if (W()/H() > ratio) { r.h = 1; r.w = wFromH(1); } else { r.w = 1; r.h = hFromW(1); } }
    else { r.w = r.h = 1; }
    r.x = (1 - r.w) / 2; r.y = (1 - r.h) / 2; apply();
  }
  function readRatio(){
    const v = $('#ar').value; $('#arCustom').hidden = v !== 'custom';
    if (v === 'free') ratio = null;
    else { const [a, b] = v === 'custom' ? [+$('#arW').value, +$('#arH').value] : v.split(':').map(Number); ratio = a > 0 && b > 0 ? a / b : null; }
    reset();
  }
  function start(){
    if (!state.local) return;
    if (!img.naturalWidth) { toast('Esperando a que cargue la imagen…'); img.addEventListener('load', start, { once: true }); return; }
    tools.classList.add('open'); box.hidden = false; lm.classList.add('cropping'); readRatio();
  }
  function stop(){ tools.classList.remove('open'); box.hidden = true; lm.classList.remove('cropping'); drag = null; }
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
function askBatch(){
  const n = prompt('¿Cuántas imágenes descargar de esta búsqueda (máx 500)? Empieza en la página actual y corre en segundo plano.', '50');
  if (!n) return;
  const p = Object.fromEntries(formParams()); p.p = state.page; p.max = Math.max(1, Math.min(500, +n)); p.action = 'create';
  api('api/jobs.php', p).then(d => { toast('Job ' + d.job.id + ' encolado'); openJobs(); pollJobs(true); }).catch(e => toast('Error: ' + e.message));
}
function openJobs(){ $('#panel').classList.add('open'); }
$('#jobsbtn').onclick = () => { $('#panel').classList.toggle('open'); pollJobs(true); };
$('#pClose').onclick = () => $('#panel').classList.remove('open');
$('#pClear').onclick = () => api('api/jobs.php', { action: 'clear' }).then(() => pollJobs(true));

async function pollJobs(now = false){
  clearTimeout(state.jobsTimer);
  try {
    const d = await api('api/jobs.php');
    const prevActive = state.jobs.filter(j => ['queued','collecting','running'].includes(j.status)).map(j => j.id);
    state.jobs = d.jobs;
    renderJobs(d);
    const active = d.jobs.some(j => ['queued','collecting','running'].includes(j.status));
    // Si un job terminó desde el último sondeo, refresca marcas de "descargada"
    if (prevActive.some(id => !d.jobs.find(j => j.id === id && ['queued','collecting','running'].includes(j.status))) && state.tab !== 'log') {
      state.tab === 'saved' ? loadSaved() : search(state.page);
    }
    $('#jobsbtn').classList.toggle('active', active);
    $('#jobsbtn').textContent = active ? `Descargas (${d.jobs.filter(j => ['queued','collecting','running'].includes(j.status)).length} activas)` : 'Descargas';
    state.jobsTimer = setTimeout(pollJobs, active ? 2000 : 15000);
  } catch (e) { state.jobsTimer = setTimeout(pollJobs, 10000); }
}
function renderJobs(d){
  $('#pWorker').textContent = d.worker_running ? '· worker en marcha' : '· worker parado';
  if (!d.jobs.length) { $('#pJobs').innerHTML = '<div class="kv">Sin descargas. Usa "Descargar todo…" en una búsqueda.</div>'; return; }
  $('#pJobs').innerHTML = d.jobs.map(j => {
    const pct = j.total ? (100 * j.cursor / j.total) : 0;
    const active = ['queued','collecting','running'].includes(j.status);
    const cur = j.current ? `descargando #${j.current.id}${j.current.total ? ` (${fmtBytes(j.current.bytes)} / ${fmtBytes(j.current.total)})` : ''}` : (j.status === 'collecting' ? 'recopilando IDs…' : '');
    const f = j.params || {};
    const filtros = [f.d, f.s === 'fav' ? 'populares' : null, f.ecchi && f.ecchi !== 'all' ? 'ecchi:' + f.ecchi : null, f.min ? `≥${f.min}px` : null, f.strict ? 'strict' : null, f.p > 1 ? 'pág ' + f.p : null].filter(Boolean).join(' · ');
    return `<div class="job" data-id="${j.id}">
      <div class="head"><b title="${esc(j.tags.join(', '))}">${esc(j.tags.join(', ') || 'todo')} <span class="kv">× ${j.max}</span></b><span class="st ${j.status}">${j.status}</span></div>
      ${filtros ? `<div class="kv">${esc(filtros)}</div>` : ''}
      <div class="progress"><i style="width:${pct.toFixed(1)}%"></i></div>
      <div class="head"><span>${j.cursor} / ${j.total} · <span style="color:var(--ok)">${j.downloaded} nuevas</span> · ${j.skipped} omitidas · <span style="color:${j.errors?'var(--err)':'inherit'}">${j.errors} errores</span></span>
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

// ---- tabs ----
function switchTab(tab){
  state.tab = tab;
  document.querySelectorAll('.tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab===tab));
  $('#savedbar').classList.toggle('open', tab === 'saved');
  $('#loglines').hidden = tab !== 'log';
  if (tab === 'saved') loadSaved(); else if (tab === 'log') loadLog(); else search(state.page);
}
document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => switchTab(b.dataset.tab));
form.addEventListener('submit', e => { e.preventDefault(); switchTab('search'); search(1); });

restoreState();
search(1);
pollJobs(true);
</script>
</body>
</html>
