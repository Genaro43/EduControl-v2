<?php
require_once '../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "SELECT id, nombre, inicio, fin FROM horarios ORDER BY inicio ASC";
    $result = $conexion->query($sql);

    $horarios = [];
    while ($row = $result->fetch_assoc()) {
        // Formateamos la hora para quitarle los segundos (ej: de 07:00:00 a 07:00)
        $inicio = date('H:i', strtotime($row['inicio']));
        $fin = date('H:i', strtotime($row['fin']));
        
        $horarios[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'label' => $row['nombre'] . ' (' . $inicio . ' - ' . $fin . ')'
        ];
    }
    echo json_encode(['ok' => true, 'horarios' => $horarios]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>