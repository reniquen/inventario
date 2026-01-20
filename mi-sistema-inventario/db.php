<?php
// db.php
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root'; // Tu usuario de MySQL
$pass = 'mysql';     // Tu contraseña de MySQL

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>