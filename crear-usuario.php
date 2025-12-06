<?php
include 'includes/conexion.php';
$username = 'paulina';      // Nombre de usuario
$password = 'prefecto03';     // Contraseña en texto plano
$rol      = 'prefecto';  // Rol asignado al usuario

// Genera un hash seguro de la contraseña
$hash = password_hash($password, PASSWORD_DEFAULT);

// Prepara la sentencia INSERT para la tabla 'usuarios'
$stmt = $conexion->prepare(" 
    INSERT INTO usuarios (nombre, password, rol)
    VALUES (?, ?, ?)
");
// Vincula parámetros: usuario, hash y rol (todos strings)
$stmt->bind_param('sss', $username, $hash, $rol);

// Ejecuta la inserción y verifica éxito
if ($stmt->execute()) {
    echo "Usuario '$username' creado con rol '$rol'.\n"; // Mensaje de éxito
} else {
    echo "Error al crear usuario: " . $stmt->error . "\n"; // Muestra error si falla
}

// Cierra la sentencia y la conexión
$stmt->close();
$conexion->close();

//** Claves **/
// $username = 'Valente';      // Nombre de usuario
// $password = 'prefecto01';     // Contraseña en texto plano
// $rol      = 'prefecto';  // Rol asignado al usuario

// $username = 'paco';      // Nombre de usuario
// $password = 'prefecto02';     // Contraseña en texto plano
// $rol      = 'prefecto';  // Rol asignado al usuario

// $username = 'paulina';      // Nombre de usuario
// $password = 'prefecto03';     // Contraseña en texto plano
// $rol      = 'prefecto';  // Rol asignado al usuario