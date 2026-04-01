<?php
// Tiempo máximo de inactividad en segundos (30 min * 60 seg)
$inactividad = 1800; 

if (isset($_SESSION['ultimo_acceso'])) {
    $vida_sesion = time() - $_SESSION['ultimo_acceso'];
    
    if ($vida_sesion > $inactividad) {
        // Si pasó el tiempo, destruimos la sesión y mandamos al login
        session_unset();
        session_destroy();
        header("Location: login.php?status=expired");
        exit;
    }
}

// Si está activo, renovamos el tiempo para que tenga otros 30 min
$_SESSION['ultimo_acceso'] = time();