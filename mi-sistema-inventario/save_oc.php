<?php
session_start();
require_once 'conexion.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validación de sesión para evitar los Warnings
    $id_usuario = $_SESSION['id_usuario'] ?? 1; // 1 como respaldo (admin)
    $id_area_destino = $_POST['id_area_destino'] ?? ($_SESSION['id_area'] ?? 1);
    
    $id_proveedor = $_POST['id_proveedor'] ?? null;
    $tipo_presupuesto = $_POST['tipo_presupuesto'] ?? ''; 
    $items = $_POST['items'] ?? []; 

    if (empty($items)) {
        die("Error: La orden no tiene productos añadidos.");
    }

    try {
        $pdo->beginTransaction();

        // 1. Generar número de OC
        $res = $pdo->query("SELECT MAX(id_orden_compra) as last_id FROM orden_compra");
        $last_id = $res->fetch()['last_id'] ?? 0;
        $numero_oc = "OC-" . date("Y") . "-" . str_pad($last_id + 1, 4, "0", STR_PAD_LEFT);

        // 2. Insertar Cabecera (Usando el área seleccionada o la del usuario)
        $sqlOC = "INSERT INTO orden_compra (numero_oc, id_proveedor, tipo_presupuesto, id_area, id_usuario, estado_oc) 
                  VALUES (?, ?, ?, ?, ?, 'Pendiente')";
        $stmtOC = $pdo->prepare($sqlOC);
        $stmtOC->execute([
            $numero_oc, 
            $id_proveedor, 
            $tipo_presupuesto, 
            $id_area_destino, 
            $id_usuario
        ]);
        
        $id_orden_nueva = $pdo->lastInsertId();

        // 3. Procesar Productos
        foreach ($items as $item) {
            $nombre_prod = trim($item['nombre_producto']);
            
            // BUSCAR SI EXISTE
            $stmtSearch = $pdo->prepare("SELECT id_producto FROM producto WHERE nombre = ? LIMIT 1");
            $stmtSearch->execute([$nombre_prod]);
            $prod = $stmtSearch->fetch();

        if ($prod) {
            $id_prod_final = $prod['id_producto'];
        } else {
            // SI NO EXISTE, LO CREAMOS SIN EL CAMPO PRECIO (Para evitar el error 1054)
            // Solo le pasamos nombre, stock 0 y el área
            $sqlNuevoProd = "INSERT INTO producto (nombre, stock, id_area) VALUES (?, 0, ?)";
            $stmtNP = $pdo->prepare($sqlNuevoProd);
            $stmtNP->execute([$nombre_prod, $id_area_destino]);
            $id_prod_final = $pdo->lastInsertId();
        }
            // Insertar en el detalle de la OC
            $sqlDetalle = "INSERT INTO detalle_orden_compra (id_orden_compra, id_producto, cantidad, precio_unitario_compra) 
                           VALUES (?, ?, ?, ?)";
            $stmtDetalle = $pdo->prepare($sqlDetalle);
            $stmtDetalle->execute([
                $id_orden_nueva, 
                $id_prod_final, 
                $item['cantidad'], 
                $item['precio']
            ]);
        }

        $pdo->commit();
        header("Location: index.php?tab=purchases&msg=oc_creada&numero=" . $numero_oc);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("Error crítico: " . $e->getMessage());
    }
}