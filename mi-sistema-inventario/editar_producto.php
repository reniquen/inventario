<?php
require __DIR__ . '/conexion.php';

// ==============================
// VALIDAR ID (Usando id_producto según tu DB)
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
    $nombre_imagen = null;

    if ($nombre === '' || !is_numeric($stock)) {
        die("Datos inválidos");
    }

    // --- LÓGICA DE IMAGEN ---
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $ruta_destino = __DIR__ . '/assets/img/productos/';
        
        // Crear carpeta si no existe
        if (!is_dir($ruta_destino)) {
            mkdir($ruta_destino, 0777, true);
        }

        $extension = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $nombre_imagen = "prod_" . $id . "_" . time() . "." . $extension;
        
        move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_destino . $nombre_imagen);
    }

    // --- ACTUALIZAR DB ---
    if ($nombre_imagen) {
        $stmt = $pdo->prepare("UPDATE producto SET nombre = ?, stock = ?, sku = ?, imagen_path = ? WHERE id_producto = ?");
        $stmt->execute([$nombre, $stock, $sku, $nombre_imagen, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE producto SET nombre = ?, stock = ?, sku = ? WHERE id_producto = ?");
        $stmt->execute([$nombre, $stock, $sku, $id]);
    }

    header("Location: index.php?tab=inventory");
    exit;
}

// ==============================
// OBTENER PRODUCTO
// ==============================
$stmt = $pdo->prepare("SELECT * FROM producto WHERE id_producto = ?");
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
    <title>Editar Producto - StockMaster Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen p-4">

<form method="POST" enctype="multipart/form-data" class="bg-slate-900 p-8 rounded-xl w-full max-w-md space-y-4 border border-slate-800 shadow-2xl">

    <h1 class="text-xl font-bold text-center text-indigo-400">Editar Producto</h1>

    <div class="flex flex-col items-center space-y-2">
        <label class="text-sm text-slate-400">Imagen Actual</label>
        <?php if (!empty($producto['imagen_path'])): ?>
            <img src="assets/img/productos/<?= $producto['imagen_path'] ?>" class="w-24 h-24 object-cover rounded-lg border-2 border-slate-700">
        <?php else: ?>
            <div class="w-24 h-24 bg-slate-800 rounded-lg flex items-center justify-center text-xs text-slate-500">Sin imagen</div>
        <?php endif; ?>
    </div>

    <div>
        <label class="text-sm text-slate-400">Nombre</label>
        <input type="text" name="nombre"
               value="<?= htmlspecialchars($producto['nombre']) ?>"
               class="w-full p-2 rounded bg-slate-800 border border-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"
               required>
    </div>

    <div>
        <label class="text-sm text-slate-400">SKU</label>
        <input type="text" name="sku"
               value="<?= htmlspecialchars($producto['sku'] ?? '') ?>"
               class="w-full p-2 rounded bg-slate-800 border border-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none">
    </div>

    <div>
        <label class="text-sm text-slate-400">Stock Disponible</label>
        <input type="number" name="stock"
               value="<?= $producto['stock'] ?>"
               class="w-full p-2 rounded bg-slate-800 border border-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"
               required>
    </div>

    <div>
        <label class="text-sm text-slate-400">Cambiar Imagen</label>
        <input type="file" name="imagen" accept="image/*"
               class="w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500 cursor-pointer">
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit"
                class="flex-1 bg-indigo-600 hover:bg-indigo-500 p-2 rounded font-bold transition-colors">
            Guardar Cambios
        </button>

        <a href="index.php?tab=inventory"
           class="flex-1 text-center bg-slate-700 hover:bg-slate-600 p-2 rounded transition-colors">
            Cancelar
        </a>
    </div>

</form>

</body>
</html>