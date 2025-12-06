<?php
// aplicar-reporte.php
session_start();

// includes: intento rutas comunes
if (!@include_once __DIR__ . '/../includes/conexion.php') {
    @include_once __DIR__ . '/includes/conexion.php';
}
// $conexion debe venir del include (mysqli)
if (!isset($conexion) || !$conexion instanceof mysqli) {
    // Si no hay conexión válida, devolvemos HTML/JS que avisa
    http_response_code(500);
    echo "Error: no hay conexión a la base de datos (includes/conexion.php).";
    exit;
}

// manejar POST JSON o form para crear reporte
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Leer JSON si viene
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // intentar form-encoded
        $data = $_POST;
    }

    // validar campos mínimos
    $matricula = trim((string)($data['matricula'] ?? ''));
    $alumno_id = isset($data['alumno_id']) ? intval($data['alumno_id']) : null;
    $tipo = trim((string)($data['tipo'] ?? ''));
    $horas = isset($data['horas']) ? intval($data['horas']) : 0;
    $descripcion = trim((string)($data['descripcion'] ?? ''));

    // obtener aplicador si existe en sesión
    $aplicador_id = null;
    if (!empty($_SESSION['user_id'])) $aplicador_id = intval($_SESSION['user_id']);
    if (!empty($_SESSION['usuario_id'])) $aplicador_id = intval($_SESSION['usuario_id']);

    // generar id de reporte
    $reportId = substr(bin2hex(random_bytes(6)), 0, 12);
    $reportId = 'r' . $reportId . time();

    // buscar alumno_id si no lo dieron pero sí la matrícula
    if (!$alumno_id && $matricula) {
        $stm = $conexion->prepare("SELECT id FROM alumnos WHERE matricula = ? LIMIT 1");
        if ($stm) {
            $stm->bind_param('s', $matricula);
            $stm->execute();
            $res = $stm->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $alumno_id = intval($row['id']);
            }
            $stm->close();
        }
    }

    // intentar insertar en la tabla reportes (si existe)
    $ok = false;
    $err = '';
    try {
        $sql = "INSERT INTO reportes (id, alumno_id, aula_id, tipo, descripcion, horas, aplicado_por, ultima_mod_por, activo, created_at, updated_at)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 1, NOW(), NOW())";
        $stmt = $conexion->prepare($sql);
        if ($stmt) {
            // aplicar valores nullables
            $appliedBy = $aplicador_id ? $aplicador_id : null;
            $ultimaModPor = $aplicador_id ? $aplicador_id : null;
            // bind with nullable ints: use 'i' for ints, 's' for strings
            $stmt->bind_param('sissiii', $reportId, $alumno_id, $tipo, $descripcion, $horas, $appliedBy, $ultimaModPor);
            $stmt->execute();
            if ($stmt->affected_rows >= 0) $ok = true;
            $stmt->close();
        } else {
            $err = "Prepare failed: " . $conexion->error;
        }
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }

    // respuesta JSON
    header('Content-Type: application/json');
    if ($ok) {
        echo json_encode(['ok' => true, 'id' => $reportId, 'redirect' => './alumnos-vista.php']);
        exit;
    } else {
        // fallback: devolver error para que el cliente guarde localmente (si así lo deseas)
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $err ?: 'No se pudo insertar el reporte en la BD.']);
        exit;
    }
}

// ---------------- GET: mostrar página ----------------

// obtener matrícula desde query ?matricula=...
$matriculaQ = isset($_GET['matricula']) ? trim($_GET['matricula']) : '';

// buscar alumno básico
$alumno = null;
if ($matriculaQ !== '') {
    $q = $conexion->prepare("SELECT id, matricula, nombre, grupo_id, foto FROM alumnos WHERE matricula = ? LIMIT 1");
    if ($q) {
        $q->bind_param('s', $matriculaQ);
        $q->execute();
        $res = $q->get_result();
        if ($res && $res->num_rows) {
            $alumno = $res->fetch_assoc();
        }
        $q->close();
    }
}

