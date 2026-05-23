<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($titulo) ? limpiar($titulo) . ' | ' . APP_NAME : APP_NAME ?></title>
  <meta name="description" content="<?= isset($descripcion) ? limpiar($descripcion) : 'Distribuidora oficial de Cerámica COBOCE en Cobija, Bolivia. Pisos, porcelánato, revestimientos y más.' ?>">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- Estilos COBOCE -->
  <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">

  <?php if (isset($estilosExtra)) echo $estilosExtra; ?>
</head>
<body>

<!-- ── TOPBAR ─────────────────────────────────────────────── -->
<div class="topbar d-none d-md-block">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center py-1">
      <div class="d-flex align-items-center gap-3">
        <span><i class="bi bi-geo-alt-fill me-1 text-warning"></i><?= CIUDAD ?></span>
        <span><i class="bi bi-clock me-1"></i>Lun–Sáb 8:00–18:00</span>
        <span><i class="bi bi-telephone-fill me-1"></i>+591 73943006</span>
      </div>
      <div class="d-flex align-items-center gap-3">
        <?php if (estaLogueado()): ?>
          <span><i class="bi bi-star-fill text-warning me-1"></i>
            <strong><?= number_format((int) $_SESSION['usuario_puntos']) ?></strong> puntos</span>
          <span>Hola, <strong><?= limpiar($_SESSION['usuario_nombre']) ?></strong></span>
        <?php else: ?>
          <a href="<?= APP_URL ?>/views/login.php" class="topbar-link">
            <i class="bi bi-person me-1"></i>Iniciar sesión
          </a>
          <a href="<?= APP_URL ?>/views/registro.php" class="topbar-link">
            <i class="bi bi-person-plus me-1"></i>Crear cuenta
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── SITE HEADER ─────────────────────────────────────────── -->
<header class="site-header">
  <div class="container">
    <div class="row align-items-center py-3 g-2">

      <!-- Logo -->
      <div class="col-6 col-lg-3">
        <a href="<?= APP_URL ?>/index.php" class="logo-link">
          <div class="logo-icon">C</div>
          <div class="logo-text">
            <div class="logo-brand">COBOCE</div>
            <div class="logo-sub">Cerámica &amp; Porcelánato</div>
          </div>
        </a>
      </div>

      <!-- Buscador -->
      <div class="col-12 col-lg-5 order-3 order-lg-2">
        <form action="<?= APP_URL ?>/views/catalogo.php" method="GET" role="search">
          <div class="input-group">
            <span class="input-group-text border-0 bg-white">
              <i class="bi bi-search text-muted"></i>
            </span>
            <input type="text" name="q" class="search-input form-control"
                   placeholder="Buscar pisos, porcelánato, revestimientos…"
                   value="<?= limpiar($_GET['q'] ?? '') ?>"
                   autocomplete="off">
            <button class="btn-search" type="submit">Buscar</button>
          </div>
        </form>
      </div>

      <!-- Acciones -->
      <div class="col-6 col-lg-4 order-2 order-lg-3">
        <div class="d-flex justify-content-end align-items-center gap-2">

          <!-- Usuario -->
          <?php if (estaLogueado()): ?>
          <div class="dropdown">
            <button class="btn btn-header-action d-flex align-items-center gap-1"
                    data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle fs-5"></i>
              <span class="d-none d-lg-inline"><?= limpiar($_SESSION['usuario_nombre']) ?></span>
              <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li>
                <div class="px-3 py-2 border-bottom">
                  <div class="fw-600 text-dark" style="font-size:.9rem">
                    <?= limpiar($_SESSION['usuario_nombre'] . ' ' . $_SESSION['usuario_apellido']) ?>
                  </div>
                  <small class="text-muted"><?= limpiar($_SESSION['usuario_email']) ?></small>
                </div>
              </li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/views/mi-cuenta.php">
                <i class="bi bi-person me-2"></i>Mi cuenta</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/views/mis-pedidos.php">
                <i class="bi bi-box-seam me-2"></i>Mis pedidos</a></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/views/mi-cuenta.php#puntos">
                <i class="bi bi-star me-2 text-warning"></i>
                Mis puntos (<?= number_format((int) $_SESSION['usuario_puntos']) ?>)</a></li>
              <?php if (esAdmin()): ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item fw-600 text-success" href="<?= APP_URL ?>/admin/index.php">
                <i class="bi bi-shield-check me-2"></i>Panel Admin</a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger"
                     href="<?= APP_URL ?>/controllers/AuthController.php?accion=logout"
                     data-confirm="¿Cerrar sesión?">
                <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
            </ul>
          </div>
          <?php else: ?>
          <a href="<?= APP_URL ?>/views/login.php" class="btn btn-header-action d-flex align-items-center gap-1">
            <i class="bi bi-person fs-5"></i>
            <span class="d-none d-lg-inline">Ingresar</span>
          </a>
          <?php endif; ?>

          <!-- Favoritos -->
          <?php
          $nWish = 0;
          if (!empty($_SESSION['usuario_id'])) {
              $dbH  = Database::getConnection();
              $stmtH = $dbH->prepare("SELECT COUNT(*) FROM wishlist WHERE usuario_id = :u");
              $stmtH->execute([':u' => (int)$_SESSION['usuario_id']]);
              $nWish = (int)$stmtH->fetchColumn();
          }
          ?>
          <a href="<?= APP_URL ?>/views/wishlist.php" class="btn btn-header-action position-relative" title="Favoritos">
            <i class="bi bi-heart fs-5"></i>
            <?php if ($nWish > 0): ?>
            <span class="wish-count position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size:.6rem;padding:.25em .45em"><?= $nWish ?></span>
            <?php else: ?>
            <span class="wish-count position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size:.6rem;padding:.25em .45em;display:none"></span>
            <?php endif; ?>
          </a>

          <!-- Carrito -->
          <a href="<?= APP_URL ?>/views/carrito.php" class="btn-cart d-flex align-items-center gap-1">
            <i class="bi bi-cart3 fs-5"></i>
            <span class="d-none d-sm-inline">Carrito</span>
            <?php $nCarrito = contarCarrito(); ?>
            <?php if ($nCarrito > 0): ?>
              <span class="cart-badge"><?= $nCarrito ?></span>
            <?php endif; ?>
          </a>
        </div>
      </div>

    </div>
  </div>
