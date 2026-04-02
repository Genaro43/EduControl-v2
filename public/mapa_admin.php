<?php
// mapa_usuario.php
// Vista de usuario / admin ligero: mapa de aulas + modal que carga grupos/maestros desde sus tablas
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>Mapa aulas — modal con grupos y maestros</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>

<style>
  :root{ --brand:#6f2b4b; --muted:#888; --accent:#c75c90; --verde:#2ecc71; --rojo:#e74c3c; }
  body{ margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial; background:#f3f5f8; color:#111; -webkit-font-smoothing:antialiased; }
  .container{ max-width:1100px; margin:18px auto; padding:12px; }
  h1{ margin:0 0 8px 0; color:var(--brand); font-size:20px; }
  .toolbar{ display:flex; gap:8px; align-items:center; margin-bottom:8px; }
  .toolbar .small{ margin-left:auto; color:var(--muted); font-size:13px; }
  button.btn{ padding:8px 10px; border-radius:6px; border:0; cursor:pointer; background:var(--brand); color:#fff; }
  button.btn.secondary{ background:var(--muted); }
  .size-control{ display:flex; gap:8px; align-items:center; color:#333; }
  .size-control input[type="range"]{ width:160px; }
  #map{ height:650px; border-radius:8px; border:8px solid #fff; box-shadow:0 8px 24px rgba(0,0,0,.12); background:#e9e9e9; }
  #lista{ margin-top:12px; max-height:160px; overflow:auto; background:#fff; padding:8px; border-radius:6px; color:#111; border:1px solid #eee; }

  /* Modal fijo abajo-izquierda sobre el mapa */
  .info-modal{
    position: fixed;
    left:18px;
    top:calc(100% - 260px);
    width:480px;
    min-height:220px;
    background:#fff;
    color:#111;
    border-radius:8px;
    padding:18px;
    box-shadow:0 18px 40px rgba(0,0,0,.35);
    z-index:9999;
    display:none;
    box-sizing:border-box;
  }
  .info-modal.ocupada{ border:6px solid var(--rojo); }
  .info-modal.libre{ border:6px solid var(--verde); }
  .info-top{ display:flex; gap:16px; align-items:flex-start; }
  .info-left{ flex:1; }
  .info-left h3{ margin:0; font-size:34px; line-height:1; font-weight:600; font-family:"Times New Roman", Georgia, serif; }
  .meta{ margin-top:12px; font-size:15px; color:#333; display:flex; flex-direction:column; gap:6px;}
  .info-right{ width:160px; height:120px; border-radius:6px; border:3px dashed #ddd; display:flex; align-items:center; justify-content:center; font-weight:700; color:#999; background:#fafafa; cursor:pointer; user-select:none; }
  .info-bottom{ margin-top:18px; display:flex; align-items:center; gap:12px; }
  .report-link{ display:inline-block; text-decoration:none; background:var(--accent); color:#fff; padding:10px 16px; border-radius:8px; font-size:15px; }
  .close-modal{ position:absolute; top:10px; right:10px; background:transparent; border:0; font-size:18px; cursor:pointer; color:rgba(0,0,0,0.6); }

  .field-select{ padding:8px; border-radius:6px; border:1px solid #ccc; font-size:15px; min-width:150px; }
  .coord-input{ width:100px; padding:6px; border-radius:6px; border:1px solid #ccc; }

  .coords-row{ margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .coords-row label{ font-size:13px; color:#444; }

  .small{ font-size:13px; color:var(--muted); }

  /* responsive */
  @media (max-width:600px){
    .info-modal{ width:calc(100% - 36px); left:18px; right:18px; top:calc(100% - 300px); }
    .info-right{ width:120px; height:90px; }
    .info-left h3{ font-size:26px; }
  }

  /* Leaflet controls above */
  .leaflet-top, .leaflet-bottom{ z-index:600; }
</style>

</head>
<body>
  <div class="container">
    <h1>Mapa de Aulas — editar / ver</h1>

    <div class="toolbar" role="toolbar">
      <button id="modoAgregar" class="btn secondary">Modo: Añadir (clic)</button>
      <button id="modoArrastrar" class="btn secondary">Modo: Mover marcadores</button>
      <button id="limpiar" class="btn secondary">Limpiar locales</button>

      <div class="size-control" title="Ajusta el ancho del pin">
        <label class="small">Tamaño pin:</label>
        <input id="sizeRange" type="range" min="24" max="240" value="90" />
        <span id="sizeDisplay" class="small">90px</span>
      </div>

      <div class="small">Clic en pin → abrir modal. Foto (clic) = editar selects (grupos/maestros/horario).</div>
    </div>

    <div id="map" role="application" aria-label="Mapa de aulas"></div>

    <div id="lista" aria-live="polite" aria-atomic="true"></div>
  </div>

  <!-- Modal -->
  <div id="infoModal" class="info-modal ocupada" role="dialog" aria-hidden="true" aria-label="Información del aula">
    <button class="close-modal" id="btnCloseModal" title="Cerrar">✕</button>
    <div class="info-top">
      <div class="info-left">
        <h3 id="modalAulaTitle">Aula</h3>
        <div class="meta">
          <div id="fieldGrupo">Grupo: <span id="modalGrupo"></span></div>
          <div id="fieldMaestro">Maestr@: <span id="modalMaestro"></span></div>
          <div id="fieldHorario">Horario: <span id="modalHorario"></span></div>

          <div id="coordsDisplay" class="small">Coords: <span id="mCoords"></span></div>
          <div id="coordsEdit" class="coords-row" style="display:none" aria-hidden="true">
            <label>X (%) <input id="inputX" class="coord-input" type="number" step="0.1" min="0" max="100" /></label>
            <label>Y (%) <input id="inputY" class="coord-input" type="number" step="0.1" min="0" max="100" /></label>
            <button id="btnSaveCoords" class="btn">Guardar coords</button>
            <button id="btnCancelCoords" class="btn secondary">Cancelar</button>
          </div>
        </div>
      </div>

      <div class="info-right" id="modalFoto" title="Clic para editar grupo/maestr@/horario">
        <div id="fotoInner">Foto</div>
      </div>
    </div>

    <div class="info-bottom">
      <a id="btnReports" class="report-link" href="reportes.php" target="_self" rel="noopener">Reportes</a>
      <button id="btnDesocupar" class="report-link" style="background:#27ae60; display:none; margin-left:8px;">Desocupar</button>
    </div>
  </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* ---------- CONFIG ---------- */
const IMG_SRC = '../src/img/mapa.jpeg';
const PIN_GREEN = '../src/img/pin_green.png';
const PIN_RED   = '../src/img/pin_red.png';
const API_BASE  = '../api'; // carpeta de tus endpoints

/* ---------- ESTADO ---------- */
let MAP, IMG_W, IMG_H, BOUNDS;
let modoAgregar = false;
let modoArrastrar = false;
let markers = {}; // id -> marker
let aulas = {};   // id -> { id, nombre, xPct, yPct, estado, grupo, maestro, grupo_text, maestro_text, horario }

let ICON_W = 90;
let pinAspect = 1.333;
let ICON_H = Math.round(ICON_W * pinAspect);

/* caches */
let cacheGrupos = null;    // [{id,semestre,grupo,label}]
let cacheMaestros = null;  // [{id,nombre}]
let cacheHorarios = null;  // optional if you have get_horarios.php

/* modal state */
const infoModal = document.getElementById('infoModal');
const modalAulaTitle = document.getElementById('modalAulaTitle');
const modalGrupo = document.getElementById('modalGrupo');
const modalMaestro = document.getElementById('modalMaestro');
const modalHorario = document.getElementById('modalHorario');
const modalFoto = document.getElementById('modalFoto');
const fotoInner = document.getElementById('fotoInner');
const coordsDisplay = document.getElementById('coordsDisplay');
const coordsEdit = document.getElementById('coordsEdit');
const inputX = document.getElementById('inputX');
const inputY = document.getElementById('inputY');
const btnSaveCoords = document.getElementById('btnSaveCoords');
const btnCancelCoords = document.getElementById('btnCancelCoords');
const btnCloseModal = document.getElementById('btnCloseModal');
const btnDesocupar = document.getElementById('btnDesocupar');
const sizeRange = document.getElementById('sizeRange');
const sizeDisplay = document.getElementById('sizeDisplay');
const lista = document.getElementById('lista');

let modalAulaId = null;
let modalFieldEditMode = false;
let modalCoordEditMode = false;

/* ---------- HELPERS ---------- */
function hmToMinutes(hm){ const [h,m] = hm.split(':').map(Number); return h*60+m; }
const PERIODOS = [
  { id:1,label:'hora1', inicio:'07:00', fin:'07:50' },
  { id:2,label:'hora2', inicio:'07:50', fin:'08:40' },
  { id:3,label:'hora3', inicio:'08:40', fin:'09:00' },
  { id:4,label:'hora4', inicio:'10:00', fin:'10:50' },
  { id:5,label:'hora5', inicio:'10:50', fin:'11:40' },
  { id:6,label:'hora6', inicio:'11:40', fin:'12:30' },
  { id:7,label:'hora7', inicio:'12:30', fin:'13:20' },
  { id:8,label:'hora8', inicio:'13:20', fin:'14:10' },
  { id:9,label:'hora9', inicio:'14:10', fin:'15:00' },
  { id:10,label:'hora10', inicio:'15:00', fin:'15:50' },
  { id:11,label:'hora11', inicio:'16:10', fin:'17:00' }
];
const RECREOS = [{ inicio:'09:00', fin:'10:00' }, { inicio:'15:50', fin:'16:10' }];

function obtenerPeriodoActual(){
  const now = new Date();
  const mins = now.getHours()*60 + now.getMinutes();
  for(const r of RECREOS){ if(mins >= hmToMinutes(r.inicio) && mins < hmToMinutes(r.fin)) return { type:'recreo', periodo:null }; }
  for(const p of PERIODOS){ if(mins >= hmToMinutes(p.inicio) && mins < hmToMinutes(p.fin)) return { type:'clase', periodo:p }; }
  return { type:'fuera', periodo:null };
}

/* fetch JSON defensivo */
async function fetchJson(url, opts){
  const res = await fetch(url, opts);
  const text = await res.text();
  const t = text.trim();
  if(t.length === 0) throw new Error('Respuesta vacía desde ' + url);
  if(t[0] === '<'){ console.error('Respuesta HTML inesperada:', t.substring(0,300)); throw new Error('Respuesta inesperada (HTML) desde ' + url); }
  try { return JSON.parse(t); } catch(e){ console.error('Error parseando JSON raw:', t); throw new Error('JSON inválido desde ' + url + ': ' + e.message); }
}

/* icon factory */
function cargarAspectoPin(){
  const img = new Image();
  img.src = PIN_GREEN + '?_=' + Date.now();
  img.onload = () => {
    if(img.naturalWidth>0){ pinAspect = img.naturalHeight / img.naturalWidth; ICON_H = Math.round(ICON_W * pinAspect); updateAllIcons(); updateSizeDisplay(); }
  };
}
function makeIcon(estado, w = ICON_W){
  const h = Math.round(w * pinAspect);
  const url = (estado === 'libre') ? PIN_GREEN : PIN_RED;
  return L.icon({ iconUrl: url, iconSize: [w,h], iconAnchor: [Math.round(w/2), h], popupAnchor: [0, -h+8] });
}

/* px <-> pct */
function pxToPct(xPx,yPx){ return { xPct: (xPx/IMG_W)*100, yPct: (yPx/IMG_H)*100 }; }
function pctToPx(xPct,yPct){ return { x: (xPct/100)*IMG_W, y: (yPct/100)*IMG_H }; }

/* ---------- CARGAR AULAS desde API ---------- */
async function cargarAulasDesdeAPI(){
  try {
    const j = await fetchJson(API_BASE + '/get_aulas.php');
    if(!j.ok) throw new Error(j.error || 'Sin datos de aulas');
    // limpiar
    Object.values(markers).forEach(m => MAP.removeLayer(m));
    markers = {}; aulas = {}; lista.innerHTML = '';
    (j.aulas || []).forEach(ar => {
      const id = String(ar.id);
      aulas[id] = {
        id,
        nombre: ar.nombre,
        xPct: ar.xPct !== null ? Number(ar.xPct) : null,
        yPct: ar.yPct !== null ? Number(ar.yPct) : null,
        estado: ar.estado || 'libre',
        grupo: ar.grupo ?? '',
        maestro: ar.maestro ?? '',
        grupo_text: ar.grupo_text ?? (ar.grupo_label ?? ''), // si tu endpoint devuelve texto
        maestro_text: ar.maestro_text ?? '',
        horario: ar.horario ?? ''
      };
      if(aulas[id].xPct !== null && aulas[id].yPct !== null) crearMarkerDesdeAula(aulas[id]);
    });
    renderLista();
  } catch(err){
    console.error('cargarAulas error', err);
    alert('No se pudieron cargar las aulas: ' + err.message);
  }
}

/* ---------- CREAR MARKER desde aula ---------- */
function crearMarkerDesdeAula(a){
  const px = pctToPx(a.xPct, a.yPct);
  const latlng = [px.y, px.x];
  const marker = L.marker(latlng, { icon: makeIcon(a.estado), draggable: modoArrastrar }).addTo(MAP);
  marker.aulaId = String(a.id);
  marker.on('click', ()=> openModalForAula(String(a.id)));
  marker.on('dragend', async (e) => {
    if(!modoArrastrar) return;
    const p = e.target.getLatLng();
    const newPx = { x: p.lng, y: p.lat };
    const pct = pxToPct(newPx.x, newPx.y);
    aulas[marker.aulaId].xPct = pct.xPct; aulas[marker.aulaId].yPct = pct.yPct;
    try {
      await guardarAulaEnServer(marker.aulaId, { xPct: pct.xPct, yPct: pct.yPct });
      renderLista();
      if(modalAulaId === marker.aulaId) updateCoordsDisplay(marker.aulaId);
    } catch(e){
      console.error('Error guardando coords tras arrastrar:', e);
      alert('No se pudo guardar la nueva posición.');
    }
  });
  markers[a.id] = marker;
  return marker;
}

/* ---------- RENDER LISTA lateral ---------- */
function renderLista(){
  lista.innerHTML = '';
  const keys = Object.keys(aulas).sort();
  if(keys.length === 0) { lista.innerHTML = '<div class="small">No hay aulas cargadas.</div>'; return; }
  keys.forEach(id => {
    const a = aulas[id];
    const el = document.createElement('div'); el.style.padding='6px 4px'; el.style.borderBottom='1px solid #eee';
    el.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <strong>${a.nombre || id}</strong>
          <div class="small">x:${a.xPct !== null ? a.xPct.toFixed(1) : '-'}% y:${a.yPct !== null ? a.yPct.toFixed(1) : '-' }%</div>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="btn secondary" onclick="(function(){ window.centrar('${id}'); })()">Centrar</button>
          <button class="btn" onclick="editarCoords('${id}')">Editar</button>
        </div>
      </div>
    `;
    lista.appendChild(el);
  });
}
window.centrar = (id) => { const m = markers[id]; if(m){ MAP.flyTo(m.getLatLng(), Math.min(MAP.getMaxZoom(), MAP.getZoom()+1)); } openModalForAula(id); };

/* ---------- PERIODO y OCUPACIONES ---------- */
async function actualizarPeriodoYOcupaciones(){
  const estado = obtenerPeriodoActual();
  if(estado.type === 'recreo' || estado.type === 'fuera'){
    // dejar iconos con estado actual de DB (no forzamos)
    return;
  }
  const periodo = estado.periodo;
  const jsDay = (new Date()).getDay();
  const dia = ((jsDay + 6) % 7) + 1;
  try {
    const j = await fetchJson(`${API_BASE}/get_ocupaciones.php?dia=${dia}&hora_id=${periodo.id}`);
    if(!j.ok) throw new Error(j.error || 'No data');
    const ids = (j.ocupaciones || []).map(x => String(x.id_aula));
    marcarOcupaciones(ids);
  } catch(err){
    console.error('get_ocupaciones error', err);
  }
}
function marcarOcupaciones(idsArray){
  const s = new Set((idsArray||[]).map(x => String(x)));
  Object.keys(markers).forEach(k => {
    const m = markers[k];
    const ocup = s.has(String(k));
    if(m) m.setIcon(makeIcon(ocup ? 'ocupada' : (aulas[k] ? aulas[k].estado : 'libre')));
  });
}

/* ---------- MODAL: abrir / cerrar / rellenar ---------- */
async function openModalForAula(id){
  modalAulaId = String(id);
  const a = aulas[modalAulaId];
  if(!a) return alert('Aula no encontrada');

  // ensure caches loaded to resolve labels
  try {
    if(!cacheGrupos){ const gj = await fetchJson(API_BASE + '/get_grupos.php'); cacheGrupos = gj.ok ? gj.grupos : []; }
  } catch(e){ cacheGrupos = []; console.warn('No se cargaron grupos', e); }
  try {
    if(!cacheMaestros){ const mj = await fetchJson(API_BASE + '/get_maestros.php'); cacheMaestros = mj.ok ? mj.maestros : []; }
  } catch(e){ cacheMaestros = []; console.warn('No se cargaron maestros', e); }

  // resolver labels
  let grupoLabel = '';
  if(a.grupo && String(a.grupo).length && !isNaN(Number(a.grupo))){
    const g = (cacheGrupos || []).find(x => String(x.id) === String(a.grupo));
    grupoLabel = g ? (g.semestre + ' ' + g.grupo) : (a.grupo_text || '');
  } else {
    grupoLabel = a.grupo_text || a.grupo || '';
  }

  let maestroLabel = '';
  if(a.maestro && String(a.maestro).length && !isNaN(Number(a.maestro))){
    const m = (cacheMaestros || []).find(x => String(x.id) === String(a.maestro));
    maestroLabel = m ? m.nombre : (a.maestro_text || '');
  } else {
    maestroLabel = a.maestro_text || a.maestro || '';
  }

  modalAulaTitle.textContent = a.nombre || a.id;
  modalGrupo.textContent = grupoLabel;
  modalMaestro.textContent = maestroLabel;
  modalHorario.textContent = a.horario || '';
  fotoInner.textContent = 'Foto';
  updateCoordsDisplay(modalAulaId);
  setFieldEditMode(false);
  setCoordsEditMode(false);
  infoModal.classList.remove('ocupada','libre');
  infoModal.classList.add(a.estado === 'libre' ? 'libre' : 'ocupada');
  btnDesocupar.style.display = (a.estado === 'ocupada') ? 'inline-block' : 'none';
  infoModal.style.display = 'block';
  positionModalOverMap();
  const m = markers[modalAulaId];
  if(m) MAP.flyTo(m.getLatLng(), Math.min(MAP.getMaxZoom(), MAP.getZoom()+1));
}
function hideInfoModal(){ infoModal.style.display = 'none'; modalAulaId = null; modalFieldEditMode = false; modalCoordEditMode = false; }
function positionModalOverMap(){ if(!MAP || !infoModal) return; const rect = MAP.getContainer().getBoundingClientRect(); const left = Math.max(8, Math.round(rect.left + 18)); const modalHeight = infoModal.offsetHeight || 260; const top = Math.max(8, Math.round(rect.bottom - modalHeight - 18)); infoModal.style.left = left + 'px'; infoModal.style.top = top + 'px'; }
function updateCoordsDisplay(id){ const a = aulas[id]; if(!a) return; document.getElementById('mCoords').textContent = `x:${a.xPct.toFixed(2)}%  y:${a.yPct.toFixed(2)}%`; }

/* ---------- EDICION CAMPOS: poblar selects y guardar (clic en imagen) ---------- */
async function setFieldEditMode(on){
  modalFieldEditMode = !!on;
  if(!modalAulaId) return;
  const a = aulas[modalAulaId];
  const fG = document.getElementById('fieldGrupo');
  const fM = document.getElementById('fieldMaestro');
  const fH = document.getElementById('fieldHorario');

  function createSelect(items, currentValue, labelFn){
    const s = document.createElement('select'); s.className = 'field-select';
    const empty = document.createElement('option'); empty.value=''; empty.textContent=''; s.appendChild(empty);
    (items||[]).forEach(it=>{
      const o = document.createElement('option'); o.value = it.id; o.textContent = labelFn ? labelFn(it) : (it.nombre || it.label || it);
      if(String(it.id) === String(currentValue)) o.selected = true;
      s.appendChild(o);
    });
    return s;
  }

  if(on){
    // cargar caches si faltan
    if(!cacheGrupos){
      try { const j = await fetchJson(API_BASE + '/get_grupos.php'); cacheGrupos = j.ok ? j.grupos : []; } catch(e){ cacheGrupos = []; console.warn(e); }
    }
    if(!cacheMaestros){
      try { const j = await fetchJson(API_BASE + '/get_maestros.php'); cacheMaestros = j.ok ? j.maestros : []; } catch(e){ cacheMaestros = []; console.warn(e); }
    }
    // horarios (opcional endpoint)
    if(!cacheHorarios){
      try { const j = await fetchJson(API_BASE + '/get_horarios.php'); if(j.ok) cacheHorarios = j.horarios; } catch(e){ cacheHorarios = null; }
    }
    const horasFallback = cacheHorarios ? cacheHorarios : [{id:'hora1', nombre:'07:00 - 07:50'}, {id:'hora2', nombre:'07:50 - 08:40'}, {id:'hora4', nombre:'10:00 - 10:50'}];

    fG.innerHTML = 'Grupo: '; fG.appendChild(createSelect(cacheGrupos, a.grupo || a.grupo_id, it => (it.semestre + ' ' + it.grupo)));
    fM.innerHTML = 'Maestr@: '; fM.appendChild(createSelect(cacheMaestros, a.maestro || a.maestro_id, it => it.nombre));
    fH.innerHTML = 'Horario: '; fH.appendChild(createSelect(horasFallback, a.horario || a.horario_id, it => it.nombre || it.label || it.id));
    btnDesocupar.style.display = (a.estado === 'ocupada') ? 'inline-block' : 'none';
  } else {
    fG.innerHTML = 'Grupo: ' + (a.grupo_text || a.grupo || '');
    fM.innerHTML = 'Maestr@: ' + (a.maestro_text || a.maestro || '');
    fH.innerHTML = 'Horario: ' + (a.horario || '');
  }
}

/* manejador clic en imagen modal */
modalFoto.addEventListener('click', async () => {
  if(!modalAulaId) return;
  if(!modalFieldEditMode){ await setFieldEditMode(true); const sel = document.querySelector('#fieldGrupo select'); if(sel) sel.focus(); return; }
  try { await saveFieldEdits(); await setFieldEditMode(false); infoModal.style.boxShadow = '0 24px 48px rgba(0,0,0,0.45)'; setTimeout(()=> infoModal.style.boxShadow = '0 18px 40px rgba(0,0,0,.35)', 180); } catch(e){ console.error(e); alert('No se pudo guardar la información.'); }
});

/* guardar selects en servidor */
async function saveFieldEdits(){
  if(!modalAulaId) throw new Error('No aula seleccionada');
  const id = modalAulaId;
  const a = aulas[id];
  const sG = document.querySelector('#fieldGrupo select');
  const sM = document.querySelector('#fieldMaestro select');
  const sH = document.querySelector('#fieldHorario select');

  const grupo_id = sG ? sG.value || '' : '';
  const maestro_id = sM ? sM.value || '' : '';
  const horario_val = sH ? sH.value || '' : '';

  const grupoObj = (cacheGrupos || []).find(x => String(x.id) === String(grupo_id));
  const maestroObj = (cacheMaestros || []).find(x => String(x.id) === String(maestro_id));
  const grupo_text = grupoObj ? (grupoObj.semestre + ' ' + grupoObj.grupo) : (sG ? sG.options[sG.selectedIndex].text : '');
  const maestro_text = maestroObj ? maestroObj.nombre : (sM ? sM.options[sM.selectedIndex].text : '');
  const horario_text = sH ? sH.options[sH.selectedIndex].text : (a.horario || '');

  const tieneDatos = (grupo_id && grupo_id !== '') || (maestro_id && maestro_id !== '') || (horario_val && horario_val !== '');
  const nuevoEstado = tieneDatos ? 'ocupada' : 'libre';

  // actualizar local
  a.grupo = grupo_id || '';
  a.maestro = maestro_id || '';
  a.grupo_text = grupo_text || '';
  a.maestro_text = maestro_text || '';
  a.horario = horario_text || horario_val || '';
  a.estado = nuevoEstado;

  // payload
  const payload = {
    id: id,
    nombre: a.nombre,
    xPct: a.xPct,
    yPct: a.yPct,
    estado: a.estado,
    grupo_id: grupo_id || '',
    maestro_id: maestro_id || '',
    grupo_text: grupo_text || '',
    maestro_text: maestro_text || '',
    horario: horario_val || a.horario || ''
  };

  const j = await fetchJson(API_BASE + '/save_aula.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  if(!j.ok) throw new Error(j.error || 'save error');

  await cargarAulasDesdeAPI();
  await actualizarPeriodoYOcupaciones();
}

/* ---------- EDICION COORDS ---------- */
function setCoordsEditMode(on){
  modalCoordEditMode = !!on;
  if(!modalAulaId) return;
  const a = aulas[modalAulaId];
  if(on){ coordsEdit.style.display = 'flex'; coordsEdit.setAttribute('aria-hidden','false'); coordsDisplay.style.display = 'none'; inputX.value = a.xPct.toFixed(2); inputY.value = a.yPct.toFixed(2); }
  else { coordsEdit.style.display = 'none'; coordsEdit.setAttribute('aria-hidden','true'); coordsDisplay.style.display = 'block'; updateCoordsDisplay(modalAulaId); }
}
btnSaveCoords.addEventListener('click', async ()=>{
  if(!modalAulaId) return;
  const id = modalAulaId;
  const x = parseFloat(inputX.value), y = parseFloat(inputY.value);
  if(isNaN(x) || isNaN(y) || x<0 || x>100 || y<0 || y>100) return alert('Coords inválidas');
  aulas[id].xPct = x; aulas[id].yPct = y;
  try {
    await guardarAulaEnServer(id, { xPct:x, yPct:y });
    const px = pctToPx(x,y);
    if(markers[id]) markers[id].setLatLng([px.y, px.x]);
    setCoordsEditMode(false);
    renderLista();
  } catch(e){ console.error(e); alert('No se pudo guardar coords'); }
});
btnCancelCoords.addEventListener('click', ()=> setCoordsEditMode(false));
function editarCoords(id){ openModalForAula(id); setCoordsEditMode(true); }

/* ---------- DESOCUPAR ---------- */
btnDesocupar.addEventListener('click', async ()=>{
  if(!modalAulaId) return;
  const id = modalAulaId;
  aulas[id].estado = 'libre'; aulas[id].grupo=''; aulas[id].maestro=''; aulas[id].grupo_text=''; aulas[id].maestro_text=''; aulas[id].horario='';
  try {
    await guardarAulaEnServer(id, { estado:'libre', grupo:'', maestro:'', grupo_text:'', maestro_text:'', horario:'' });
    await cargarAulasDesdeAPI();
    await actualizarPeriodoYOcupaciones();
    hideInfoModal();
  } catch(e){ console.error(e); alert('No se pudo desocupar'); }
});

/* ---------- GUARDAR aula en servidor (save_aula.php) ---------- */
async function guardarAulaEnServer(id, partial){
  if(!id) throw new Error('id requerido');
  const a = aulas[id] || {};
  const payload = {
    id: id,
    nombre: a.nombre || ('Aula ' + id),
    xPct: (partial.xPct !== undefined) ? partial.xPct : a.xPct,
    yPct: (partial.yPct !== undefined) ? partial.yPct : a.yPct,
    estado: (partial.estado !== undefined) ? partial.estado : a.estado,
    grupo_id: (partial.grupo !== undefined) ? partial.grupo : (a.grupo || ''),
    maestro_id: (partial.maestro !== undefined) ? partial.maestro : (a.maestro || ''),
    grupo_text: (partial.grupo_text !== undefined) ? partial.grupo_text : (a.grupo_text || ''),
    maestro_text: (partial.maestro_text !== undefined) ? partial.maestro_text : (a.maestro_text || ''),
    horario: (partial.horario !== undefined) ? partial.horario : (a.horario || '')
  };
  const j = await fetchJson(API_BASE + '/save_aula.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  if(!j.ok) throw new Error(j.error || 'save failed');
  return j;
}

/* ---------- ICONS / UI helpers ---------- */
function updateAllIcons(){ Object.keys(markers).forEach(k => { const m = markers[k]; const a = aulas[k]; if(m && a) m.setIcon(makeIcon(a.estado)); }); }
function updateSizeDisplay(){ sizeDisplay.textContent = ICON_W + 'px'; }

/* ---------- INICIALIZAR MAPA ---------- */
function initMapa(){
  cargarAspectoPin();

  const img = new Image();
  img.src = IMG_SRC + '?_=' + Date.now();
  img.onload = () => {
    IMG_W = img.naturalWidth; IMG_H = img.naturalHeight;
    BOUNDS = [[0,0],[IMG_H, IMG_W]];
    MAP = L.map('map',{
      crs: L.CRS.Simple,
      minZoom: -4,
      maxZoom: 2,
      zoomSnap: 0.25,
      wheelPxPerZoomLevel: 120,
      maxBoundsViscosity: 1
    });
    L.imageOverlay(IMG_SRC, BOUNDS).addTo(MAP);
    MAP.fitBounds(BOUNDS);
    MAP.setMaxBounds(BOUNDS);

    // cargar aulas
    cargarAulasDesdeAPI().then(()=> actualizarPeriodoYOcupaciones());
    setInterval(actualizarPeriodoYOcupaciones, 30 * 1000);

    MAP.on('move zoom resize', positionModalOverMap);
    window.addEventListener('resize', positionModalOverMap);

    MAP.on('moveend', ()=> { try{ MAP.panInsideBounds(BOUNDS, { animate:false }); }catch(e){} });
    MAP.on('zoomend', ()=> { try{ MAP.panInsideBounds(BOUNDS, { animate:false }); }catch(e){} });

    MAP.on('click', async (e) => {
      if(!modoAgregar) return;
      const px = { x: e.latlng.lng, y: e.latlng.lat };
      const name = prompt('Nombre del aula / etiqueta (ej: Aula 5)', 'Aula ' + (Object.keys(aulas).length + 1));
      const estado = confirm('¿Marcar como LIBRE ahora? (Aceptar = libre, Cancelar = ocupada)') ? 'libre' : 'ocupada';
      // crear en servidor: enviar payload mínimo (mismo shape que save_aula.php espera)
      const idTemp = 'aula_' + Date.now();
      aulas[idTemp] = { id: idTemp, nombre: name, xPct: (px.x/IMG_W)*100, yPct: (px.y/IMG_H)*100, estado, grupo:'', maestro:'', grupo_text:'', maestro_text:'', horario:'' };
      try {
        // Intentamos guardar en servidor (si tu save_aula.php hace INSERT si no existe, perfecto).
        const j = await guardarAulaEnServer(idTemp, { nombre:name, xPct:aulas[idTemp].xPct, yPct:aulas[idTemp].yPct, estado:estado });
        // si servidor devuelve id real, recargar todo
        await cargarAulasDesdeAPI();
      } catch(e){
        console.warn('No se pudo crear en servidor, creado solo localmente:', e);
        // crear localmente
        crearMarkerDesdeAula(aulas[idTemp]);
        renderLista();
      }
    });
  };
  img.onerror = ()=> alert('No se pudo cargar la imagen "' + IMG_SRC + '". Revisa la ruta.');
}
function cargarAspectoPin(){ const img = new Image(); img.src = PIN_GREEN + '?_=' + Date.now(); img.onload = ()=> { if(img.naturalWidth>0){ pinAspect = img.naturalHeight / img.naturalWidth; ICON_H = Math.round(ICON_W * pinAspect); updateAllIcons(); updateSizeDisplay(); } }; }

/* ---------- UI botones ---------- */
document.getElementById('modoAgregar').addEventListener('click', function(){
  modoAgregar = !modoAgregar;
  this.textContent = modoAgregar ? 'Modo: Añadir (clic)' : 'Modo: Navegar';
});
document.getElementById('modoArrastrar').addEventListener('click', function(){
  modoArrastrar = !modoArrastrar;
  this.classList.toggle('secondary', !modoArrastrar);
  this.textContent = modoArrastrar ? 'Modo: Mover (arrastrar ON)' : 'Modo: Mover marcadores';
  Object.values(markers).forEach(m => { if(m.dragging) (modoArrastrar ? m.dragging.enable() : m.dragging.disable()); });
});
document.getElementById('limpiar').addEventListener('click', function(){
  if(!confirm('Eliminar marcadores locales? (esto no borra la BD)')) return;
  Object.values(markers).forEach(m => MAP.removeLayer(m));
  markers = {}; aulas = {}; renderLista(); hideInfoModal();
});

/* slider */
sizeRange.addEventListener('input', ()=> { ICON_W = parseInt(sizeRange.value,10) || 90; ICON_H = Math.round(ICON_W * pinAspect); updateAllIcons(); updateSizeDisplay(); });

/* cerrar modal */
btnCloseModal.addEventListener('click', async ()=>{
  if(modalFieldEditMode){ try{ await saveFieldEdits(); } catch(e){ console.warn('No se pudo guardar al cerrar', e); } }
  hideInfoModal();
});
document.addEventListener('keydown', (e)=> { if(e.key === 'Escape') hideInfoModal(); });

/* exponer editarCoords global para botones lista */
window.editarCoords = editarCoords;

/* arrancar */
initMapa();
updateSizeDisplay();
</script>
</body>
</html>