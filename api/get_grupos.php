<?php
require_once '../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "SELECT id, semestre, grupo FROM grupos ORDER BY semestre ASC, grupo ASC";
    $result = $conexion->query($sql);

    $grupos = [];
    while ($row = $result->fetch_assoc()) {
        $grupos[] = [
            'id' => $row['id'],
            'semestre' => $row['semestre'],
            'grupo' => $row['grupo']
        ];
    }
    echo json_encode(['ok' => true, 'grupos' => $grupos]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>