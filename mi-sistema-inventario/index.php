<?php
// 1. Configuración de Roles y Áreas
if (!defined('ROLES')) {
    define('ROLES', [
        'ADMIN' => 'admin',
        'ENCARGADO' => 'encargado',
        'CONSULTOR' => 'consultor'
    ]);
}

$AREAS = ['Electrónica', 'Hogar', 'Deportes', 'Moda'];

// 2. Simulación de Estado
$currentRole  = $_GET['role']   ?? ROLES['ADMIN'];
$activeTab    = $_GET['tab']    ?? 'dashboard';
$searchTerm   = $_GET['search'] ?? '';
$assignedArea = 'Electrónica';

$inventory = [
    ['id' => 1, 'name' => 'Laptop Pro 14"', 'stock' => 15, 'area' => 'Electrónica', 'status' => 'Disponible'],
    ['id' => 2, 'name' => 'Monitor 4K 27"', 'stock' => 8, 'area' => 'Electrónica', 'status' => 'Bajo Stock'],
    ['id' => 3, 'name' => 'Sofá Minimalista', 'stock' => 4, 'area' => 'Hogar', 'status' => 'Disponible'],
    ['id' => 4, 'name' => 'Balón de Fútbol N5', 'stock' => 45, 'area' => 'Deportes', 'status' => 'Disponible'],
    ['id' => 5, 'name' => 'Camiseta Running', 'stock' => 2, 'area' => 'Moda', 'status' => 'Crítico'],
];

// 3. Filtrado lógico
$filteredInventory = array_filter($inventory, function($item) use ($searchTerm, $currentRole, $assignedArea) {
    $matchesSearch = empty($searchTerm) || stripos($item['name'], $searchTerm) !== false;
    if ($currentRole === ROLES['ENCARGADO']) {
        return $matchesSearch && $item['area'] === $assignedArea;
    }
    return $matchesSearch;
});

function lucideIcon($name, $class = "") {
    // Retornamos el tag i con el atributo necesario para Lucide
    return "<i data-lucide='{$name}' class='inline-block {$class}'></i>";
}
?>

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StockMaster Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style type="text/tailwindcss">
        @layer base {
            body { @apply bg-slate-950 text-slate-100 antialiased; }
        }
        /* Fix para evitar parpadeo de iconos */
        [data-lucide] { width: 20px; height: 20px; }
    </style>
</head>
<body class="h-full overflow-hidden">

