<?php
session_start();

// ==============================
// CONEXIÓN DB
// ==============================
$host = 'localhost';
$db   = 'stockmaster_db';
$user = 'root';
$pass = 'mysql'; // Cambiado de 'mysql' a vacío si usas AMPPS/XAMPP por defecto

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$error = "";

// ==============================
// LOGIN
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "Completa todos los campos.";
    } else {

        // Buscar usuario según tu tabla `usuario`
        $stmt = $pdo->prepare("
            SELECT id_usuario, nombre, email, password, id_rol, id_area
            FROM usuario
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $passwordDB = $usuario['password'];
            $loginOK = false;

            // Verificación compatible (Hash o Texto Plano)
            if (password_verify($password, $passwordDB)) {
                $loginOK = true;
            } elseif ($password === $passwordDB) {
                $loginOK = true;
            }

            if ($loginOK) {
                // Guardar sesión con los nombres de columna reales de tu DB
                $_SESSION['user_id']     = $usuario['id_usuario'];
                $_SESSION['user_nombre'] = $usuario['nombre'];
                $_SESSION['user_rol']    = $usuario['id_rol'];
                $_SESSION['user_area']   = $usuario['id_area'];

                header("Location: index.php");
                exit;
            } else {
                $error = "Correo o contraseña incorrectos.";
            }
        } else {
            $error = "Correo o contraseña incorrectos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - StockMaster</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-white flex items-center justify-center h-screen p-4">

<div class="bg-slate-900 p-8 rounded-3xl border border-slate-800 w-full max-w-md shadow-2xl">

    <h1 class="text-3xl font-bold mb-2 text-center text-indigo-500">StockMaster</h1>
    <p class="text-slate-500 text-center mb-8 text-sm">
        Ingresa tus credenciales para continuar
    </p>

    <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500 text-red-500 p-3 rounded-xl mb-6 text-sm text-center">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <div>
            <label class="block text-xs font-bold uppercase text-slate-500 mb-2">Email</label>
            <input type="email" name="email" required
                   placeholder="admin@example.com"
                   class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
        </div>

        <div>
            <label class="block text-xs font-bold uppercase text-slate-500 mb-2">Contraseña</label>
            <input type="password" name="password" required
                   placeholder="••••••••"
                   class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
        </div>

        <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-500 py-4 rounded-xl font-bold transition-all shadow-lg active:scale-95">
            Iniciar Sesión
        </button>
    </form>
</div>

</body>
</html>