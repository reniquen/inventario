<?php
include 'conexion.php'; 

$id_oc = $_GET['id'] ?? null;

if (!$id_oc) {
    die("Error: No se proporcionó un ID de Orden de Compra.");
}

// 1. Obtenemos la cabecera (Datos generales y Área)
$stmtCabecera = $pdo->prepare("
    SELECT oc.*, prov.nombre as proveedor, a.nombre_area
    FROM orden_compra oc
    JOIN proveedor prov ON oc.id_proveedor = prov.id_proveedor
    JOIN area a ON oc.id_area = a.id_area
    WHERE oc.id_orden_compra = ?
");
$stmtCabecera->execute([$id_oc]);
$oc = $stmtCabecera->fetch(PDO::FETCH_ASSOC);

if (!$oc) {
    die("Error: Orden de Compra no encontrada.");
}

// 2. Obtenemos TODOS los productos de esta orden
$stmtDetalle = $pdo->prepare("
    SELECT d.*, p.nombre as producto_nombre
    FROM detalle_orden_compra d
    JOIN producto p ON d.id_producto = p.id_producto
    WHERE d.id_orden_compra = ?
");
$stmtDetalle->execute([$id_oc]);
$productos = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden de Compra <?= $oc['numero_oc'] ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f1f5f9; margin: 0; padding: 20px; }
        @media print {
            .no-print { display: none; }
            body { background-color: white; padding: 0; }
            .document-box { border: 1px solid #000; box-shadow: none; margin: 0; width: 100%; }
        }
        .document-box { 
            background: white; 
            border: 1px solid #e2e8f0; 
            padding: 40px; 
            max-width: 850px; 
            margin: auto; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .header { text-align: center; border-bottom: 3px solid #1e293b; margin-bottom: 30px; padding-bottom: 10px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .info-item { font-size: 14px; color: #334155; }
        .info-item strong { color: #0f172a; text-transform: uppercase; font-size: 12px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8fafc; color: #475569; text-transform: uppercase; font-size: 11px; padding: 12px; border: 1px solid #e2e8f0; }
        td { padding: 12px; border: 1px solid #e2e8f0; font-size: 13px; }
        .text-right { text-align: right; }
        .footer-sigs { margin-top: 80px; display: flex; justify-content: space-around; }
        .signature { text-align: center; border-top: 1px solid #94a3b8; width: 220px; padding-top: 10px; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:center; margin-bottom:20px; display:flex; justify-content:center; gap:10px;">
        <button onclick="window.print()" style="background:#4f46e5; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer;">
            🖨️ Imprimir
        </button>

        <a href="download_oc.php?id=<?= $id_oc ?>" style="background:#0ea5e9; color:white; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold;">
            📥 Descargar PDF
        </a>
    </div>

    <div class="document-box">
        <div class="header">
            <h2 style="margin:0; color:#1e293b;">MUNICIPALIDAD DE CODEGUA</h2>
            <h4 style="margin:5px 0; color:#64748b; font-weight:normal;">Departamento de Adquisiciones e Inventario</h4>
            <h3 style="margin:15px 0 0 0; color:#4f46e5;">ORDEN DE COMPRA N° <?= $oc['numero_oc'] ?></h3>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <strong>Proveedor:</strong><br><?= htmlspecialchars($oc['proveedor']) ?>
            </div>
            <div class="info-item">
                <strong>Área de Destino:</strong><br><?= htmlspecialchars($oc['nombre_area']) ?>
            </div>
            <div class="info-item">
                <<strong>Fecha Emisión:</strong><br><?= isset($oc['fecha_creacion']) ? date("d/m/Y", strtotime($oc['fecha_creacion'])) : date("d/m/Y") ?>
            <div class="info-item">
                <strong>Estado:</strong><br><?= $oc['estado_oc'] ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="50%">Descripción del Producto</th>
                    <th width="15%">Cantidad</th>
                    <th width="15%">P. Unitario</th>
                    <th width="20%">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_general = 0;
                foreach ($productos as $p): 
                    $subtotal = $p['cantidad'] * $p['precio_unitario_compra'];
                    $total_general += $subtotal;
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['producto_nombre']) ?></td>
                    <td class="text-right"><?= $p['cantidad'] ?></td>
                    <td class="text-right">$<?= number_format($p['precio_unitario_compra'], 0, ',', '.') ?></td>
                    <td class="text-right">$<?= number_format($subtotal, 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f8fafc; font-weight:bold;">
                    <td colspan="3" class="text-right">TOTAL ORDEN:</td>
                    <td class="text-right" style="color:#4f46e5; font-size:16px;">$<?= number_format($total_general, 0, ',', '.') ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:40px; font-size:12px; color:#64748b;">
            <p><strong>Nota:</strong> Esta orden de compra debe ser presentada al momento de la entrega de productos en la bodega municipal.</p>
        </div>

        <div class="footer-sigs">
            <div class="signature">Firma Jefe de Adquisiciones</div>
            <div class="signature">Timbre de Recepción Bodega</div>
        </div>
    </div>
</body>
</html>