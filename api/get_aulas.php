<?php
// api/get_aulas.php

// 1. Ocultar errores de PHP en pantalla para no romper la respuesta JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 2. Forzar el encabezado correcto de JSON
header('Content-Type: application/json; charset=utf-8');

// 3. Incluir la conexión
require_once '../includes/conexion.php'; 

try {
    // Validación de seguridad por si tu variable se llama diferente
    if (!isset($conexion) && isset($mysqli)) { $conexion = $mysqli; }
    if (!isset($conexion) && isset($conn)) { $conexion = $conn; }
    
    if (!isset($conexion)) {
        throw new Exception("No se detectó la conexión a la base de datos.");
    }

    // 4. Consulta SQL (solo campos físicos, como acordamos en la normalización)
    $sql = "SELECT id_aula, nombre, x_pct, y_pct, estado FROM aulas";
    $result = $conexion->query($sql);

    if (!$result) {
        throw new Exception("Error SQL: " . $conexion->error);
    }

    $aulas = [];
    
    // 5. Formatear los datos exactamente como los pide el JavaScript
    while ($row = $result->fetch_assoc()) {
        $aulas[] = [
            'id' => (string)$row['id_aula'], // El JS mapea el ID como string
            'nombre' => $row['nombre'] ?? 'Aula sin nombre',
            'xPct' => $row['x_pct'] !== null ? (float)$row['x_pct'] : null,
            'yPct' => $row['y_pct'] !== null ? (float)$row['y_pct'] : null,
            'estado' => $row['estado'] ? $row['estado'] : 'libre',
            
            // Rellenos obligatorios para evitar errores de "undefined" en tu JS
            'grupo' => '',
            'maestro' => '',
            'grupo_text' => '',
            'maestro_text' => '',
            'horario' => '',
            'horario_id' => null,
            'horario_text' => ''
        ];
    }

    // 6. Enviar éxito
    echo json_encode([
        'ok' => true, 
        'aulas' => $aulas
    ]);

} catch (Exception $e) {
    // Enviar el error en un JSON limpio para que la consola del navegador lo lea bien
    echo json_encode([
        'ok' => false, 
        'error' => $e->getMessage()
    ]);
}
?>