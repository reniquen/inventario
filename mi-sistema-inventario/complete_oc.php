<?php
session_start();
require_once 'db_connection.php';

$id = $_GET['id'];

try {
    // Iniciamos una transacción para que si algo falla, no se guarde nada a medias
    $pdo->beginTransaction();

    // 1. Cambiar el estado de la Orden de Compra
    $stmt1 = $pdo->prepare("UPDATE orden_compra SET estado_oc = 'Completada' WHERE id_orden_compra = ?");
    $stmt1->execute([$id]);

    // 2. Aumentar el stock de los productos (lo que antes hacía el trigger)
    $stmt2 = $pdo->prepare("
        UPDATE producto p
        INNER JOIN detalle_orden_compra d ON p.id_producto = d.id_producto
        SET p.stock = p.stock + d.cantidad
        WHERE d.id_orden_compra = ?
    ");
    $stmt2->execute([$id]);

    // 3. Crear el historial de movimiento (lo que antes hacía el trigger)
    $stmt3 = $pdo->prepare("
        INSERT INTO movimiento (id_usuario, tipo, motivo, fecha, id_orden_compra)
        SELECT id_usuario, 'ENTRADA', CONCAT('Ingreso por OC #', numero_oc), NOW(), id_orden_compra
        FROM orden_compra
        WHERE id_orden_compra = ?
    ");
    $stmt3->execute([$id]);

    // Si todo salió bien, guardamos los cambios
    $pdo->commit();

    header("Location: index.php?tab=purchases&status=updated");

} catch (Exception $e) {
    // Si algo falla (ej. error de conexión), deshacemos todo para no descuadrar el stock
    $pdo->rollBack();
    echo "Error al actualizar: " . $e->getMessage();
}
?>