<?php
session_start();
include '../includes/conexion.php'; // ajusta si tu ruta es otra

// helper: escapar
function esc($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// helper: existe columna en tabla
function columnExists(mysqli $conn, string $table, string $col): bool
{
    $q = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $res = $conn->query("SHOW COLUMNS FROM `{$q}` LIKE '{$c}'");
    if (!$res) return false;
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

// obtener matrícula (GET -> sesión alumno)
$matricula = '';
if (!empty($_GET['matricula'])) {
    $matricula = trim($_GET['matricula']);
} elseif (!empty($_SESSION['edu_rol']) && $_SESSION['edu_rol'] === 'alumno') {
    $matricula = trim($_SESSION['edu_matricula'] ?? $_SESSION['edu_user'] ?? '');
}
if (!$matricula) {
    http_response_code(400);
    echo "Matrícula no proporcionada. Usa: /public/alumno.php?matricula=2025001";
    exit;
}

// 1) obtener datos del alumno (siempre por matricula)
$sqlAlumno = "
    SELECT a.id AS a_id, a.matricula AS a_matricula, a.nombre AS a_nombre, a.foto AS a_foto, a.es_jefe AS a_es_jefe,
           g.* 
    FROM alumnos a
    LEFT JOIN grupos g ON a.grupo_id = g.id
    WHERE a.matricula = ?
    LIMIT 1
";
$row = null;
if ($stmt = $conexion->prepare($sqlAlumno)) {
    $stmt->bind_param('s', $matricula);
    if (! $stmt->execute()) {
        error_log("alumno.php execute error: " . $stmt->error);
    } else {
        $res = $stmt->get_result();
        if ($res && $res->num_rows) $row = $res->fetch_assoc();
        if ($res) $res->free();
    }
    $stmt->close();
} else {
    error_log("alumno.php prepare error: " . $conexion->error);
    http_response_code(500);
    echo "Error en la consulta a la base de datos. Revisa logs.";
    exit;
}
if (!$row) {
    http_response_code(404);
    echo "Alumno no encontrado para la matrícula: " . esc($matricula);
    exit;
}

// normalizar campos básicos
$alumnoId = $row['a_id'] ?? null;
$nombreCompleto = trim($row['a_nombre'] ?? '');
$foto = $row['a_foto'] ?? '';
$esJefe = false;
if (array_key_exists('a_es_jefe', $row)) {
    $esJefe = ($row['a_es_jefe'] === 1 || $row['a_es_jefe'] === '1' || $row['a_es_jefe'] === true || $row['a_es_jefe'] === 'true');
} elseif (array_key_exists('es_jefe', $row)) {
    $esJefe = ($row['es_jefe'] === 1 || $row['es_jefe'] === '1' || $row['es_jefe'] === true || $row['es_jefe'] === 'true');
}
if (!$foto) $foto = '../assets/avatar-placeholder.png';

// detectar grado y grupo con nombres flexibles
$grado = '';
$grupoNombre = '';
foreach (['grado', 'semestre', 'nivel'] as $c) if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
    $grado = $row[$c];
    break;
}
foreach (['nombre', 'grupo', 'grupo_nombre', 'codigo', 'nombre_grupo'] as $c) if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
    $grupoNombre = $row[$c];
    break;
}

// 2) leer reportes del alumno (robusto: si reportes tiene alumno_id lo usamos; si tiene matricula usamos matricula)
$reportes = [];

$hasAlumnoId = columnExists($conexion, 'reportes', 'alumno_id');
$hasMatriculaCol = columnExists($conexion, 'reportes', 'matricula'); // por si acaso
$hasAplicadoPor = columnExists($conexion, 'reportes', 'aplicado_por');
$hasUltimaModPor = columnExists($conexion, 'reportes', 'ultima_mod_por');
$joinUsuarios = ($hasAplicadoPor || $hasUltimaModPor) && columnExists($conexion, 'usuarios', 'id');

