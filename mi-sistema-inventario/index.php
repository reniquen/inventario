<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. Validar tiempo de inactividad
require_once 'check_session.php';

header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.


// 2. Configuración de Roles y Sesión
$currentRole   = $_SESSION['user_rol']; 
$activeTab     = $_GET['tab']    ?? 'inventory';
$searchTerm    = $_GET['search'] ?? '';
$assignedArea  = $_SESSION['user_area'] ?? '';


if (!defined('ROLES')) {
    define('ROLES', [
        'ADMIN' => 'admin',
        'ENCARGADO' => 'encargado',
        'CONSULTOR' => 'consultor'
    ]);
}

$allowedTabsByRole = [
    ROLES['CONSULTOR'] => ['inventory', 'statistics'],
    ROLES['ENCARGADO'] => ['inventory', 'statistics', 'movements', 'purchases'],
    ROLES['ADMIN']     => ['inventory', 'statistics', 'roles', 'movements', 'purchases']
];

if (!in_array($activeTab, $allowedTabsByRole[$currentRole])) {
    $activeTab = 'inventory';
}


// 3. Conexión a Base de Datos
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root'; 
$pass = 'mysql'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// --- 4. ACCIONES BACKEND ---

// Eliminar Producto
if (isset($_GET['delete_id']) && $currentRole === ROLES['ADMIN']) {
    $id_eliminar = $_GET['delete_id'];

    try {
        $pdo->beginTransaction();

        // 1. Eliminar primero el stock en las áreas (Tabla hija)
        $stmtStock = $pdo->prepare("DELETE FROM stock_area WHERE id_producto = ?");
        $stmtStock->execute([$id_eliminar]);

        // 2. Opcional: Si tienes detalles de movimientos o ventas, también debes borrarlos aquí
        // $stmtMov = $pdo->prepare("DELETE FROM detalle_movimiento WHERE id_producto = ?");
        // $stmtMov->execute([$id_eliminar]);

        // 3. Finalmente, eliminar el producto (Tabla padre)
        $stmtProd = $pdo->prepare("DELETE FROM producto WHERE id_producto = ?");
        $stmtProd->execute([$id_eliminar]);

        $pdo->commit();
        header("Location: index.php?tab=$activeTab&success=deleted");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error al eliminar: " . $e->getMessage());
    }
}

// Agregar o Editar Producto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $currentRole !== ROLES['CONSULTOR']) {
    $nombre  = $_POST['nombre'];
    $sku     = $_POST['sku'];
    $stock   = $_POST['stock'];
    $precio  = $_POST['precio'];
    $id_area = $_POST['id_area'] ?? $_SESSION['user_area_id'];
    
    // Lógica de imagen
    $imagenPath = $_POST['current_image_path'] ?? null;
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $fileName = time() . '_' . basename($_FILES['imagen']['name']); 
        $targetFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $targetFile)) {
            $imagenPath = $targetFile;
        }
    }