// si no hay alumno, intentar cargar uno por fallback (primer alumno)
if (!$alumno) {
    $res = $conexion->query("SELECT id, matricula, nombre, grupo_id, foto FROM alumnos LIMIT 1");
    if ($res && $res->num_rows) $alumno = $res->fetch_assoc();
}

// preparar texto de grupo/grado de forma robusta
$grupoTexto = '';
if (!empty($alumno['grupo_id'])) {
    $gid = intval($alumno['grupo_id']);
    // inspeccionar columnas de grupos
    $cols = [];
    $rcols = $conexion->query("SHOW COLUMNS FROM grupos");
    if ($rcols) {
        while ($row = $rcols->fetch_assoc()) $cols[] = $row['Field'];
        $rcols->free();
    }
    $hasGrado = in_array('grado', $cols, true);
    $hasNombre = in_array('nombre', $cols, true);
    $hasGrupo  = in_array('grupo', $cols, true);
    $hasSemestre = in_array('semestre', $cols, true);

    if ($hasGrado && $hasNombre) {
        $stm = $conexion->prepare("SELECT grado, nombre FROM grupos WHERE id = ? LIMIT 1");
        if ($stm) {
            $stm->bind_param('i', $gid);
            $stm->execute();
            $res = $stm->get_result();
            if ($res && $row = $res->fetch_assoc()) $grupoTexto = $row['grado'] . ' · ' . $row['nombre'];
            $stm->close();
        }
    } elseif ($hasGrado && $hasGrupo) {
        $stm = $conexion->prepare("SELECT grado, `grupo` FROM grupos WHERE id = ? LIMIT 1");
        if ($stm) {
            $stm->bind_param('i', $gid);
            $stm->execute();
            $res = $stm->get_result();
            if ($res && $row = $res->fetch_assoc()) $grupoTexto = $row['grado'] . ' · ' . $row['grupo'];
            $stm->close();
        }
    } elseif ($hasSemestre && $hasGrupo) {
        $stm = $conexion->prepare("SELECT semestre, `grupo` FROM grupos WHERE id = ? LIMIT 1");
        if ($stm) {
            $stm->bind_param('i', $gid);
            $stm->execute();
            $res = $stm->get_result();
            if ($res && $row = $res->fetch_assoc()) $grupoTexto = $row['semestre'] . ' · ' . $row['grupo'];
            $stm->close();
        }
    } else {
        // fallback: seleccionar todo
        $stm = $conexion->prepare("SELECT * FROM grupos WHERE id = ? LIMIT 1");
        if ($stm) {
            $stm->bind_param('i', $gid);
            $stm->execute();
            $res = $stm->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                if (isset($row['grado'])) $grupoTexto = $row['grado'] . ' ';
                if (isset($row['nombre'])) $grupoTexto .= $row['nombre'];
                elseif (isset($row['grupo'])) $grupoTexto .= $row['grupo'];
            }
            $stm->close();
        }
    }
}

// para desplegar
$nombreAlumno = $alumno['nombre'] ?? 'Alumno';
$matricula = $alumno['matricula'] ?? '';
$foto = $alumno['foto'] ?? '../assets/avatar-placeholder.png';
$alumno_id = $alumno['id'] ?? null;

