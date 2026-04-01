<?php
session_start();

// 1. CONEXIÓN DB
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root';
$pass = 'mysql';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$error = "";

// 2. PROCESO DE LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "Completa todos los campos.";
    } else {
        // AGREGAMOS u.id_area a la consulta para poder usarlo después
        $stmt = $pdo->prepare("
            SELECT u.id_usuario, u.nombre, u.email, u.password, u.id_area, r.nombre_rol, a.nombre_area
            FROM usuario u
            INNER JOIN rol r ON u.id_rol = r.id_rol
            LEFT JOIN area a ON u.id_area = a.id_area
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $loginOK = false;
            if (password_verify($password, $usuario['password']) || $password === $usuario['password']) {
                $loginOK = true;
            }

            if ($loginOK) {
                // TODO ESTO DEBE IR DENTRO DEL BLOQUE DONDE EL LOGIN ES EXITOSO
                $_SESSION['user_id']      = $usuario['id_usuario'];
                $_SESSION['user_nombre']  = $usuario['nombre'];
                $_SESSION['user_rol']     = $usuario['nombre_rol']; 
                $_SESSION['user_area']    = $usuario['nombre_area'];
                $_SESSION['user_area_id'] = $usuario['id_area']; // Guardamos el ID numérico
                $_SESSION['ultimo_acceso'] = time();

                header("Location: index.php");
                exit;
            } else {
                $error = "Contraseña incorrecta.";
            }
        } else {
            $error = "El correo no está registrado.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - StockMaster</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-white flex items-center justify-center h-screen">

<div class="bg-slate-900 p-8 rounded-[2.5rem] border border-slate-800 w-full max-w-md shadow-2xl mx-4">
    <div class="flex justify-center mb-6">
        <div class="bg-indigo-600 p-3 rounded-2xl shadow-lg shadow-indigo-600/20">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
        </div>
    </div>

    <h1 class="text-3xl font-bold mb-2 text-center text-white">StockMaster</h1>
    <p class="text-slate-500 text-center mb-8 text-sm">Ingresa al panel de control</p>

    <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500/50 text-red-400 p-4 rounded-2xl mb-6 text-sm text-center">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">Correo Electrónico</label>
            <input type="email" name="email" required placeholder="Email"
                   class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3.5 outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
        </div>

        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-2 ml-1">Contraseña</label>
            <input type="password" name="password" required placeholder="••••••••"
                   class="w-full bg-slate-800 border border-slate-700 rounded-2xl px-5 py-3.5 outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
        </div>

        <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-500 py-4 rounded-2xl font-bold transition-all shadow-lg shadow-indigo-600/20 mt-4">
            Entrar al Sistema
        </button>
    </form>
</div>

</body>
</html>
