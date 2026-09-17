<?php
/**
 * Galería + descargador de Zerochan. Frontend vanilla: markup aquí, estilos en assets/app.css,
 * lógica en assets/app.js; los datos vienen de api/*.php.
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
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header>
  <form class="bar" id="form">
    <h1>Zerochan</h1>
    <div class="ac"><input type="text" name="tags" id="tags" placeholder="Tags separados por coma, ej: Genshin Impact, Lumine" autocomplete="off"><div class="ac-list" id="suggest" hidden></div></div>
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

<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
