<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conexion.php';
require_once 'SimpleXLSX.php';

use Shuchkin\SimpleXLSX;

if (!isset($_FILES['archivo'])) {
    die("No se recibió ningún archivo.");
}

$rutaTemporal = $_FILES['archivo']['tmp_name'];

if ($xlsx = SimpleXLSX::parse($rutaTemporal)) {

    $filas = $xlsx->rows();
    $gruposDetectados = [];

    foreach ($filas as $index => $fila) {

        if ($index == 0) continue;

        $folio = trim($fila[4] ?? '');

        if (strlen($folio) < 2) continue;

        $grupo = strtoupper(substr($folio, -1));
        $grado = (int) substr($folio, -2, 1);

        $clave = $grado . '-' . $grupo;

        if (!isset($gruposDetectados[$clave])) {
            $gruposDetectados[$clave] = [
                'grado' => $grado,
                'grupo' => $grupo
            ];
        }
    }

    $insertados = 0;

    foreach ($gruposDetectados as $g) {

        // Verificar si ya existe
        $stmtCheck = $conexion->prepare("
            SELECT id FROM grupos 
            WHERE grado = ? AND grupo = ?
        ");
        $stmtCheck->bind_param("is", $g['grado'], $g['grupo']);
        $stmtCheck->execute();
        $resultado = $stmtCheck->get_result();

        if ($resultado->num_rows == 0) {

            $stmtInsert = $conexion->prepare("
                INSERT INTO grupos (grado, grupo)
                VALUES (?, ?)
            ");
            $stmtInsert->bind_param("is", $g['grado'], $g['grupo']);
            $stmtInsert->execute();
            $stmtInsert->close();

            $insertados++;
        }

        $stmtCheck->close();
    }

    echo "Grupos creados: $insertados";

} else {
    echo SimpleXLSX::parseError();
}