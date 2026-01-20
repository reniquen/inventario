<?php
session_start();

// 1. Verificación de Seguridad
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. Configuración basada en la SESIÓN
$currentRole   = $_SESSION['user_rol']; 
$activeTab     = $_GET['tab']    ?? 'inventory';
$searchTerm    = $_GET['search'] ?? '';
$assignedArea  = $_SESSION['user_area'] ?? ''; // Previene error si no está definido

// Definición de constantes de roles
if (!defined('ROLES')) {
    define('ROLES', [
        'ADMIN' => 'admin',
        'ENCARGADO' => 'encargado',
        'CONSULTOR' => 'consultor'
    ]);
}

// 3. Conexión a Base de Datos
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root'; 
$pass = 'mysql'; // Ajusta esto si tu clave es vacía '' o 'root'

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error Crítico: " . $e->getMessage());
}

// --- 4. PROCESAMIENTO DE ACCIONES (BACKEND) ---

// A. Eliminar (Solo ADMIN)
if (isset($_GET['delete_id']) && $currentRole === ROLES['ADMIN']) {
    $stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    header("Location: index.php?tab=$activeTab");
    exit;
}

// B. Agregar o Editar (ADMIN y ENCARGADO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $currentRole !== ROLES['CONSULTOR']) {
    $nombre = $_POST['nombre'];
    $sku    = $_POST['sku'];
    $stock  = $_POST['stock'];
    $id_area = $_POST['id_area'];

    // Acción: Agregar (Solo Admin)
    if ($_POST['action'] === 'add' && $currentRole === ROLES['ADMIN']) {
        $stmt = $pdo->prepare("INSERT INTO productos (nombre, sku, stock, id_area) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nombre, $sku, $stock, $id_area]);
    
    // Acción: Editar (Admin y Encargado)
    } elseif ($_POST['action'] === 'edit') {
        // Validar que el ID existe
        if(isset($_POST['id'])){
            $stmt = $pdo->prepare("UPDATE productos SET nombre=?, sku=?, stock=?, id_area=? WHERE id=?");
            $stmt->execute([$nombre, $sku, $stock, $id_area, $_POST['id']]);
        }
    }
    header("Location: index.php?tab=$activeTab");
    exit;
}

// 5. CONSULTA DE DATOS FILTRADA
$query = "SELECT p.*, a.nombre AS area_nombre FROM productos p 
          LEFT JOIN areas a ON p.id_area = a.id 
          WHERE p.nombre LIKE :search";

// Si es ENCARGADO, forzamos el filtro por su área
if ($currentRole === ROLES['ENCARGADO']) { 
    $query .= " AND a.nombre = :area"; 
}

$stmt = $pdo->prepare($query);
$params = [':search' => "%$searchTerm%"];

if ($currentRole === ROLES['ENCARGADO']) { 
    $params[':area'] = $assignedArea; 
}

$stmt->execute($params);
$inventory = $stmt->fetchAll();

// Obtener lista de áreas para el formulario (Select)
$areasList = $pdo->query("SELECT * FROM areas")->fetchAll();

function lucideIcon($name, $class = "w-5 h-5") {
    return "<i data-lucide='{$name}' class='{$class}'></i>";
}
?>

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>StockMaster Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-950 text-slate-100 h-full overflow-hidden">

