<?php
session_start();

// 1. Limpiar todas las variables de sesión
$_SESSION = array();

// 2. Destruir la cookie de sesión en el navegador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruir la sesión en el servidor
session_destroy();

// 4. Redirigir al login con un parámetro de mensaje
header("Location: login.php?status=success");
exit;