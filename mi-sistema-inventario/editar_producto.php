<?php
require __DIR__ . '/conexion.php';

// ==============================
// VALIDAR ID
// ==============================
$id = $_GET['id'] ?? null;

if (!$id || !is_numeric($id)) {
    die("ID inválido");
}

// ==============================
// ACTUALIZAR PRODUCTO (POST)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'] ?? '';
    $stock  = $_POST['stock'] ?? 0;
    $sku    = $_POST['sku'] ?? null;

    if ($nombre === '' || !is_numeric($stock)) {
        die("Datos inválidos");
    }

    $stmt = $pdo->prepare("
        UPDATE productos 
        SET nombre = ?, stock = ?, sku = ?
        WHERE id = ?
    ");
    $stmt->execute([$nombre, $stock, $sku, $id]);

    header("Location: index.php?tab=inventory");
    exit;
}

// ==============================
// OBTENER PRODUCTO
// ==============================
$stmt = $pdo->prepare("SELECT * FROM productos WHERE id = ?");
$stmt->execute([$id]);
$producto = $stmt->fetch();

if (!$producto) {
    die("Producto no encontrado");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Producto</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen">

<form method="POST" class="bg-slate-900 p-8 rounded-xl w-96 space-y-4 border border-slate-800">

    <h1 class="text-xl font-bold text-center">Editar Producto</h1>

    <div>
        <label class="text-sm text-slate-400">Nombre</label>
        <input type="text" name="nombre"
               value="<?= htmlspecialchars($producto['nombre']) ?>"
               class="w-full p-2 rounded bg-slate-800 border border-slate-700"
               required>
    </div>

    <div>
        <label class="text-sm text-slate-400">SKU</label>
        <input type="text" name="sku"
               value="<?= htmlspecialchars($producto['sku']) ?>"
               class="w-full p-2 rounded bg-slate-800 border border-slate-700">
    </div>

    <div>
        <label class="text-sm text-slate-400">Stock</label>
        <input type="number" name="stock"
               value="<?= $producto['stock'] ?>"
               class="w-full p-2 rounded bg-slate-800 border border-slate-700"
               required>
    </div>

    <div class="flex gap-2">
        <button type="submit"
                class="flex-1 bg-indigo-600 hover:bg-indigo-500 p-2 rounded font-bold">
            Guardar
        </button>

        <a href="index.php?tab=inventory"
           class="flex-1 text-center bg-slate-700 hover:bg-slate-600 p-2 rounded">
            Cancelar
        </a>
    </div>

</form>

</body>
</html>
