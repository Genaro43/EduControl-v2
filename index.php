<?php
// Configuración segura de cookies de sesión
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();
include 'includes/conexion.php'; //incluir conexion a la base de datos
include 'includes/config.php'; //incluir conexion a la base de datos

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

                $hash = $found['password'] ?? '';
                $activo = isset($found['activo']) ? $found['activo'] : 1;

                if ($activo === "0" || $activo === 0 || $activo === false) {
                    $error = 'Cuenta inactiva.';
                } else {

                    $ok = false;

                    if (is_string($hash) && $hash !== '') {
                        if (password_verify($pass, $hash)) $ok = true;
                        if (!$ok && $pass === $hash) $ok = true; // compatibilidad antigua
                    } else {
                        if ($pass === $hash) $ok = true;
                    }

                    if ($ok) {

                        // 🔐 Regenerar sesión
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = intval($found['id']);
                        $_SESSION['edu_user'] = $found['nombre'];
                        $_SESSION['edu_rol'] = strtolower(trim($found['rol'] ?? 'prefecto'));

                        $rol = $_SESSION['edu_rol'];

                        // 🔴 ADMIN
                        if ($rol === 'admin') {
                            header('Location: public/admin.php');
                            exit;
                        }

                        // 🟣 ORIENTACION
                        elseif ($rol === 'orientacion' || $rol === 'orientación') {
                            header('Location: public/orientacion.php');
                            exit;
                        }

                        // 🟢 ALUMNO
                        elseif ($rol === 'alumno') {

                            $mat = '';
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

                            if (!$mat && preg_match('/\d{4,}/', $found['nombre'], $m)) $mat = $m[0];

                            $dest = 'public/alumno.php' . ($mat ? ('?matricula=' . urlencode($mat)) : '');
                            header('Location: ' . $dest);
                            exit;
                        }

                        // 🟡 PREFECTO u otros
                        else {
                            header('Location: public/prefectos.php');
                            exit;
                        }

                    } else {
                        $error = 'Usuario o contraseña incorrectos.';
                    }
                }

            } else {
                $matricula = $found['matricula'] ?? '';

                if ($pass === $matricula) {

                    session_regenerate_id(true);

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
    <link rel="icon" href="src/img/cecyteh.ico" type="image/x-icon">
    <meta charset="utf-8">
    <title>EduControl</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="build/css/app.css?v=<?php echo CSS_VERSION; ?>">
</head>

<body>
    <div class="contenedor-login">
        <h1>EduControl</h1>

        <?php if ($error): ?>
            <div style="max-width:380px;margin:10px auto;color:#b00000;font-weight:700;">
                <?= esc($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="campo">
                <label for="usuario" class="txWithe">Usuario / Matrícula</label>
                <input id="usuario" name="usuario" type="text"
                    value="<?= esc($valor_usuario) ?>"
                    placeholder="usuario o matrícula"
                    required autofocus>
            </div>

            <div class="campo password-wrapper">
                <label for="password" class="txWithe">Contraseña</label>
                <input id="password" name="password" type="password"
                    placeholder="contraseña" required>
                <span id="togglePassword" class="toggle-password">👁</span>
            </div>

            <input type="submit" class="btn" value="Iniciar Sesión">
        </form>
    </div>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁' : '🙈';
        });
    </script>
</body>
</html>