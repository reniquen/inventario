<?php

require_once 'dompdf/autoload.inc.php'; // Asegúrate de tener la carpeta dompdf
use Dompdf\Dompdf;

$dompdf = new Dompdf();
// Aquí va todo tu código HTML de la OC
$html =  '
<div style="font-family: sans-serif; padding: 30px;">
    <h1 style="color: #059669;">Orden de Compra #'.$oc['numero_orden'].'</h1>
    <p><strong>Fecha:</strong> '.$oc['fecha'].'</p>
    <hr>
    <table style="width: 100%; border-collapse: collapse;">
        <tr style="background: #f8fafc;">
            <th style="padding: 10px; border: 1px solid #e2e8f0;">Producto</th>
            <th style="padding: 10px; border: 1px solid #e2e8f0;">Cantidad</th>
            <th style="padding: 10px; border: 1px solid #e2e8f0;">Proveedor</th>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #e2e8f0;">'.$oc['producto'].'</td>
            <td style="padding: 10px; border: 1px solid #e2e8f0;">'.$oc['cantidad'].'</td>
            <td style="padding: 10px; border: 1px solid #e2e8f0;">'.$oc['proveedor'].'</td>
        </tr>
    </table>
    <div style="margin-top: 50px; text-align: center;">
        <p>__________________________</p>
        <p>Firma Encargado Adquisiciones</p>
    </div>
</div>'; 

$dompdf->loadHtml($html);
$dompdf->render();

// El nombre del archivo que se descargará
$dompdf->stream("OC_Codegua_" . $oc['numero_oc'] . ".pdf", ["Attachment" => true]);