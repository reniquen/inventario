<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 1. Validar tiempo de inactividad
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

if (isset($_GET['error']) && $_GET['error'] === 'timeout') {
    $error = "Tu sesión ha expirado por inactividad. Por favor, ingresa nuevamente.";
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

// --- OBTENER TASAS DE CAMBIO INTERNACIONALES EN TIEMPO REAL ---
// Valores de respaldo por defecto en caso de que la API externa falle
$tasasCambio = [
    'CLP' => 1,
    'USD' => 950,
    'EUR' => 1050,
    'CNY' => 130 // Yuan Chino
];

try {
    // Usamos exchangerate-api para obtener todas las monedas basadas en USD
    $ch = curl_init('https://api.exchangerate-api.com/v4/latest/USD');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 segundos de espera máximo
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['rates']['CLP'])) {
            $clpRate = $data['rates']['CLP'];
            $tasasCambio['USD'] = $clpRate; // 1 USD = X CLP
            
            // Calcular conversiones cruzadas hacia CLP
            if(isset($data['rates']['EUR'])) {
                $tasasCambio['EUR'] = $clpRate / $data['rates']['EUR'];
            }
            if(isset($data['rates']['CNY'])) {
                $tasasCambio['CNY'] = $clpRate / $data['rates']['CNY'];
            }
        }
    }
} catch (Exception $e) {}

// --- MOTOR DE ANÁLISIS DE LINKS Y REGLAS DE IMPORTACIÓN ---
function analizarLinkImportacion($url) {
    if (empty($url)) return ['es_importado' => 0, 'moneda' => 'CLP'];

    $domain = parse_url($url, PHP_URL_HOST);
    if (!$domain) return ['es_importado' => 0, 'moneda' => 'CLP'];
    
    // Reglas Automáticas por Dominio (Se detecta la tienda y se asigna su moneda local)
    $reglas = [
        'alibaba.com'   => ['es_importado' => 1, 'moneda' => 'CNY'], // Yuan Chino
        'aliexpress.com'=> ['es_importado' => 1, 'moneda' => 'USD'], // Por lo general se tranza en USD
        'amazon.com'    => ['es_importado' => 1, 'moneda' => 'USD'],
        'amazon.es'     => ['es_importado' => 1, 'moneda' => 'EUR'], // Amazon España
        'ebay.com'      => ['es_importado' => 1, 'moneda' => 'USD'],
        'apple.com'     => ['es_importado' => 1, 'moneda' => 'USD'],
        'pcfactory.cl'  => ['es_importado' => 0, 'moneda' => 'CLP'],
        'mercadolibre.cl'=> ['es_importado' => 0, 'moneda' => 'CLP'],
    ];

    foreach ($reglas as $key => $valores) {
        if (strpos($domain, $key) !== false) {
            return $valores;
        }
    }

    // Por defecto asume Nacional
    return ['es_importado' => 0, 'moneda' => 'CLP'];
}

// --- FUNCIÓN DE CÁLCULO MUNICIPAL / ADUANERO MULTIMONEDA ---
function calcularCostosAdquisicion($precioBase, $monedaOrigen, $esImportado, $tasas) {
    $iva = 0.19; // 19% IVA Chile
    $arancel_aduanero = 0.06; // 6% Ad Valorem

    // Estandarizar base a CLP multiplicando por la tasa de la moneda origen
    $tasaAplicada = $tasas[$monedaOrigen] ?? 1;
    $baseCLP = $precioBase * $tasaAplicada;
    
    // Arancel Aduanero (Aplica solo si es importado)
    $costoArancelCLP = $esImportado ? ($baseCLP * $arancel_aduanero) : 0;
    
    // Subtotal CIF
    $subtotalCLP = $baseCLP + $costoArancelCLP;
    
    // IVA
    $montoIvaCLP = $subtotalCLP * $iva;
    
    // Totales
    $totalCLP = $subtotalCLP + $montoIvaCLP;
    $totalUSD = $totalCLP / $tasas['USD']; // Para referencia internacional

    return [
        'precio_origen' => $precioBase,
        'moneda_origen' => $monedaOrigen,
        'tasa_aplicada' => $tasaAplicada,
        'base_clp'      => $baseCLP,
        'arancel_clp'   => $costoArancelCLP,
        'iva_clp'       => $montoIvaCLP,
        'total_clp'     => $totalCLP,
        'total_usd'     => $totalUSD,
        'es_importado'  => $esImportado
    ];
}

// --- 4. ACCIONES BACKEND ---

