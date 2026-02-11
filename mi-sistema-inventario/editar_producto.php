<?php
require __DIR__ . '/conexion.php'; 

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) { die("ID inválido"); }

// ==============================
// PROCESAR ACTUALIZACIÓN (POST)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre  = trim($_POST['nombre']);
    $sku     = trim($_POST['sku']);
    $stock   = intval($_POST['stock']);
    $precio  = floatval($_POST['precio']);

    try {
        // Quitamos id_area del UPDATE para forzar el uso de Movimientos
    $sql = "UPDATE producto 
            SET nombre = :nom, 
                precio_referencial = :pre 
            WHERE id_producto = :id";
        
        $stmt = $pdo->prepare($sql);
        $resultado = $stmt->execute([
            ':nom'  => $nombre,
            ':sku'  => $sku,
            ':stk'  => $stock,
            ':pre'  => $precio,
            ':id'   => $id
        ]);

        header("Location: index.php?tab=inventory&success=edit");
        exit;
    } catch (PDOException $e) {
        die("Error en la base de datos: " . $e->getMessage());
    }
}

// OBTENER DATOS ACTUALES
$stmt = $pdo->prepare("SELECT p.*, a.nombre_area FROM producto p LEFT JOIN area a ON p.id_area = a.id_area WHERE p.id_producto = ?");
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) { die("Producto no encontrado"); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Producto - StockMaster</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen p-4">

<div class="bg-slate-900 p-8 rounded-[2.5rem] border border-slate-800 w-full max-w-md shadow-2xl">
    <h2 class="text-3xl font-bold mb-8 text-white">Editar Producto</h2>
    
    <form method="POST" class="space-y-6">
        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">Nombre</label>
            <input type="text" name="nombre" value="<?= htmlspecialchars($p['nombre']) ?>" 
                   class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">SKU (Código)</label>
                <input type="text" name="sku" value="<?= htmlspecialchars($p['sku'] ?? '') ?>" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">Precio ($)</label>
                <input type="number" step="0.01" name="precio" value="<?= $p['precio_referencial'] ?>" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">Stock</label>
                <input type="number" name="stock" value="<?= $p['stock'] ?>" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">Área (Solo Lectura)</label>
                <div class="w-full bg-slate-950/50 border border-slate-800 text-slate-500 rounded-2xl px-4 py-3 text-sm italic">
                    <?= htmlspecialchars($p['nombre_area'] ?? 'Sin área') ?>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4 pt-4">
            <a href="index.php?tab=inventory" class="flex-1 text-center text-slate-500 font-bold hover:text-white transition-colors">Cancelar</a>
            <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 py-4 rounded-2xl font-bold shadow-lg shadow-indigo-600/20 transition-all">Guardar</button>
        </div>
    </form>
</div>
</body>
</html>