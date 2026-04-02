<?php
// api/save_ocupacion.php
require_once '../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['ok' => false, 'error' => 'No hay datos']);
    exit;
}

$id_aula = (int)$data['id_aula'];
$id_dia = (int)$data['id_dia'];
$id_horario = (int)$data['id_horario'];
$accion = $data['accion'] ?? 'ocupar';

try {
    if ($accion === 'liberar') {
        $stmt = $conexion->prepare("DELETE FROM ocupacion_aulas WHERE id_aula = ? AND id_dia = ? AND id_horario = ?");
        $stmt->bind_param("iii", $id_aula, $id_dia, $id_horario);
    } else {
        $grupo = $data['grupo'];
        $maestro = $data['maestro'];
        $stmt = $conexion->prepare("INSERT INTO ocupacion_aulas (id_aula, id_dia, id_horario, grupo, maestro) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE grupo = VALUES(grupo), maestro = VALUES(maestro)");
        $stmt->bind_param("iiiss", $id_aula, $id_dia, $id_horario, $grupo, $maestro);
    }

    if ($stmt->execute()) {
        echo json_encode(['ok' => true]);
    } else {
        throw new Exception($stmt->error);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}