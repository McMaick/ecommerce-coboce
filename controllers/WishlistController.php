<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'login' => true]);
    exit;
}

$usuarioId  = (int)$_SESSION['usuario_id'];
$productoId = limpiarInt($_POST['producto_id'] ?? 0);

if (!$productoId) {
    echo json_encode(['ok' => false, 'msg' => 'Producto inválido']);
    exit;
}

$db = Database::getConnection();

$existe = $db->prepare("SELECT id FROM wishlist WHERE usuario_id = :u AND producto_id = :p");
$existe->execute([':u' => $usuarioId, ':p' => $productoId]);

if ($existe->fetchColumn()) {
    $db->prepare("DELETE FROM wishlist WHERE usuario_id = :u AND producto_id = :p")
       ->execute([':u' => $usuarioId, ':p' => $productoId]);
    $enWishlist = false;
} else {
    $db->prepare("INSERT INTO wishlist (usuario_id, producto_id) VALUES (:u, :p)")
       ->execute([':u' => $usuarioId, ':p' => $productoId]);
    $enWishlist = true;
}

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM wishlist WHERE usuario_id = :u");
$stmtTotal->execute([':u' => $usuarioId]);
$total = (int)$stmtTotal->fetchColumn();

echo json_encode(['ok' => true, 'enWishlist' => $enWishlist, 'total' => $total]);
