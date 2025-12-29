<?php
// public/ver-reporte.php  (archivo completo, robusto contra esquemas distintos)
session_start();
include '../includes/conexion.php'; // debe exponer $conexion (mysqli)

/* ---------- helpers ---------- */
function esc($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
function get_table_columns(mysqli $conn, string $table): array
{
    $cols = [];
    $table = $conn->real_escape_string($table);
    if ($res = $conn->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        $res->free();
    }
    return $cols;
}
function jsonErr($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function jsonOk($data = [])
{
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

/* ---------- validar conexión ---------- */
if (!($conexion instanceof mysqli)) {
    http_response_code(500);
    echo "Error: conexión a la base de datos no disponible.";
    exit;
}


// colocar justo antes del INSERT/UPDATE
error_log("DEBUG session keys: " . json_encode(array_keys($_SESSION ?? [])));
error_log("DEBUG session content: " . json_encode([
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_nombre'] ?? $_SESSION['user'] ?? null,
    'edu_user' => $_SESSION['edu_user'] ?? null
]));


/* ---------- Endpoint AJAX: actualizar reporte (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_report') {
    header('Content-Type: application/json; charset=utf-8');

    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') jsonErr('Falta id del reporte.');

    $horas = isset($_POST['horas']) ? intval($_POST['horas']) : null;
    if ($horas === null || $horas < 0) jsonErr('Valor de horas no válido.');

    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : null;
    $nota = isset($_POST['nota']) ? trim($_POST['nota']) : null;

    // intentar obtener id de usuario editor desde sesión (ajusta según tu login)
    $editorId = null;
    if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $editorId = (int)$_SESSION['user_id'];
    if (!$editorId && !empty($_SESSION['usuario_id']) && is_numeric($_SESSION['usuario_id'])) $editorId = (int)$_SESSION['usuario_id'];
    $editorLabel = $_SESSION['usuario'] ?? $_SESSION['edu_user'] ?? null;

    $conexion->begin_transaction();
    try {
        // comprobar que existe reporte
        $sel = $conexion->prepare("SELECT horas FROM reportes WHERE id = ? LIMIT 1");
        if (!$sel) throw new Exception("Error prepare SELECT: " . $conexion->error);
        $sel->bind_param('s', $id);
        $sel->execute();
        $r = $sel->get_result();
        if (!$r || $r->num_rows === 0) {
            $sel->close();
            throw new Exception("Reporte no encontrado");
        }
        $row = $r->fetch_assoc();
        $horasPrev = (int)($row['horas'] ?? 0);
        $sel->close();

        // UPDATE reportes
        if ($descripcion !== null) {
            $upd = $conexion->prepare("UPDATE reportes SET horas = ?, descripcion = ?, updated_at = NOW(), ultima_mod_por = ? WHERE id = ?");
            if (!$upd) throw new Exception("Error prepare UPDATE: " . $conexion->error);
            $upd->bind_param('isis', $horas, $descripcion, $editorId, $id);
        } else {
            $upd = $conexion->prepare("UPDATE reportes SET horas = ?, updated_at = NOW(), ultima_mod_por = ? WHERE id = ?");
            if (!$upd) throw new Exception("Error prepare UPDATE: " . $conexion->error);
            $upd->bind_param('iis', $horas, $editorId, $id);
        }
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            throw new Exception("No se pudo actualizar: " . $err);
        }
        $upd->close();

        // Insert historial (si existe la tabla)
        $histSql = "INSERT INTO reportes_historial (reporte_id, horas_prev, horas_new, usuario_id, nota, creado_at) VALUES (?, ?, ?, ?, ?, NOW())";
        if ($h = $conexion->prepare($histSql)) {
            $notaFinal = $nota ?: ($editorLabel ? "editado por: " . substr($editorLabel, 0, 120) : '');
            $h->bind_param('siiis', $id, $horasPrev, $horas, $editorId, $notaFinal);
            if (!$h->execute()) {
                $err = $h->error;
                $h->close();
                throw new Exception("No se pudo insertar historial: " . $err);
            }
            $h->close();
        }
        $conexion->commit();
        jsonOk(['id' => $id, 'horas' => $horas, 'horas_prev' => $horasPrev]);
    } catch (Exception $ex) {
        $conexion->rollback();
        jsonErr($ex->getMessage(), 500);
    }
}

/* ---------- Render (GET) ---------- */
/* detectar columnas para construir consultas seguras */
$grCols = get_table_columns($conexion, 'grupos');
$alCols = get_table_columns($conexion, 'alumnos');
$rCols  = get_table_columns($conexion, 'reportes');
$uCols  = get_table_columns($conexion, 'usuarios');

/* columnas candidatas para nombre de grupo y grado/semestre */
$groupNameCandidates = array_values(array_intersect(['nombre', 'grupo', 'nombre_grupo', 'grupo_nombre'], $grCols));
$gradoCandidates = [];
// preferir columna en alumnos, luego en grupos
if (in_array('grado', $alCols, true)) $gradoCandidates[] = 'a.`grado`';
if (in_array('grado', $grCols, true)) $gradoCandidates[] = 'g.`grado`';
if (in_array('semestre', $grCols, true)) $gradoCandidates[] = 'g.`semestre`';

$groupExpr = empty($groupNameCandidates) ? "'' AS grupo" : ("COALESCE(" . implode(', ', array_map(function ($c) {
    return "g.`$c`";
}, $groupNameCandidates)) . ", '') AS grupo");
$gradoExpr = empty($gradoCandidates) ? "'' AS grado" : ("COALESCE(" . implode(', ', $gradoCandidates) . ", '') AS grado");

/* obtener matrícula objetivo (GET o sesión) */
$matricula = '';
if (!empty($_GET['matricula'])) $matricula = trim($_GET['matricula']);
elseif (!empty($_SESSION['edu_matricula'])) $matricula = trim($_SESSION['edu_matricula']);
elseif (!empty($_SESSION['matricula'])) $matricula = trim($_SESSION['matricula']);

if (!$matricula) {
    http_response_code(400);
    echo "Matrícula no proporcionada.";
    exit;
}

/* Obtener info del alumno (usando solo columnas seguras) */
$alumno = null;
$sqlA = "
    SELECT
        a.id AS id,
        COALESCE(a.matricula, '') AS matricula,
        COALESCE(a.nombre, '') AS nombre,
        $gradoExpr,
        $groupExpr,
        COALESCE(a.foto, '') AS foto
    FROM alumnos a
    LEFT JOIN grupos g ON a.grupo_id = g.id
    WHERE a.matricula = ?
    LIMIT 1
";
$stmtA = $conexion->prepare($sqlA);
if (!$stmtA) {
    error_log("ver-reporte.php prepare alumno failed: " . $conexion->error);
    http_response_code(500);
    echo "Error interno (consulta alumno).";
    exit;
}
$stmtA->bind_param('s', $matricula);
$stmtA->execute();
$resA = $stmtA->get_result();
if ($resA && $rowA = $resA->fetch_assoc()) $alumno = $rowA;
$stmtA->close();

if (!$alumno) {
    http_response_code(404);
    echo "Alumno no encontrado para matrícula: " . esc($matricula);
    exit;
}

/* Determinar cómo relacionar reportes con alumno:
   - si reportes tiene columna alumno_id -> usar r.alumno_id = alumno.id
   - else si reportes tiene columna matricula -> usar r.matricula = alumno.matricula
   - else -> error (no hay forma de filtrar) */
$whereClause = '';
$bindTypes = '';
$bindValue = null;
if (in_array('alumno_id', $rCols, true)) {
    $whereClause = 'r.alumno_id = ?';
    $bindTypes = 'i';
    $bindValue = (int)$alumno['id'];
} elseif (in_array('matricula', $rCols, true)) {
    $whereClause = 'r.matricula = ?';
    $bindTypes = 's';
    $bindValue = (string)$alumno['matricula'];
} else {
    // mostrar columnas detectadas para depuración
    http_response_code(500);
    echo "La tabla 'reportes' no tiene 'alumno_id' ni 'matricula'. Columnas detectadas: " . implode(', ', $rCols);
    exit;
}

/* Construir SELECT de reportes: incluir nombres aplicador/ultima_mod si columnas FK existen y usuarios tienen 'nombre' */
$joins = '';
$selectExtra = '';
if (in_array('aplicado_por', $rCols, true) && in_array('nombre', $uCols, true)) {
    $joins .= " LEFT JOIN usuarios u1 ON u1.id = r.aplicado_por ";
    $selectExtra .= " , u1.nombre AS aplicado_nombre ";
}
if (in_array('ultima_mod_por', $rCols, true) && in_array('nombre', $uCols, true)) {
    $joins .= " LEFT JOIN usuarios u2 ON u2.id = r.ultima_mod_por ";
    $selectExtra .= " , u2.nombre AS ultima_mod_nombre ";
}

/* seleccionar columnas seguras de reportes (evitar SELECT r.* si quieres filtrar columnas no existentes) */
$reportSelectCols = [];
// lista de columnas que queremos si existen
$wantedReportCols = ['id', 'tipo', 'descripcion', 'horas', 'created_at', 'updated_at', 'aplicado_por', 'ultima_mod_por', 'matricula', 'alumno_id'];
foreach ($wantedReportCols as $c) {
    if (in_array($c, $rCols, true)) $reportSelectCols[] = "r.`$c`";
}
if (empty($reportSelectCols)) {
    http_response_code(500);
    echo "La tabla reportes no tiene columnas esperadas: " . implode(', ', $wantedReportCols);
    exit;
}
$reportSelect = implode(', ', $reportSelectCols);

/* Consulta final de reportes (adeudos primero, luego completados) */
$sqlR = "
    SELECT $reportSelect
    $selectExtra
    FROM reportes r
    $joins
    WHERE $whereClause
    ORDER BY (r.horas > 0) DESC, r.horas DESC, r.created_at DESC
";
$stmtR = $conexion->prepare($sqlR);
if (!$stmtR) {
    error_log("ver-reporte.php prepare reportes failed: " . $conexion->error . " SQL: " . $sqlR);
    http_response_code(500);
    echo "Error interno (consulta reportes).";
    exit;
}
$stmtR->bind_param($bindTypes, $bindValue);
$stmtR->execute();
$resR = $stmtR->get_result();
$reportes = [];
if ($resR) {
    while ($rr = $resR->fetch_assoc()) $reportes[] = $rr;
    $resR->free();
}
$stmtR->close();

/* separar adeudos/completados */
$adeudos = array_filter($reportes, function ($r) {
    return ((int)($r['horas'] ?? 0)) > 0;
});
$completados = array_filter($reportes, function ($r) {
    return ((int)($r['horas'] ?? 0)) === 0;
});

function estadoClase($horas)
{
    return ((int)$horas > 0) ? 'adeuda' : 'pagado';
}

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>EduControl</title>
    <link rel="icon" href="../src/img/cecyteh.ico" type="image/x-icon">
    <link rel="stylesheet" href="../build/css/app.css" />
</head>

<body class="app">
    <header class="app__header">
        <div class="logo" aria-hidden="true">logo</div>
    </header>

    <main class="app__main pagina-reportes" role="main">
        <!-- PERFIL -->
        <section class="perfil">
            <h1 class="nombre-alumno"><?= esc($alumno['nombre']) ?></h1>
            <div class="perfil-contenedor">
                <div class="perfil-info">
                    <?php if (($alumno['grado'] ?? '') !== ''): ?>
                        <div class="campo-info"><strong>Semestre</strong> <span><?= esc($alumno['grado']) ?></span></div>
                    <?php endif; ?>
                    <?php if (($alumno['grupo'] ?? '') !== ''): ?>
                        <div class="campo-info"><strong>Grupo</strong> <span><?= esc($alumno['grupo']) ?></span></div>
                    <?php endif; ?>
                    <div class="campo-info"><strong>Matrícula</strong> <span class="matricula"><?= esc($alumno['matricula']) ?></span></div>
                </div>
                <div class="perfil-foto">
                    <div class="foto-tarjeta">
                        <img src="<?= esc($alumno['foto'] ?: '../assets/avatar-placeholder.png') ?>" alt="Foto del alumno" onerror="this.onerror=null;this.src='../assets/avatar-placeholder.png'" />
                    </div>
                </div>
            </div>
        </section>

        <!-- LISTA -->
        <section id="lista-reportes" class="lista-reportes" aria-live="polite">
            <?php
            $mostrar = array_merge(array_values($adeudos), array_values($completados));
            if (empty($mostrar)): ?>
                <div>No hay reportes para esta matrícula.</div>
                <?php else:
                foreach ($mostrar as $r):
                    $horas = (int)($r['horas'] ?? 0);
                    $cls = estadoClase($horas);
                ?>
                    <article class="reporte-card <?= esc($cls) ?>" data-id="<?= esc($r['id'] ?? '') ?>" data-horas="<?= esc($horas) ?>" tabindex="0">
                        <div class="reporte-contenido">
                            <div class="reporte-tipo"><?= esc($r['tipo'] ?? 'reporte') ?></div>

                            <div class="reporte-meta">
                                <div>
                                    <strong>Creado:</strong>
                                    <span><?= !empty($r['created_at']) ? esc(date('Y-m-d H:i', strtotime($r['created_at']))) : '—' ?></span>
                                    <?php if (!empty($r['aplicado_nombre'])): ?> · <span>por <?= esc($r['aplicado_nombre']) ?></span><?php endif; ?>
                                </div>
                                <div>
                                    <strong>Última modificación:</strong>
                                    <span><?= !empty($r['updated_at']) ? esc(date('Y-m-d H:i', strtotime($r['updated_at']))) : '—' ?></span>
                                    <?php if (!empty($r['ultima_mod_nombre'])): ?> · <span>por <?= esc($r['ultima_mod_nombre']) ?></span><?php endif; ?>
                                </div>
                            </div>

                            <div class="reporte-horas">Horas adeudadas: <span class="horas-valor"><?= esc($horas) ?></span></div>
                            <?php if (!empty($r['descripcion'])): ?>
                                <div class="reporte-desc"><?= esc($r['descripcion']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="reporte-controles">
                            <button class="btn flecha flecha-up" type="button" disabled>▲</button>
                            <button class="btn flecha flecha-down" type="button" disabled>▼</button>
                        </div>
                    </article>
            <?php endforeach;
            endif; ?>
        </section>

        <div class="acciones-final">
            <button class="btn boton-salir btn--exit" type="button" onclick="history.back()">salir</button>
        </div>
    </main>

    <script>
        (function() {
            // activar tarjeta y editar horas (igual al patrón que ya usas)
            const lista = document.getElementById('lista-reportes');
            let tarjetaActiva = null,
                horasTemp = 0;

            function setFlechas(tarjeta, activar) {
                tarjeta.querySelectorAll('.flecha').forEach(b => activar ? b.removeAttribute('disabled') : b.setAttribute('disabled', ''));
            }

            async function guardarCambios(tarjeta) {
                const id = tarjeta.dataset.id;
                const nuevasHoras = horasTemp;
                const descEl = tarjeta.querySelector('.reporte-desc');
                const descripcion = descEl ? descEl.textContent.trim() : '';

                const form = new FormData();
                form.append('action', 'update_report');
                form.append('id', id);
                form.append('horas', String(nuevasHoras));
                form.append('descripcion', descripcion);

                try {
                    const resp = await fetch(location.pathname, {
                        method: 'POST',
                        body: form
                    });
                    const j = await resp.json();
                    if (!j.ok) {
                        alert('Error: ' + (j.error || 'desconocido'));
                        return false;
                    }
                    tarjeta.dataset.horas = String(nuevasHoras);
                    const span = tarjeta.querySelector('.horas-valor');
                    if (span) span.textContent = String(nuevasHoras);
                    if (Number(nuevasHoras) <= 0) {
                        tarjeta.classList.remove('adeuda');
                        tarjeta.classList.add('pagado');
                    } else {
                        tarjeta.classList.remove('pagado');
                        tarjeta.classList.add('adeuda');
                    }
                    return true;
                } catch (err) {
                    alert('Error de red: ' + err.message);
                    return false;
                }
            }

            lista.addEventListener('click', function(e) {
                const up = e.target.closest('.flecha-up');
                const down = e.target.closest('.flecha-down');
                if (up || down) {
                    const card = e.target.closest('.reporte-card');
                    if (!card || card !== tarjetaActiva) return;
                    horasTemp = up ? horasTemp + 1 : Math.max(0, horasTemp - 1);
                    const span = card.querySelector('.horas-valor');
                    if (span) span.textContent = String(horasTemp);
                    return;
                }
                const tarjeta = e.target.closest('.reporte-card');
                if (!tarjeta) return;
                if (!tarjetaActiva) {
                    tarjetaActiva = tarjeta;
                    tarjetaActiva.classList.add('seleccionado');
                    horasTemp = Number(tarjetaActiva.dataset.horas || 0);
                    setFlechas(tarjetaActiva, true);
                    return;
                }
                if (tarjetaActiva === tarjeta) {
                    guardarCambios(tarjetaActiva).then(ok => {
                        if (ok) {
                            setFlechas(tarjetaActiva, false);
                            tarjetaActiva.classList.remove('seleccionado');
                            tarjetaActiva = null;
                            horasTemp = 0;
                        }
                    });
                    return;
                }
                if (tarjetaActiva && tarjetaActiva !== tarjeta) {
                    const prev = tarjetaActiva;
                    const spanPrev = prev.querySelector('.horas-valor');
                    if (spanPrev) spanPrev.textContent = String(prev.dataset.horas || '0');
                    setFlechas(prev, false);
                    prev.classList.remove('seleccionado');
                    tarjetaActiva = tarjeta;
                    tarjetaActiva.classList.add('seleccionado');
                    horasTemp = Number(tarjetaActiva.dataset.horas || 0);
                    setFlechas(tarjetaActiva, true);
                    return;
                }
            });

            lista.addEventListener('keydown', function(e) {
                const card = e.target.closest('.reporte-card');
                if (!card) return;
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    card.click();
                }
                if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && card === tarjetaActiva) {
                    e.preventDefault();
                    horasTemp = (e.key === 'ArrowUp') ? horasTemp + 1 : Math.max(0, horasTemp - 1);
                    const span = card.querySelector('.horas-valor');
                    if (span) span.textContent = String(horasTemp);
                }
            });
        })();
    </script>
</body>

</html>