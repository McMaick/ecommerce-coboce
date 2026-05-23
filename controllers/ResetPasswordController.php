<?php
declare(strict_types=1);
ini_set('display_errors', '0');

// Limpiar cualquier buffer previo y empezar limpio
while (ob_get_level() > 0) ob_end_clean();

require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

$email = trim($_POST['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'msg' => 'Ingresa un correo electrónico válido.']);
    exit;
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT id, nombre FROM usuarios WHERE email = :e AND activo = 1 LIMIT 1"
    );
    $stmt->execute([':e' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        // No revelar si el email existe o no
        echo json_encode(['ok' => true, 'pass' => null]);
        exit;
    }

    // Generar contraseña temporal (9 chars, sin caracteres confusos)
    $chars   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $tmpPass = '';
    for ($i = 0; $i < 9; $i++) {
        $tmpPass .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $db->prepare("UPDATE usuarios SET password_hash = :p WHERE id = :id")
       ->execute([
           ':p'   => password_hash($tmpPass, PASSWORD_DEFAULT),
           ':id'  => (int)$usuario['id'],
       ]);

    echo json_encode([
        'ok'     => true,
        'pass'   => $tmpPass,
        'nombre' => $usuario['nombre'],
    ]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error interno. Intenta de nuevo.']);
}
