<?php
session_start();

// Configuración de conexión (Ajusta si es necesario)
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root'; 
$pass = 'mysql'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_producto     = $_POST['id_producto'];
    $id_area_destino = $_POST['id_area_destino'];
    $id_area_origen  = $_POST['id_area_origen'];
    $cantidad        = intval($_POST['cantidad']);
    $motivo          = !empty($_POST['motivo']) ? $_POST['motivo'] : 'Traslado de suministros';
    $id_usuario      = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        // 1. Obtener datos completos del producto de origen
        // Necesitamos SKU y otros datos por si hay que crear el producto en la nueva área
        $stmt = $pdo->prepare("SELECT * FROM producto WHERE id_producto = ?");
        $stmt->execute([$id_producto]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) throw new Exception("El producto no existe.");
        if ($producto['stock'] < $cantidad) throw new Exception("Stock insuficiente en el área de origen.");

        // 2. LOGICA DE TRASPASO ENTRE ÁREAS
        
        // A. Restar stock del producto original (Origen)
        $nuevoStockOrigen = $producto['stock'] - $cantidad;
        $stmtRestar = $pdo->prepare("UPDATE producto SET stock = ? WHERE id_producto = ?");
        $stmtRestar->execute([$nuevoStockOrigen, $id_producto]);

        // B. Gestionar el destino
        $areaDestinoFinal = ($id_area_destino === "NULL") ? null : $id_area_destino;

        // Buscamos si ya existe ese mismo producto (por SKU) en el área de destino
        $stmtBusca = $pdo->prepare("SELECT id_producto, stock FROM producto WHERE sku = ? AND id_area <=> ?");
        $stmtBusca->execute([$producto['sku'], $areaDestinoFinal]);
        $productoDestino = $stmtBusca->fetch(PDO::FETCH_ASSOC);

        if ($productoDestino) {
            // Si ya existe en esa área, simplemente sumamos el stock
            $nuevoStockDestino = $productoDestino['stock'] + $cantidad;
            $stmtSumar = $pdo->prepare("UPDATE producto SET stock = ? WHERE id_producto = ?");
            $stmtSumar->execute([$nuevoStockDestino, $productoDestino['id_producto']]);
        } else {
            // Si NO existe en esa área, creamos el nuevo registro con los datos del original
            $stmtInsert = $pdo->prepare("INSERT INTO producto (nombre, descripcion, stock, sku, precio_referencial, estado, id_area, precio) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([
                $producto['nombre'],
                $producto['descripcion'],
                $cantidad, // Empezamos con la cantidad traspasada
                $producto['sku'],
                $producto['precio_referencial'],
                $producto['estado'],
                $areaDestinoFinal,
                $producto['precio']
            ]);
        }

        // 3. Insertar en tabla 'movimiento'
        // fecha, tipo, observacion, motivo, id_usuario, id_area_origen, id_area
        $stmtMov = $pdo->prepare("INSERT INTO movimiento (fecha, tipo, observacion, motivo, id_usuario, id_area_origen, id_area) 
                                 VALUES (NOW(), 'SALIDA', ?, ?, ?, ?, ?)");
        
        $obs = "Traspaso de $cantidad unidades de " . $producto['nombre'];
        $orig = ($id_area_origen === "NULL") ? null : $id_area_origen;
        $dest = ($id_area_destino === "NULL") ? null : $id_area_destino;
        
        $stmtMov->execute([$obs, $motivo, $id_usuario, $orig, $dest]);
        
        // 4. Insertar en tabla 'detalle_movimiento'
        // id_movimiento, id_producto, cantidad
        $idMov = $pdo->lastInsertId();
        $stmtDet = $pdo->prepare("INSERT INTO detalle_movimiento (id_movimiento, id_producto, cantidad) VALUES (?, ?, ?)");
        $stmtDet->execute([$idMov, $id_producto, $cantidad]);

        $pdo->commit();
        header("Location: index.php?tab=movements&status=success");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: index.php?tab=movements&status=error&msg=" . urlencode($e->getMessage()));
    }
    exit;
}