// cerrar conexión (no obligatorio)
$conexion->close();

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Aplicar reporte — <?= htmlspecialchars($nombreAlumno, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="../build/css/app.css" />
</head>

<body class="app">
    <header class="app__header" role="banner">
        <div class="logo" aria-hidden="true">logo</div>
    </header>

    <main class="app__main pagina-alumno" role="main" aria-label="Detalle del alumno">
        <h1 class="titulo-alumno"><?= htmlspecialchars($nombreAlumno, ENT_QUOTES) ?></h1>

        <section class="detalle-alumno">
            <div class="lado-izquierdo">
                <div class="etiqueta">Grado · Grupo</div>
                <div class="valor"><?= htmlspecialchars($grupoTexto, ENT_QUOTES) ?></div>
            </div>

            <div class="foto-contenedor">
                <div class="foto-tarjeta">
                    <img src="<?= htmlspecialchars($foto, ENT_QUOTES) ?>" alt="Foto del alumno" class="foto-alumno" />
                </div>

                <div class="matricula">Matrícula: <?= htmlspecialchars($matricula, ENT_QUOTES) ?></div>
            </div>
        </section>

        <section class="grid-reportes" aria-label="Acciones de reporte">
            <button type="button" class="boton-reporte" data-tipo="corte-cabello">C. Cabello</button>
            <button type="button" class="boton-reporte" data-tipo="uniforme">Uniforme</button>
            <button type="button" class="boton-reporte" data-tipo="credencial">Credencial</button>

            <div class="reporte-personalizado">
                <button type="button" class="boton-reporte reporte-personalizado-btn" data-tipo="personalizado">Personalizado</button>
                <div class="opciones-personalizadas" aria-hidden="true" style="display:none;">
                    <button type="button" class="opcion-personalizada" data-tipo="personalizado-grupal">Grupal</button>
                    <button type="button" class="opcion-personalizada" data-tipo="personalizado-individual">Individual</button>
                </div>
            </div>
        </section>

        <a href="./alumnos-vista.php" class="btn btn--exit boton-salir">salir</a>
        <button class="btn boton-enviar" id="btn-enviar" type="button" disabled>Enviar</button>
    </main>

    <script>
        (function() {
            const botones = Array.from(document.querySelectorAll('.grid-reportes .boton-reporte'));
            const personalizadoBtn = document.querySelector('.reporte-personalizado-btn');
            const opcionesPersonalizadas = document.querySelector('.opciones-personalizadas');
            const opciones = Array.from(document.querySelectorAll('.opcion-personalizada'));
            const btnEnviar = document.getElementById('btn-enviar');

            let reporteSeleccionado = null;
            let subtipoPersonalizado = null;

            function deseleccionarTodos() {
                botones.forEach(b => b.classList.remove('seleccionado'));
                opciones.forEach(o => o.classList.remove('seleccionado'));
            }

            function actualizarEnviar() {
                if (!reporteSeleccionado) {
                    btnEnviar.disabled = true;
                    return;
                }
                if (reporteSeleccionado.startsWith('personalizado') && !subtipoPersonalizado) {
                    btnEnviar.disabled = true;
                    return;
                }
                btnEnviar.disabled = false;
            }

            botones.forEach(boton => {
                boton.addEventListener('click', (e) => {
                    const tipo = boton.getAttribute('data-tipo');
                    if (!tipo) return;
                    if (boton.classList.contains('reporte-personalizado-btn')) {
                        const isOpen = opcionesPersonalizadas && opcionesPersonalizadas.style.display === 'block';
                        if (opcionesPersonalizadas) {
                            opcionesPersonalizadas.style.display = isOpen ? 'none' : 'block';
                            opcionesPersonalizadas.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
                        }
                        return;
                    }
                    deseleccionarTodos();
                    boton.classList.add('seleccionado');
                    reporteSeleccionado = tipo;
                    subtipoPersonalizado = null;
                    if (opcionesPersonalizadas) {
                        opcionesPersonalizadas.style.display = 'none';
                        opcionesPersonalizadas.setAttribute('aria-hidden', 'true');
                    }
                    actualizarEnviar();
                });
                boton.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        boton.click();
                    }
                });
            });

            opciones.forEach(op => {
                op.addEventListener('click', () => {
                    deseleccionarTodos();
                    op.classList.add('seleccionado');
                    if (personalizadoBtn) personalizadoBtn.classList.add('seleccionado');
                    const dt = op.getAttribute('data-tipo');
                    reporteSeleccionado = dt;
                    subtipoPersonalizado = dt;
                    if (opcionesPersonalizadas) {
                        opcionesPersonalizadas.style.display = 'none';
                        opcionesPersonalizadas.setAttribute('aria-hidden', 'true');
                    }
                    actualizarEnviar();
                });
                op.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        op.click();
                    }
                });
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.reporte-personalizado')) {
                    if (opcionesPersonalizadas) {
                        opcionesPersonalizadas.style.display = 'none';
                        opcionesPersonalizadas.setAttribute('aria-hidden', 'true');
                    }
                }
            });

            // modal builder
            function abrirModalConfirm({
                titulo = 'Confirmar reporte',
                tipoLabel = '',
                horas = 0,
                descripcion = ''
            } = {}) {
                const overlay = document.createElement('div');
                overlay.className = 'er-modal-overlay';
                overlay.setAttribute('role', 'dialog');
                overlay.setAttribute('aria-modal', 'true');

                const card = document.createElement('div');
                card.className = 'er-modal-card';
                const h = document.createElement('h3');
                h.className = 'er-modal-titulo';
                h.textContent = titulo;
                const body = document.createElement('div');
                body.className = 'er-modal-body';

                const tipoRow = document.createElement('div');
                tipoRow.className = 'er-row';
                tipoRow.innerHTML = `<strong>Tipo:</strong> <span class="er-tipo">${tipoLabel}</span>`;

                const horasRow = document.createElement('div');
                horasRow.className = 'er-row';
                const horasLabel = document.createElement('label');
                horasLabel.setAttribute('for', 'er-horas');
                horasLabel.innerHTML = '<strong>Horas de servicio</strong>';
                const inputHoras = document.createElement('input');
                inputHoras.id = 'er-horas';
                inputHoras.type = 'number';
                inputHoras.min = '0';
                inputHoras.step = '1';
                inputHoras.className = 'er-input-horas';
                inputHoras.value = String(Number(horas) || 0);
                horasRow.appendChild(horasLabel);
                horasRow.appendChild(inputHoras);

                const descRow = document.createElement('div');
                descRow.className = 'er-row er-desc-row';
                const textarea = document.createElement('textarea');
                textarea.id = 'er-descripcion';
                textarea.className = 'er-textarea';
                textarea.placeholder = 'Descripción (opcional)';
                textarea.value = descripcion || '';
                if (String(tipoLabel).toLowerCase().includes('personalizado')) descRow.appendChild(textarea);
                else descRow.style.display = 'none';

                const footer = document.createElement('div');
                footer.className = 'er-modal-footer';
                const btnCancel = document.createElement('button');
                btnCancel.type = 'button';
                btnCancel.className = 'btn er-btn-cancel';
                btnCancel.textContent = 'Cancelar';
                const btnConfirm = document.createElement('button');
                btnConfirm.type = 'button';
                btnConfirm.className = 'btn er-btn-confirm';
                btnConfirm.textContent = 'Confirmar';
                footer.appendChild(btnCancel);
                footer.appendChild(btnConfirm);

                body.appendChild(tipoRow);
                body.appendChild(horasRow);
                body.appendChild(descRow);
                card.appendChild(h);
                card.appendChild(body);
                card.appendChild(footer);
                overlay.appendChild(card);
                document.body.appendChild(overlay);

                setTimeout(() => {
                    try {
                        inputHoras.focus();
                    } catch (e) {}
                }, 40);

                function close() {
                    document.removeEventListener('keydown', onKey);
                    overlay.remove();
                }

                function onKey(e) {
                    if (e.key === 'Escape') close();
                    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                        btnConfirm.click();
                    }
                }
                document.addEventListener('keydown', onKey);

                btnCancel.addEventListener('click', close);
                btnConfirm.addEventListener('click', () => {
                    const nuevasHoras = Math.max(0, Math.floor(Number(inputHoras.value) || 0));
                    const nuevaDesc = (textarea && textarea.value) ? textarea.value.trim() : '';
                    close();
                    if (typeof overlay.onConfirm === 'function') overlay.onConfirm({
                        horas: nuevasHoras,
                        descripcion: nuevaDesc
                    });
                });

                overlay.addEventListener('click', (ev) => {
                    if (ev.target === overlay) close();
                });

                return overlay;
            }

            // default horas por tipo
            const HORAS_POR_TIPO = {
                'corte-cabello': 2,
                'uniforme': 4,
                'credencial': 1,
                'personalizado-grupal': 0,
                'personalizado-individual': 0
            };

            btnEnviar.addEventListener('click', () => {
                if (!reporteSeleccionado) {
                    alert('Selecciona un tipo de reporte.');
                    return;
                }

                // determinar label y horas iniciales
                let tipoLabel = reporteSeleccionado;
                if (reporteSeleccionado.startsWith('personalizado')) {
                    tipoLabel = reporteSeleccionado.indexOf('grupal') !== -1 ? 'personalizado (grupal)' : 'personalizado (individual)';
                }
                const horasInit = HORAS_POR_TIPO[reporteSeleccionado] || 0;

                const modal = abrirModalConfirm({
                    titulo: 'Confirmar reporte',
                    tipoLabel,
                    horas: horasInit,
                    descripcion: ''
                });

                modal.onConfirm = async ({
                    horas,
                    descripcion
                }) => {
                    // preparar payload
                    const matriculaSpan = document.querySelector('.matricula');
                    const matricula = matriculaSpan ? matriculaSpan.textContent.replace(/\D/g, '') : '';
                    // alumno_id disponible si lo incrustaste en data-atributo (no lo hicimos), así que enviamos matricula
                    const payload = {
                        matricula: matricula,
                        tipo: tipoLabel,
                        horas: horas,
                        descripcion: descripcion
                    };

                    // llamar al endpoint (mismo archivo) via fetch
                    try {
                        const res = await fetch(location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });
                        const j = await res.json();
                        if (res.ok && j.ok) {
                            // redirigir a alumnos-vista
                            window.location.href = (j.redirect || './alumnos-vista.php');
                            return;
                        } else {
                            // si error servidor -> fallback: guardar en localStorage y redirigir
                            console.warn('No se guardó en servidor:', j);
                            // guardar localmente para demo (estructura obj keyed by id)
                            const id = 'r' + Date.now().toString(36) + Math.floor(Math.random() * 1000).toString(36);
                            const allRaw = localStorage.getItem('edu_reportes') || '{}';
                            let all;
                            try {
                                all = JSON.parse(allRaw || '{}');
                            } catch (e) {
                                all = {};
                            }
                            all[id] = {
                                id,
                                matricula: matricula,
                                tipo: tipoLabel,
                                descripcion: descripcion,
                                horas: horas,
                                aplicadoPor: sessionStorage.getItem('edu_user') || 'demo',
                                ultimaMod: sessionStorage.getItem('edu_user') || 'demo',
                                createdAt: new Date().toISOString(),
                                updatedAt: new Date().toISOString()
                            };
                            localStorage.setItem('edu_reportes', JSON.stringify(all));
                            alert('Reporte guardado localmente (modo demo).');
                            window.location.href = './alumnos-vista.php';
                            return;
                        }
                    } catch (err) {
                        console.error(err);
                        // fallback local
                        const id = 'r' + Date.now().toString(36) + Math.floor(Math.random() * 1000).toString(36);
                        const allRaw = localStorage.getItem('edu_reportes') || '{}';
                        let all;
                        try {
                            all = JSON.parse(allRaw || '{}');
                        } catch (e) {
                            all = {};
                        }
                        all[id] = {
                            id,
                            matricula: matricula,
                            tipo: tipoLabel,
                            descripcion: descripcion,
                            horas: horas,
                            aplicadoPor: sessionStorage.getItem('edu_user') || 'demo',
                            ultimaMod: sessionStorage.getItem('edu_user') || 'demo',
                            createdAt: new Date().toISOString(),
                            updatedAt: new Date().toISOString()
                        };
                        localStorage.setItem('edu_reportes', JSON.stringify(all));
                        alert('Sin conexión al servidor. Reporte guardado localmente (demo).');
                        window.location.href = './alumnos-vista.php';
                    }
                };
            });

            // exponer seleccion actual (debug)
            window._reporteSeleccionado = () => reporteSeleccionado;
        })();
    </script>
</body>

</html>