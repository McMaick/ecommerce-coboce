<?php
declare(strict_types=1);

// ── Sanitización ──────────────────────────────────────────
function limpiar(string $valor): string {
    return htmlspecialchars(trim($valor), ENT_QUOTES, 'UTF-8');
}

function limpiarInt(mixed $valor): int {
    return (int) filter_var($valor, FILTER_SANITIZE_NUMBER_INT);
}

function limpiarFloat(mixed $valor): float {
    return (float) filter_var($valor, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

// ── Redirección ───────────────────────────────────────────
function redirigir(string $url): never {
    header('Location: ' . $url);
    exit;
}

// ── Sesión / Auth ─────────────────────────────────────────
function estaLogueado(): bool {
    return isset($_SESSION['usuario_id']);
}

function esAdmin(): bool {
    return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
}

function esDelivery(): bool {
    return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'delivery';
}

function requiereLogin(string $redireccion = ''): void {
    if (!estaLogueado()) {
        $_SESSION['redirect_after_login'] = $redireccion ?: $_SERVER['REQUEST_URI'];
        redirigir(APP_URL . '/views/login.php');
    }
}

function requiereAdmin(): void {
    if (!esAdmin()) redirigir(APP_URL . '/index.php');
}

function requiereDelivery(): void {
    requiereLogin();
    if (!esDelivery()) redirigir(APP_URL . '/index.php');
}

// ── Flash messages ────────────────────────────────────────
function flash(string $tipo, string $mensaje): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function obtenerFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// ── CSRF ──────────────────────────────────────────────────
function generarToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificarToken(?string $token = null): bool {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function guardarFlash(string $tipo, string $mensaje): void {
    flash($tipo, $mensaje);
}

function campoCSRF(): string {
    return '<input type="hidden" name="csrf_token" value="' . generarToken() . '">';
}

// ── Formato ───────────────────────────────────────────────
function precio(float $monto): string {
    return MONEDA . ' ' . number_format($monto, 2, '.', ',');
}

function truncar(string $texto, int $largo = 80): string {
    return mb_strlen($texto) > $largo
        ? mb_substr($texto, 0, $largo) . '…'
        : $texto;
}

function tiempoRelativo(string $fecha): string {
    $diff = time() - strtotime($fecha);
    if ($diff < 60)     return 'Hace un momento';
    if ($diff < 3600)   return 'Hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400)  return 'Hace ' . floor($diff / 3600) . ' h';
    if ($diff < 604800) return 'Hace ' . floor($diff / 86400) . ' días';
    return date('d/m/Y', strtotime($fecha));
}

// ── Carrito (sesión) ──────────────────────────────────────
function contarCarrito(): int {
    if (!isset($_SESSION['carrito'])) return 0;
    $total = 0;
    foreach ($_SESSION['carrito'] as $item) {
        $total += $item['cantidad'];
    }
    return (int) ceil($total);
}

function totalCarrito(): float {
    if (!isset($_SESSION['carrito'])) return 0.0;
    $total = 0.0;
    foreach ($_SESSION['carrito'] as $item) {
        $total += $item['precio'] * $item['cantidad'];
    }
    return $total;
}

// ── Pedidos ───────────────────────────────────────────────
function generarCodigoPedido(): string {
    return 'COB' . strtoupper(substr(uniqid(), -4)) . '-' . date('Ymd');
}

// ── Imagen ────────────────────────────────────────────────
function urlImagen(?string $ruta, string $placeholder = 'producto'): string {
    if ($ruta && file_exists(UPLOADS_PATH . '/' . $ruta)) {
        return UPLOADS_URL . '/' . $ruta;
    }
    return APP_URL . '/assets/img/placeholder-' . $placeholder . '.png';
}

// ── Paginación ────────────────────────────────────────────
function paginacion(int $totalRegistros, int $porPagina, int $paginaActual, string $urlBase): string {
    $totalPaginas = (int) ceil($totalRegistros / $porPagina);
    if ($totalPaginas <= 1) return '';

    $html  = '<nav><ul class="pagination justify-content-center">';
    $prev  = $paginaActual > 1 ? $paginaActual - 1 : null;
    $next  = $paginaActual < $totalPaginas ? $paginaActual + 1 : null;

    $html .= '<li class="page-item' . ($prev ? '' : ' disabled') . '">'
           . '<a class="page-link" href="' . ($prev ? $urlBase . '&pagina=' . $prev : '#') . '">«</a></li>';

    for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++) {
        $html .= '<li class="page-item' . ($i === $paginaActual ? ' active' : '') . '">'
               . '<a class="page-link" href="' . $urlBase . '&pagina=' . $i . '">' . $i . '</a></li>';
    }

    $html .= '<li class="page-item' . ($next ? '' : ' disabled') . '">'
           . '<a class="page-link" href="' . ($next ? $urlBase . '&pagina=' . $next : '#') . '">»</a></li>';

    return $html . '</ul></nav>';
}
