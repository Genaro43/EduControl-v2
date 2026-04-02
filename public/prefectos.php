<?php
include '../includes/config.php';
    session_start();

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['edu_rol']) || $_SESSION['edu_rol'] !== 'prefecto') {
        header('Location: ../index.php');
        exit;
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <link rel="icon" href="../src/img/cecyteh.ico" type="image/x-icon">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Prefectos</title>
    <link rel="stylesheet" href="../build/css/app.css?v=<?php echo CSS_VERSION; ?>" />
</head>

<body class="app">
    <header class="app__header" role="banner">
        <div class="logo" aria-hidden="true"></div>
    </header>

    <main class="app__main prefectos-page" role="main">
        <!-- <button class="btn btn--primary" id="btn-alumnos" type="button" aria-label="Ir a alumnos">
            Alumnos
        </button> -->

        <a href="alumnos-vista.php" class="btn btn--primary">Alumnos</a>


        <!-- Tarjeta para el mapa / imagen de la escuela -->
        <section class="card card--map tarjeta-mapa " aria-label="Mapa de la escuela">
            <img src="../src/img/2 sin título2_20251019140944.jpg" alt="">
        </section>

        <!-- Botón salir fijo abajo a la izquierda -->
        <!-- <button class="btn btn--exit" id="btn-salir" type="button" aria-label="Salir">salir</button> -->
        <a href="logout.php" class="btn btn--exit boton-salir">salir</a>

    </main>
</body>

</html>