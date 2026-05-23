<?php
declare(strict_types=1);

// ── Base de datos ──────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'ecommerce_coboce');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── Aplicación ─────────────────────────────────────────────
define('APP_NAME',    'Cerámica COBOCE');
define('APP_URL',     'http://localhost/ecommerce-coboce');
define('APP_VERSION', '1.0.0');
define('MONEDA',      'Bs.');
define('CIUDAD',      'Cobija, Bolivia');

// ── Rutas ──────────────────────────────────────────────────
define('ROOT_PATH',    dirname(__DIR__));
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('UPLOADS_URL',  APP_URL  . '/uploads');

// ── Sesión ─────────────────────────────────────────────────
define('SESSION_LIFETIME', 86400); // 24 horas

// ── Sistema de puntos (caché; la fuente de verdad está en BD)
define('PUNTOS_POR_BS',    1.0);
define('VALOR_PUNTO_BS',   0.10);
define('MAX_CANJE_PCT',    30);

// ── Inventario ─────────────────────────────────────────────
define('STOCK_MINIMO_ALERTA', 5);

// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/funciones.php';
