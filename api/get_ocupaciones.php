<?php
// api/get_ocupaciones.php
require_once '../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

// Obtenemos día y hora del JS
$dia = isset($_GET['dia']) ? (int)$_GET['dia'] : 0;
$hora_id = isset($_GET['hora_id']) ? (int)$_GET['hora_id'] : 0;

if ($dia === 0 || $hora_id === 0) {
    echo json_encode(['ok' => true, 'ocupaciones' => [], 'debug' => 'Faltan parámetros']);
    exit;
}

try {
    // IMPORTANTE: Hacemos JOIN para traer los nombres reales
    $sql = "SELECT 
                oa.id_aula, 
                CONCAT(g.semestre, ' ', g.grupo) AS nombre_grupo, 
                d.nombre AS nombre_maestro
            FROM ocupacion_aulas oa
            INNER JOIN grupos g ON oa.grupo = g.id
            INNER JOIN docentes d ON oa.maestro = d.id
            WHERE oa.id_dia = ? AND oa.id_horario = ?";
            
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("ii", $dia, $hora_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $ocupaciones = [];
    while ($row = $result->fetch_assoc()) {
        $ocupaciones[] = [
            'id_aula' => $row['id_aula'],
            'grupo'   => $row['nombre_grupo'],
            'maestro' => $row['nombre_maestro']
        ];
    }
    
    echo json_encode([
        'ok' => true, 
        'ocupaciones' => $ocupaciones,
        'periodo_consultado' => $hora_id,
        'dia_consultado' => $dia
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}