</header>

<!-- ── MAIN NAV ───────────────────────────────────────────── -->
<nav class="main-nav" aria-label="Navegación principal">
  <div class="container">
    <div class="nav-overflow d-flex align-items-center gap-1 py-1">

      <a href="<?= APP_URL ?>/index.php"
         class="nav-item-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
        <i class="bi bi-house me-1"></i>Inicio
      </a>

      <a href="<?= APP_URL ?>/views/catalogo.php"
         class="nav-item-link <?= basename($_SERVER['PHP_SELF']) === 'catalogo.php' ? 'active' : '' ?>">
        <i class="bi bi-grid me-1"></i>Catálogo
      </a>

      <div class="dropdown">
        <a class="nav-item-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button">
          <i class="bi bi-layers me-1"></i>Categorías
        </a>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" href="<?= APP_URL ?>/views/catalogo.php?categoria=1">
            <i class="bi bi-square me-2"></i>Pisos</a></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/views/catalogo.php?categoria=2">
            <i class="bi bi-layout-text-window me-2"></i>Revestimientos</a></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/views/catalogo.php?categoria=3">
            <i class="bi bi-gem me-2"></i>Porcelánato</a></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/views/catalogo.php?categoria=4">
            <i class="bi bi-grid-3x3 me-2"></i>Mosaicos</a></li>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/views/catalogo.php?categoria=5">
            <i class="bi bi-tools me-2"></i>Accesorios</a></li>
        </ul>
      </div>

      <a href="<?= APP_URL ?>/views/catalogo.php?oferta=1" class="nav-item-link">
        <i class="bi bi-tag me-1"></i>Ofertas
      </a>

      <a href="#delivery" class="nav-item-link">
        <i class="bi bi-truck me-1"></i>Delivery
      </a>

      <a href="#puntos" class="nav-item-link">
        <i class="bi bi-star me-1"></i>Puntos
      </a>

      <a href="#contacto" class="nav-item-link">
        <i class="bi bi-telephone me-1"></i>Contacto
      </a>
    </div>
  </div>
</nav>

<!-- ── FLASH MESSAGE ──────────────────────────────────────── -->
<?php $flash = obtenerFlash(); if ($flash): ?>
<?php $bsType = match($flash['tipo']) {
    'exito'       => 'success',
    'error'       => 'danger',
    'advertencia' => 'warning',
    default       => 'info',
}; ?>
<?php $bsIcon = match($flash['tipo']) {
    'exito'  => 'check-circle-fill',
    'error'  => 'exclamation-triangle-fill',
    default  => 'info-circle-fill',
}; ?>
<div class="container mt-2">
  <div class="alert alert-<?= $bsType ?> alert-dismissible fade show d-flex align-items-start gap-2"
       role="alert">
    <i class="bi bi-<?= $bsIcon ?> flex-shrink-0 mt-1"></i>
    <div><?= $flash['mensaje'] ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>

<!-- ── MAIN ───────────────────────────────────────────────── -->
<main class="main-content">
