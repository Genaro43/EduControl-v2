<?php
require_once '../includes/conexion.php';
include '../includes/config.php';

session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['edu_rol']) || $_SESSION['edu_rol'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// =======================
// FUNCIONES
// =======================

function obtenerTotalAlumnos($conexion) {
    $r = $conexion->query("SELECT COUNT(*) as total FROM alumnos");
    return ($r) ? $r->fetch_assoc()['total'] : 0;
}

function obtenerTotalReportesActivos($conexion) {
    $r = $conexion->query("SELECT COUNT(*) as total FROM reportes WHERE activo = 1");
    return ($r) ? $r->fetch_assoc()['total'] : 0;
}

function obtenerTotalHorasActivas($conexion) {
    $r = $conexion->query("SELECT COALESCE(SUM(horas),0) as total FROM reportes WHERE activo = 1");
    return ($r) ? $r->fetch_assoc()['total'] : 0;
}

function obtenerTotalUsuariosActivos($conexion) {
    $r = $conexion->query("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1");
    return ($r) ? $r->fetch_assoc()['total'] : 0;
}

function obtenerUsuarios($conexion) {
    return $conexion->query("SELECT id, nombre, rol FROM usuarios ORDER BY id DESC");
}

// =======================
// ELIMINAR USUARIO
// =======================

if (isset($_POST['eliminar_usuario'])) {

    $id = intval($_POST['usuario_id']);

    $stmt = $conexion->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: admin.php");
    exit();
}

// =======================
// AGREGAR USUARIO
// =======================

if (isset($_POST['agregar_usuario'])) {

    $nombre = trim($_POST['nombre']);
    $password = trim($_POST['password']);
    $confirmar = trim($_POST['confirmar_password']);
    $rol = $_POST['rol'];

    if (!empty($nombre) && !empty($password) && !empty($rol)) {

        if ($password !== $confirmar) {
            echo "<script>alert('Las contraseñas no coinciden');</script>";
        } else {

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conexion->prepare(
                "INSERT INTO usuarios (nombre, password, rol, activo) VALUES (?, ?, ?, 1)"
            );

            $stmt->bind_param("sss", $nombre, $hash, $rol);
            $stmt->execute();

            header("Location: admin.php");
            exit();
        }
    }
}

// =======================
// DATOS
// =======================

$totalAlumnos = obtenerTotalAlumnos($conexion);
$totalReportes = obtenerTotalReportesActivos($conexion);
$totalHoras = obtenerTotalHorasActivas($conexion);
$totalUsuarios = obtenerTotalUsuariosActivos($conexion);
$usuarios = obtenerUsuarios($conexion);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin</title>
<link rel="icon" href="../src/img/cecyteh.ico" type="image/x-icon">
<link rel="stylesheet" href="../build/css/app.css?v=<?php echo CSS_VERSION; ?>">

<style>
.password-group {
    position: relative;
}

.password-group input {
    padding-right: 35px;
}

.toggle-pass {
    position: absolute;
    right: 10px;
    top: 8px;
    cursor: pointer;
    font-size: 16px;
}
</style>

</head>

<body>
<div class="admin-app">

<header class="admin-header">
<div class="logo"></div>
<div class="header-info">
<h1>Administrador</h1>
<span>Panel de Control</span>
</div>
</header>

<section class="resumen-cards">
<div class="card"><h3>Alumnos</h3><p><?= $totalAlumnos ?></p></div>
<div class="card"><h3>Reportes</h3><p><?= $totalReportes ?></p></div>
<div class="card"><h3>Horas</h3><p><?= $totalHoras ?></p></div>
<div class="card"><h3>Usuarios</h3><p><?= $totalUsuarios ?></p></div>
</section>

<!-- ================= GESTIÓN DE ALUMNOS ================= -->

<section class="panel">
<h2>Gestión de Alumnos</h2>

<div class="panel-placeholder alumnos-panel">

<form action="../includes/procesar-exel.php"
      method="POST"
      enctype="multipart/form-data"
      class="form-excel">

    <div class="excel-input-group">
        <label for="archivoExcel">Seleccionar archivo Excel (.xlsx)</label>
        <input type="file"
               name="archivo"
               id="archivoExcel"
               accept=".xlsx"
               required>
    </div>

    <div class="excel-actions">
        <button type="submit" class="btn-subir-excel">
            Subir
        </button>
    </div>

</form>

</div>
</section>

<!-- ================= GESTIÓN DE USUARIOS ================= -->

<section class="panel">
<h2>Gestión de Usuarios</h2>

<div class="panel-placeholder usuarios-panel">
<div class="usuarios-lista">

<?php if ($usuarios && $usuarios->num_rows > 0): ?>
<?php while ($u = $usuarios->fetch_assoc()): ?>
<div class="usuario-item">
<div class="usuario-info">
<strong><?= htmlspecialchars($u['nombre']) ?></strong>
<span class="rol"><?= htmlspecialchars($u['rol']) ?></span>
</div>

<button type="button"
class="btn-eliminar"
onclick="abrirModalEliminar(<?= $u['id'] ?>)">
Eliminar
</button>
</div>
<?php endwhile; ?>
<?php else: ?>
<p>No hay usuarios registrados.</p>
<?php endif; ?>

</div>
</div>
</section>

<nav class="admin-nav">
<a href="logout.php" class="active">salir</a>
<button>Alumnos</button>
<button onclick="abrirModal()">Usuario Nuevo</button>
<a href="mapa_admin.php" class="active">Mapa</a>
<a href="mapa_usuario.php" class="active">Mapa User</a>
<a href="gestor_horarios.php" class="active">Horarios</a>
</nav>

</div>

<!-- ================= MODAL AGREGAR ================= -->

<div id="modalUsuario" class="modal-overlay">
<div class="modal-box">
<span class="cerrar-modal" onclick="cerrarModal()">&times;</span>
<h3>Agregar Usuario</h3>

<form method="POST" class="form-usuario" onsubmit="return validarPasswords()">

<input type="text" name="nombre" placeholder="Usuario" required>

<div class="password-group">
<input type="password" id="password" name="password" placeholder="Contraseña" required>
<span class="toggle-pass" onclick="togglePass('password')">👁</span>
</div>

<div class="password-group">
<input type="password" id="confirmar_password" name="confirmar_password" placeholder="Confirmar contraseña" required>
<span class="toggle-pass" onclick="togglePass('confirmar_password')">👁</span>
</div>

<select name="rol" required>
<option value="prefecto">Prefecto</option>
<option value="orientacion">Orientador</option>
<option value="admin">Admin</option>
</select>

<button type="submit" name="agregar_usuario" class="btn-agregar">
Agregar Usuario
</button>
</form>
</div>
</div>

<!-- ================= MODAL ELIMINAR ================= -->

<div id="modalEliminar" class="modal-overlay">
<div class="modal-box">
<h3>¿Eliminar usuario?</h3>
<p>Esta acción no se puede deshacer.</p>

<form method="POST">
<input type="hidden" name="usuario_id" id="usuarioEliminarId">
<button type="submit" name="eliminar_usuario" class="btn-eliminar">
Sí, eliminar
</button>
<button type="button" onclick="cerrarModalEliminar()" class="btn-cancelar">
Cancelar
</button>
</form>
</div>
</div>

<script>
function abrirModal() {
document.getElementById("modalUsuario").style.display = "flex";
}

function cerrarModal() {
document.getElementById("modalUsuario").style.display = "none";
}

function abrirModalEliminar(id) {
document.getElementById("usuarioEliminarId").value = id;
document.getElementById("modalEliminar").style.display = "flex";
}

function cerrarModalEliminar() {
document.getElementById("modalEliminar").style.display = "none";
}

function togglePass(id) {
let input = document.getElementById(id);
input.type = input.type === "password" ? "text" : "password";
}

function validarPasswords() {
let p1 = document.getElementById("password").value;
let p2 = document.getElementById("confirmar_password").value;

if (p1 !== p2) {
alert("Las contraseñas no coinciden");
return false;
}
return true;
}

window.onclick = function(e) {
const modal1 = document.getElementById("modalUsuario");
const modal2 = document.getElementById("modalEliminar");

if (e.target === modal1) modal1.style.display = "none";
if (e.target === modal2) modal2.style.display = "none";
}
</script>

</body>
</html>