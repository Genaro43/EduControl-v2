<?php
session_start();
include '../includes/conexion.php';

// obtener matrícula (prioridad GET, luego sesión si definiste)
$matricula = '';
if (!empty($_GET['matricula'])) {
    $matricula = trim($_GET['matricula']);
} elseif (!empty($_SESSION['edu_rol']) && $_SESSION['edu_rol'] === 'alumno') {
    $matricula = trim($_SESSION['edu_matricula'] ?? $_SESSION['edu_user'] ?? '');
}

if (! $matricula) {
    http_response_code(400);
    echo "Matrícula no proporcionada. Usa: /public/alumno.php?matricula=2025001";
    exit;
}

/*
  Consulta robusta:
  - Seleccionamos campos de alumnos (prefijo a.)
  - Seleccionamos g.* para no fallar si la tabla 'grupos' tiene columnas distintas.
  - Luego en PHP detectamos qué columna usar para grado y grupo.
*/
$sql = "
    SELECT
      a.id AS a_id,
      a.matricula AS a_matricula,
      a.nombre AS a_nombre,
      a.foto AS a_foto,
      g.* 
    FROM alumnos a
    LEFT JOIN grupos g ON a.grupo_id = g.id
    WHERE a.matricula = ?
    LIMIT 1
";

$alumno = null;
$row = null;
if ($stmt = $conexion->prepare($sql)) {
    $stmt->bind_param('s', $matricula);
    if (! $stmt->execute()) {
        error_log("alumno.php - execute error: " . $stmt->error);
    } else {
        $res = $stmt->get_result();
        if ($res && $res->num_rows) {
            $row = $res->fetch_assoc();
        }
    }
    $stmt->close();
} else {
    error_log("alumno.php - prepare error: " . $conexion->error);
    http_response_code(500);
    echo "Error en la consulta a la base de datos. Revisa logs.";
    exit;
}

if (! $row) {
    http_response_code(404);
    echo "Alumno no encontrado para la matrícula: " . htmlspecialchars($matricula, ENT_QUOTES, 'UTF-8');
    exit;
}

// Normalizar: campos del alumno vienen con prefijo a_
// extraer nombre completo y foto del alumno
$nombreCompleto = trim($row['a_nombre'] ?? '');
$foto = $row['a_foto'] ?? '';

// Detectar campo para grado en la fila de grupos:
$grado = '';
$grupoNombre = '';

// posibles nombres de columna que podrías tener en 'grupos'
$posiblesGradoCols = ['grado', 'semestre', 'nivel'];
$posiblesGrupoCols = ['nombre', 'grupo', 'grupo_nombre', 'codigo'];

foreach ($posiblesGradoCols as $c) {
    if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
        $grado = $row[$c];
        break;
    }
}

foreach ($posiblesGrupoCols as $c) {
    if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
        $grupoNombre = $row[$c];
        break;
    }
}

// Si sigue vacío, intentar tomar 'grupo' sin prefijo en alumnos
if ($grado === '' && array_key_exists('grado', $row)) $grado = $row['grado'];
if ($grupoNombre === '' && array_key_exists('grupo', $row)) $grupoNombre = $row['grupo'];

// fallback si aún vacío
if ($grado === '') $grado = $row['a_grado'] ?? '';
if ($grupoNombre === '') $grupoNombre = $row['a_grupo'] ?? '';

if (! $foto) $foto = '../assets/avatar-placeholder.png';

// separar nombre y apellidos para mostrar (opcional)
$partes = preg_split('/\s+/', $nombreCompleto);
$nombre = array_shift($partes) ?: '';
$apellidos = count($partes) ? implode(' ', $partes) : '';

function esc($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Alumno — <?= esc($nombreCompleto) ?></title>
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
                </div>
            </div>
        </section>

        <section id="lista-reportes" class="lista-reportes" aria-live="polite"></section>

        <div class="acciones-final">
            <a class="btn boton-salir" id="btn-regresar" href="../index.php">regresar</a>
        </div>
    </main>

    <script>
        // Poner matrícula en querystring para reutilizar scripts (ver-reporte.js etc.)
        (function() {
            const m = "<?= esc($matricula) ?>";
            try {
                const url = new URL(location.href);
                if (!url.searchParams.get('matricula')) {
                    url.searchParams.set('matricula', m);
                    history.replaceState({}, '', url);
                }
            } catch (e) {}
        })();
    </script>
</body>

</html>