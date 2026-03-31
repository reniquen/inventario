<?php
session_start();
if (!isset($_SESSION['user_id'])) { die("Acceso denegado"); }

$host = 'localhost'; $db = 'stockmaster_db'; $user = 'root'; $pass = 'mysql';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { die("Error: " . $e->getMessage()); }

$id = $_GET['id'] ?? 0;

// Consulta extendida para traer firmas y nombres
$stmt = $pdo->prepare("SELECT oc.*, p.nombre as proveedor, p.rut as proveedor_rut, a.nombre_area 
                       FROM orden_compra oc 
                       LEFT JOIN proveedor p ON oc.id_proveedor = p.id_proveedor 
                       LEFT JOIN area a ON oc.id_area = a.id_area
                       WHERE oc.id_orden_compra = ?");
$stmt->execute([$id]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oc) { die("Orden de compra no encontrada."); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Decreto Municipal OC #<?= $oc['numero_oc'] ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; padding: 40px; color: #333; line-height: 1.6; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .muni-name { font-weight: bold; text-transform: uppercase; font-size: 18px; }
        .doc-title { font-size: 22px; font-weight: bold; margin: 20px 0; }
        .section { margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .firmas { margin-top: 80px; display: flex; justify-content: space-around; text-align: center; }
        .firma-box { border-top: 1px solid #000; width: 200px; padding-top: 5px; font-size: 12px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #4f46e5; color: white; border: none; border-radius: 5px; cursor: pointer;">🖨️ Imprimir Decreto</button>
    </div>

    <div class="header">
        <div class="muni-name">Ilustre Municipalidad de Codegua</div>
        <div>Región del Libertador General Bernardo O'Higgins</div>
    </div>

    <center class="doc-title">ORDEN DE COMPRA MUNICIPAL N° <?= htmlspecialchars($oc['numero_oc']) ?></center>

    <div class="section">
        <div class="grid">
            <div>
                <strong>ÁREA SOLICITANTE:</strong> <?= htmlspecialchars($oc['nombre_area']) ?><br>
                <strong>FECHA DE EMISIÓN:</strong> <?= date('d/m/Y', strtotime($oc['fecha_creacion'] ?? 'now')) ?>
            </div>
            <div>
                <strong>PROVEEDOR:</strong> <?= htmlspecialchars($oc['proveedor']) ?><br>
                <strong>RUT:</strong> <?= htmlspecialchars($oc['proveedor_rut'] ?? 'S/R') ?>
            </div>
        </div>
    </div>

    <div class="section">
        <strong>VISTOS:</strong> Las facultades que me confiere la Ley N° 18.695, Orgánica Constitucional de Municipalidades; la Ley N° 19.886 de Bases sobre Contratos Administrativos de Suministro y Prestación de Servicios.
    </div>

    <table>
        <thead>
            <tr>
                <th>Descripción</th>
                <th>Estado del Flujo</th>
                <th>Total Bruto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Adquisición de insumos según solicitud de departamento.</td>
                <td><?= htmlspecialchars($oc['estado_flujo']) ?></td>
                <td>$<?= number_format($oc['total'] ?? 0, 0, ',', '.') ?> CLP</td>
            </tr>
        </tbody>
    </table>

    <div class="firmas">
        <div class="firma-box">
            V°B° FINANZAS<br>
            <?= $oc['vobo_finanzas'] ? 'FECHA: '.date('d/m/Y', strtotime($oc['vobo_finanzas'])) : 'PENDIENTE' ?>
        </div>
        <div class="firma-box">
            V°B° CONTROL INTERNO<br>
            <?= $oc['vobo_control'] ? 'FECHA: '.date('d/m/Y', strtotime($oc['vobo_control'])) : 'PENDIENTE' ?>
        </div>
        <div class="firma-box">
            FIRMA ALCALDÍA<br>
            <?= $oc['vobo_alcaldia'] ? 'FECHA: '.date('d/m/Y', strtotime($oc['vobo_alcaldia'])) : 'PENDIENTE' ?>
        </div>
    </div>

    <div style="margin-top: 50px; font-size: 10px; color: #666; text-align: center;">
        Documento generado por StockMaster Pro - Sistema de Gestión Municipal
    </div>
</body>
</html>