if ($hasAlumnoId && $alumnoId !== null) {
    // preferible: join con usuarios (si existen columnas)
    $select = "r.id, r.tipo, r.descripcion, r.horas, r.created_at, r.updated_at";
    $joins = "";
    if ($joinUsuarios) {
        if ($hasAplicadoPor) {
            $select .= ", u1.nombre AS creado_por, r.aplicado_por";
            $joins .= " LEFT JOIN usuarios u1 ON r.aplicado_por = u1.id ";
        }
        if ($hasUltimaModPor) {
            $select .= ", u2.nombre AS mod_por, r.ultima_mod_por";
            $joins .= " LEFT JOIN usuarios u2 ON r.ultima_mod_por = u2.id ";
        }
    } else {
        // no hay users joins, pero aún incluimos las columnas si existen
        if ($hasAplicadoPor) $select .= ", r.aplicado_por";
        if ($hasUltimaModPor) $select .= ", r.ultima_mod_por";
    }

    $sqlR = "SELECT {$select} FROM reportes r {$joins} WHERE r.alumno_id = ? ORDER BY COALESCE(r.created_at, r.updated_at) DESC";
    if ($stmt = $conexion->prepare($sqlR)) {
        $stmt->bind_param('i', $alumnoId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) $reportes[] = $r;
                $res->free();
            }
        } else {
            error_log("alumno.php reportes execute error: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("alumno.php reportes prepare error: " . $conexion->error);
    }
} elseif ($hasMatriculaCol) {
    // fallback por matricula directamente en reportes
    $select = "r.id, r.tipo, r.descripcion, r.horas, r.created_at, r.updated_at";
    $joins = "";
    if ($joinUsuarios) {
        if ($hasAplicadoPor) {
            $select .= ", u1.nombre AS creado_por, r.aplicado_por";
            $joins .= " LEFT JOIN usuarios u1 ON r.aplicado_por = u1.id ";
        }
        if ($hasUltimaModPor) {
            $select .= ", u2.nombre AS mod_por, r.ultima_mod_por";
            $joins .= " LEFT JOIN usuarios u2 ON r.ultima_mod_por = u2.id ";
        }
    } else {
        if ($hasAplicadoPor) $select .= ", r.aplicado_por";
        if ($hasUltimaModPor) $select .= ", r.ultima_mod_por";
    }

    $sqlR = "SELECT {$select} FROM reportes r {$joins} WHERE r.matricula = ? ORDER BY COALESCE(r.created_at, r.updated_at) DESC";
    if ($stmt = $conexion->prepare($sqlR)) {
        $stmt->bind_param('s', $matricula);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) $reportes[] = $r;
                $res->free();
            }
        } else {
            error_log("alumno.php reportes execute error: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("alumno.php reportes prepare error: " . $conexion->error);
    }
} else {
    // no hay forma clara de asociar reportes al alumno: buscar por texto en descripcion o tipo (opcional)
    // Lo dejamos vacío y notificamos en logs
    error_log("alumno.php: la tabla 'reportes' no tiene columna 'alumno_id' ni 'matricula' para relacionar.");
}

// separar adeudos / completados
$adeudos = [];
$completados = [];
foreach ($reportes as $r) {
    $horas = isset($r['horas']) ? (int)$r['horas'] : 0;
    if ($horas > 0) $adeudos[] = $r;
    else $completados[] = $r;
}

// helper formateo fecha
function fmtFecha($s)
{
    if (!$s) return '';
    $ts = strtotime($s);
    if ($ts === false) return esc($s);
    return date('Y-m-d H:i', $ts);
}

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>EduControl</title>
    <link rel="stylesheet" href="../build/css/app.css" />
</head>

