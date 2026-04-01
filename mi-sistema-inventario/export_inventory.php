<?php
session_start();
require_once 'check_session.php';

// 1. Conexión a la base de datos
$host = 'localhost'; $db = 'stockmaster_db'; $user = 'root'; $pass = 'mysql';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// 2. Lógica de filtro por área (Igual que en tu index actualizado)
$areaFilter = "";
$params = [];

if ($_SESSION['user_rol'] !== 'admin') {
    // Si es Encargado o Consultor, solo ve el stock de SU área en la tabla stock_area
    $areaFilter = " WHERE sa.id_area = :user_area_id ";
    $params[':user_area_id'] = $_SESSION['user_area_id'];
}

// 3. Consulta actualizada a la tabla stock_area
$query = "SELECT p.sku, p.nombre, a.nombre_area, sa.cantidad as stock, p.precio_referencial 
          FROM producto p 
          INNER JOIN stock_area sa ON p.id_producto = sa.id_producto 
          LEFT JOIN area a ON sa.id_area = a.id_area 
          $areaFilter";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Configuración de cabeceras para descarga de CSV (Compatible con Excel)
$nombreArea = $_SESSION['user_area'] ?? 'General';
$filename = "Inventario_" . str_replace(' ', '_', $nombreArea) . "_" . date('d-m-Y') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// 5. Generar el archivo
$output = fopen('php://output', 'w');

// BOM para que Excel reconozca tildes y eñes (UTF-8)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeceras de las columnas
fputcsv($output, ['SKU', 'Producto', 'Área', 'Stock Actual', 'Precio Ref.']);

// Insertar los datos
foreach ($productos as $fila) {
    fputcsv($output, $fila);
}

fclose($output);
exit;