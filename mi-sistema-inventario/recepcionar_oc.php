<?php
require_once 'conexion.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_oc = $_POST['id_orden_compra'] ?? null;
    $archivo = $_FILES['boleta'] ?? null;

    if (!$id_oc || !$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
        die("Error: No se recibió la orden o el archivo correctamente.");
    }

    try {
        $pdo->beginTransaction();

        // 1. OBTENER DATOS DE LA OC
        $stmtOC = $pdo->prepare("SELECT id_area FROM orden_compra WHERE id_orden_compra = ?");
        $stmtOC->execute([$id_oc]);
        $datosOC = $stmtOC->fetch();

        if (!$datosOC) {
            throw new Exception("La Orden de Compra no existe.");
        }
        $id_area_destino = $datosOC['id_area'];

        // 2. PROCESAR ARCHIVO
        $directorio = "uploads/documentos_oc/";
        if (!file_exists($directorio)) mkdir($directorio, 0777, true);
        $ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);
        $nombre_final = "OC_" . $id_oc . "_" . time() . "." . $ext;
        
        move_uploaded_file($archivo['tmp_name'], $directorio . $nombre_final);

        // 3. OBTENER PRODUCTOS (Agregamos precio_unitario_compra)
        $stmtItems = $pdo->prepare("SELECT id_producto, cantidad, precio_unitario_compra FROM detalle_orden_compra WHERE id_orden_compra = ?");
        $stmtItems->execute([$id_oc]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // 4. ACTUALIZAR STOCKS Y PRECIOS
        foreach ($items as $item) {
            $id_p = $item['id_producto'];
            $cant = $item['cantidad'];
            $precio_compra = $item['precio_unitario_compra']; // Nuevo: capturamos el precio

            // A. Sumar stock y ACTUALIZAR PRECIO en la tabla 'producto'
            // Usamos el precio de la OC para que deje de ser $0.00
            $nuevoSKU = "SKU-" . str_pad($id_p, 4, "0", STR_PAD_LEFT);
// Actualizamos Stock, Precio, SKU e ID_PROVEEDOR de una sola vez
            $updProd = $pdo->prepare("UPDATE producto SET 
                stock = stock + ?, 
                precio = ?, 
                precio_referencial = ?, 
                sku = IFNULL(sku, ?),
                id_proveedor = ? 
                WHERE id_producto = ?");
            $updProd->execute([$cant, $precio, $precio, $nuevoSKU, $datosOC['id_proveedor'], $id_p]);

            // B. Sumar al stock por área (Lo que lee tu INDEX)
            $updArea = $pdo->prepare("UPDATE stock_area SET cantidad = cantidad + ? WHERE id_producto = ? AND id_area = ?");
            $updArea->execute([$cant, $id_p, $id_area_destino]);

            if ($updArea->rowCount() == 0) {
                $insArea = $pdo->prepare("INSERT INTO stock_area (id_producto, id_area, cantidad) VALUES (?, ?, ?)");
                $insArea->execute([$id_p, $id_area_destino, $cant]);
            }
        }

        // 5. FINALIZAR
        $updOC = $pdo->prepare("UPDATE orden_compra SET estado_oc = 'Recibida', archivo_comprobante = ? WHERE id_orden_compra = ?");
        $updOC->execute([$nombre_final, $id_oc]);

        $pdo->commit();
        header("Location: index.php?tab=purchases&success=recibida");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("Error crítico: " . $e->getMessage());
    }
}