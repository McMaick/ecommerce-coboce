<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';
$back   = $_SERVER['HTTP_REFERER'] ?? APP_URL . '/index.php';

switch ($accion) {

    // ── AGREGAR ───────────────────────────────────────────────
    case 'agregar':
        $id       = limpiarInt($_GET['id'] ?? $_POST['id'] ?? 0);
        $cantidad = max(0.25, (float)($_GET['cantidad'] ?? $_POST['cantidad'] ?? 1));

        if (!$id) { flash('error', 'Producto no válido.'); redirigir($back); }

        $prod = (new Producto())->buscarPorId($id);
        if (!$prod) { flash('error', 'El producto no existe.'); redirigir($back); }
        if ((int)$prod['stock'] <= 0) { flash('advertencia', 'Producto sin stock disponible.'); redirigir($back); }

        if (!isset($_SESSION['carrito'])) $_SESSION['carrito'] = [];

        if (isset($_SESSION['carrito'][$id])) {
            $nueva = $_SESSION['carrito'][$id]['cantidad'] + $cantidad;
            $_SESSION['carrito'][$id]['cantidad'] = min($nueva, (int)$prod['stock']);
        } else {
            $_SESSION['carrito'][$id] = [
                'id'       => (int)  $prod['id'],
                'nombre'   =>        $prod['nombre'],
                'precio'   => (float)$prod['precio'],
                'oferta'   =>        $prod['precio_oferta'] ? (float)$prod['precio_oferta'] : null,
                'cantidad' =>        min($cantidad, (int)$prod['stock']),
                'unidad'   =>        $prod['unidad'],
                'imagen'   =>        $prod['imagen'],
                'stock'    => (int)  $prod['stock'],
                'puntos'   => (int)  $prod['puntos_genera'],
                'categoria'=>        $prod['categoria'],
            ];
        }

        flash('exito', '«' . truncar($prod['nombre'], 40) . '» agregado al carrito.');
        redirigir($back);

    // ── ACTUALIZAR CANTIDADES ─────────────────────────────────
    case 'actualizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirigir(APP_URL . '/views/carrito.php');

        $cantidades = $_POST['cantidad'] ?? [];
        foreach ($cantidades as $id => $qty) {
            $id  = (int) $id;
            $qty = (float) $qty;
            if (!isset($_SESSION['carrito'][$id])) continue;
            if ($qty <= 0) {
                unset($_SESSION['carrito'][$id]);
            } else {
                $maxStock = $_SESSION['carrito'][$id]['stock'];
                $_SESSION['carrito'][$id]['cantidad'] = min($qty, $maxStock);
            }
        }

        flash('exito', 'Carrito actualizado correctamente.');
        redirigir(APP_URL . '/views/carrito.php');

    // ── ELIMINAR ÍTEM ─────────────────────────────────────────
    case 'eliminar':
        $id = limpiarInt($_GET['id'] ?? 0);
        if (isset($_SESSION['carrito'][$id])) {
            $nombre = $_SESSION['carrito'][$id]['nombre'];
            unset($_SESSION['carrito'][$id]);
            flash('info', '«' . truncar($nombre, 40) . '» eliminado del carrito.');
        }
        redirigir(APP_URL . '/views/carrito.php');

    // ── VACIAR ────────────────────────────────────────────────
    case 'vaciar':
        unset($_SESSION['carrito']);
        flash('info', 'El carrito fue vaciado.');
        redirigir(APP_URL . '/views/carrito.php');

    // ── MINI-CARRITO JSON (AJAX) ──────────────────────────────
    case 'obtener':
        header('Content-Type: application/json');
        $items = [];
        foreach ($_SESSION['carrito'] ?? [] as $item) {
            $p = $item['oferta'] ?? $item['precio'];
            $items[] = [
                'id'       => $item['id'],
                'nombre'   => $item['nombre'],
                'precio'   => $p,
                'cantidad' => $item['cantidad'],
                'subtotal' => round($p * $item['cantidad'], 2),
            ];
        }
        echo json_encode([
            'items'   => $items,
            'total'   => round(totalCarrito(), 2),
            'cantidad'=> contarCarrito(),
        ]);
        exit;

    default:
        redirigir(APP_URL . '/views/carrito.php');
}
