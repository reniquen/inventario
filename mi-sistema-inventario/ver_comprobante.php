<?php
require_once 'conexion.php'; // Tu archivo de conexión real

$id = $_GET['id'] ?? 1; // ID de la orden

// Consulta de la orden y sus productos
$oc = $pdo->prepare("SELECT oc.*, p.nombre as proveedor, p.rut, a.nombre_area 
                    FROM orden_compra oc 
                    JOIN proveedor p ON oc.id_proveedor = p.id_proveedor
                    JOIN area a ON oc.id_area = a.id_area 
                    WHERE oc.id_orden_compra = ?");
$oc->execute([$id]);
$datos = $oc->fetch();

$items = $pdo->prepare("SELECT * FROM detalle_orden_compra WHERE id_orden_compra = ?");
$items->execute([$id]);
$productos = $items->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Comprobante de Recepción - <?= $datos['numero_oc'] ?></title>
</head>
<body class="bg-slate-100 p-10">
    <div class="max-w-2xl mx-auto bg-white p-8 shadow-lg border-t-8 border-emerald-600">
        <div class="flex justify-between items-start mb-8">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">COMPROBANTE DE INGRESO</h1>
                <p class="text-emerald-600 font-mono font-bold">N° RECEPCIÓN: REC-<?= date('Y') ?>-<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></p>
            </div>
            <div class="text-right text-sm text-slate-500">
                <p>MUNICIPALIDAD DE CODEGUA</p>
                <p>Fecha: <?= date('d/m/Y H:i') ?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-8 text-sm">
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-slate-500 uppercase text-[10px] font-bold">Proveedor</p>
                <p class="font-bold"><?= htmlspecialchars($datos['proveedor']) ?></p>
                <p>RUT: <?= $datos['rut'] ?? '60.101.000-k' ?></p>
            </div>
            <div class="bg-slate-50 p-4 rounded-lg">
                <p class="text-slate-500 uppercase text-[10px] font-bold">Referencia OC</p>
                <p class="font-bold text-indigo-600"><?= $datos['numero_oc'] ?></p>
                <p>Área: <?= htmlspecialchars($datos['nombre_area']) ?></p>
            </div>
        </div>

        <table class="w-full mb-8">
            <thead>
                <tr class="border-b-2 border-slate-100 text-left text-[10px] uppercase text-slate-400">
                    <th class="py-2">Descripción</th>
                    <th class="py-2 text-center">Cant.</th>
                    <th class="py-2 text-right">Unitario</th>
                    <th class="py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="text-sm">
                <?php $total = 0; foreach($productos as $p): 
                    $subtotal = $p['cantidad'] * $p['precio'];
                    $total += $subtotal;
                ?>
                <tr class="border-b border-slate-50">
                    <td class="py-3 text-slate-700"><?= htmlspecialchars($p['nombre_producto']) ?></td>
                    <td class="py-3 text-center"><?= $p['cantidad'] ?></td>
                    <td class="py-3 text-right">$<?= number_format($p['precio'], 0, ',', '.') ?></td>
                    <td class="py-3 text-right font-bold">$<?= number_format($subtotal, 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="flex justify-between items-center bg-slate-900 text-white p-4 rounded-xl">
            <span class="font-bold uppercase tracking-widest text-xs">Total Ingresado</span>
            <span class="text-xl font-bold">$<?= number_format($total, 0, ',', '.') ?></span>
        </div>

        <div class="mt-12 text-center border-t border-slate-100 pt-8">
            <div class="inline-block border-b border-slate-400 w-48 mb-2"></div>
            <p class="text-[10px] uppercase font-bold text-slate-500">Firma Encargado de Bodega</p>
        </div>
    </div>
    
    <div class="text-center mt-6 no-print">
        <button onclick="window.print()" class="bg-slate-800 text-white px-6 py-2 rounded-lg font-bold hover:bg-slate-700 transition-all">
            🖨️ Imprimir Comprobante
        </button>
    </div>
</body>
</html>