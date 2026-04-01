<?php
require __DIR__ . '/conexion.php'; 
session_start();

// Definición de Roles (Asegúrate que coincidan con tus constantes)
define('ROLES_AUTH', ['admin' => 'ADMIN', 'encargado' => 'ENCARGADO']);

// 1. Seguridad: Solo Admin o Encargado entran
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], [ROLES_AUTH['admin'], ROLES_AUTH['encargado']])) {
    header("Location: index.php?tab=inventory&error=unauthorized");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) { die("ID inválido"); }

// ==============================
// PROCESAR ACTUALIZACIÓN
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $precio = floatval($_POST['precio']);
    $stock  = intval($_POST['stock']);
    $id_area = intval($_POST['id_area']);

    try {
        $pdo->beginTransaction();
        
        // Actualizar tabla producto
        $stmt = $pdo->prepare("UPDATE producto SET nombre = ?, precio_referencial = ? WHERE id_producto = ?");
        $stmt->execute([$nombre, $precio, $id]);

        // Actualizar tabla stock_area
        $stmtStock = $pdo->prepare("UPDATE stock_area SET cantidad = ? WHERE id_producto = ? AND id_area = ?");
        $stmtStock->execute([$stock, $id, $id_area]);

        $pdo->commit();
        header("Location: index.php?tab=inventory&success=edit");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}

// OBTENER DATOS (Cruzado con la tabla de stock por área)
$stmt = $pdo->prepare("SELECT p.*, sa.cantidad as stock_actual, sa.id_area, a.nombre_area 
                       FROM producto p 
                       JOIN stock_area sa ON p.id_producto = sa.id_producto 
                       JOIN area a ON sa.id_area = a.id_area 
                       WHERE p.id_producto = ?");
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) die("Producto no disponible");
?>

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Editar - StockMaster Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen p-6">

<div class="bg-slate-900 border border-slate-800 p-8 rounded-[2.5rem] w-full max-w-lg shadow-2xl">
    <div class="flex items-center gap-4 mb-8">
        <div class="bg-indigo-600/20 p-3 rounded-2xl text-indigo-400">
            <i data-lucide="edit-3"></i>
        </div>
        <div>
            <h2 class="text-2xl font-bold">Editar Existencias</h2>
            <p class="text-slate-500 text-sm">Área: <span class="text-indigo-400 font-bold"><?= $p['nombre_area'] ?></span></p>
        </div>
    </div>

    <form method="POST" class="space-y-5">
        <input type="hidden" name="id_area" value="<?= $p['id_area'] ?>">

        <div>
            <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Nombre del Insumo</label>
            <input type="text" name="nombre" value="<?= htmlspecialchars($p['nombre']) ?>" required
                   class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Precio Unitario ($)</label>
                <input type="number" step="0.01" name="precio" value="<?= $p['precio_referencial'] ?>" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Stock Actual</label>
                <input type="number" name="stock" value="<?= $p['stock_actual'] ?>" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>

        <div class="pt-4 flex gap-4">
            <a href="index.php?tab=inventory" class="flex-1 text-center py-4 text-slate-500 font-bold hover:text-white transition-colors">Cancelar</a>
            <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 py-4 rounded-2xl font-bold shadow-lg shadow-indigo-600/20 transition-all active:scale-95">
                Guardar Cambios
            </button>
        </div>
    </form>
</div>

<script>lucide.createIcons();</script>
</body>
</html>