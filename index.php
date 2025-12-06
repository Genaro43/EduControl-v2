<?php
// Nombre: Rosales Carrasco Genaro
// Fecha de creacion: 13/09/2025
// Fecha de ultima actualizacion: 11/07/2025
// Descripcion: Loginn de usuarios y alumnos para el sistema EduControl
session_start();
include 'includes/conexion.php'; //incluir conexion a la base de datos

$error = '';
$valor_usuario = '';

// escape simple
function esc($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// Buscar usuario (usuarios.nombre) o alumno (alumnos.matricula)
function buscarUsuario(mysqli $conexion, string $valor)
{
    $valor = trim($valor);
    if ($valor === '') return null;

    // 1) Buscar en usuarios por 'nombre'
    $sql = "SELECT id, nombre, password, rol, activo FROM usuarios WHERE nombre = ? LIMIT 1";
    if ($stmt = $conexion->prepare($sql)) {
        $stmt->bind_param('s', $valor);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $row['tipo_origen'] = 'usuario';
            return $row;
        }
    }

    // 2) Buscar en alumnos por 'matricula'
    $sql2 = "SELECT id, matricula, nombre FROM alumnos WHERE matricula = ? LIMIT 1";
    if ($stmt2 = $conexion->prepare($sql2)) {
        $stmt2->bind_param('s', $valor);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $al = $res2 ? $res2->fetch_assoc() : null;
        $stmt2->close();
        if ($al) {
            return [
                'id' => $al['id'],
                'nombre' => $al['nombre'],
                'matricula' => $al['matricula'],
                'tipo_origen' => 'alumno'
            ];
        }
    }

    return null;
}

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor_usuario = trim($_POST['usuario'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    if ($valor_usuario === '' || $pass === '') {
        $error = 'Usuario/matrícula y contraseña son obligatorios.';
    } else {
        $found = buscarUsuario($conexion, $valor_usuario);

        if (!$found) {
            $error = 'Usuario o contraseña incorrectos.';
        } else {
            if ($found['tipo_origen'] === 'usuario') {
                // usuario del sistema (prefecto, orientacion, admin, etc.)
                $hash = $found['password'] ?? '';
                $activo = isset($found['activo']) ? $found['activo'] : 1;

                if ($activo === "0" || $activo === 0 || $activo === false) {
                    $error = 'Cuenta inactiva.';
                } else {
                    $ok = false;
                    if (is_string($hash) && $hash !== '') {
                        if (password_verify($pass, $hash)) $ok = true;
                        // fallback dev: comparar texto plano (solo si no hay hash)
                        if (!$ok && $pass === $hash) $ok = true;
                    } else {
                        if ($pass === $hash) $ok = true;
                    }

                    if ($ok) {
                        // iniciar sesión
                        $_SESSION['edu_user_id'] = $found['id'];
                        $_SESSION['edu_user'] = $found['nombre'];
                        $_SESSION['edu_rol'] = $found['rol'] ?? 'prefecto';

                        $rol = strtolower(trim($_SESSION['edu_rol']));

                        // redirigir según rol
                        if ($rol === 'orientacion' || $rol === 'orientación') {
                            header('Location: orientacion.html');
                            exit;
                        } elseif ($rol === 'alumno') {
                            // intentar encontrar matricula vinculada (si existe columna alumno_id en usuarios)
                            $mat = '';
                            // try: if users has alumno_id column, retrieve it
                            $check = $conexion->query("SHOW COLUMNS FROM `usuarios` LIKE 'alumno_id'");
                            if ($check && $check->num_rows) {
                                $q = $conexion->prepare("SELECT alumno_id FROM usuarios WHERE id = ? LIMIT 1");
                                if ($q) {
                                    $q->bind_param('i', $found['id']);
                                    $q->execute();
                                    $r = $q->get_result()->fetch_assoc();
                                    $q->close();
                                    if (!empty($r['alumno_id'])) {
                                        $aid = (int)$r['alumno_id'];
                                        $q2 = $conexion->prepare("SELECT matricula FROM alumnos WHERE id = ? LIMIT 1");
                                        if ($q2) {
                                            $q2->bind_param('i', $aid);
                                            $q2->execute();
                                            $r2 = $q2->get_result()->fetch_assoc();
                                            $q2->close();
                                            if (!empty($r2['matricula'])) $mat = $r2['matricula'];
                                        }
                                    }
                                }
                            }
                            // fallback: tratar de extraer dígitos del nombre
                            if (!$mat && preg_match('/\d{4,}/', $found['nombre'], $m)) $mat = $m[0];

                            $dest = 'public/alumno.php' . ($mat ? ('?matricula=' . urlencode($mat)) : '');
                            header('Location: ' . $dest);
                            exit;
                        } else {
                            header('Location: public/prefectos.html');
                            exit;
                        }
                    } else {
                        $error = 'Usuario o contraseña incorrectos.';
                    }
                }
            } else {
                // es alumno buscado por matricula
                $matricula = $found['matricula'] ?? '';
                // demo: contraseña igual a matrícula
                if ($pass === $matricula) {
                    $_SESSION['edu_user_id'] = $found['id'];
                    $_SESSION['edu_user'] = $found['nombre'];
                    $_SESSION['edu_rol'] = 'alumno';
                    header('Location: public/alumno.php?matricula=' . urlencode($matricula));
                    exit;
                } else {
                    $error = 'Usuario o contraseña incorrectos.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>EduControl — Login</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="build/css/app.css">
</head>

<body>
    <div class="contenedor-login">
        <h1>EduControl</h1>

        <?php if ($error): ?>
            <div style="max-width:380px;margin:10px auto;color:#b00000;font-weight:700;"><?= esc($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="campo">
                <label for="usuario">Usuario / Matrícula</label>
                <input id="usuario" name="usuario" type="text" value="<?= esc($valor_usuario) ?>" placeholder="usuario o matrícula" required autofocus>
            </div>

            <div class="campo">
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" placeholder="contraseña" required>
            </div>

            <input type="submit" class="btn" value="Iniciar Sesión">
        </form>
    </div>
</body>

</html>