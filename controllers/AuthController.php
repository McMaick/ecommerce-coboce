<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Usuario.php';

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

switch ($accion) {

    // ── LOGIN ─────────────────────────────────────────────
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirigir(APP_URL . '/views/login.php');
        }

        if (!verificarToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Sesión expirada. Vuelve a intentarlo.');
            redirigir(APP_URL . '/views/login.php');
        }

        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';

        if (!$email || !$password) {
            flash('error', 'Completa todos los campos obligatorios.');
            redirigir(APP_URL . '/views/login.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El formato del email no es válido.');
            redirigir(APP_URL . '/views/login.php');
        }

        $modelo  = new Usuario();
        $usuario = $modelo->buscarPorEmail($email);

        if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
            flash('error', 'Email o contraseña incorrectos.');
            redirigir(APP_URL . '/views/login.php');
        }

        session_regenerate_id(true);

        $_SESSION['usuario_id']       = (int) $usuario['id'];
        $_SESSION['usuario_nombre']   = $usuario['nombre'];
        $_SESSION['usuario_apellido'] = $usuario['apellido'];
        $_SESSION['usuario_email']    = $usuario['email'];
        $_SESSION['usuario_rol']      = $usuario['rol'];
        $_SESSION['usuario_puntos']   = (int) $usuario['puntos'];

        flash('exito', '¡Bienvenido de nuevo, ' . $usuario['nombre'] . '!');

        $destino = $_SESSION['redirect_after_login'] ?? null;
        unset($_SESSION['redirect_after_login']);

        redirigir($usuario['rol'] === 'admin'
            ? APP_URL . '/admin/index.php'
            : ($destino ?? APP_URL . '/index.php')
        );

    // ── REGISTRO ──────────────────────────────────────────
    case 'registro':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirigir(APP_URL . '/views/registro.php');
        }

        if (!verificarToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Sesión expirada. Vuelve a intentarlo.');
            redirigir(APP_URL . '/views/registro.php');
        }

        $nombre    = trim($_POST['nombre']             ?? '');
        $apellido  = trim($_POST['apellido']           ?? '');
        $email     = trim($_POST['email']              ?? '');
        $telefono  = trim($_POST['telefono']           ?? '');
        $ci        = trim($_POST['ci']                 ?? '');
        $password  = $_POST['password']                ?? '';
        $confirmar = $_POST['password_confirmar']      ?? '';
        $terminos  = isset($_POST['terminos']);

        $errores = [];

        if (!$nombre)                               $errores[] = 'El nombre es obligatorio.';
        if (!$apellido)                             $errores[] = 'El apellido es obligatorio.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El email no es válido.';
        if (strlen($password) < 8)                  $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($password !== $confirmar)               $errores[] = 'Las contraseñas no coinciden.';
        if (!$terminos)                             $errores[] = 'Debes aceptar los términos y condiciones.';

        if ($errores) {
            flash('error', implode('<br>', $errores));
            redirigir(APP_URL . '/views/registro.php');
        }

        $modelo = new Usuario();

        if ($modelo->emailExiste($email)) {
            flash('error', 'Ya existe una cuenta registrada con ese email. <a href="' . APP_URL . '/views/login.php" class="alert-link">Inicia sesión</a>.');
            redirigir(APP_URL . '/views/registro.php');
        }

        $id = $modelo->crear([
            'nombre'   => $nombre,
            'apellido' => $apellido,
            'email'    => $email,
            'telefono' => $telefono,
            'ci'       => $ci,
            'password' => $password,
        ]);

        if (!$id) {
            flash('error', 'Ocurrió un error al crear la cuenta. Intenta nuevamente.');
            redirigir(APP_URL . '/views/registro.php');
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id']       = $id;
        $_SESSION['usuario_nombre']   = $nombre;
        $_SESSION['usuario_apellido'] = $apellido;
        $_SESSION['usuario_email']    = $email;
        $_SESSION['usuario_rol']      = 'cliente';
        $_SESSION['usuario_puntos']   = Usuario::PUNTOS_BIENVENIDA;

        flash('exito', '¡Cuenta creada exitosamente! Bienvenido a ' . APP_NAME . ', ' . $nombre . '.');
        redirigir(APP_URL . '/index.php');

    // ── LOGOUT ────────────────────────────────────────────
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start();
        flash('info', 'Sesión cerrada correctamente. ¡Hasta pronto!');
        redirigir(APP_URL . '/index.php');

    default:
        redirigir(APP_URL . '/index.php');
}