<div class="flex h-screen">
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col">
        <div class="p-8 flex items-center gap-3">
            <div class="bg-indigo-600 p-2 rounded-lg"><?= lucideIcon('package', 'text-white w-6 h-6') ?></div>
            <span class="text-xl font-bold">StockMaster</span>
        </div>
        <nav class="flex-1 px-4 space-y-2">
            <a href="?tab=inventory" class="flex items-center gap-3 p-3 rounded-xl <?= $activeTab === 'inventory' ? 'bg-indigo-600' : 'hover:bg-slate-800' ?>">
                <?= lucideIcon('boxes') ?> Inventario
            </a>
        </nav>
        <div class="p-4 border-t border-slate-800">
             <a href="login.php" class="text-red-400 text-sm flex items-center gap-2 hover:bg-red-500/10 p-2 rounded-lg">
                <?= lucideIcon('log-out') ?> Cerrar Sesión
             </a>
        </div>
        <div class="p-6 border-t border-slate-800 text-xs text-slate-500">
            Usuario: <span class="text-white block"><?= htmlspecialchars($_SESSION['user_nombre']) ?></span>
            Rol: <span class="text-indigo-400 font-bold uppercase"><?= $currentRole ?></span>
        </div>
    </aside>

    <main class="flex-1 flex flex-col overflow-hidden">
        <header class="h-20 bg-slate-900/50 border-b border-slate-800 px-8 flex items-center justify-between">
            <div class="text-sm font-medium text-slate-400">
                <?php if($currentRole === ROLES['ENCARGADO']): ?>
                    Área: <span class="text-indigo-400"><?= htmlspecialchars($assignedArea) ?></span>
                <?php endif; ?>
            </div>
            
            <form class="relative">
                <input type="text" name="search" placeholder="Buscar..." value="<?= htmlspecialchars($searchTerm) ?>" class="bg-slate-800 border border-slate-700 rounded-xl pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                <div class="absolute left-3 top-2.5 text-slate-500"><?= lucideIcon('search', 'w-4 h-4') ?></div>
            </form>
        </header>

        <section class="p-8 overflow-y-auto">
            <div class="max-w-6xl mx-auto">
                <div class="flex justify-between items-end mb-6">
                    <div>
                        <h1 class="text-2xl font-bold">Gestión de Inventario</h1>
                        <p class="text-slate-500 text-sm">Mostrando <?= count($inventory) ?> productos.</p>
                    </div>
                    
                    <?php if ($currentRole === ROLES['ADMIN']): ?>
                        <button onclick="openModal('add')" class="bg-indigo-600 hover:bg-indigo-500 px-4 py-2 rounded-xl font-bold flex items-center gap-2 transition-all">
                            <?= lucideIcon('plus') ?> Nuevo Producto
                        </button>
                    <?php endif; ?>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden shadow-2xl">
                    <table class="w-full text-left">
                        <thead class="bg-slate-800/50 text-slate-500 text-[10px] uppercase tracking-widest">
                            <tr>
                                <th class="px-6 py-4">Producto / SKU</th>
                                <th class="px-6 py-4 text-center">Área</th>
                                <th class="px-6 py-4 text-center">Stock</th>
                                <th class="px-6 py-4 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($inventory as $item): ?>
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-bold"><?= htmlspecialchars($item['nombre']) ?></div>
                                    <div class="text-[10px] text-slate-500 font-mono"><?= $item['sku'] ?></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="bg-slate-800 px-3 py-1 rounded-full text-xs border border-slate-700"><?= $item['area_nombre'] ?></span>
                                </td>
                                <td class="px-6 py-4 text-center font-mono font-bold <?= $item['stock'] < 5 ? 'text-red-500' : '' ?>">
                                    <?= $item['stock'] ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-center gap-2">
                                        <?php if ($currentRole !== ROLES['CONSULTOR']): ?>
                                            <button onclick='openModal("edit", <?= json_encode($item) ?>)' class="text-indigo-400 p-2 hover:bg-indigo-500/10 rounded-lg"><?= lucideIcon('edit-3') ?></button>
                                        <?php endif; ?>

                                        <?php if ($currentRole === ROLES['ADMIN']): ?>
                                            <a href="?delete_id=<?= $item['id'] ?>" onclick="return confirm('¿Borrar producto?')" class="text-red-400 p-2 hover:bg-red-500/10 rounded-lg"><?= lucideIcon('trash-2') ?></a>
                                        <?php endif; ?>
                                        
                                        <?php if ($currentRole === ROLES['CONSULTOR']): ?>
                                            <span class="text-slate-600 text-xs italic">Solo lectura</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<div id="productModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 p-8 rounded-3xl w-full max-w-md">
        <h2 id="modalTitle" class="text-2xl font-bold mb-6">Producto</h2>
        
        <form method="POST" id="productForm" class="space-y-4">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="productId">
            
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase">Nombre</label>
                <input type="text" name="nombre" id="p_nombre" required class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 mt-1 outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase">SKU</label>
                    <input type="text" name="sku" id="p_sku" required class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 mt-1 outline-none">
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase">Stock</label>
                    <input type="number" name="stock" id="p_stock" required class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 mt-1 outline-none">
                </div>
            </div>
            
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase">Área</label>
                <select name="id_area" id="p_area" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 mt-1 outline-none">
                    <?php foreach ($areasList as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= $a['nombre'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 text-slate-400 font-bold hover:text-white transition-colors">Cancelar</button>
                <button type="submit" class="flex-1 bg-indigo-600 py-3 rounded-xl font-bold hover:bg-indigo-500 transition-all">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Inicializar iconos
    lucide.createIcons();

    function openModal(mode, data = null) {
        const modal = document.getElementById('productModal');
        const form = document.getElementById('productForm');
        
        // Mostrar modal
        modal.classList.remove('hidden');
        
        if (mode === 'edit' && data) {
            // Modo Edición: Rellenar datos
            document.getElementById('modalTitle').innerText = 'Editar Producto';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('productId').value = data.id;
            
            document.getElementById('p_nombre').value = data.nombre;
            document.getElementById('p_sku').value = data.sku;
            document.getElementById('p_stock').value = data.stock;
            document.getElementById('p_area').value = data.id_area;
        } else {
            // Modo Agregar: Limpiar formulario
            document.getElementById('modalTitle').innerText = 'Nuevo Producto';
            document.getElementById('formAction').value = 'add';
            form.reset();
        }
    }

    function closeModal() {
        document.getElementById('productModal').classList.add('hidden');
    }
    
    // Cerrar modal al hacer clic fuera 1234
    document.getElementById('productModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
</script>
</body>
</html>