<?php
try {
    class Conectar
    {
        private $servidor = "localhost";
        private $usuario = "genaro";
        private $password = "123456";
        private $db = "eduControl";
        private $port = "3306";

        public function conexion()
        {
            $conexion = mysqli_connect(
                $this->servidor,
                $this->usuario,
                $this->password,
                $this->db,
                $this->port
            );

            if (!$conexion) {
                throw new Exception("Error de conexión: " . mysqli_connect_error());
            }

            return $conexion;
        }
    }

    $obj = new Conectar();
    $conexion = $obj->conexion();
} catch (Exception $e) {
    error_log("Error al conectar con la base de datos: " . $e->getMessage());
    echo "error";
}