<div class="flex h-screen overflow-hidden">
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col shrink-0">
        <div class="flex items-center gap-3 px-6 py-8">
            <div class="p-2 bg-indigo-600 rounded-xl shadow-lg shadow-indigo-500/20">
                <?= lucideIcon('package', 'text-white w-6 h-6') ?>
            </div>
            <span class="text-xl font-bold tracking-tight text-white">StockMaster</span>
        </div>

        <nav class="flex-1 px-4 space-y-1">
            <?php
            $menuItems = [
                ['id' => 'dashboard', 'label' => 'Panel General', 'icon' => 'layout-dashboard', 'roles' => [ROLES['ADMIN'], ROLES['ENCARGADO'], ROLES['CONSULTOR']]],
                ['id' => 'inventory', 'label' => 'Inventario', 'icon' => 'boxes', 'roles' => [ROLES['ADMIN'], ROLES['ENCARGADO'], ROLES['CONSULTOR']]],
                ['id' => 'users', 'label' => 'Usuarios', 'icon' => 'users', 'roles' => [ROLES['ADMIN']]],
            ];

            foreach ($menuItems as $item): 
                if (in_array($currentRole, $item['roles'])): 
                    $isActive = ($activeTab === $item['id']);
                ?>
                    <a href="?tab=<?= $item['id'] ?>&role=<?= $currentRole ?>" 
                       class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 <?= $isActive ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:bg-slate-800 hover:text-white' ?>">
                        <?= lucideIcon($item['icon'], 'w-5 h-5') ?>
                        <span class="font-medium"><?= $item['label'] ?></span>
                    </a>
                <?php endif; 
            endforeach; ?>
        </nav>

        <div class="p-4 border-t border-slate-800">
            <div class="bg-slate-800/50 p-4 rounded-2xl border border-slate-700/50 mb-4">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Sesión iniciada como</p>
                <div class="flex items-center gap-2 mt-2">
                    <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                    <span class="text-sm font-bold text-indigo-400 capitalize"><?= $currentRole ?></span>
                </div>
            </div>
            <button class="w-full flex items-center justify-center gap-2 px-4 py-3 text-sm font-semibold text-red-400 hover:bg-red-500/10 rounded-xl transition-colors">
                <?= lucideIcon('log-out', 'w-4 h-4') ?>
                Cerrar Sesión
            </button>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 bg-slate-950">
        <header class="h-20 bg-slate-900/50 backdrop-blur-xl border-b border-slate-800 px-8 flex items-center justify-between sticky top-0 z-10">
            <div class="flex items-center gap-4">
                <span class="text-xs font-bold text-slate-500 tracking-widest uppercase">Simulador de vista</span>
                <div class="flex bg-slate-800 p-1 rounded-lg border border-slate-700">
                    <?php foreach (ROLES as $key => $val): ?>
                        <a href="?role=<?= $val ?>&tab=<?= $activeTab ?>" 
                           class="px-3 py-1.5 rounded-md text-[10px] font-black uppercase transition-all <?= $currentRole === $val ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200' ?>">
                            <?= $key ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <form action="" method="GET" class="relative group">
                <input type="hidden" name="role" value="<?= $currentRole ?>">
                <input type="hidden" name="tab" value="<?= $activeTab ?>">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500 group-focus-within:text-indigo-400 transition-colors">
                    <?= lucideIcon('search', 'w-4 h-4') ?>
                </div>
                <input type="text" name="search" placeholder="Buscar en el sistema..." value="<?= htmlspecialchars($searchTerm) ?>"
                       class="bg-slate-800/50 border border-slate-700 rounded-xl pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 w-72 text-white placeholder-slate-500 transition-all">
            </form>
        </header>

        <div class="p-8 overflow-y-auto">
            <div class="max-w-6xl mx-auto space-y-8">
                
                <?php if ($activeTab === 'dashboard'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="bg-slate-900 border border-slate-800 p-6 rounded-3xl relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                                <?= lucideIcon('package', 'w-12 h-12') ?>
                            </div>
                            <h3 class="text-slate-400 text-xs font-bold uppercase tracking-widest">Total Productos</h3>
                            <p class="text-4xl font-black mt-2"><?= count($inventory) ?></p>
                            <div class="mt-4 flex items-center text-emerald-400 text-xs font-bold">
                                <?= lucideIcon('trending-up', 'w-3 h-3 mr-1') ?> +12.5% este mes
                            </div>
                        </div>
                        <div class="bg-slate-900 border border-slate-800 p-6 rounded-3xl group">
                            <h3 class="text-slate-400 text-xs font-bold uppercase tracking-widest">Stock Crítico</h3>
                            <p class="text-4xl font-black mt-2 text-red-500"><?= count(array_filter($inventory, fn($i) => $i['stock'] < 5)) ?></p>
                            <p class="mt-4 text-slate-500 text-xs">Requieren atención inmediata</p>
                        </div>
                        <div class="bg-slate-900 border border-slate-800 p-6 rounded-3xl group">
                            <h3 class="text-slate-400 text-xs font-bold uppercase tracking-widest">Áreas Activas</h3>
                            <p class="text-4xl font-black mt-2 text-indigo-400"><?= count($AREAS) ?></p>
                            <p class="mt-4 text-slate-500 text-xs">Distribución global</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-slate-900 border border-slate-800 rounded-3xl shadow-xl overflow-hidden">
                    <div class="p-6 border-b border-slate-800 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-900/50">
                        <div>
                            <h2 class="text-xl font-bold text-white tracking-tight">Listado de Inventario</h2>
                            <p class="text-slate-500 text-xs mt-1">Gestión de existencias y estados de producto.</p>
                        </div>
                        <?php if ($currentRole === ROLES['ADMIN']): ?>
                            <button class="bg-indigo-600 hover:bg-indigo-500 text-white px-5 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all flex items-center gap-2">
                                <?= lucideIcon('plus', 'w-4 h-4') ?> Nuevo Artículo
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-slate-500 text-[10px] font-black uppercase tracking-widest border-b border-slate-800 bg-slate-800/30">
                                    <th class="px-6 py-4">Información del Producto</th>
                                    <th class="px-6 py-4 text-center">Área</th>
                                    <th class="px-6 py-4 text-center">Stock</th>
                                    <th class="px-6 py-4 text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/50">
                                <?php foreach ($filteredInventory as $item): ?>
                                <tr class="hover:bg-indigo-500/[0.02] transition-colors group">
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-slate-100 group-hover:text-indigo-400 transition-colors"><?= htmlspecialchars($item['name']) ?></div>
                                        <div class="text-[10px] text-slate-500 font-mono mt-0.5 uppercase tracking-tighter">SKU: #INV-00<?= $item['id'] ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="text-xs font-semibold text-slate-300 bg-slate-800 px-3 py-1 rounded-full border border-slate-700"><?= $item['area'] ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="font-mono font-black text-lg <?= $item['stock'] < 5 ? 'text-red-500' : 'text-slate-200' ?>">
                                            <?= $item['stock'] ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-center gap-2">
                                            <?php if ($currentRole === ROLES['CONSULTOR']): ?>
                                                <button class="p-2 text-slate-500 hover:text-white transition-colors" title="Ver"><?= lucideIcon('eye', 'w-5 h-5') ?></button>
                                            <?php else: ?>
                                                <button class="p-2 text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all" title="Editar"><?= lucideIcon('edit-3', 'w-5 h-5') ?></button>
                                                <?php if ($currentRole === ROLES['ADMIN']): ?>
                                                    <button class="p-2 text-red-400 hover:bg-red-500/10 rounded-lg transition-all" title="Eliminar"><?= lucideIcon('trash-2', 'w-5 h-5') ?></button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Inicializar iconos
    lucide.createIcons();
</script>
</body>
</html>