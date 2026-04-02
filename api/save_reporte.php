<?php
header('Content-Type: application/json; charset=utf-8');

$possible = [ __DIR__ . '/../includes/conexion.php', __DIR__ . '/../conexion.php', __DIR__ . '/conexion.php' ];
foreach($possible as $p) if(file_exists($p)){ require_once $p; break; }
if(!isset($conexion) || !$conexion) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No DB']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if(!$input) $input = $_POST;

$reportero = isset($input['reportero']) ? trim($input['reportero']) : '';
$aula_id = isset($input['aula_id']) ? intval($input['aula_id']) : 0;
$grupo_id = isset($input['grupo_id']) && $input['grupo_id'] !== '' ? intval($input['grupo_id']) : null;
$maestro_id = isset($input['maestro_id']) && $input['maestro_id'] !== '' ? intval($input['maestro_id']) : null;
$descripcion = isset($input['descripcion']) ? trim($input['descripcion']) : '';
$foto_b64 = isset($input['foto_base64']) ? $input['foto_base64'] : null;

if(!$reportero || !$aula_id){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Falta reportero o aula']); exit; }

// manejar foto: si hay base64 -> guardar archivo
$foto_path = null;
if($foto_b64){
    $dir = __DIR__ . '/../uploads/reportes/';
    if(!is_dir($dir)) mkdir($dir, 0755, true);
    // generar nombre
    $fn = 'r_' . time() . '_' . rand(1000,9999) . '.jpg';
    $full = $dir . $fn;
    $bytes = base64_decode($foto_b64);
    if($bytes === false){
        // no válida, ignorar
    } else {
        file_put_contents($full, $bytes);
        // ruta relativa para usar desde web
        $foto_path = 'uploads/reportes/' . $fn;
    }
}

// insertar en tabla reportes (ajusta nombres si tu tabla es distinta)
$sql = "INSERT INTO reportes (id_aula, reportero, id_grupo, id_maestro, descripcion, foto_path, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
$stmt = mysqli_prepare($conexion, $sql);
if(!$stmt){ echo json_encode(['ok'=>false,'error'=>mysqli_error($conexion)]); exit; }
mysqli_stmt_bind_param($stmt, 'isisss', $aula_id, $reportero, $grupo_id, $maestro_id, $descripcion, $foto_path);
$ok = mysqli_stmt_execute($stmt);
if(!$ok){ echo json_encode(['ok'=>false,'error'=>mysqli_stmt_error($stmt)]); exit; }

$newId = mysqli_insert_id($conexion);
echo json_encode(['ok'=>true,'id'=>$newId,'mensaje'=>'Reporte guardado']);