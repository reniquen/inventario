<?php
// ==============================
// CONEXIÓN DIRECTA
// ==============================
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root';
$pass = 'mysql';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// ==============================
// ELIMINAR PRODUCTO
// ==============================
$id = $_GET['id'] ?? null;

if (!$id || !is_numeric($id)) {
    die("ID inválido");
}

$stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
$stmt->execute([$id]);

// ==============================
// REDIRECCIÓN
// ==============================
header("Location: index.php?tab=inventory");
exit;
