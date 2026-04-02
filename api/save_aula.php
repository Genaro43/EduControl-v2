<?php
// api/save_aula.php
// Guardado adaptativo: mapea grupo_text/maestro_text/horario_text -> columnas 'grupo','maestro','horario' si existen.

header('Content-Type: application/json; charset=utf-8');
$logfile = __DIR__ . '/save_aula_debug.log';

// localizar conexion.php
$paths = [ __DIR__ . '/../includes/conexion.php', __DIR__ . '/../conexion.php', __DIR__ . '/conexion.php' ];
$found = null;
foreach ($paths as $p) if (file_exists($p)) { $found = $p; break; }
if (!$found) { file_put_contents($logfile, date('c')." - ERROR: conexion.php no encontrado\n", FILE_APPEND); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'conexion.php no encontrado']); exit; }
require_once $found;
if (!isset($conexion) || !$conexion) { file_put_contents($logfile, date('c')." - ERROR: conexion null\n", FILE_APPEND); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Sin conexión']); exit; }

// leer input JSON o form
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

// log request
file_put_contents($logfile, date('c') . " - REQUEST: " . var_export($input, true) . "\n", FILE_APPEND);

// inspeccionar columnas de la tabla aulas
$colsRes = mysqli_query($conexion, "SHOW COLUMNS FROM `aulas`");
if (!$colsRes) { $err = mysqli_error($conexion); file_put_contents($logfile, date('c')." - SHOW COLUMNS ERROR: $err\n", FILE_APPEND); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No puedo leer columnas de aulas']); exit; }
$existingCols = [];
while ($r = mysqli_fetch_assoc($colsRes)) $existingCols[] = $r['Field'];
file_put_contents($logfile, date('c') . " - COLUMNS: " . implode(',', $existingCols) . "\n", FILE_APPEND);

// Helper
function refValues($arr){ $refs = []; foreach ($arr as $k => $v) $refs[$k] = &$arr[$k]; return $refs; }
function valOrNull($v){ if ($v === '' || $v === null) return null; return $v; }

// Mapeo preferente: si tabla tiene 'grupo' guardamos grupo_text o grupo_id, idem maestro y horario.
$toSave = []; // column -> value

// nombre obligatorio
if (isset($input['nombre'])) $toSave['nombre'] = $input['nombre'];

// x/y porcentuales
if (array_key_exists('xPct', $input) && in_array('x_pct', $existingCols)) $toSave['x_pct'] = is_numeric($input['xPct']) ? floatval($input['xPct']) : null;
if (array_key_exists('yPct', $input) && in_array('y_pct', $existingCols)) $toSave['y_pct'] = is_numeric($input['yPct']) ? floatval($input['yPct']) : null;

// estado
if (isset($input['estado']) && in_array('estado', $existingCols)) $toSave['estado'] = $input['estado'];

// Grupo: preferir grupo_text, si no existe usar grupo_id (pero si la columna 'grupo' existe)
if (in_array('grupo', $existingCols)) {
    if (isset($input['grupo_text']) && $input['grupo_text'] !== '') $toSave['grupo'] = $input['grupo_text'];
    elseif (isset($input['grupo_id']) && $input['grupo_id'] !== '') $toSave['grupo'] = (string)$input['grupo_id'];
    elseif (isset($input['grupo']) && $input['grupo'] !== '') $toSave['grupo'] = $input['grupo'];
}

// Maestro
if (in_array('maestro', $existingCols)) {
    if (isset($input['maestro_text']) && $input['maestro_text'] !== '') $toSave['maestro'] = $input['maestro_text'];
    elseif (isset($input['maestro_id']) && $input['maestro_id'] !== '') $toSave['maestro'] = (string)$input['maestro_id'];
    elseif (isset($input['maestro']) && $input['maestro'] !== '') $toSave['maestro'] = $input['maestro'];
}

// Horario (columna 'horario' existe según tus logs)
if (in_array('horario', $existingCols)) {
    if (isset($input['horario_text']) && $input['horario_text'] !== '') $toSave['horario'] = $input['horario_text'];
    elseif (isset($input['horario_id']) && $input['horario_id'] !== '') $toSave['horario'] = (string)$input['horario_id'];
    elseif (isset($input['horario']) && $input['horario'] !== '') $toSave['horario'] = $input['horario'];
}

// foto (opcional)
if (isset($input['foto']) && in_array('foto', $existingCols)) $toSave['foto'] = $input['foto'];

// ahora decidir si UPDATE o INSERT
$idProvided = (isset($input['id']) && $input['id'] !== '') ? $input['id'] : null;
// detectar pk existente (id_aula o id)
$pk = in_array('id', $existingCols) ? 'id' : (in_array('id_aula', $existingCols) ? 'id_aula' : null);

// Si no hay columnas para guardar (edge-case)
if (count($toSave) === 0) {
    file_put_contents($logfile, date('c') . " - WARNING: payload no contiene campos mapeables para guardar.\n", FILE_APPEND);
    echo json_encode(['ok'=>false,'error'=>'No hay campos para guardar en la tabla.']); exit;
}

try {
    if ($idProvided && $pk) {
        // armar UPDATE
        $sets = []; $types = ''; $params = [];
        foreach ($toSave as $col => $val) {
            $sets[] = "`$col` = ?";
            // guess types: x_pct,y_pct => d ; otherwise s
            if (in_array($col, ['x_pct','y_pct'])) $types .= 'd';
            else $types .= 's';
            $params[] = $val;
        }
        if (in_array('updated_at', $existingCols)) $sets[] = "updated_at = NOW()";
        $sql = "UPDATE `aulas` SET " . implode(', ', $sets) . " WHERE `$pk` = ?";
        $stmt = mysqli_prepare($conexion, $sql);
        if (!$stmt) { $err = mysqli_error($conexion); file_put_contents($logfile, date('c')." - PREPARE ERROR: $err\nSQL: $sql\n", FILE_APPEND); throw new Exception($err); }
        // bind params + id
        $types .= 's'; // pk we pass as string to be safe (id_aula may be int, but conversion ok)
        $params[] = (string)$idProvided;
        $bind = array_merge([$types], $params);
        array_unshift($bind, $stmt);
        if (!call_user_func_array('mysqli_stmt_bind_param', refValues($bind))) {
            $err = mysqli_stmt_error($stmt);
            file_put_contents($logfile, date('c')." - BIND ERROR: $err\n", FILE_APPEND);
            throw new Exception($err);
        }
        $exec = mysqli_stmt_execute($stmt);
        if ($exec === false) { $err = mysqli_stmt_error($stmt); file_put_contents($logfile, date('c')." - EXEC ERROR: $err\n", FILE_APPEND); throw new Exception($err); }
        $affected = mysqli_stmt_affected_rows($stmt);
        $resp = ['ok'=>true,'mensaje'=>'Aula actualizada','id'=>$idProvided,'affected'=>$affected];
        file_put_contents($logfile, date('c') . " - SUCCESS UPDATE: " . json_encode($resp) . "\n", FILE_APPEND);
        echo json_encode($resp); exit;
    } else {
        // INSERT
        $cols = []; $place = []; $types = ''; $params = [];
        foreach ($toSave as $col => $val) {
            $cols[] = "`$col`"; $place[] = '?';
            if (in_array($col, ['x_pct','y_pct'])) $types .= 'd'; else $types .= 's';
            $params[] = $val;
        }
        if (in_array('created_at', $existingCols)) { $cols[] = '`created_at`'; $place[] = 'NOW()'; }
        $sql = "INSERT INTO `aulas` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $place) . ")";
        $stmt = mysqli_prepare($conexion, $sql);
        if (!$stmt) { $err = mysqli_error($conexion); file_put_contents($logfile, date('c')." - PREPARE INSERT ERROR: $err\nSQL: $sql\n", FILE_APPEND); throw new Exception($err); }
        if (strlen($types) > 0) {
            $bind = array_merge([$types], $params); array_unshift($bind, $stmt);
            if (!call_user_func_array('mysqli_stmt_bind_param', refValues($bind))) {
                $err = mysqli_stmt_error($stmt);
                file_put_contents($logfile, date('c')." - BIND INSERT ERROR: $err\n", FILE_APPEND);
                throw new Exception($err);
            }
        }
        $exec = mysqli_stmt_execute($stmt);
        if ($exec === false) { $err = mysqli_stmt_error($stmt); file_put_contents($logfile, date('c')." - EXEC INSERT ERROR: $err\n", FILE_APPEND); throw new Exception($err); }
        $newId = mysqli_insert_id($conexion);
        $resp = ['ok'=>true,'mensaje'=>'Aula creada','id'=>$newId];
        file_put_contents($logfile, date('c') . " - SUCCESS INSERT: " . json_encode($resp) . "\n", FILE_APPEND);
        echo json_encode($resp); exit;
    }
} catch (Exception $e) {
    $msg = 'Exception: ' . $e->getMessage();
    file_put_contents($logfile, date('c') . " - EXCEPTION: $msg\n", FILE_APPEND);
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}