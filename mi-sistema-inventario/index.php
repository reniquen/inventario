<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 1. Validar tiempo de inactividad
require_once 'check_session.php'; //

define('ROLES', [
    'ADMIN' => 'admin',
    'ENCARGADO' => 'encargado',
    'CONSULTOR' => 'consultor'
]);

$currentRole   = $_SESSION['user_rol'];
$currentUserId = $_SESSION['user_id'];
$userAreaId    = $_SESSION['user_area'] ?? null;
$searchTerm    = $_GET['search'] ?? '';
$activeTab     = $_GET['tab'] ?? 'inventory';

// Conexión a Base de Datos
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

// Función para bitácora
function registrarLog($pdo, $userId, $accion, $descripcion) {
    $stmt = $pdo->prepare("INSERT INTO bitacora (id_usuario, accion, descripcion) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $accion, $descripcion]);
}

/* --- ACCIONES BACKEND --- */

// Agregar o Editar Producto con Imagen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $currentRole !== ROLES['CONSULTOR']) {
    $nombre  = $_POST['nombre'];
    $sku     = $_POST['sku'];
    $stock   = $_POST['stock'];
    $precio  = $_POST['precio'];
    $id_area = $_POST['id_area'];
    $desc    = $_POST['descripcion'] ?? '';
    
    // Lógica de carga de imagen
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
        $stmt = $pdo->prepare("INSERT INTO PRODUCTO (nombre, descripcion, sku, stock, precio, id_area, imagen_path, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'Activo')");
        $stmt->execute([$nombre, $desc, $sku, $stock, $precio, $id_area, $imagenPath]);
        registrarLog($pdo, $currentUserId, 'CREAR', "Registró bien con imagen: $nombre");
    } elseif ($_POST['action'] === 'edit') {
        $stmt = $pdo->prepare("UPDATE PRODUCTO SET nombre=?, descripcion=?, sku=?, stock=?, precio=?, id_area=?, imagen_path=? WHERE id_producto=?");
        $stmt->execute([$nombre, $desc, $sku, $stock, $precio, $id_area, $imagenPath, $_POST['id_producto']]);
        registrarLog($pdo, $currentUserId, 'EDITAR', "Actualizó bien: $nombre");
    }
    header("Location: index.php?tab=inventory");
    exit;
}

/* --- CONSULTAS --- */
$query = "SELECT p.*, a.nombre_area FROM PRODUCTO p LEFT JOIN AREA a ON p.id_area = a.id_area WHERE (p.nombre LIKE :search OR p.sku LIKE :search)";
$params = [':search' => "%$searchTerm%"];
if ($currentRole === ROLES['ENCARGADO']) { 
    $query .= " AND p.id_area = :area"; 
    $params[':area'] = $userAreaId;
}
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventory = $stmt->fetchAll();

$areasList = $pdo->query("SELECT * FROM AREA")->fetchAll();
$logs = $pdo->query("SELECT b.*, u.nombre as usuario_nombre FROM bitacora b JOIN usuario u ON b.id_usuario = u.id_usuario ORDER BY b.fecha DESC LIMIT 50")->fetchAll();

function icon($name, $class = "w-4 h-4") {
    return "<i data-lucide='{$name}' class='{$class}'></i>";
}
?>

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>MuniStock - Inventario</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-950 text-slate-100 h-full overflow-hidden">