// Eliminar Producto
if (isset($_GET['delete_id']) && $currentRole === ROLES['ADMIN']) {
    $stmt = $pdo->prepare("DELETE FROM producto WHERE id_producto = ?");
    $stmt->execute([$_GET['delete_id']]);
    header("Location: index.php?tab=$activeTab");
    exit;
}

// Agregar o Editar Producto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $currentRole !== ROLES['CONSULTOR']) {
    $nombre        = $_POST['nombre'];
    $sku           = $_POST['sku'];
    $stock         = $_POST['stock'];
    $precio        = $_POST['precio'];
    $id_area       = $_POST['id_area'];
    $url_producto  = trim($_POST['producto_url'] ?? '');
    
    // Analizar Link para Auto-Detección
    $infoLink = analizarLinkImportacion($url_producto);
    
    // Si hay link, domina el link, sino, toma los valores manuales del formulario
    $moneda_origen = !empty($url_producto) ? $infoLink['moneda'] : ($_POST['moneda_origen'] ?? 'CLP');
    $es_importado  = !empty($url_producto) ? $infoLink['es_importado'] : (isset($_POST['es_importado']) ? 1 : 0);
    
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
        $stmt = $pdo->prepare("INSERT INTO producto (nombre, sku, stock, precio_referencial, id_area, imagen_path, estado, moneda_origen, es_importado, producto_url) VALUES (?, ?, ?, ?, ?, ?, 'activo', ?, ?, ?)");
        $stmt->execute([$nombre, $sku, $stock, $precio, $id_area, $imagenPath, $moneda_origen, $es_importado, $url_producto]);
    } elseif ($_POST['action'] === 'edit' && isset($_POST['id_producto'])) {
        $stmt = $pdo->prepare("UPDATE producto SET nombre=?, sku=?, stock=?, precio_referencial=?, id_area=?, imagen_path=?, moneda_origen=?, es_importado=?, producto_url=? WHERE id_producto=?");
        $stmt->execute([$nombre, $sku, $stock, $precio, $id_area, $imagenPath, $moneda_origen, $es_importado, $url_producto, $_POST['id_producto']]);
    }
    header("Location: index.php?tab=$activeTab");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role']) && $currentRole === ROLES['ADMIN']) {
    $stmt = $pdo->prepare("UPDATE usuario SET id_rol = ? WHERE id_usuario = ?");
    $stmt->execute([$_POST['id_rol'], $_POST['id_usuario']]);
}

// 5. CONSULTA DE DATOS ACTUALIZADA
$query = "SELECT 
            p.id_producto,
            p.nombre,
            p.sku,
            p.stock,
            p.imagen_path,
            p.precio_referencial AS precio,
            p.moneda_origen,
            p.es_importado,
            p.producto_url,
            p.id_area,
            prov.nombre AS nombre_proveedor,
            a.nombre_area
          FROM producto p
          LEFT JOIN area a ON p.id_area = a.id_area
          LEFT JOIN producto_proveedor pp ON p.id_producto = pp.id_producto AND pp.proveedor_principal = 1
          LEFT JOIN proveedor prov ON pp.id_proveedor = prov.id_proveedor
          WHERE p.nombre LIKE :search OR p.sku LIKE :search";

$stmt = $pdo->prepare($query);
$params = [':search' => "%$searchTerm%"];
$stmt->execute($params);
$inventory = $stmt->fetchAll();

$areasList = $pdo->query("SELECT * FROM area")->fetchAll();

