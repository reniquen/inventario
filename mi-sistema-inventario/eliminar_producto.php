<?php
session_start();
require __DIR__ . '/conexion.php'; 

// Seguridad básica de roles
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['ADMIN', 'ENCARGADO'])) {
    header("Location: index.php?tab=inventory&error=unauthorized");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) { die("ID Inválido"); }

try {
    // Si es ENCARGADO, verificamos que el producto esté en su área asignada
    if ($_SESSION['user_rol'] === 'ENCARGADO') {
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_area WHERE id_producto = ? AND id_area = ?");
        $stmtCheck->execute([$id, $_SESSION['user_area_id']]);
        if ($stmtCheck->fetchColumn() == 0) {
            header("Location: index.php?tab=inventory&error=no_permission_area");
            exit;
        }
    }

    // Eliminación (El CASCADE se encarga de stock_area y movimientos)
    $stmt = $pdo->prepare("DELETE FROM producto WHERE id_producto = ?");
    $stmt->execute([$id]);

    header("Location: index.php?tab=inventory&success=deleted");
    exit;

} catch (PDOException $e) {
    die("Error al eliminar: " . $e->getMessage());
}