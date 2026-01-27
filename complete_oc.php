<?php
// ... conexión ...
$id = $_GET['id'];
$stmt = $pdo->prepare("UPDATE orden_compra SET estado_oc = 'Completada' WHERE id_orden_compra = ?");
$stmt->execute([$id]);
// Al ejecutarse este UPDATE, el TRIGGER de arriba saltará solo y subirá el stock.
header("Location: index.php?tab=purchases&status=updated");