<div class="flex h-screen">
    <aside class="w-64 bg-slate-900 border-r border-slate-800 p-6 flex flex-col">
        <div class="flex items-center gap-3 mb-8 text-indigo-400">
            <div class="bg-indigo-600 p-2 rounded-lg text-white"><?= icon('package', 'w-6 h-6') ?></div>
            <span class="text-xl font-bold">MuniStock</span>
        </div>
        <nav class="flex-1 space-y-2">
            <a href="?tab=inventory" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'inventory' ? 'bg-indigo-600 text-white' : 'text-slate-400' ?>">
                <?= icon('boxes') ?> Inventario
            </a>
            <a href="?tab=logs" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'logs' ? 'bg-indigo-600 text-white' : 'text-slate-400' ?>">
                <?= icon('clipboard-list') ?> Bitácora
            </a>
        </nav>
        <div class="pt-6 border-t border-slate-800 text-xs">
            <p class="font-bold"><?= htmlspecialchars($_SESSION['user_nombre']) ?></p>
            <a href="logout.php" class="text-red-400 mt-2 block">Cerrar Sesión</a>
        </div>
    </aside>

    <main class="flex-1 p-8 overflow-y-auto">
        <?php if ($activeTab === 'inventory'): ?>
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold">Inventario Municipal</h1>
                <button onclick="openModal('add')" class="bg-indigo-600 px-6 py-3 rounded-xl font-bold flex gap-2">
                    <?= icon('plus') ?> Registrar
                </button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-800/50 text-slate-500 uppercase text-[10px]">
                        <tr>
                            <th class="p-4">Imagen</th>
                            <th class="p-4">Bien</th>
                            <th class="p-4 text-center">Stock</th>
                            <th class="p-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory as $item): ?>
                        <tr class="border-t border-slate-800">
                            <td class="p-4">
                                <?php if($item['imagen_path']): ?>
                                    <img src="<?= $item['imagen_path'] ?>" class="w-10 h-10 object-cover rounded-lg">
                                <?php else: ?>
                                    <div class="w-10 h-10 bg-slate-800 rounded-lg flex items-center justify-center"><?= icon('image') ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 font-bold"><?= htmlspecialchars($item['nombre']) ?></td>
                            <td class="p-4 text-center"><?= $item['stock'] ?></td>
                            <td class="p-4 text-right">
                                <button onclick='openModal("edit", <?= json_encode($item) ?>)' class="text-indigo-400"><?= icon('edit') ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>

<div id="productModal" class="hidden fixed inset-0 bg-black/80 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 p-8 rounded-3xl w-full max-w-md">
        <h2 id="modalTitle" class="text-2xl font-bold mb-6 text-white">Producto</h2>
        <form method="POST" id="productForm" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id_producto" id="productId">
            <input type="hidden" name="current_image_path" id="currentImagePath">

            <input type="text" name="nombre" id="p_nombre" placeholder="Nombre" required class="w-full bg-slate-800 p-3 rounded-xl outline-none">
            
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-slate-500 uppercase">Imagen del Producto</label>
                <input type="file" name="imagen" accept="image/*" class="w-full bg-slate-800 p-2 rounded-xl text-xs">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <input type="text" name="sku" id="p_sku" placeholder="SKU" required class="bg-slate-800 p-3 rounded-xl outline-none">
                <input type="number" name="precio" id="p_precio" placeholder="Precio" required class="bg-slate-800 p-3 rounded-xl outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <input type="number" name="stock" id="p_stock" placeholder="Stock" required class="bg-slate-800 p-3 rounded-xl outline-none">
                <select name="id_area" id="p_area" class="bg-slate-800 p-3 rounded-xl outline-none">
                    <?php foreach ($areasList as $a): ?>
                        <option value="<?= $a['id_area'] ?>"><?= $a['nombre_area'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <textarea name="descripcion" id="p_desc" placeholder="Descripción..." class="w-full bg-slate-800 p-3 rounded-xl outline-none h-20"></textarea>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 text-slate-500 font-bold">Cancelar</button>
                <button type="submit" class="flex-1 bg-indigo-600 py-3 rounded-xl font-bold">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();
    function openModal(mode, data = null) {
        document.getElementById('productModal').classList.remove('hidden');
        if (mode === 'edit' && data) {
            document.getElementById('modalTitle').innerText = 'Editar Bien';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('productId').value = data.id_producto;
            document.getElementById('currentImagePath').value = data.imagen_path || '';
            document.getElementById('p_nombre').value = data.nombre;
            document.getElementById('p_sku').value = data.sku;
            document.getElementById('p_precio').value = data.precio;
            document.getElementById('p_stock').value = data.stock;
            document.getElementById('p_area').value = data.id_area;
            document.getElementById('p_desc').value = data.descripcion || '';
        } else {
            document.getElementById('modalTitle').innerText = 'Nuevo Registro';
            document.getElementById('formAction').value = 'add';
            document.getElementById('productForm').reset();
        }
    }
    function closeModal() { document.getElementById('productModal').classList.add('hidden'); }
</script>
</body>
</html>