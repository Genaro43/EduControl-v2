<?php
require_once '../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "SELECT id, nombre FROM docentes ORDER BY nombre ASC";
    $result = $conexion->query($sql);

    $maestros = [];
    while ($row = $result->fetch_assoc()) {
        $maestros[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre']
        ];
    }
    echo json_encode(['ok' => true, 'maestros' => $maestros]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>