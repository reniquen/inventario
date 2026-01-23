<?php

$inactividad = 1800;

if (isset($_SESSION['ultima_actividad']) && (time() - $_SESSION['ultima_actividad'] > $inactividad)) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['ultima_actividad'] = time();
?>