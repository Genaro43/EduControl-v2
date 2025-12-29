<?php
// public/orientacion.php
session_start();
include '../includes/conexion.php'; // Ajusta si tu include está en otra ruta

// helper escapes
function esc($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// 1) Leer todos los grupos (SELECT *) y detectar columnas útiles
$gruposRaw = [];
$gradoCandidates = ['grado', 'semestre', 'nivel'];
$grupoCandidates = ['nombre', 'grupo', 'grupo_nombre', 'codigo', 'nombre_grupo'];

$gradoCol = null;
$grupoCol = null;

if ($res = $conexion->query("SELECT * FROM grupos")) {
    while ($r = $res->fetch_assoc()) {
        $gruposRaw[] = $r;
    }
    $res->free();
}

// detectar columna de grado y grupo a partir del primer row disponible (fallbacks por lista)
if (!empty($gruposRaw)) {
    $first = $gruposRaw[0];
    foreach ($gradoCandidates as $c) {
        if (array_key_exists($c, $first)) {
            $gradoCol = $c;
            break;
        }
    }
    foreach ($grupoCandidates as $c) {
        if (array_key_exists($c, $first)) {
            $grupoCol = $c;
            break;
        }
    }
}

// construir arrays únicos de opciones (grado, grupo) desde $gruposRaw
$gradoOptions = [];
$grupoOptions = [];
$groupsMap = []; // id => [grado, grupo, raw row]

foreach ($gruposRaw as $g) {
    $gid = $g['id'] ?? null;
    $gval = '';
    $gdval = '';
    if ($grupoCol !== null && array_key_exists($grupoCol, $g)) $gval = trim((string)$g[$grupoCol]);
    if ($gradoCol !== null && array_key_exists($gradoCol, $g)) $gdval = trim((string)$g[$gradoCol]);

    if ($gval !== '') $grupoOptions[$gval] = true;
    if ($gdval !== '') $gradoOptions[$gdval] = true;

    if ($gid !== null) $groupsMap[$gid] = ['grupo' => $gval, 'grado' => $gdval, 'raw' => $g];
}

// ordenar opciones
$gradoOptions = array_keys($gradoOptions);
sort($gradoOptions, SORT_NATURAL);
$grupoOptions = array_keys($grupoOptions);
sort($grupoOptions, SORT_NATURAL);

// 2) Consultar alumnos + suma horas de reportes activos (si existe campo 'activo' en reportes usamos filtro)
$alumnos = [];

// Consulta segura: no referenciamos columnas inciertas. Usamos a.grupo_id para enlazar con groupsMap.
$sql = "
    SELECT
      a.id AS alumno_id,
      COALESCE(a.matricula, '') AS matricula,
      COALESCE(a.nombre, '') AS nombre,
      COALESCE(a.foto, '') AS foto,
      COALESCE(a.grupo_id, '') AS grupo_id,
      COALESCE(SUM(r.horas), 0) AS total_horas,
      COUNT(r.id) AS num_reportes
    FROM alumnos a
    LEFT JOIN reportes r
      ON r.alumno_id = a.id
         AND (r.activo IS NULL OR r.activo = 1)
    GROUP BY a.id
    ORDER BY total_horas DESC, a.nombre COLLATE utf8mb4_general_ci ASC
";

if ($res = $conexion->query($sql)) {
    while ($r = $res->fetch_assoc()) {
        // obtener info de grupo desde groupsMap si existe
        $gid = $r['grupo_id'] ?? null;
        $grupoLabel = '';
        $gradoLabel = '';
        if ($gid !== null && $gid !== '' && isset($groupsMap[$gid])) {
            $grupoLabel = $groupsMap[$gid]['grupo'] ?? '';
            $gradoLabel = $groupsMap[$gid]['grado'] ?? '';
        }
        $r['grupo_label'] = $grupoLabel;
        $r['grado_label'] = $gradoLabel;
        $r['total_horas'] = (int)$r['total_horas'];
        $r['num_reportes'] = (int)$r['num_reportes'];
        $alumnos[] = $r;
    }
    $res->free();
}

// helper clase según horas
function nivelClaseHoras(int $h): string
{
    if ($h <= 0) return 'adeuda-baja';
    if ($h >= 1 && $h <= 4) return 'adeuda-media';
    return 'adeuda-alta';
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
    <header class="app__header" role="banner">
        <div class="logo" aria-hidden="true">logo</div>
    </header>

    <main class="app__main pagina-orientacion" role="main">
        <!-- filtros -->
        <section class="filtros">
            <div class="fila-filtros">
                <input id="f-matricula" class="input-filtro" type="text" placeholder="matrícula" />
                <input id="f-nombre" class="input-filtro input-grande" type="text" placeholder="nombre" />
            </div>

            <div class="fila-selects">
                <select id="f-grado" class="select-filtro">
                    <option value="">grado</option>
                    <?php foreach ($gradoOptions as $g): ?>
                        <option value="<?= esc($g) ?>"><?= esc($g) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="f-grupo" class="select-filtro">
                    <option value="">grupo</option>
                    <?php foreach ($grupoOptions as $gr): ?>
                        <option value="<?= esc($gr) ?>"><?= esc($gr) ?></option>
                    <?php endforeach; ?>
                </select>

                <div style="display:flex; align-items:center; gap:12px;">
                    <button id="btn-limpiar" class="btn" type="button">Limpiar</button>
                    <div id="contador" aria-live="polite" style="font-weight:700; color: #fff;">
                        <?= count($alumnos) ?> resultados
                    </div>
                </div>
            </div>
        </section>

        <!-- lista de alumnos -->
        <section id="lista-alumnos" class="lista-orientacion" aria-live="polite">
            <?php if (empty($alumnos)): ?>
                <div style="padding:16px;color:#666;">No hay alumnos.</div>
            <?php else: ?>
                <?php foreach ($alumnos as $a):
                    $mat = esc($a['matricula'] ?? '');
                    $nom = esc($a['nombre'] ?? '');
                    $grado = esc($a['grado_label'] ?? '');
                    $grupo = esc($a['grupo_label'] ?? '');
                    $horas = (int)($a['total_horas'] ?? 0);
                    $badge = $horas . 'h';
                    $cl = nivelClaseHoras($horas);
                ?>
                    <article class="tarjeta-alumno <?= $cl ?>"
                        data-matricula="<?= $mat ?>"
                        data-nombre="<?= $nom ?>"
                        data-grado="<?= $grado ?>"
                        data-grupo="<?= $grupo ?>"
                        data-horas="<?= $horas ?>"
                        tabindex="0">
                        <div class="contenido-tarjeta">
                            <div class="nombre"><?= $nom ?></div>
                            <div class="meta">Matrícula: <?= $mat ?></div>
                            <div class="meta"><?= ($grado !== '' ? 'Grado ' . $grado . ' · ' : '') ?><?= ($grupo !== '' ? 'Grupo ' . $grupo : '') ?></div>
                        </div>
                        <div class="badge"><?= $badge ?></div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <div class="acciones-final">
            <!-- <a href="prefectos.html" class="btn btn--exit boton-salir">salir</a> -->

            <button class="btn boton-salir btn--exit" type="button" onclick="history.back()">salir</button>
            <button class="btn boton-acciones" id="btn-exportar" type="button">Exportar</button>
        </div>
    </main>

    <script>
        (function() {
            const inputMat = document.getElementById('f-matricula');
            const inputNom = document.getElementById('f-nombre');
            const selGrado = document.getElementById('f-grado');
            const selGrupo = document.getElementById('f-grupo');
            const btnLimpiar = document.getElementById('btn-limpiar');
            const lista = document.getElementById('lista-alumnos');
            const contador = document.getElementById('contador');

            function debounce(fn, wait = 120) {
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
                contador.textContent = visibles + ' resultados';
            }

            const fdeb = debounce(filtrar, 150);
            [inputMat, inputNom].forEach(i => i.addEventListener('input', fdeb));
            [selGrado, selGrupo].forEach(s => s.addEventListener('change', fdeb));

            btnLimpiar.addEventListener('click', () => {
                inputMat.value = '';
                inputNom.value = '';
                selGrado.value = '';
                selGrupo.value = '';
                filtrar();
            });

            // Inicializar contador
            (function() {
                const total = Array.from(lista.children).filter(c => c.tagName && c.tagName.toLowerCase() === 'article').length;
                contador.textContent = total + ' resultados';
            })();

            // Click en tarjeta: toggle activo (evita navegación accidental)
            lista.addEventListener('click', (e) => {
                const card = e.target.closest('.tarjeta-alumno');
                if (!card) return;
                document.querySelectorAll('.tarjeta-alumno.activo').forEach(c => {
                    if (c !== card) c.classList.remove('activo');
                });
                card.classList.toggle('activo');
            });

            // tecla Enter en tarjeta
            lista.querySelectorAll('.tarjeta-alumno').forEach(card => {
                card.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        card.click();
                    }
                });
            });
        })();
    </script>
</body>

</html>