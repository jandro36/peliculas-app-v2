<?php
// Sesiones en volumen compartido (accesible por los 3 contenedores Apache)
$sessions_path = '/var/www/html/sessions';
if (!is_dir($sessions_path)) {
    mkdir($sessions_path, 0777, true);
}
session_save_path($sessions_path);
session_start();

// Archivo donde guardar usuarios
$archivo_usuarios = 'usuarios.json';

// Crear archivo si no existe
if (!file_exists($archivo_usuarios)) {
    $usuarios_default = [
        'admin' => password_hash('admin123', PASSWORD_DEFAULT),
        'usuario' => password_hash('user123', PASSWORD_DEFAULT)
    ];
    file_put_contents($archivo_usuarios, json_encode($usuarios_default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$mensaje = "";
$error = "";

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_login'])) {
    $usuario = $_POST['usuario'] ?? '';
    $contrasena = $_POST['contrasena'] ?? '';

    // Leer usuarios
    $usuarios = json_decode(file_get_contents($archivo_usuarios), true);

    // Validar credenciales
    if (isset($usuarios[$usuario]) && password_verify($contrasena, $usuarios[$usuario])) {
        $_SESSION['usuario_logueado'] = $usuario;
        $_SESSION['login_time'] = time();
        header('Location: index.php');
        exit;
    } else {
        $error = "❌ Usuario o contraseña incorrectos";
    }
}

// Procesar registro (opcional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_registro'])) {
    $nuevo_usuario = $_POST['nuevo_usuario'] ?? '';
    $nueva_contrasena = $_POST['nueva_contrasena'] ?? '';
    $nueva_contrasena_conf = $_POST['nueva_contrasena_conf'] ?? '';

    if (strlen($nuevo_usuario) < 3) {
        $error = "❌ El usuario debe tener al menos 3 caracteres";
    } elseif ($nueva_contrasena !== $nueva_contrasena_conf) {
        $error = "❌ Las contraseñas no coinciden";
    } elseif (strlen($nueva_contrasena) < 6) {
        $error = "❌ La contraseña debe tener al menos 6 caracteres";
    } else {
        $usuarios = json_decode(file_get_contents($archivo_usuarios), true);

        if (isset($usuarios[$nuevo_usuario])) {
            $error = "❌ El usuario ya existe";
        } else {
            $usuarios[$nuevo_usuario] = password_hash($nueva_contrasena, PASSWORD_DEFAULT);
            file_put_contents($archivo_usuarios, json_encode($usuarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $mensaje = "✅ Registro exitoso. Ahora puedes iniciar sesión.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mi Registro de Películas</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            text-align: center;
            font-size: 28px;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .tabs {
            display: flex;
            margin-bottom: 30px;
            gap: 10px;
        }

        .tab-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: #f0f0f0;
            cursor: pointer;
            border-radius: 5px;
            font-weight: bold;
            color: #666;
            transition: all 0.3s;
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-login {
            background: #667eea;
            color: white;
        }

        .btn-login:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .mensaje {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }

        .mensaje.error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .mensaje.success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        .info {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 5px;
            font-size: 12px;
            color: #666;
            margin-top: 20px;
            text-align: center;
        }

        .emoji {
            font-size: 40px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="emoji">🎬</div>
    <h1>Mi Registro de Películas</h1>
    <p class="subtitle">Acceso seguro a tu colección</p>

    <!-- Información del contenedor movida aquí -->
    <p style="font-size: 12px; color: #95a5a6; margin-bottom: 20px; text-align: center;">
      🐳 Contenedor: <code><?php echo gethostname(); ?></code>
    </p>

    <?php if ($error): ?>
        <div class="mensaje error"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($mensaje): ?>
        <div class="mensaje success"><?php echo $mensaje; ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="cambiarTab(event, 'login')">Iniciar Sesión</button>
        <button class="tab-btn" onclick="cambiarTab(event, 'registro')">Registrarse</button>
    </div>

    <!-- TAB LOGIN -->
    <div id="login" class="tab-content active">
        <form method="POST">
            <div class="form-group">
                <label for="usuario">👤 Usuario:</label>
                <input type="text" id="usuario" name="usuario" required autofocus>
            </div>

            <div class="form-group">
                <label for="contrasena">🔐 Contraseña:</label>
                <input type="password" id="contrasena" name="contrasena" required>
            </div>

            <button type="submit" name="accion_login" class="btn btn-login">Entrar</button>
        </form>
    </div>

    <!-- TAB REGISTRO -->
    <div id="registro" class="tab-content">
        <form method="POST">
            <div class="form-group">
                <label for="nuevo_usuario">👤 Nuevo Usuario:</label>
                <input type="text" id="nuevo_usuario" name="nuevo_usuario" required>
            </div>

            <div class="form-group">
                <label for="nueva_contrasena">🔐 Contraseña:</label>
                <input type="password" id="nueva_contrasena" name="nueva_contrasena" required>
            </div>

            <div class="form-group">
                <label for="nueva_contrasena_conf">🔐 Confirmar Contraseña:</label>
                <input type="password" id="nueva_contrasena_conf" name="nueva_contrasena_conf" required>
            </div>

            <button type="submit" name="accion_registro" class="btn btn-login">Crear Cuenta</button>

            <div class="info">
                Mínimo 3 caracteres para usuario<br>
                Mínimo 6 caracteres para contraseña
            </div>
        </form>
    </div>
</div>

<script>
function cambiarTab(evt, tabName) {
    var i, tabcontent, tabbuttons;

    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("active");
    }

    tabbuttons = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tabbuttons.length; i++) {
        tabbuttons[i].classList.remove("active");
    }

    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}
</script>

<footer style="position: absolute; bottom: 20px; width: 100%; text-align: center; color: #fff; font-size: 12px; opacity: 0.8;">
    <p>Servidor: <code><?php echo gethostname(); ?></code> | IP: <code><?php echo $_SERVER['SERVER_ADDR'] ?? 'N/A'; ?></code> | PID: <code><?php echo getmypid(); ?></code></p>
</footer>

</body>
</html>