if ($_POST['action'] === 'add' && $currentRole === ROLES['ADMIN']) {
    try {
        $pdo->beginTransaction();

        // 1. Insertamos el producto sin SKU todavía
        $stmt = $pdo->prepare("INSERT INTO producto (nombre, precio_referencial, imagen_path, estado, id_area) VALUES (?, 0, ?, ?, 'activo', ?)");
        $stmt->execute([$nombre, $precio, $imagenPath, $id_area]);
        
        $idNuevo = $pdo->lastInsertId();

        // 2. Generamos el Código Automático (SKU) basado en el ID
        $nuevoCodigo = "SKU-" . str_pad($idNuevo, 4, "0", STR_PAD_LEFT);
        
        $stmtUpdate = $pdo->prepare("UPDATE producto SET sku = ? WHERE id_producto = ?");
        $stmtUpdate->execute([$nuevoCodigo, $idNuevo]);

        // 3. ¡NUEVO! Insertar stock inicial en la tabla stock_area
        $stmtStock = $pdo->prepare("INSERT INTO stock_area (id_producto, id_area, cantidad) VALUES (?, ?, ?)");
        $stmtStock->execute([$idNuevo, $id_area, $stock]);

        // 4. Registrar el movimiento inicial de stock (Carga de inventario)
        $stmtMov = $pdo->prepare("INSERT INTO movimiento (id_usuario, tipo, motivo, id_area) VALUES (?, 'ENTRADA', 'Carga inicial automatizada', ?)");
        $stmtMov->execute([$_SESSION['user_id'], $id_area]);
        $idMov = $pdo->lastInsertId();

        $stmtDet = $pdo->prepare("INSERT INTO detalle_movimiento (id_movimiento, id_producto, cantidad) VALUES (?, ?, ?)");
        $stmtDet->execute([$idMov, $idNuevo, $stock]);

        $pdo->commit();
        header("Location: index.php?tab=inventory&msg=success");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}
}
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_role']) &&
    $currentRole === ROLES['ADMIN']
) {
    $stmt = $pdo->prepare("
        UPDATE USUARIO 
        SET id_rol = ?
        WHERE id_usuario = ?
    ");
    $stmt->execute([
        $_POST['id_rol'],
        $_POST['id_usuario']
    ]);
}


// --- 5. CONSULTA DE DATOS CON FILTRO DE ÁREA ---
$areaFilter = "";
$params = [':search' => "%$searchTerm%"];

if ($currentRole !== ROLES['ADMIN']) {
    // Filtro para encargados: solo ven su área específica en stock_area
    $areaFilter = " AND sa.id_area = :user_area_id ";
    $params[':user_area_id'] = $_SESSION['user_area_id'];
}

$query = "SELECT 
            p.id_producto,
            p.nombre, 
            p.sku,
            p.stock,           
            p.id_area,
            p.imagen_path,
            p.precio, 
            p.id_orden_compra,
            a.nombre_area,
            prov.nombre AS nombre_proveedor 
          FROM producto p
          LEFT JOIN area a ON p.id_area = a.id_area
          LEFT JOIN proveedor prov ON p.id_proveedor = prov.id_proveedor
          WHERE (p.nombre LIKE :search OR p.sku LIKE :search) 
          AND p.stock > 0 -- Solo mostramos lotes que tengan existencia
          $areaFilter
          ORDER BY p.nombre ASC, a.nombre_area ASC";

$stmt = $pdo->prepare($query);
// Asegúrate de que $params tenga el valor de :search (ej: '%')
$stmt->execute($params);
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sqlCritico = "SELECT COUNT(*) FROM stock_area WHERE cantidad <= 5";
if ($currentRole !== ROLES['ADMIN']) {
    $sqlCritico .= " AND id_area = " . intval($_SESSION['user_area_id']);
}
$totalCritico = $pdo->query($sqlCritico)->fetchColumn();

$areasList = $pdo->query("SELECT * FROM AREA")->fetchAll();

$users = $pdo->query("
    SELECT u.id_usuario, u.nombre, u.email, u.id_rol, r.nombre_rol
    FROM USUARIO u
    JOIN ROL r ON u.id_rol = r.id_rol
")->fetchAll();

// En la sección de consultas de tu PHP, reemplaza la consulta de movimientos:
// Consulta mejorada para trazabilidad Origen -> Destino
$movimientos = $pdo->query("
    SELECT 
        m.fecha,
        m.tipo,
        p.nombre AS producto,
        d.cantidad,
        u.nombre AS usuario,
        a_orig.nombre_area AS origen,   -- Área que entrega
        a_dest.nombre_area AS destino,  -- Área que recibe
        IFNULL(m.motivo, 'S/M') AS motivo -- Evita el error de 'Undefined key'
    FROM movimiento m
    JOIN detalle_movimiento d ON m.id_movimiento = d.id_movimiento
    JOIN producto p ON d.id_producto = p.id_producto
    JOIN usuario u ON m.id_usuario = u.id_usuario
    LEFT JOIN area a_orig ON m.id_area_origen = a_orig.id_area
    LEFT JOIN area a_dest ON m.id_area = a_dest.id_area
    ORDER BY m.fecha DESC
")->fetchAll();


$proveedores = $pdo->query("SELECT * FROM proveedor")->fetchAll();
$productosOC = $pdo->query("SELECT id_producto, nombre, sku FROM producto")->fetchAll();

function lucideIcon($name, $class = "w-5 h-5") {
    return "<i data-lucide='{$name}' class='{$class}'></i>";
}
?>

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StockMaster Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-950 text-slate-100 h-full overflow-hidden">

<div class="flex h-screen">
    <aside class="w-72 bg-slate-900 border-r border-slate-800 flex flex-col">
        <div class="p-8 flex items-center gap-3">
            <div class="bg-indigo-600 p-2 rounded-lg text-white"><?= lucideIcon('package', 'w-6 h-6') ?></div>
            <span class="text-xl font-bold tracking-tight">StockMaster</span>
        </div>
        
        <nav class="flex-1 px-4 space-y-2">
            <a href="?tab=inventory" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'inventory' ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/20' : 'hover:bg-slate-800 text-slate-400' ?>">
                <?= lucideIcon('boxes') ?> Inventario
            </a>
            <a href="?tab=statistics" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'statistics' ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/20' : 'hover:bg-slate-800 text-slate-400' ?>">
                <?= lucideIcon('chart-column-decreasing') ?> Estadisticas
            </a>
            <?php if ($currentRole === ROLES['ADMIN']): ?>
                <a href="?tab=roles" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'roles' ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/20' : 'hover:bg-slate-800 text-slate-400' ?>">
                    <?= lucideIcon('shield') ?> Gestión de Roles
                </a>
            <?php endif; ?>
                <a href="?tab=movements" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'movements' ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/20' : 'hover:bg-slate-800 text-slate-400' ?>">
                <?= lucideIcon('history') ?> Movimientos
            <?php if ($currentRole === ROLES['ADMIN'] || $currentRole === ROLES['ENCARGADO']): ?>
                <a href="?tab=purchases" 
                class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'purchases' ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/20' : 'hover:bg-slate-800 text-slate-400' ?>">
                    <?= lucideIcon('shopping-cart') ?> Órdenes de Compra
                </a>
            <?php endif; ?>
        </nav>

        <div class="p-6 border-t border-slate-800 text-xs text-slate-500">
            <p>Usuario: <span class="text-white font-medium"><?= htmlspecialchars($_SESSION['user_nombre']) ?></span></p>
            <p>Rol: <span class="text-indigo-400 font-bold uppercase"><?= $currentRole ?></span></p>
            <a href="logout.php" class="mt-4 flex items-center gap-2 text-red-400 hover:text-red-300 transition-colors">
                <?= lucideIcon('log-out', 'w-4 h-4') ?> Cerrar Sesión
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col overflow-hidden">
        <header class="h-20 bg-slate-900/50 border-b border-slate-800 px-8 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-400 uppercase tracking-widest">Panel de Control</h2>
            <form class="relative w-64">
                <input type="text" name="search" placeholder="Buscar..." value="<?= htmlspecialchars($searchTerm) ?>" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-xl pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                <div class="absolute left-3 top-2.5 text-slate-500"><?= lucideIcon('search', 'w-4 h-4') ?></div>
            </form>
        </header>

<section class="p-8 overflow-y-auto">
    <div class="max-w-6xl mx-auto">

        <?php if ($activeTab === 'inventory'): ?>

            <!-- ================= INVENTARIO ================= -->
            <div class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white">Inventario Actual</h1>
                    <p class="text-slate-500 italic">Gestión de existencias por área</p>
                    <a href="export_inventory.php" class="btn btn-success">
                        <i class="bi bi-file-earmark-excel"></i> Descargar Inventario
                    </a>
                </div>
                

                <?php if ($currentRole === ROLES['ADMIN']): ?>
                    <button onclick="openModal('add')" class="bg-indigo-600 hover:bg-indigo-500 px-6 py-3 rounded-2xl font-bold flex items-center gap-2 shadow-lg shadow-indigo-600/20">
                        <?= lucideIcon('plus') ?> Nuevo Producto
                    </button>
                <?php endif; ?>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-[2rem] overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase tracking-widest">
                        <tr>
                            <th class="px-8 py-5">Imagen</th>
                            <th class="px-6 py-5">Producto / SKU</th>
                            <th class="px-6 py-5 text-center">Área</th>
                            <th class="px-6 py-5 text-center">Precio</th>
                            <th class="px-6 py-5 text-center">Stock</th>
                            <?php if (in_array($currentRole, [ROLES['ADMIN'], ROLES['ENCARGADO']])): ?>
                                <th class="px-6 py-5 text-center">Proveedor</th>
                            <?php endif; ?>
                            <th class="px-8 py-5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($inventory as $item): ?>
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="px-8 py-5">
                                <?php if($item['imagen_path']): ?>
                                    <img src="<?= $item['imagen_path'] ?>" class="w-12 h-12 object-cover rounded-xl border border-slate-700">
                                <?php else: ?>
                                    <div class="w-12 h-12 bg-slate-800 rounded-xl flex items-center justify-center text-slate-600"><?= lucideIcon('image-off') ?></div>
                                <?php endif; ?>
                            </td>

                            <td class="px-6 py-5">
                                <div class="font-bold text-slate-200"><?= htmlspecialchars($item['nombre']) ?></div>
                                <div class="text-[11px] text-slate-500 font-mono"><?= $item['sku'] ?></div>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <span class="bg-slate-800 px-3 py-1 rounded-lg text-[10px] border border-slate-700">
                                    <?= htmlspecialchars($item['nombre_area'] ?? 'General') ?>
                                </span>
                            </td>

                            <td class="px-6 py-5 text-center font-mono text-indigo-400">
                                $<?= number_format($item['precio'] ?? 0, 2) ?>
                            </td>

                            <td class="px-6 py-5 text-center font-bold <?= ($item['stock'] ?? 0) < 10 ? 'text-red-500' : 'text-emerald-400' ?>">
                                <?= $item['stock'] ?? 0 ?>
                            </td>

                            <td class="px-6 py-5 text-center text-slate-400 text-sm">
                                <?= htmlspecialchars($p['nombre_proveedor'] ?? '---') ?>
                            </td>

                            <td class="px-8 py-5 text-right">
                                <div class="flex justify-end gap-2">
                                    <?php 
                                    // Lógica de Permisos: 
                                    // 1. Los Admin pueden editar cualquier cosa.
                                    // 2. Los Encargados SOLO pueden editar si el producto es de su área.
                                    $puedeEditar = ($currentRole === ROLES['ADMIN']) || 
                                                ($currentRole === ROLES['ENCARGADO'] && $item['id_area'] == $_SESSION['user_area_id']);
                                    ?>

                                    <?php if ($puedeEditar): ?>
                                        <a href="editar_producto.php?id=<?= $item['id_producto'] ?>" 
                                        class="p-2 hover:bg-indigo-500/20 text-slate-400 hover:text-indigo-400 rounded-xl transition-all"
                                        title="Editar Producto">
                                            <?= lucideIcon('edit-3', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($currentRole === ROLES['ADMIN']): ?>
                                        <a href="?delete_id=<?= $item['id_producto'] ?>" 
                                        onclick="return confirm('¿Eliminar producto? Esta acción es permanente.')" 
                                        class="p-2 hover:bg-red-500/20 text-slate-400 hover:text-red-400 rounded-xl transition-all"
                                        title="Eliminar Producto">
                                            <?= lucideIcon('trash-2', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($activeTab === 'statistics'): ?>

            <!-- ================= ESTADÍSTICAS ================= -->
            <h1 class="text-3xl font-bold text-white mb-2">Estadísticas</h1>
            <p class="text-slate-500 mb-8">Resumen general del inventario</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                    <p class="text-slate-400 text-sm">vaiedad de Productos</p>
                    <p class="text-3xl font-bold text-indigo-400"><?= count($inventory) ?></p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                    <p class="text-slate-400 text-sm">Stock Bajo (&lt; 10)</p>
                    <p class="text-3xl font-bold text-red-400">
                        <?= count(array_filter($inventory, fn($i) => $i['stock'] < 10)) ?>
                    </p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                    <p class="text-slate-400 text-sm">Valor Total</p>
                    <p class="text-3xl font-bold text-emerald-400">
                        $
                        <?= number_format(array_sum(array_map(fn($i) => $i['precio'] * $i['stock'], $inventory)), 0) ?>
                    </p>
                </div>
            </div>
        <?php elseif ($activeTab === 'roles' && $currentRole === ROLES['ADMIN']): ?>

            <!-- ================= GESTIÓN DE ROLES ================= -->
            <div class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white">Gestión de Usuarios</h1>
                    <p class="text-slate-500 italic">Administración de roles y permisos</p>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-[2rem] overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase tracking-widest">
                        <tr>
                            <th class="px-8 py-5">Usuario</th>
                            <th class="px-6 py-5">Correo</th>
                            <th class="px-6 py-5 text-center">Rol</th>
                            <th class="px-8 py-5 text-right">Acción</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="px-8 py-5 font-bold text-slate-200">
                                <?= htmlspecialchars($u['nombre']) ?>
                            </td>

                            <td class="px-6 py-5 text-slate-400 text-sm">
                                <?= htmlspecialchars($u['email']) ?>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <form method="POST" class="flex justify-center gap-3">
                                    <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">

                                    <select name="id_rol" class="bg-slate-800 border border-slate-700 rounded-xl px-4 py-2 text-sm outline-none">
                                        <option value="1" <?= ($u['id_rol'] ?? 0) == 1 ? 'selected' : '' ?>>Admin</option>
                                        <option value="2" <?= ($u['id_rol'] ?? 0) == 2 ? 'selected' : '' ?>>Encargado</option>
                                        <option value="3" <?= ($u['id_rol'] ?? 0) == 3 ? 'selected' : '' ?>>Consultor</option>
                                    </select>
                            </td>

                            <td class="px-8 py-5 text-right">
                                    <button type="submit" name="update_role"
                                        class="bg-indigo-600 hover:bg-indigo-500 px-4 py-2 rounded-xl text-sm font-bold shadow-lg shadow-indigo-600/20">
                                        Guardar
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- ================= movimientos ================= -->
        <?php elseif ($activeTab === 'movements'): ?>
            <div class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white">Historial de Movimientos</h1>
                    <p class="text-slate-500">Registro de entradas y transferencias entre áreas</p>
                </div>
                <button onclick="openMovementModal()" class="bg-emerald-600 hover:bg-emerald-500 px-6 py-3 rounded-2xl font-bold flex items-center gap-2 shadow-lg shadow-emerald-600/20">
                    <?= lucideIcon('arrow-right-left') ?> Registrar Salida / Traspaso
                </button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-[2rem] overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase tracking-widest">
                        <tr>
                            <th class="px-6 py-4">Fecha</th>
                            <th class="px-6 py-4">Tipo</th>
                            <th class="px-6 py-4">Producto</th>
                            <th class="px-6 py-4 text-center">Cant.</th>
                            <th class="px-6 py-4">Ruta del Movimiento</th> <th class="px-6 py-4">Motivo</th>
                            <th class="px-6 py-4">Responsable</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($movimientos as $m): ?>
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="px-6 py-4 text-slate-400 text-sm">
                                <?= date('d/m/Y H:i', strtotime($m['fecha'])) ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded-md text-[10px] font-bold <?= $m['tipo'] === 'ENTRADA' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                                    <?= $m['tipo'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 font-medium"><?= htmlspecialchars($m['producto']) ?></td>
                            <td class="px-6 py-4 text-center font-mono"><?= $m['cantidad'] ?></td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-slate-500 text-[10px] font-bold uppercase tracking-wider">
                                            <?= htmlspecialchars($m['origen']) ?>
                                        </span>
                                        
                                        <span class="text-indigo-500 flex items-center">
                                            <?= lucideIcon('move-right', 'w-4 h-4') ?>
                                        </span>
                                        
                                        <span class="text-slate-200 font-bold text-sm">
                                            <?= htmlspecialchars($m['destino']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                                        <span class="text-[9px] text-slate-500 uppercase font-medium">Trazabilidad Activa</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-slate-400 text-sm"><?= htmlspecialchars($m['motivo']) ?></td>
                            <td class="px-6 py-4 text-xs"><?= htmlspecialchars($m['usuario']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                    </tbody>
                </table>
                <div id="movementModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md z-[60] flex items-center justify-center p-4">
                            <div class="bg-slate-900 border border-slate-800 p-8 rounded-[2.5rem] w-full max-w-md shadow-2xl">
                                <h2 class="text-2xl font-bold mb-6 text-white text-center">Registrar Entrega</h2>
                                
                                <form method="POST" action="process_movement.php" class="space-y-4">
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Producto a Entregar</label>
                                        <select name="id_producto" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-emerald-500">
                                            <option value="" disabled selected>Seleccione un producto</option>
                                            <?php foreach ($inventory as $prod): ?>
                                                <option value="<?= $prod['id_producto'] ?>"><?= htmlspecialchars($prod['nombre']) ?> (Stock: <?= $prod['stock'] ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">¿De qué área sale?</label>
                                        <select name="id_area_origen" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-amber-500">
                                            <option value="NULL">Bodega Central (General)</option>
                                            <?php foreach ($areasList as $area): ?>
                                                <option value="<?= $area['id_area'] ?>"><?= htmlspecialchars($area['nombre_area']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">¿A qué área va?</label>
                                        <select name="id_area_destino" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-emerald-500">
                                            <?php foreach ($areasList as $area): ?>
                                                <option value="<?= $area['id_area'] ?>"><?= htmlspecialchars($area['nombre_area']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="space-y-4"> 
                                        <div>
                                            <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Cantidad</label>
                                            <input type="number" name="cantidad" min="1" required 
                                                class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-emerald-500" 
                                                placeholder="0">
                                        </div>

                                        <div>
                                            <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Motivo / Glosa de Entrega</label>
                                            <textarea name="motivo" rows="2" 
                                                    class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-emerald-500 resize-none" 
                                                    placeholder="Ej: Suministros solicitados por dirección de salud para operativo médico..."></textarea>
                                        </div>
                                    </div>
                                    <div class="flex gap-4 pt-6">
                                        <button type="button" 
                                                onclick="closeMovementModal()" 
                                                class="flex-1 text-slate-500 font-bold hover:text-white transition-colors">
                                            Cancelar
                                        </button>
                                        
                                        <button type="submit" 
                                                class="flex-1 bg-emerald-600 py-3 rounded-2xl font-bold text-white hover:bg-emerald-500 shadow-lg shadow-emerald-600/20 transition-all active:scale-95">
                                            Confirmar Entrega
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
            </div>
            <!-- ================= orden de compra ================= -->
<?php elseif ($activeTab === 'purchases'): 
            // Consulta para listar las órdenes
            $compras = $pdo->query("SELECT oc.*, p.nombre as proveedor, a.nombre_area 
                                    FROM orden_compra oc 
                                    JOIN proveedor p ON oc.id_proveedor = p.id_proveedor
                                    JOIN area a ON oc.id_area = a.id_area 
                                    ORDER BY oc.id_orden_compra DESC")->fetchAll();
            
            // Obtenemos las áreas para el selector del modal
            $areas = $pdo->query("SELECT id_area, nombre_area FROM area ORDER BY nombre_area ASC")->fetchAll();
        ?>
            <div class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white">Órdenes de Compra</h1>
                    <p class="text-slate-500 italic">Gestión de adquisiciones y recepciones</p>
                </div>
                <button onclick="openOCModal()" class="bg-indigo-600 hover:bg-indigo-500 px-6 py-3 rounded-2xl font-bold flex items-center gap-2 shadow-lg shadow-indigo-600/20 transition-all">
                    <?= lucideIcon('plus') ?> Crear Orden
                </button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-[2rem] overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase">
                        <tr>
                            <th class="px-6 py-4">N° Orden</th>
                            <th class="px-6 py-4">Proveedor</th>
                            <th class="px-6 py-4">Área</th>
                            <th class="px-6 py-4">Estado</th>
                            <th class="px-8 py-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($compras as $c): ?>
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="px-6 py-4 font-mono text-indigo-400"><?= $c['numero_oc'] ?></td>
                            <td class="px-6 py-4 text-slate-300"><?= htmlspecialchars($c['proveedor']) ?></td>
                            <td class="px-6 py-4 text-slate-400"><?= htmlspecialchars($c['nombre_area']) ?></td>
                            <td class="px-6 py-4">
                                <?php 
                                    $statusClass = $c['estado_oc'] === 'Pendiente' ? 'bg-amber-500/10 text-amber-400' : 
                                                ($c['estado_oc'] === 'Recibida' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-indigo-500/10 text-indigo-400');
                                ?>
                                <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase <?= $statusClass ?>">
                                    <?= $c['estado_oc'] ?>
                                </span>
                            </td>
                            <td class="px-8 py-4 text-right">
                                <div class="flex justify-end gap-3">
                                    <?php if ($c['estado_oc'] === 'Pendiente'): ?>
                                        <button onclick="openReceiptModal(<?= $c['id_orden_compra'] ?>)" 
                                                class="flex items-center gap-2 px-4 py-2 bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600 hover:text-white rounded-xl text-xs font-bold transition-all border border-emerald-500/20">
                                            <?= lucideIcon('package-check', 'w-4 h-4') ?> Recepcionar
                                        </button>
                                    <?php endif; ?>

                                    <a href="print_oc.php?id=<?= $c['id_orden_compra'] ?>" target="_blank" class="p-2 text-slate-400 hover:text-white hover:bg-slate-700 rounded-lg transition-all" title="Ver Orden">
                                        <?= lucideIcon('external-link', 'w-5 h-5') ?>
                                    </a>

                                    <?php if ($c['estado_oc'] === 'Pendiente'): ?>
                                        <button onclick="deleteOC(<?= $c['id_orden_compra'] ?>, '<?= $c['numero_oc'] ?>')" 
                                                class="p-2 text-red-400 hover:text-red-500 hover:bg-red-500/10 rounded-lg transition-all"
                                                title="Eliminar Orden">
                                            <?= lucideIcon('trash-2', 'w-5 h-5') ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="ocModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md z-[70] flex items-center justify-center p-4">
                <div class="bg-slate-900 border border-slate-800 p-8 rounded-[2.5rem] w-full max-w-4xl shadow-2xl max-h-[90vh] overflow-y-auto">
                    <h2 class="text-2xl font-bold mb-6 text-white flex items-center gap-2">
                        <?= lucideIcon('shopping-basket') ?> Nueva Orden de Compra
                    </h2>
                    <form action="save_oc.php" method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Proveedor</label>
                                <select name="id_proveedor" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-2xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-500 mt-1">
                                    <option value="">Seleccione un proveedor...</option>
                                    <?php foreach($proveedores as $p): ?>
                                        <option value="<?= $p['id_proveedor'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Área de Destino</label>
                                <select name="id_area_destino" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-2xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-500 mt-1">
                                    <?php foreach($areas as $area): ?>
                                        <option value="<?= $area['id_area'] ?>"><?= htmlspecialchars($area['nombre_area']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Motivo / Presupuesto</label>
                                <input type="text" name="tipo_presupuesto" required placeholder="Ej: Adquisición Oficina" class="w-full bg-slate-800 border border-slate-700 text-white rounded-2xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-500 mt-1">
                            </div>
                        </div>

                        <div class="border-t border-slate-800 pt-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-widest">Productos del Pedido</h3>
                                <button type="button" onclick="addProductRow()" class="text-xs bg-indigo-600/20 text-indigo-400 px-4 py-2 rounded-xl border border-indigo-500/30 hover:bg-indigo-600 hover:text-white transition-all">
                                    + Agregar otro producto
                                </button>
                            </div>
                            <div id="ocItemsContainer" class="space-y-3">
                                <div class="grid grid-cols-12 gap-3 items-end bg-slate-800/30 p-4 rounded-2xl border border-slate-800">
                                    <div class="col-span-6">
                                        <label class="text-[9px] uppercase text-slate-500 font-bold ml-1">Producto / Modelo</label>
                                        <input type="text" name="items[0][nombre_producto]" placeholder="Escribe el nombre o modelo..." required class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-3 py-2 text-sm outline-none">
                                    </div>
                                    <div class="col-span-2">
                                        <label class="text-[9px] uppercase text-slate-500 font-bold ml-1">Cant.</label>
                                        <input type="number" name="items[0][cantidad]" placeholder="0" min="1" required class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-3 py-2 text-sm outline-none">
                                    </div>
                                    <div class="col-span-3">
                                        <label class="text-[9px] uppercase text-slate-500 font-bold ml-1">Precio Compra</label>
                                        <input type="number" step="0.01" name="items[0][precio]" placeholder="$" required class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-3 py-2 text-sm outline-none">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-4 pt-6">
                            <button type="button" onclick="closeOCModal()" class="flex-1 text-slate-500 font-bold hover:text-white py-4 transition-colors">Cancelar</button>
                            <button type="submit" class="flex-1 bg-indigo-600 py-4 rounded-2xl font-bold text-white hover:bg-indigo-500 transition-all">Generar Orden Pendiente</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="receiptModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md z-[80] flex items-center justify-center p-4">
                <div class="bg-slate-900 border border-slate-800 p-8 rounded-[2.5rem] w-full max-w-lg shadow-2xl">
                    <h2 class="text-2xl font-bold mb-2 text-white flex items-center gap-2">
                        <?= lucideIcon('package-check', 'text-emerald-500') ?> Recepcionar Pedido
                    </h2>
                    <p class="text-slate-500 text-sm mb-6">Sube el comprobante para cargar los productos al inventario.</p>
                    
                    <form action="recepcionar_oc.php" method="POST" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="id_orden_compra" id="receipt_id_oc">
                        
                        <div class="bg-slate-800/50 p-6 rounded-2xl border-2 border-dashed border-slate-700 hover:border-indigo-500 transition-colors group">
                            <label class="cursor-pointer flex flex-col items-center gap-3">
                                <div class="p-3 bg-slate-800 rounded-xl group-hover:bg-indigo-600/20 group-hover:text-indigo-400 transition-all">
                                    <?= lucideIcon('upload-cloud', 'w-8 h-8') ?>
                                </div>
                                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest text-center">Seleccionar Boleta/Factura</span>
                                <input type="file" name="boleta" accept="image/*,.pdf" required class="hidden" onchange="updateFileName(this)">
                                <span id="fileName" class="text-[10px] text-indigo-400 italic">No se ha seleccionado archivo</span>
                            </label>
                        </div>

                        <div class="flex gap-4 pt-2">
                            <button type="button" onclick="closeReceiptModal()" class="flex-1 text-slate-500 font-bold hover:text-white transition-colors">Cancelar</button>
                            <button type="submit" class="flex-1 bg-emerald-600 py-3 rounded-2xl font-bold text-white hover:bg-emerald-500 shadow-lg shadow-emerald-600/20 transition-all">
                                Confirmar Ingreso
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>


<div id="productModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 p-8 rounded-[2.5rem] w-full max-w-md">
        <h2 id="modalTitle" class="text-2xl font-bold mb-6 text-white">Producto</h2>
        <form method="POST" id="productForm" class="space-y-4" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id_producto" id="productId">
            <input type="hidden" name="current_image_path" id="currentImagePath">

            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Nombre</label>
                <input type="text" name="nombre" id="p_nombre" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Imagen</label>
                <input type="file" name="imagen" accept="image/*" class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-2 text-xs text-slate-400 file:bg-indigo-600 file:text-white file:border-0 file:rounded-lg file:px-2 file:py-1 file:mr-4">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <input type="number" step="0.01" name="precio" id="p_precio" placeholder="Precio" required class="bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none">                
                <input type="number" name="stock" id="p_stock" placeholder="Stock" required class="bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none">
            </div>
            <div>
                <select name="id_area" id="p_area" class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none">
                    <?php foreach ($areasList as $a): ?>
                        <option value="<?= $a['id_area'] ?>"><?= $a['nombre_area'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 text-slate-500 font-bold hover:text-white">Cancelar</button>
                <button type="submit" class="flex-1 bg-indigo-600 py-3 rounded-2xl font-bold hover:bg-indigo-500 shadow-lg shadow-indigo-600/20">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();

    function openModal(mode, data = null) {
        const modal = document.getElementById('productModal');
        modal.classList.remove('hidden');
        if (mode === 'edit' && data) {
            document.getElementById('modalTitle').innerText = 'Editar Producto';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('productId').value = data.id_producto;
            document.getElementById('currentImagePath').value = data.imagen_path || '';
            document.getElementById('p_nombre').value = data.nombre;
            document.getElementById('p_sku').value = data.sku;
            document.getElementById('p_precio').value = data.precio;
            document.getElementById('p_stock').value = data.stock;
            document.getElementById('p_area').value = data.id_area;
        } else {
            document.getElementById('modalTitle').innerText = 'Nuevo Producto';
            document.getElementById('formAction').value = 'add';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
        }
    }

    function closeModal() {
        document.getElementById('productModal').classList.add('hidden');
    }

    document.getElementById('productModal').addEventListener('click', (e) => {
        if (e.target.id === 'productModal') closeModal();
    });
    function openMovementModal() {
        document.getElementById('movementModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Evita que se haga scroll al fondo
    }

    function closeMovementModal() {
        document.getElementById('movementModal').classList.add('hidden');
        document.body.style.overflow = 'auto'; // Devuelve el scroll
    }

    // Cerrar el modal si se hace clic fuera del cuadro blanco
    window.onclick = function(event) {
        const modal = document.getElementById('movementModal');
        if (event.target == modal) {
            closeMovementModal();
        }
    }

    // Cerrar al hacer clic fuera
    document.getElementById('movementModal').addEventListener('click', (e) => {
        if (e.target.id === 'movementModal') closeMovementModal();
    });


    function openOCModal() {
        document.getElementById('ocModal').classList.remove('hidden');
    }

    function closeOCModal() {
        document.getElementById('ocModal').classList.add('hidden');
    }


    let rowCount = 1; // Empezamos en 1 porque la fila 0 ya está en el HTML

    function addProductRow() {
        const container = document.getElementById('ocItemsContainer');
        
        // Creamos el nuevo slot
        const newRow = document.createElement('div');
        newRow.className = "grid grid-cols-12 gap-3 items-end bg-slate-800/30 p-4 rounded-2xl border border-slate-800 animate-in fade-in slide-in-from-top-2";
        
        newRow.innerHTML = `
            <div class="col-span-6">
                <label class="text-[9px] uppercase text-slate-500 font-bold ml-1">Producto</label>
                <input type="text" 
                    name="items[${rowCount}][nombre_producto]" 
                    placeholder="Escribe el nombre del producto..." 
                    required 
                    class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
            <div class="col-span-2">
                <label class="text-[9px] uppercase text-slate-500 font-bold ml-1">Cant.</label>
                <input type="number" 
                    name="items[${rowCount}][cantidad]" 
                    placeholder="0" 
                    min="1" 
                    required 
                    class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-3 py-2 text-sm outline-none">
            </div>
            <div class="col-span-3">
                <label class="text-[9px] uppercase text-slate-500 font-bold ml-1">Precio Compra</label>
                <input type="number" 
                    step="0.01" 
                    name="items[${rowCount}][precio]" 
                    placeholder="$" 
                    required 
                    class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-3 py-2 text-sm outline-none">
            </div>
            <div class="col-span-1 text-right">
                <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-red-500 hover:text-red-400 p-2 transition-colors">
                    <i data-lucide="trash-2" class="w-5 h-5"></i>
                </button>
            </div>
        `;
        
        container.appendChild(newRow);
        rowCount++;
        
        // Si usas Lucide para los iconos, esto los renderiza en la nueva fila
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }
    function deleteOC(id, numero) {
    if (confirm(`¿Estás seguro de eliminar la orden ${numero}? Esta acción no se puede deshacer.`)) {
        window.location.href = `delete_oc.php?id=${id}`;
    }
    }

    function openReceiptModal(id) {
        document.getElementById('receipt_id_oc').value = id;
        document.getElementById('receiptModal').classList.remove('hidden');
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.add('hidden');
    }

    function updateFileName(input) {
        const label = document.getElementById('fileName');
        if (input.files && input.files[0]) {
            // Si hay archivo, mostramos el nombre y cambiamos el color a verde
            label.innerText = "📄 Archivo listo: " + input.files[0].name;
            label.classList.remove('text-indigo-400');
            label.classList.add('text-emerald-400', 'font-bold');
        } else {
            // Si se cancela la selección
            label.innerText = "No se ha seleccionado archivo";
            label.classList.remove('text-emerald-400');
            label.classList.add('text-indigo-400');
        }
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.add('hidden');
    }


</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'error') {
        Swal.fire({
            icon: 'error',
            title: 'Movimiento Fallido',
            text: urlParams.get('msg') || 'Error desconocido',
            background: '#0f172a',
            color: '#fff',
            confirmButtonColor: '#10b981'
        });
    }
    if (urlParams.get('status') === 'success') {
        Swal.fire({
            icon: 'success',
            title: '¡Registrado!',
            text: 'El movimiento se ha guardado correctamente.',
            background: '#0f172a',
            color: '#fff',
            confirmButtonColor: '#10b981'
        });
    }
</script>

</body>
</html>
 