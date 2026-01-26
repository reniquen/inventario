<?php
// check_session.php

// Asegurarnos de que la sesión esté iniciada (por si se incluye en otro archivo)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Verificar si el usuario está logueado
// (Aunque index.php ya lo hace, es buena práctica de seguridad repetirlo aquí)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. Configuración del tiempo de inactividad
$max_time = 1800; // Tiempo en segundos (1800 seg = 30 minutos)

// 3. Comprobar si existe una marca de tiempo de "última actividad"
if (isset($_SESSION['last_activity'])) {
    // Calcular el tiempo transcurrido desde la última interacción
    $time_elapsed = time() - $_SESSION['last_activity'];

    // Si el tiempo transcurrido supera el límite permitido
    if ($time_elapsed > $max_time) {
        // Destruir la sesión
        session_unset();
        session_destroy();

        // Redirigir al login con un mensaje de error (opcional)
        header("Location: login.php?error=timeout");
        exit;
    }
}

// 4. Actualizar la marca de tiempo a la hora actual
$_SESSION['last_activity'] = time();
?>