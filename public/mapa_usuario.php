<?php
// mapa_usuario.php
// mapa_usuario.php
require_once '../includes/config.php';

// IMPORTANTE: Asegúrate de que session_start() se ejecute. 
// Si config.php NO tiene session_start(), ponlo aquí:
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['edu_rol'])) {
    header('Location: ../login.php');
    exit;
}

// Agregamos "administrador" a la lista para que coincida con tu DB
$roles_permitidos = ['admin', 'administrador', 'prefecto', 'jefe_grupo', 'orientacion'];

if (!in_array($_SESSION['edu_rol'], $roles_permitidos)) {
    header('Location: ../login.php?error=rol_no_permitido');
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <title>EduControl — Mapa Interactivo</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="../build/css/app.css">
    <style>
        #map { 
            height: calc(100vh - 200px); 
            width: 100%;
            border: 4px solid #4b4693;
            border-radius: 4px;
            background: #154655;
        }
        .info-modal {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 400px;
            z-index: 2000;
            display: none;
        }
        .info-modal.ocupada { border-color: #ff4b4b; }
        .info-modal.libre { border-color: #39d353; }
        .seccion-edicion {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dashed #4b4693;
        }
        @media (min-width: 768px) { .info-modal { left: 24px; transform: none; } }
    </style>
</head>
<body class="app">

    <header class="app__header container">
        <div class="logo"></div>
    </header>

    <main class="app__main container">
        <div class="flex items-center justify-between" style="width:100%; margin-bottom: 12px;">
            <h1 style="color:white; font-size: 24px;">EduControl Mapa</h1>
            <button id="btnRefresh" class="btn" style="font-size: 14px;">Actualizar</button>
        </div>
        <div id="map"></div>
    </main>

    <div id="infoModal" class="card info-modal">
        <div class="flex justify-between items-center" style="margin-bottom:10px;">
            <h3 id="modalAulaTitle" class="card__title" style="margin:0;">Aula</h3>
            <span id="btnCloseModal" style="cursor:pointer; font-weight:bold; color:#4b4693;">✕</span>
        </div>
        
        <div class="card__body" id="infoVisual">
            <div class="campo"><strong>Grupo:</strong> <span id="modalGrupo">---</span></div>
            <div class="campo"><strong>Docente:</strong> <span id="modalMaestro">---</span></div>
            <div class="campo"><strong>Horario:</strong> <span id="modalHorario">---</span></div>
        </div>

        <div id="seccionEdicion" class="seccion-edicion" style="display:none;">
            <p style="font-size:12px; font-weight:bold; margin-bottom:8px; color:#4b4693;">GESTIÓN DE AULA:</p>
            
            <div id="formOcupar" class="flex-column gap-sm">
                <select id="editGrupo" class="campo"></select>
                <select id="editMaestro" class="campo"></select>
                <button id="btnConfirmarOcupar" class="btn" style="background:#39d353; font-size:14px;">Ocupar Aula</button>
            </div>

            <button id="btnLiberarAula" class="btn" style="width:100%; background:#ff4b4b; color:white; border-color:#c80000; display:none;">Informar que el Aula está Libre</button>
        </div>

        <div class="flex gap-sm" style="margin-top:12px;">
            <a href="reportes.php" class="btn" style="flex:1; font-size:12px; background:#dbebf5;">Ver Reportes</a>
        </div>
    </div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const IMG_SRC = '../src/img/mapa.jpeg';
const PIN_GREEN = '../src/img/pin_green.png';
const PIN_RED = '../src/img/pin_red.png';
const API_BASE = '../api';
const MI_ROL = "<?php echo $_SESSION['edu_rol']; ?>";

let MAP, markers = {}, aulas = {}, modalAulaId = null;
let catalogosCargados = false;

/* PERIODOS */
const PERIODOS = [
    { id: 1, nombre: '1ra Hora', inicio: '07:00', fin: '07:50' },
    { id: 2, nombre: '2da Hora', inicio: '07:50', fin: '08:40' },
    { id: 3, nombre: 'Receso', inicio: '08:40', fin: '09:00' },
    { id: 4, nombre: '3ra Hora', inicio: '10:00', fin: '10:50' },
    { id: 5, nombre: '4ta Hora', inicio: '10:50', fin: '11:40' },
    { id: 6, nombre: '5ta Hora', inicio: '11:40', fin: '12:30' },
    { id: 7, nombre: '6ta Hora', inicio: '12:30', fin: '13:20' },
    { id: 8, nombre: '7ma Hora', inicio: '13:20', fin: '14:10' },
    { id: 9, nombre: '8va Hora', inicio: '14:10', fin: '15:00' },
    { id: 10, nombre: '9na Hora', inicio: '15:00', fin: '15:50' },
    { id: 11, nombre: '10ma Hora', inicio: '16:10', fin: '17:00' }
];

function getPeriodo() {
    const now = new Date();
    const mins = now.getHours() * 60 + now.getMinutes();
    return PERIODOS.find(p => {
        const [h1, m1] = p.inicio.split(':').map(Number);
        const [h2, m2] = p.fin.split(':').map(Number);
        return mins >= (h1 * 60 + m1) && mins < (h2 * 60 + m2);
    }) || null;
}

/* API */
async function sync() {
    const p = getPeriodo();
    let dia = new Date().getDay();
    if (dia === 0) dia = 7; // Domingo

    if (!p || dia > 5) {
        Object.keys(aulas).forEach(id => resetAula(id));
        updateMap();
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/get_ocupaciones.php?dia=${dia}&hora_id=${p.id}`);
        const data = await res.json();
        Object.keys(aulas).forEach(id => resetAula(id));
        if (data.ok) {
            data.ocupaciones.forEach(o => {
                const id = String(o.id_aula);
                if (aulas[id]) {
                    aulas[id].estado = 'ocupada';
                    aulas[id].grupo_text = o.grupo;
                    aulas[id].maestro_text = o.maestro;
                    aulas[id].horario_label = `${p.nombre} (${p.inicio}-${p.fin})`;
                }
            });
        }
        updateMap();
        if (modalAulaId) openModal(modalAulaId);
    } catch (e) { console.error(e); }
}

function resetAula(id) {
    if (!aulas[id]) return;
    aulas[id].estado = 'libre';
    aulas[id].grupo_text = 'Aula Libre';
    aulas[id].maestro_text = 'N/A';
    aulas[id].horario_label = 'Sin clase asignada';
}

/* CATALOGOS */
async function loadCats() {
    if (catalogosCargados) return;
    const [gR, mR] = await Promise.all([
        fetch(`${API_BASE}/get_grupos.php`).then(r => r.json()),
        fetch(`${API_BASE}/get_maestros.php`).then(r => r.json())
    ]);
    const sG = document.getElementById('editGrupo'), sM = document.getElementById('editMaestro');
    sG.innerHTML = '<option value="">Elegir Grupo...</option>';
    mR.ok && mR.maestros.forEach(m => sM.innerHTML += `<option value="${m.id}">${m.nombre}</option>`);
    gR.ok && gR.grupos.forEach(g => sG.innerHTML += `<option value="${g.id}">${g.semestre} ${g.grupo}</option>`);
    catalogosCargados = true;
}

/* UI */
function openModal(id) {
    modalAulaId = id;
    const a = aulas[id];
    document.getElementById('modalAulaTitle').textContent = a.nombre;
    document.getElementById('modalGrupo').textContent = a.grupo_text;
    document.getElementById('modalMaestro').textContent = a.maestro_text;
    document.getElementById('modalHorario').textContent = a.horario_label;
    
    const modal = document.getElementById('infoModal');
    modal.className = `card info-modal ${a.estado}`;
    modal.style.display = 'block';

    // Lógica de Edición
    if (['jefe_grupo', 'prefecto', 'admin'].includes(MI_ROL)) {
        document.getElementById('seccionEdicion').style.display = 'block';
        loadCats();
        const ocupada = a.estado === 'ocupada';
        document.getElementById('formOcupar').style.display = ocupada ? 'none' : 'flex';
        document.getElementById('btnLiberarAula').style.display = ocupada ? 'block' : 'none';
    }
}

async function sendAction(payload) {
    const res = await fetch(`${API_BASE}/save_ocupacion.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok) { sync(); document.getElementById('infoModal').style.display = 'none'; }
}

document.getElementById('btnConfirmarOcupar').onclick = () => {
    const p = getPeriodo();
    sendAction({
        id_aula: modalAulaId, id_dia: new Date().getDay(), id_horario: p.id,
        grupo: document.getElementById('editGrupo').value,
        maestro: document.getElementById('editMaestro').value,
        accion: 'ocupar'
    });
};

document.getElementById('btnLiberarAula').onclick = () => {
    if(confirm("¿Seguro que el aula está libre?")) {
        const p = getPeriodo();
        sendAction({ id_aula: modalAulaId, id_dia: new Date().getDay(), id_horario: p.id, accion: 'liberar' });
    }
};

/* LEAFLET INIT */
function init() {
    const img = new Image(); img.src = IMG_SRC;
    img.onload = () => {
        MAP = L.map('map', { crs: L.CRS.Simple, minZoom: -2, attributionControl:false });
        L.imageOverlay(IMG_SRC, [[0,0], [img.naturalHeight, img.naturalWidth]]).addTo(MAP);
        MAP.fitBounds([[0,0], [img.naturalHeight, img.naturalWidth]]);

        fetch(`${API_BASE}/get_aulas.php`).then(r => r.json()).then(d => {
            d.aulas.forEach(a => {
                aulas[a.id] = {...a, grupo_text:'---', maestro_text:'---', horario_label:''};
                const m = L.marker([(a.yPct/100)*img.naturalHeight, (a.xPct/100)*img.naturalWidth], {
                    icon: L.icon({ iconUrl: PIN_GREEN, iconSize: [40, 52], iconAnchor: [20, 52] })
                }).addTo(MAP);
                m.on('click', () => openModal(a.id));
                markers[a.id] = m;
            });
            sync();
        });
    };
}

function updateMap() {
    Object.keys(markers).forEach(id => {
        markers[id].setIcon(L.icon({ 
            iconUrl: aulas[id].estado === 'ocupada' ? PIN_RED : PIN_GREEN, 
            iconSize: [40, 52], iconAnchor: [20, 52] 
        }));
    });
}

document.getElementById('btnCloseModal').onclick = () => { document.getElementById('infoModal').style.display = 'none'; modalAulaId = null; };
document.getElementById('btnRefresh').onclick = sync;
setInterval(sync, 30000);
init();
</script>
</body>
</html>