<body class="app">
    <header class="app__header" role="banner">
        <div class="logo" aria-hidden="true">logo</div>
    </header>

    <main class="app__main pagina-reportes" role="main" aria-label="Detalle del alumno">
        <section class="perfil">
            <h1 class="nombre-alumno"><?= esc($nombreCompleto) ?></h1>

            <div class="perfil-contenedor">
                <div class="perfil-info">
                    <?php if ($grado !== ''): ?>
                        <div class="campo-info"><strong>Grado</strong> <span><?= esc($grado) ?></span></div>
                    <?php endif; ?>

                    <?php if ($grupoNombre !== ''): ?>
                        <div class="campo-info"><strong>Grupo</strong> <span><?= esc($grupoNombre) ?></span></div>
                    <?php endif; ?>

                    <div class="campo-info"><strong>Matrícula</strong> <span class="matricula"><?= esc($matricula) ?></span></div>
                </div>

                <div class="perfil-foto">
                    <div class="foto-tarjeta">
                        <img id="foto-alumno" src="<?= esc($foto) ?>" alt="Foto del alumno"
                            onerror="this.onerror=null;this.src='../assets/avatar-placeholder.png'" />
                    </div>
                    <?php if ($esJefe): ?>
                        <div>
                            <button id="btn-jefe" class="btn btn--small" type="button" data-matricula="<?= esc($matricula) ?>">
                                Aulas
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="lista-reportes">
            <h2 style="color:white;">Adeudos</h2>
            <?php if (empty($adeudos)): ?>
                <div style="padding:12px;color:#666;">No hay adeudos.</div>
            <?php else: ?>
                <?php foreach ($adeudos as $r):
                    $hor = isset($r['horas']) ? (int)$r['horas'] : 0;
                    $tipo = $r['tipo'] ?? '';
                    $desc = $r['descripcion'] ?? '';
                    $creadoPor = $r['creado_por'] ?? ($r['aplicado_por'] ?? '');
                    $modPor = $r['mod_por'] ?? ($r['ultima_mod_por'] ?? '');
                    $created = fmtFecha($r['created_at'] ?? '');
                    $updated = fmtFecha($r['updated_at'] ?? '');
                ?>
                    <article class="reporte-card adeuda" tabindex="0">
                        <div class="reporte-contenido">
                            <div class="reporte-tipo"><?= esc($tipo) ?></div>
                            <div class="reporte-meta">
                                Creado por: <?= esc($creadoPor) ?: '—' ?> · <?= esc($created) ?>
                                <?php if ($modPor || $updated): ?>
                                    <br>Modificado por: <?= esc($modPor) ?: '—' ?> · <?= esc($updated) ?>
                                <?php endif; ?>
                            </div>
                            <div class="reporte-horas">Horas adeudadas: <span class="horas-valor"><?= esc($hor) ?></span></div>
                            <?php if ($desc): ?>
                                <div class="reporte-desc"><?= esc($desc) ?></div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>

            <h2 style="color:white;">Completados</h2>
            <?php if (empty($completados)): ?>
                <div>No hay reportes completados.</div>
            <?php else: ?>
                <?php foreach ($completados as $r):
                    $hor = isset($r['horas']) ? (int)$r['horas'] : 0;
                    $tipo = $r['tipo'] ?? '';
                    $desc = $r['descripcion'] ?? '';
                    $creadoPor = $r['creado_por'] ?? ($r['aplicado_por'] ?? '');
                    $modPor = $r['mod_por'] ?? ($r['ultima_mod_por'] ?? '');
                    $created = fmtFecha($r['created_at'] ?? '');
                    $updated = fmtFecha($r['updated_at'] ?? '');
                ?>
                    <article class="reporte-card pagado" tabindex="0">
                        <div class="reporte-contenido">
                            <div class="reporte-tipo"><?= esc($tipo) ?></div>
                            <div class="reporte-meta">
                                Creado por: <?= esc($creadoPor) ?: '—' ?> · <?= esc($created) ?>
                                <?php if ($modPor || $updated): ?>
                                    <br>Modificado por: <?= esc($modPor) ?: '—' ?> · <?= esc($updated) ?>
                                <?php endif; ?>
                            </div>
                            <div class="reporte-horas">Horas adeudadas: <span class="horas-valor"><?= esc($hor) ?></span></div>
                            <?php if ($desc): ?>
                                <div class="reporte-desc"><?= esc($desc) ?></div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <div class="acciones-final">
            <button class="btn boton-salir btn--exit" type="button" onclick="history.back()">salir</button>
        </div>
    </main>
</body>

</html>