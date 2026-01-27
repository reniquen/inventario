<?php

// 1. Conexión (usa tu archivo de conexión o pega los datos aquí)
include 'conexion.php'; 

// 2. Obtener el ID de la URL
$id_oc = $_GET['id'] ?? null;

if (!$id_oc) {
    die("Error: No se proporcionó un ID de Orden de Compra.");
}

// 3. Consulta a la base de datos (IMPORTANTE: Debe llamarse $oc)
$stmt = $pdo->prepare("
    SELECT oc.*, p.nombre as producto, d.cantidad, prov.nombre as proveedor
    FROM orden_compra oc
    JOIN detalle_orden_compra d ON oc.id_orden_compra = d.id_orden_compra
    JOIN producto p ON d.id_producto = p.id_producto
    JOIN proveedor prov ON oc.id_proveedor = prov.id_proveedor
    WHERE oc.id_orden_compra = ?
");
$stmt->execute([$id_oc]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oc) {
    die("Error: Orden de Compra no encontrada.");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Orden de Compra <?= $oc['numero_oc'] ?></title>
    <style>
        @media print {
            .no-print { display: none; }
            body { font-size: 12pt; color: black; }
        }
        .document-box { border: 2px solid #000; padding: 20px; max-width: 800px; margin: auto; }
        .header { text-align: center; border-bottom: 2px solid #000; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:center; margin-bottom:20px;">
        <button onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <div class="document-box">
        <div class="header">
            <h2>MUNICIPALIDAD DE CODEGUA</h2>
            <h3>ORDEN DE COMPRA N° <?= $oc['numero_oc'] ?></h3>
        </div>
        
        <p><strong>Proveedor:</strong> <?= $oc['proveedor'] ?></p>
        <p><strong>Fecha Emisión:</strong> <?= $oc['fecha'] ?></p>
        
        <table border="1" width="100%" style="border-collapse: collapse; margin-top:20px;">
            <thead>
                <tr>
                    <th>Descripción Producto</th>
                    <th>Cantidad</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= $oc['producto'] ?></td>
                    <td><?= $oc['cantidad'] ?></td>
                    <td><?= $oc['estado_oc'] ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 100px; display: flex; justify-content: space-around;">
            <div style="text-align: center; border-top: 1px solid #000; width: 200px;">Firma Adquisiciones</div>
            <div style="text-align: center; border-top: 1px solid #000; width: 200px;">Timbre Recepción</div>
        </div>
    </div>
</body>
</html>