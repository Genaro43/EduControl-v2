<?php
// api/delete_aula.php
header('Content-Type: application/json; charset=utf-8');

$possible = [
    __DIR__ . '/../includes/conexion.php',
    __DIR__ . '/../conexion.php',
    __DIR__ . '/conexion.php',
];

$found = false;
foreach ($possible as $p) {
    if (file_exists($p)) { require_once $p; $found = true; break; }
}
if (!$found) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se encontró conexion.php']); exit; }
if (!isset($conexion) || !$conexion) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'$conexion no definido']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;
$id = isset($input['id']) ? intval($input['id']) : null;
if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Falta id']); exit; }

$stmt = mysqli_prepare($conexion, "DELETE FROM aulas WHERE id_aula = ?");
if (!$stmt) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_error($conexion)]); exit; }
mysqli_stmt_bind_param($stmt, 'i', $id);
$ok = mysqli_stmt_execute($stmt);
if (!$ok) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_stmt_error($stmt)]); exit; }
echo json_encode(['ok'=>true,'mensaje'=>'Aula eliminada']);