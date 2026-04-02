<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conexion.php';
require_once 'SimpleXLSX.php';

use Shuchkin\SimpleXLSX;

if (!isset($_FILES['archivo'])) {
    die("No se recibió ningún archivo.");
}

$extension = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);

if (strtolower($extension) != 'xlsx') {
    die("Solo se permiten archivos .xlsx");
}

$rutaTemporal = $_FILES['archivo']['tmp_name'];

if ($xlsx = SimpleXLSX::parse($rutaTemporal)) {

    $filas = $xlsx->rows();

    $insertados = 0;
    $omitidos = 0;
    $grupos_creados = 0;

    foreach ($filas as $index => $fila) {

        if ($index == 0) continue;

        $matricula   = trim($fila[0] ?? '');
        $ap_paterno  = trim($fila[1] ?? '');
        $ap_materno  = trim($fila[2] ?? '');
        $nombre      = trim($fila[3] ?? '');
        $folio       = trim($fila[4] ?? '');

        if (empty($matricula) || strlen($folio) < 2) {
            $omitidos++;
            continue;
        }

        $nombre_completo = trim($ap_paterno . ' ' . $ap_materno . ' ' . $nombre);

        $grupo_letra = strtoupper(substr($folio, -1));
        $grado = (int) substr($folio, -2, 1);

        // 🔹 Buscar grupo
        $stmtGrupo = $conexion->prepare("
            SELECT id FROM grupos
            WHERE grado = ? AND grupo = ?
            LIMIT 1
        ");

        $stmtGrupo->bind_param("is", $grado, $grupo_letra);
        $stmtGrupo->execute();
        $resultadoGrupo = $stmtGrupo->get_result();

        if ($resultadoGrupo->num_rows == 0) {

            // Crear grupo si no existe
            $stmtNuevoGrupo = $conexion->prepare("
                INSERT INTO grupos (grado, grupo)
                VALUES (?, ?)
            ");

            $stmtNuevoGrupo->bind_param("is", $grado, $grupo_letra);
            $stmtNuevoGrupo->execute();
            $grupo_id = $stmtNuevoGrupo->insert_id;
            $stmtNuevoGrupo->close();

            $grupos_creados++;

        } else {

            $grupoData = $resultadoGrupo->fetch_assoc();
            $grupo_id = $grupoData['id'];
        }

        $stmtGrupo->close();

        // 🔹 Insertar alumno
        $stmtAlumno = $conexion->prepare("
            INSERT INTO alumnos
            (matricula, nombre, grupo_id, foto, es_jefe)
            VALUES (?, ?, ?, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                grupo_id = VALUES(grupo_id)
        ");

        $stmtAlumno->bind_param("ssi", $matricula, $nombre_completo, $grupo_id);

        if ($stmtAlumno->execute()) {
            $insertados++;
        } else {
            $omitidos++;
        }

        $stmtAlumno->close();
    }

    echo "<h3>Importación completada</h3>";
    echo "Grupos creados automáticamente: $grupos_creados <br>";
    echo "Alumnos insertados/actualizados: $insertados <br>";
    echo "Registros omitidos: $omitidos <br>";
    echo "Total filas Excel: " . count($filas);

} else {
    echo "Error al leer el archivo: " . SimpleXLSX::parseError();
}