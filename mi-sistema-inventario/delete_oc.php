<?php
session_start();
require_once 'conexion.php';

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    try {
        $pdo->beginTransaction();

        // 1. Solo permitir eliminar si sigue Pendiente
        $check = $pdo->prepare("SELECT estado_oc FROM orden_compra WHERE id_orden_compra = ?");
        $check->execute([$id]);
        $oc = $check->fetch();

        if ($oc && $oc['estado_oc'] === 'Pendiente') {
            // 2. Eliminar detalles primero
            $pdo->prepare("DELETE FROM detalle_orden_compra WHERE id_orden_compra = ?")->execute([$id]);
            
            // 3. Eliminar cabecera
            $pdo->prepare("DELETE FROM orden_compra WHERE id_orden_compra = ?")->execute([$id]);

            $pdo->commit();
            header("Location: index.php?tab=purchases&msg=oc_eliminada");
        } else {
            throw new Exception("No se puede eliminar una orden que ya ha sido completada.");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}