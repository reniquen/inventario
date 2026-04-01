<?php
// Cargar la librería (Ajusta la ruta según tu carpeta)
require_once 'dompdf/autoload.inc.php'; 
use Dompdf\Dompdf;
use Dompdf\Options;

include 'conexion.php'; 

$id_oc = $_GET['id'] ?? null;
if (!$id_oc) die("ID no válido");

// 1. Obtener datos (Misma consulta que en print_oc.php)
$stmt = $pdo->prepare("
    SELECT oc.*, prov.nombre as proveedor, a.nombre_area
    FROM orden_compra oc
    JOIN proveedor prov ON oc.id_proveedor = prov.id_proveedor
    JOIN area a ON oc.id_area = a.id_area
    WHERE oc.id_orden_compra = ?
");
$stmt->execute([$id_oc]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);

$stmtDet = $pdo->prepare("
    SELECT d.*, p.nombre as producto_nombre
    FROM detalle_orden_compra d
    JOIN producto p ON d.id_producto = p.id_producto
    WHERE d.id_orden_compra = ?
");
$stmtDet->execute([$id_oc]);
$productos = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

// 2. Preparar el HTML en una variable
ob_start(); // Iniciamos buffer para capturar el HTML
?>
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        .header { text-align: center; border-bottom: 2px solid #333; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eee; padding: 8px; border: 1px solid #ccc; }
        td { padding: 8px; border: 1px solid #ccc; }
        .total { font-weight: bold; text-align: right; background: #f9f9f9; }
    </style>
</head>
<body>
    <div class="header">
        <h2>MUNICIPALIDAD DE CODEGUA</h2>
        <h3>ORDEN DE COMPRA N° <?php echo $oc['numero_oc']; ?></h3>
    </div>
    <p><strong>Proveedor:</strong> <?php echo $oc['proveedor']; ?></p>
    <p><strong>Área:</strong> <?php echo $oc['nombre_area']; ?></p>
    <p><strong>Fecha:</strong> <?php echo date("d/m/Y", strtotime($oc['fecha'])); ?></p>

    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th>Cant.</th>
                <th>Precio</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total = 0;
            foreach ($productos as $p): 
                $sub = $p['cantidad'] * $p['precio_unitario_compra'];
                $total += $sub;
            ?>
            <tr>
                <td><?php echo $p['producto_nombre']; ?></td>
                <td><?php echo $p['cantidad']; ?></td>
                <td>$<?php echo number_format($p['precio_unitario_compra'], 0, ',', '.'); ?></td>
                <td>$<?php echo number_format($sub, 0, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3" class="total">TOTAL:</td>
                <td class="total">$<?php echo number_format($total, 0, ',', '.'); ?></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
<?php
$html = ob_get_clean(); // Guardamos el HTML en la variable

// 3. Configurar Dompdf y generar
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// 4. Salida al navegador (Descarga)
$dompdf->stream("Orden_Compra_".$oc['numero_oc'].".pdf", ["Attachment" => true]);
?>