<?php
session_start();
include '../includes/conexion.php';

//? helper: escapar salida
function esc($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// obtener columnas de una tabla 
function get_table_columns(mysqli $conn, string $table): array
{
    $cols = [];
    if ($res = $conn->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        $res->free();
    }
    return $cols;
}

$alumnos = [];
$gruposList = []; //* lista rows de grupos para poblar selects
$gradoOptions = [];
$grupoOptions = [];

if ($conexion instanceof mysqli) {
    //? detectar columnas en grupos
    $gcols = get_table_columns($conexion, 'grupos');

    //? determinar nombre de columna que guarda el "nombre del grupo"
    $groupNameCol = null;
    foreach (['nombre', 'grupo', 'nombre_grupo', 'grupo_nombre'] as $cand) {
        if (in_array($cand, $gcols, true)) {
            $groupNameCol = $cand;
            break;
        }
    }
    //? determinar columna para "grado" o "semestre"
    $gradoCol = null;
    foreach (['grado', 'semestre', 'nivel'] as $cand) {
        if (in_array($cand, $gcols, true)) {
            $gradoCol = $cand;
            break;
        }
    }

    //? Leer todos los grupos para poblar selects
    $sqlG = "SELECT * FROM grupos";
    if ($res = $conexion->query($sqlG)) {
        while ($r = $res->fetch_assoc()) {
            //? extraer valores según columnas detectadas (fallback a vacío)
            $gNombre = $groupNameCol ? (string)($r[$groupNameCol] ?? '') : '';
            $gGrado  = $gradoCol ? (string)($r[$gradoCol] ?? '') : '';
            $gruposList[] = ['id' => $r['id'] ?? null, 'nombre' => $gNombre, 'grado' => $gGrado];

            // Usamos arrays como "set" para mantener unicidad
            if ($gNombre !== '') $grupoOptions[$gNombre] = true;
            if ($gGrado !== '')  $gradoOptions[$gGrado]  = true;
        }
        $res->free();
    }

    // Normalizamos arrays de opciones (ordenadas)
    $gradoOptions = array_keys($gradoOptions);
    sort($gradoOptions, SORT_NATURAL);
    $grupoOptions = array_keys($grupoOptions);
    sort($grupoOptions, SORT_NATURAL);

    // Construir expresión SELECT para grado/grupo en alumnos (centrado en columnas existentes)
    $alCols = get_table_columns($conexion, 'alumnos');
    $grCols = $gcols;

    // Para "grado" en la tarjeta: preferimos a.grado, luego g.grado, luego g.semestre
    $gradoCandidates = [];
    if (in_array('grado', $alCols, true)) $gradoCandidates[] = 'a.grado';
    if (in_array('grado', $grCols, true)) $gradoCandidates[] = 'g.grado';
    if (in_array('semestre', $grCols, true)) $gradoCandidates[] = 'g.semestre';
    $gradoExpr = empty($gradoCandidates) ? "'' AS grado" : "COALESCE(" . implode(', ', $gradoCandidates) . ", '') AS grado";

    // Para "grupo" (label)
    $grupoCandidates = [];
    if (in_array('nombre', $grCols, true)) $grupoCandidates[] = 'g.nombre';
    if (in_array('grupo', $grCols, true)) $grupoCandidates[] = 'g.grupo';
    if (in_array('nombre_grupo', $grCols, true)) $grupoCandidates[] = 'g.nombre_grupo';
    $grupoExpr = empty($grupoCandidates) ? "'' AS grupo" : "COALESCE(" . implode(', ', $grupoCandidates) . ", '') AS grupo";

    // Consulta alumnos (LEFT JOIN grupos)
    $sql = "
        SELECT
            a.id AS id,
            COALESCE(a.matricula, '') AS matricula,
            COALESCE(a.nombre, '') AS nombre,
            $gradoExpr,
            $grupoExpr,
            COALESCE(a.foto, '') AS foto
        FROM alumnos a
        LEFT JOIN grupos g ON a.grupo_id = g.id
        ORDER BY a.nombre COLLATE utf8mb4_general_ci ASC
        LIMIT 2000
    ";

    if ($stmt = $conexion->prepare($sql)) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $alumnos = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
        }
        $stmt->close();
    } else {
        error_log("alumnos-vista.php: error preparando consulta: " . $conexion->error);
    }

    // -----------------------
    // Obtener conteo de reportes por alumno (una sola consulta)
    // -----------------------
    $reportCounts = [];
    if (!empty($alumnos)) {
        // recolectar ids (enteros)
        $ids = array_map(function ($r) {
            return (int)($r['id'] ?? 0);
        }, $alumnos);
        $ids = array_filter($ids, function ($v) {
            return $v > 0;
        });
        if (!empty($ids)) {
            $in = implode(',', $ids); // valores ya enteros -> seguro
            $sqlCounts = "SELECT alumno_id, COUNT(*) AS cnt FROM reportes WHERE activo = 1 AND alumno_id IN ($in) GROUP BY alumno_id";
            if ($r = $conexion->query($sqlCounts)) {
                while ($row = $r->fetch_assoc()) {
                    $aid = (int)$row['alumno_id'];
                    $reportCounts[$aid] = (int)$row['cnt'];
                }
                $r->free();
            }
        }
    }

    // -----------------------
    // Ordenar alumnos por #reportes (desc) y luego por nombre (asc)
    // -----------------------
    usort($alumnos, function ($a, $b) use ($reportCounts) {
        $aid = (int)($a['id'] ?? 0);
        $bid = (int)($b['id'] ?? 0);
        $ca = $reportCounts[$aid] ?? 0;
        $cb = $reportCounts[$bid] ?? 0;

        // primero por count desc
        if ($ca !== $cb) return $cb <=> $ca; // cb <=> ca para orden descendente
        // empate: ordenar por nombre asc, case-insensitive
        return strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? '');
    });
} else {
    error_log('alumnos-vista.php: $conexion no es instancia de mysqli');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>EduControl</title>
    <link rel="icon" href="../src/img/cecyteh.ico" type="image/x-icon">
    <link rel="stylesheet" href="../build/css/app.css" />
</head>

<body class="app">
    <header class="app__header" role="banner">
        <div class="logo" aria-hidden="true">logo</div>
    </header>

    <main class="app__main alumnos-page" role="main">
        <!-- filtros de texto -->
        <section class="filtros">
            <div class="filtros__campo">
                <label class="sr-only" for="f-matricula">Matrícula</label>
                <input id="f-matricula" class="input--texto" type="text" placeholder="matrícula" />
            </div>

            <div class="filtros__campo">
                <label class="sr-only" for="f-nombre">Nombre</label>
                <input id="f-nombre" class="input--texto input--grande" type="text" placeholder="nombre" />
            </div>

            <div class="filtros__selects">
                <div class="select-wrap">
                    <label class="sr-only" for="f-grado">Grado</label>
                    <select id="f-grado" class="select">
                        <option value="">grado</option>
                        <?php foreach ($gradoOptions as $g): ?>
                            <option value="<?php echo esc($g); ?>"><?php echo esc($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="select-wrap">
                    <label class="sr-only" for="f-grupo">Grupo</label>
                    <select id="f-grupo" class="select">
                        <option value="">grupo</option>
                        <?php foreach ($grupoOptions as $gr): ?>
                            <option value="<?php echo esc($gr); ?>"><?php echo esc($gr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- contador y limpiar -->
                <div>
                    <button id="btn-limpiar" class="btn" type="button">Limpiar</button>
                    <div id="contador" aria-live="polite" style="font-weight:700; color:white;"><?php echo count($alumnos); ?> resultados</div>
                </div>
            </div>
        </section>

        <!-- lista de alumnos: generado desde PHP -->
        <section id="lista-alumnos" class="lista-alumnos" aria-live="polite">
            <?php if (empty($alumnos)): ?>
                <div>No hay alumnos.</div>
            <?php else: ?>
                <?php
                $topN = 3; // cuantos quieres marcar como recomendados
                foreach ($alumnos as $idx => $row):
                    $mat = esc($row['matricula'] ?? '');
                    $nom = esc($row['nombre'] ?? '');
                    $grado = esc($row['grado'] ?? '');
                    $grupo = esc($row['grupo'] ?? '');
                    $foto = esc($row['foto'] ?: '../assets/avatar-placeholder.png');

                    $aid = (int)($row['id'] ?? 0);
                    $cnt = $reportCounts[$aid] ?? 0;

                    if ($cnt === 0) {
                        $reportClass = '';
                    } elseif ($cnt === 1) {
                        $reportClass = 'reportes-bajo';
                    } elseif ($cnt >= 2 && $cnt <= 4) {
                        $reportClass = 'reportes-medio';
                    } else {
                        $reportClass = 'reportes-alto';
                    }

                    // bandera recomendado para topN
                    $esRecomendado = ($idx < $topN);
                ?>
                    <article class="alumno-card <?php echo $reportClass ?>" data-matricula="<?php echo $mat ?>" data-nombre="<?php echo $nom ?>" data-grado="<?php echo $grado ?>" data-grupo="<?php echo $grupo ?>" tabindex="0">
                        <div class="alumno-info">
                            <div class="alumno-nombre"><?php echo $nom ?></div>
                            <div class="alumno-meta">
                                Matrícula: <?php echo $mat ?> &nbsp;•&nbsp; Semestre: <?php echo $grado ?> &nbsp;•&nbsp; Grupo: <?php echo $grupo ?>
                            </div>
                        </div>

                        <div class="acciones">
                            <a class="btn btn--small" href="./ver-reporte.php?matricula=<?php echo urlencode($mat) ?>">reportes</a>
                            <a class="btn btn--small" href="./aplicar-reporte.php?matricula=<?php echo urlencode($mat) ?>">reportar</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- botón salir -->
        <button class="btn boton-salir btn--exit" type="button" onclick="history.back()">salir</button>
    </main>

    <!-- Script: filtrado eficiente + toggle acciones -->
    <script>
        (function() {
            const inputMat = document.getElementById('f-matricula');
            const inputNom = document.getElementById('f-nombre');
            const selGrado = document.getElementById('f-grado');
            const selGrupo = document.getElementById('f-grupo');
            const btnLimpiar = document.getElementById('btn-limpiar');
            const lista = document.getElementById('lista-alumnos');
            const contador = document.getElementById('contador');

            function debounce(fn, wait = 180) {
                let t;
                return (...args) => {
                    clearTimeout(t);
                    t = setTimeout(() => fn.apply(this, args), wait);
                };
            }

            function filtrar() {
                const m = (inputMat.value || '').trim().toLowerCase();
                const n = (inputNom.value || '').trim().toLowerCase();
                const g = selGrado.value;
                const gr = selGrupo.value;

                let visibles = 0;
                Array.from(lista.children).forEach(card => {
                    if (!card.dataset) return;
                    const nombre = (card.dataset.nombre || '').toLowerCase();
                    const matricula = (card.dataset.matricula || '').toLowerCase();
                    const grado = card.dataset.grado || '';
                    const grupo = card.dataset.grupo || '';

                    const okMat = !m || matricula.includes(m);
                    const okNom = !n || nombre.includes(n);
                    const okGrado = !g || grado === g;
                    const okGrupo = !gr || grupo === gr;

                    const mostrar = okMat && okNom && okGrado && okGrupo;
                    card.style.display = mostrar ? '' : 'none';
                    if (mostrar) visibles++;
                });

                contador.textContent = `${visibles} resultados`;
            }

            const filtrarDebounced = debounce(filtrar, 160);
            [inputMat, inputNom].forEach(i => i.addEventListener('input', filtrarDebounced));
            [selGrado, selGrupo].forEach(s => s.addEventListener('change', filtrarDebounced));

            btnLimpiar.addEventListener('click', () => {
                inputMat.value = '';
                inputNom.value = '';
                selGrado.value = '';
                selGrupo.value = '';
                filtrar();
            });

            (function initContador() {
                const total = Array.from(lista.children).filter(c => c.tagName && c.tagName.toLowerCase() === 'article').length;
                contador.textContent = `${total} resultados`;
            })();

            document.addEventListener('click', function(e) {
                const card = e.target.closest('.alumno-card');
                if (card) {
                    document.querySelectorAll('.alumno-card.activo').forEach(c => {
                        if (c !== card) c.classList.remove('activo');
                    });
                    card.classList.toggle('activo');
                    return;
                }
                if (!e.target.closest('.alumno-card')) {
                    document.querySelectorAll('.alumno-card.activo').forEach(c => c.classList.remove('activo'));
                }
            });

            document.querySelectorAll('.alumno-card').forEach(card => {
                card.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        card.classList.toggle('activo');
                    }
                });
            });
        })();
    </script>
</body>

</html>