$users = $pdo->query("
    SELECT u.id_usuario, u.nombre, u.email, u.id_rol, r.nombre_rol
    FROM usuario u
    JOIN rol r ON u.id_rol = r.id_rol
")->fetchAll();

$movimientos = $pdo->query("
    SELECT 
        m.id_movimiento,
        m.tipo,
        m.fecha,
        m.motivo,
        u.nombre AS usuario,
        a.nombre_area AS area_destino,
        p.nombre AS producto,
        d.cantidad
    FROM movimiento m
    JOIN detalle_movimiento d ON m.id_movimiento = d.id_movimiento
    JOIN producto p ON d.id_producto = p.id_producto
    JOIN usuario u ON m.id_usuario = u.id_usuario
    LEFT JOIN area a ON m.id_area = a.id_area
    ORDER BY m.fecha DESC
")->fetchAll();

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
            </a>
                <a href="?tab=purchases" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'purchases' ? 'bg-indigo-600/20 text-indigo-400 border border-indigo-500/20' : 'hover:bg-slate-800 text-slate-400' ?>">
                <?= lucideIcon('shopping-cart') ?> Órdenes de Compra
            </a>
        </nav>

        <div class="p-6 border-t border-slate-800 text-xs text-slate-500">
            <p>Usuario: <span class="text-white font-medium"><?= htmlspecialchars($_SESSION['user_nombre']) ?></span></p>
            <p>Rol: <span class="text-indigo-400 font-bold uppercase"><?= $currentRole ?></span></p>
            <a href="login.php" class="mt-4 flex items-center gap-2 text-red-400 hover:text-red-300 transition-colors">
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

            <div class="flex justify-between items-end mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white">Inventario Actual</h1>
                    <p class="text-slate-500 italic">Gestión de existencias por área</p>
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
                            <th class="px-6 py-5 text-center">Valor Total CDP</th>
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
                                <div class="flex items-center gap-2">
                                    <div class="font-bold text-slate-200"><?= htmlspecialchars($item['nombre']) ?></div>
                                    <?php if(!empty($item['producto_url'])): ?>
                                        <a href="<?= htmlspecialchars($item['producto_url']) ?>" target="_blank" class="text-blue-400 hover:text-blue-300 transition-colors" title="Ver producto original">
                                            <?= lucideIcon('external-link', 'w-3 h-3') ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-500 font-mono mt-0.5"><?= $item['sku'] ?></div>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <span class="bg-slate-800 px-3 py-1 rounded-lg text-[10px] border border-slate-700">
                                    <?= htmlspecialchars($item['nombre_area'] ?? 'General') ?>
                                </span>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <?php 
                                    $esImportado = $item['es_importado'] ?? 0;
                                    $monedaOrigen = $item['moneda_origen'] ?? 'CLP';
                                    $costos = calcularCostosAdquisicion($item['precio'], $monedaOrigen, $esImportado, $tasasCambio);
                                ?>
                                <div class="font-bold text-indigo-400 text-sm">
                                    $<?= number_format($costos['total_clp'], 0, ',', '.') ?> CLP
                                </div>
                                <div class="text-[11px] text-slate-400 font-mono mt-0.5" title="Conversión de <?= $monedaOrigen ?>">
                                    Ref: USD $<?= number_format($costos['total_usd'], 2, '.', ',') ?>
                                </div>
                                <button onclick='abrirModalDesglose(<?= json_encode($costos) ?>)' class="mt-2 mx-auto text-[10px] bg-slate-800 text-slate-400 hover:text-white px-2 py-1 rounded-md border border-slate-700 flex items-center gap-1 transition-colors">
                                    <?= lucideIcon('calculator', 'w-3 h-3') ?> Ver Desglose
                                </button>
                            </td>

                            <td class="px-6 py-5 text-center font-bold <?= ($item['stock'] ?? 0) < 10 ? 'text-red-500' : 'text-emerald-400' ?>">
                                <?= $item['stock'] ?? 0 ?>
                            </td>

                            <td class="px-6 py-5 text-center text-slate-400 text-sm">
                                <?= htmlspecialchars($item['nombre_proveedor'] ?? '—') ?>
                            </td>

                            <td class="px-8 py-5 text-right">
                                <div class="flex justify-end gap-2">
                                    <?php if ($currentRole !== ROLES['CONSULTOR']): ?>
                                        <button onclick='openModal("edit", <?= json_encode($item) ?>)' class="p-2 hover:bg-indigo-500/20 text-slate-400 hover:text-indigo-400 rounded-xl">
                                            <?= lucideIcon('edit-3', 'w-4 h-4') ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($currentRole === ROLES['ADMIN']): ?>
                                        <a href="?delete_id=<?= $item['id_producto'] ?>" onclick="return confirm('¿Eliminar?')" class="p-2 hover:bg-red-500/20 text-slate-400 hover:text-red-400 rounded-xl">
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

            <h1 class="text-3xl font-bold text-white mb-2">Estadísticas</h1>
            <p class="text-slate-500 mb-8">Resumen general del inventario</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                    <p class="text-slate-400 text-sm">Variedad de Productos</p>
                    <p class="text-3xl font-bold text-indigo-400"><?= count($inventory) ?></p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                    <p class="text-slate-400 text-sm">Stock Bajo (&lt; 10)</p>
                    <p class="text-3xl font-bold text-red-400">
                        <?= count(array_filter($inventory, fn($i) => $i['stock'] < 10)) ?>
                    </p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                    <p class="text-slate-400 text-sm">Valor Total Neto (Referencial)</p>
                    <p class="text-3xl font-bold text-emerald-400">
                        $
                        <?= number_format(array_sum(array_map(fn($i) => $i['precio'] * $i['stock'], $inventory)), 0, ',', '.') ?>
                    </p>
                </div>
            </div>
        <?php elseif ($activeTab === 'roles' && $currentRole === ROLES['ADMIN']): ?>

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
        <?php elseif ($activeTab === 'movements'): ?>
            <h1 class="text-3xl font-bold text-white mb-2">Historial de Movimientos</h1>
            <p class="text-slate-500 mb-8">Registro de entradas y transferencias entre áreas</p>

            <div class="bg-slate-900 border border-slate-800 rounded-[2rem] overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase tracking-widest">
                        <tr>
                            <th class="px-6 py-4">Fecha</th>
                            <th class="px-6 py-4">Tipo</th>
                            <th class="px-6 py-4">Producto</th>
                            <th class="px-6 py-4 text-center">Cant.</th>
                            <th class="px-6 py-4">Área Relacionada</th>
                            <th class="px-6 py-4">Motivo</th>
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
                                <span class="text-slate-300 text-sm italic">
                                    <?= htmlspecialchars($m['area_destino'] ?? 'Bodega Central') ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-slate-400 text-sm"><?= htmlspecialchars($m['motivo']) ?></td>
                            <td class="px-6 py-4 text-xs"><?= htmlspecialchars($m['usuario']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($activeTab === 'purchases'): 
            $compras = $pdo->query("SELECT oc.*, p.nombre as proveedor, a.nombre_area 
                                FROM orden_compra oc 
                                JOIN proveedor p ON oc.id_proveedor = p.id_proveedor
                                JOIN area a ON oc.id_area = a.id_area")->fetchAll();
            ?>
                <h1 class="text-3xl font-bold text-white mb-8">Órdenes de Compra</h1>
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
                            <tr>
                                <td class="px-6 py-4 font-mono text-indigo-400"><?= $c['numero_oc'] ?></td>
                                <td class="px-6 py-4"><?= $c['proveedor'] ?></td>
                                <td class="px-6 py-4"><?= $c['nombre_area'] ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 rounded-full text-xs bg-indigo-500/10 text-indigo-400">
                                        <?= $c['estado_oc'] ?>
                                    </span>
                                </td>
                                <td class="px-8 py-4 text-right">
                                    <a href="print_oc.php?id=<?= $c['id_orden_compra'] ?>" target="_blank" class="text-slate-400 hover:text-white">
                                        <?= lucideIcon('external-link', 'w-5 h-5') ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Link de Referencia (Auto-detecta Importación)</label>
                <div class="relative">
                    <input type="url" name="producto_url" id="p_url" placeholder="https://www.alibaba.com/..." class="w-full bg-slate-800 border border-slate-700 rounded-2xl pl-10 pr-5 py-3 outline-none focus:ring-2 focus:ring-blue-500 text-xs">
                    <div class="absolute left-3 top-3.5 text-slate-500"><?= lucideIcon('link', 'w-4 h-4') ?></div>
                </div>
            </div>

            <div>
                <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Imagen</label>
                <input type="file" name="imagen" accept="image/*" class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-2 text-xs text-slate-400 file:bg-indigo-600 file:text-white file:border-0 file:rounded-lg file:px-2 file:py-1 file:mr-4">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">SKU</label>
                    <input type="text" name="sku" id="p_sku" placeholder="Código" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Precio Neto</label>
                    <input type="number" step="0.01" name="precio" id="p_precio" placeholder="0.00" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t border-slate-800 pt-4 mt-2">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Moneda Origen</label>
                    <select name="moneda_origen" id="p_moneda" class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="CLP">CLP (Pesos)</option>
                        <option value="USD">USD (Dólares)</option>
                        <option value="EUR">EUR (Euros)</option>
                        <option value="CNY">CNY (Yuan)</option>
                    </select>
                </div>
                <div class="flex items-center mt-6">
                    <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-300 font-medium">
                        <input type="checkbox" name="es_importado" id="p_importado" value="1" class="w-5 h-5 rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500">
                        <span>Aplica Arancel (6%)</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Stock</label>
                    <input type="number" name="stock" id="p_stock" placeholder="0" required class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 uppercase ml-1">Área Destino</label>
                    <select name="id_area" id="p_area" class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3 outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        <?php foreach ($areasList as $a): ?>
                            <option value="<?= $a['id_area'] ?>"><?= $a['nombre_area'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex gap-4 pt-4 border-t border-slate-800">
                <button type="button" onclick="closeModal()" class="flex-1 text-slate-500 font-bold hover:text-white transition-colors">Cancelar</button>
                <button type="submit" class="flex-1 bg-indigo-600 py-3 rounded-2xl font-bold hover:bg-indigo-500 shadow-lg shadow-indigo-600/20 transition-all">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div id="desgloseModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md z-[110] flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 p-8 rounded-[2rem] w-full max-w-md shadow-2xl">
        <div class="flex items-center gap-3 mb-6">
            <div class="bg-emerald-600/20 p-2 rounded-xl text-emerald-500">
                <i data-lucide="landmark" class="w-6 h-6"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-white">Costos de Adquisición</h2>
                <p class="text-[10px] text-slate-500 uppercase tracking-widest">Soporte Mercado Público (CDP)</p>
            </div>
        </div>

        <div class="space-y-3 font-mono text-sm border-y border-slate-800 py-6 mb-6">
            <div class="flex justify-between text-slate-400">
                <span>Moneda Local y Valor:</span>
                <span id="desc_origen_tasa" class="text-white"></span>
            </div>
            <div class="flex justify-between text-slate-400">
                <span>Valor Neto Original:</span>
                <span id="desc_origen" class="text-white font-bold"></span>
            </div>
            
            <div class="flex justify-between text-slate-400 mt-4 border-t border-slate-800 pt-4">
                <span>Valor Base (CLP):</span>
                <span id="desc_base_clp" class="text-white"></span>
            </div>

            <div id="row_arancel" class="flex justify-between text-amber-500 hidden">
                <span>Arancel Aduanero (6%):</span>
                <span id="desc_arancel">+ $0</span>
            </div>

            <div class="flex justify-between text-indigo-400">
                <span>IVA (19%):</span>
                <span id="desc_iva">+ $0</span>
            </div>
        </div>

        <div class="flex justify-between items-center text-lg font-bold text-emerald-400 mb-6 border-b border-slate-800 pb-6">
            <span>TOTAL A PAGAR:</span>
            <span id="desc_total_clp">$0 CLP</span>
        </div>
        
        <div class="text-[10px] text-slate-500 italic mb-6">
            * Valores calculados en tiempo real. 1 USD = <strong class="text-slate-300">$<?= number_format($tasasCambio['USD'], 2, ',', '.') ?> CLP</strong>.
        </div>

        <button type="button" onclick="document.getElementById('desgloseModal').classList.add('hidden')" class="w-full bg-slate-800 hover:bg-slate-700 text-white py-3 rounded-xl font-bold transition-colors">
            Cerrar Desglose
        </button>
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
            
            document.getElementById('p_url').value = data.producto_url || '';
            document.getElementById('p_moneda').value = data.moneda_origen || 'CLP';
            document.getElementById('p_importado').checked = data.es_importado == 1;
        } else {
            document.getElementById('modalTitle').innerText = 'Nuevo Producto';
            document.getElementById('formAction').value = 'add';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('p_url').value = '';
            document.getElementById('p_moneda').value = 'CLP';
        }
    }

    function closeModal() {
        document.getElementById('productModal').classList.add('hidden');
    }

    document.getElementById('productModal').addEventListener('click', (e) => {
        if (e.target.id === 'productModal') closeModal();
    });

    function formatearCLP(numero) {
        return new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(numero);
    }

    function abrirModalDesglose(costos) {
        // Tasa y Moneda Original
        const siglas = {
            'USD': 'Dólares ($)',
            'CNY': 'Yuan Chino (¥)',
            'EUR': 'Euros (€)',
            'CLP': 'Pesos Chilenos ($)'
        };
        const nombreMoneda = siglas[costos.moneda_origen] || costos.moneda_origen;
        
        document.getElementById('desc_origen_tasa').innerText = `1 ${costos.moneda_origen} = ${formatearCLP(costos.tasa_aplicada)}`;
        document.getElementById('desc_origen').innerText = `${parseFloat(costos.precio_origen).toFixed(2)} ${costos.moneda_origen}`;
        
        // Base CLP
        document.getElementById('desc_base_clp').innerText = formatearCLP(costos.base_clp);
        
        // Arancel
        const rowArancel = document.getElementById('row_arancel');
        if (costos.es_importado == 1) {
            rowArancel.classList.remove('hidden');
            document.getElementById('desc_arancel').innerText = '+ ' + formatearCLP(costos.arancel_clp);
        } else {
            rowArancel.classList.add('hidden');
        }
        
        // IVA
        document.getElementById('desc_iva').innerText = '+ ' + formatearCLP(costos.iva_clp);
        
        // Total
        document.getElementById('desc_total_clp').innerText = formatearCLP(costos.total_clp);
        
        lucide.createIcons();
        document.getElementById('desgloseModal').classList.remove('hidden');
    }
    
    document.getElementById('desgloseModal').addEventListener('click', (e) => {
        if (e.target.id === 'desgloseModal') {
            document.getElementById('desgloseModal').classList.add('hidden');
        }
    });
</script>
</body>
</html>