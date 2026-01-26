<?php
// Conexión a la base de datos
$db = new PDO('mysql:host=localhost;dbname=stockmaster_db', 'root', '');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id_producto'];
    $nombre = $_POST['nombre'];
    $nombre_imagen = null;

    // Verificar si se subió una imagen
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['imagen']['tmp_name'];
        $fileName = $_FILES['imagen']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Generar un nombre único para la imagen (ej: 1_162534.jpg)
        $nuevoNombreImagen = $id . "_" . time() . "." . $fileExtension;
        
        // Ruta de destino
        $dest_path = "assets/img/productos/" . $nuevoNombreImagen;

        // Mover el archivo de la carpeta temporal a la carpeta del proyecto
        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $nombre_imagen = $nuevoNombreImagen;
        }
    }

    // Actualizar Base de Datos
    if ($nombre_imagen) {
        // Si hay imagen nueva, actualizamos nombre e imagen
        $sql = "UPDATE producto SET nombre = ?, imagen_path = ? WHERE id_producto = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$nombre, $nombre_imagen, $id]);
    } else {
        // Si no se subió imagen, solo actualizamos el nombre
        $sql = "UPDATE producto SET nombre = ? WHERE id_producto = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$nombre, $id]);
    }

    header("Location: index.php?status=success");
}
?>