<?php
// Protección: solo admins
requiereAdmin();

// Página activa para el menú
$paginaAdmin = $paginaAdmin ?? basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($tituloAdmin) ? limpiar($tituloAdmin) . ' — Admin' : 'Panel Admin' ?> | <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --verde: #1A6B3A; --verde-dark: #145730; --verde-light: #238048;
      --dorado: #C9A84C; --dorado-dark: #A8882E;
      --sidebar-w: 240px;
      --topbar-h: 60px;
      --trans: all .22s ease;
    }
    *, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
    body { font-family: 'Poppins', sans-serif; background: #F0F2F5; color: #2D2D2D; }
    a    { text-decoration: none; color: inherit; }

    /* ── SIDEBAR ───────────────────────────────── */
    .admin-sidebar {
      position: fixed; left: 0; top: 0; bottom: 0;
      width: var(--sidebar-w);
      background: var(--verde-dark);
      display: flex; flex-direction: column;
      z-index: 1040; overflow-y: auto;
      transition: transform .3s ease;
    }
    .sidebar-logo {
      padding: 1.25rem 1rem;
      border-bottom: 1px solid rgba(255,255,255,.1);
      display: flex; align-items: center; gap: .7rem;
    }
    .sidebar-logo-icon {
      width: 40px; height: 40px; background: var(--dorado);
      border-radius: 8px; display: flex; align-items: center;
      justify-content: center; font-weight: 800; color: var(--verde-dark);
      font-size: 1.2rem; flex-shrink: 0;
    }
    .sidebar-logo-text { color: white; line-height: 1.2; }
    .sidebar-logo-text .brand { font-size: .95rem; font-weight: 700; letter-spacing: 1px; }
    .sidebar-logo-text .sub   { font-size: .65rem; opacity: .65; }

    .sidebar-section {
      padding: .75rem 1rem .25rem;
      font-size: .65rem; font-weight: 700; color: rgba(255,255,255,.4);
      text-transform: uppercase; letter-spacing: 1.2px;
    }
    .sidebar-link {
      display: flex; align-items: center; gap: .65rem;
      padding: .6rem 1rem; margin: .1rem .5rem;
      border-radius: 8px; color: rgba(255,255,255,.75);
      font-size: .85rem; font-weight: 500; transition: var(--trans);
    }
    .sidebar-link i { font-size: 1rem; width: 18px; text-align: center; flex-shrink: 0; }
    .sidebar-link:hover { background: rgba(255,255,255,.1); color: white; }
    .sidebar-link.active {
      background: var(--dorado); color: var(--verde-dark); font-weight: 700;
    }
    .sidebar-link.active i { color: var(--verde-dark); }
    .sidebar-badge {
      margin-left: auto; background: #dc3545; color: white;
      border-radius: 50px; padding: .1rem .4rem; font-size: .65rem; font-weight: 700;
    }
    .sidebar-footer {
      margin-top: auto; padding: 1rem;
      border-top: 1px solid rgba(255,255,255,.1);
    }

    /* ── TOPBAR ────────────────────────────────── */
    .admin-topbar {
      position: fixed; top: 0; left: var(--sidebar-w); right: 0;
      height: var(--topbar-h); background: white;
      border-bottom: 1px solid #dee2e6;
      display: flex; align-items: center;
      padding: 0 1.5rem; gap: 1rem; z-index: 1030;
      box-shadow: 0 1px 8px rgba(0,0,0,.06);
    }
    .admin-topbar .page-title {
      font-size: 1rem; font-weight: 700; color: var(--verde-dark);
      white-space: nowrap;
    }
    .topbar-actions { margin-left: auto; display: flex; align-items: center; gap: .75rem; }
    .topbar-btn {
      width: 36px; height: 36px; border-radius: 8px;
      background: #F0F2F5; border: none; color: #555;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; transition: var(--trans); font-size: 1rem;
    }
    .topbar-btn:hover { background: var(--verde); color: white; }
    .topbar-user {
      display: flex; align-items: center; gap: .5rem;
      font-size: .82rem; cursor: pointer;
    }
    .topbar-avatar {
      width: 34px; height: 34px; border-radius: 50%;
      background: var(--verde); color: white;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: .85rem;
    }

    /* ── MAIN CONTENT ──────────────────────────── */
    .admin-main {
      margin-left: var(--sidebar-w);
      margin-top: var(--topbar-h);
      padding: 1.5rem;
      min-height: calc(100vh - var(--topbar-h));
    }

    /* ── STATS CARDS ───────────────────────────── */
    .stat-card {
      background: white; border-radius: 12px;
      padding: 1.25rem; border: 1px solid #dee2e6;
      box-shadow: 0 2px 10px rgba(0,0,0,.05);
      transition: var(--trans);
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
    .stat-icon {
      width: 48px; height: 48px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.4rem;
    }
    .stat-num { font-size: 1.8rem; font-weight: 800; line-height: 1; }
    .stat-lbl { font-size: .78rem; color: #6B7280; margin-top: .2rem; }
    .stat-trend { font-size: .75rem; margin-top: .5rem; }

    /* ── TABLES ────────────────────────────────── */
    .admin-table { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
    .admin-table table { margin: 0; }
    .admin-table th { background: #F8F9FA; font-size: .78rem; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #dee2e6; }
    .admin-table td { font-size: .88rem; vertical-align: middle; border-color: #F0F2F5; }
    .admin-table tr:hover td { background: #FAFBFC; }

    /* ── FORMS / CARDS ─────────────────────────── */
    .admin-card {
      background: white; border-radius: 12px;
      border: 1px solid #dee2e6; padding: 1.5rem;
      box-shadow: 0 2px 10px rgba(0,0,0,.05);
    }
    .admin-card-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 1.25rem; padding-bottom: 1rem;
      border-bottom: 1px solid #dee2e6;
    }
    .admin-card-title { font-size: 1rem; font-weight: 700; color: var(--verde-dark); margin: 0; }
    .form-label { font-weight: 500; font-size: .83rem; color: #374151; margin-bottom: .3rem; }
    .form-control, .form-select {
      font-family: 'Poppins', sans-serif; font-size: .88rem;
      border: 1.5px solid #dee2e6; border-radius: 8px; padding: .55rem .85rem;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--verde); box-shadow: 0 0 0 .18rem rgba(26,107,58,.15);
    }
    .btn-verde { background: var(--verde); color: white; border: none; border-radius: 8px; font-weight: 600; padding: .5rem 1.1rem; font-family: 'Poppins', sans-serif; transition: var(--trans); }
    .btn-verde:hover { background: var(--verde-dark); color: white; }
    .btn-dorado { background: var(--dorado); color: var(--verde-dark); border: none; border-radius: 8px; font-weight: 700; padding: .5rem 1.1rem; font-family: 'Poppins', sans-serif; transition: var(--trans); }
    .btn-dorado:hover { background: var(--dorado-dark); }

    /* Imagen preview */
    .img-preview-wrap {
      width: 100%; aspect-ratio: 1; border-radius: 10px;
      border: 2px dashed #dee2e6; background: #F8F9FA;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden; cursor: pointer; transition: border-color .2s;
      position: relative;
    }
    .img-preview-wrap:hover { border-color: var(--verde); }
    .img-preview-wrap img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
    .img-preview-placeholder { text-align: center; color: #adb5bd; }
    .img-preview-placeholder i { font-size: 2.5rem; display: block; margin-bottom: .5rem; }
    .img-preview-placeholder span { font-size: .8rem; }

    /* Stock badge */
    .stock-ok   { color: #198754; font-weight: 600; }
    .stock-low  { color: #fd7e14; font-weight: 600; }
    .stock-out  { color: #dc3545; font-weight: 600; }

    /* Alert */
    .alert-admin { border-radius: 10px; border: none; padding: .75rem 1rem; font-size: .88rem; }

    /* Mobile */
    @media (max-width: 992px) {
      .admin-sidebar { transform: translateX(-100%); }
      .admin-sidebar.show { transform: translateX(0); }
      .admin-topbar { left: 0; }
      .admin-main   { margin-left: 0; }
    }
  </style>
</head>
<body>

<!-- ── SIDEBAR ───────────────────────────────────────────── -->
<aside class="admin-sidebar" id="adminSidebar">

  <div class="sidebar-logo">
    <div class="sidebar-logo-icon">C</div>
    <div class="sidebar-logo-text">
      <div class="brand">COBOCE</div>
      <div class="sub">Panel Admin</div>
    </div>
  </div>

  <nav class="flex-grow-1 py-2">
    <div class="sidebar-section">Principal</div>
    <a href="<?= APP_URL ?>/admin/index.php"
       class="sidebar-link <?= $paginaAdmin === 'index.php' ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>

    <div class="sidebar-section mt-2">Tienda</div>
    <a href="<?= APP_URL ?>/admin/productos.php"
       class="sidebar-link <?= $paginaAdmin === 'productos.php' ? 'active' : '' ?>">
      <i class="bi bi-box-seam"></i> Productos
    </a>
    <a href="<?= APP_URL ?>/admin/categorias.php"
       class="sidebar-link <?= $paginaAdmin === 'categorias.php' ? 'active' : '' ?>">
      <i class="bi bi-tags"></i> Categorías
    </a>
    <a href="<?= APP_URL ?>/admin/pedidos.php"
       class="sidebar-link <?= $paginaAdmin === 'pedidos.php' ? 'active' : '' ?>">
      <i class="bi bi-bag-check"></i> Pedidos
      <!-- <span class="sidebar-badge">3</span> -->
    </a>

    <div class="sidebar-section mt-2">Gestión</div>
    <a href="<?= APP_URL ?>/admin/inventario.php"
       class="sidebar-link <?= $paginaAdmin === 'inventario.php' ? 'active' : '' ?>">
      <i class="bi bi-graph-up-arrow"></i> Inventario
    </a>
    <a href="<?= APP_URL ?>/admin/delivery.php"
       class="sidebar-link <?= $paginaAdmin === 'delivery.php' ? 'active' : '' ?>">
      <i class="bi bi-truck"></i> Delivery
    </a>
    <a href="<?= APP_URL ?>/admin/usuarios.php"
       class="sidebar-link <?= $paginaAdmin === 'usuarios.php' ? 'active' : '' ?>">
      <i class="bi bi-people"></i> Usuarios
    </a>
    <a href="<?= APP_URL ?>/admin/puntos.php"
       class="sidebar-link <?= $paginaAdmin === 'puntos.php' ? 'active' : '' ?>">
      <i class="bi bi-star"></i> Puntos
    </a>

    <div class="sidebar-section mt-2">Sistema</div>
    <a href="<?= APP_URL ?>/admin/reportes.php"
       class="sidebar-link <?= $paginaAdmin === 'reportes.php' ? 'active' : '' ?>">
      <i class="bi bi-bar-chart-line"></i> Reportes
    </a>
    <a href="<?= APP_URL ?>/admin/config-tienda.php"
       class="sidebar-link <?= $paginaAdmin === 'config-tienda.php' ? 'active' : '' ?>">
      <i class="bi bi-shop-window"></i> Config. Tienda
    </a>
    <a href="<?= APP_URL ?>/index.php" class="sidebar-link" target="_blank">
      <i class="bi bi-shop"></i> Ver tienda
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="d-flex align-items-center gap-2">
      <div style="width:32px;height:32px;border-radius:50%;background:var(--dorado);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--verde-dark);font-size:.85rem">
        <?= strtoupper(substr($_SESSION['usuario_nombre'] ?? 'A', 0, 1)) ?>
      </div>
      <div style="flex:1;overflow:hidden">
        <div style="font-size:.8rem;color:white;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= limpiar(($_SESSION['usuario_nombre'] ?? '') . ' ' . ($_SESSION['usuario_apellido'] ?? '')) ?>
        </div>
        <div style="font-size:.68rem;color:rgba(255,255,255,.5)">Administrador</div>
      </div>
      <a href="<?= APP_URL ?>/controllers/AuthController.php?accion=logout"
         class="topbar-btn" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.7)" title="Cerrar sesión">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </div>
</aside>

<!-- ── TOPBAR ─────────────────────────────────────────────── -->
<header class="admin-topbar">
  <button class="topbar-btn d-lg-none" onclick="document.getElementById('adminSidebar').classList.toggle('show')">
    <i class="bi bi-list fs-5"></i>
  </button>
  <span class="page-title">
    <i class="bi bi-chevron-right text-muted me-1" style="font-size:.7rem"></i>
    <?= isset($tituloAdmin) ? limpiar($tituloAdmin) : 'Dashboard' ?>
  </span>
  <div class="topbar-actions">
    <a href="<?= APP_URL ?>/views/catalogo.php" target="_blank" class="topbar-btn" title="Ver tienda">
      <i class="bi bi-shop"></i>
    </a>
    <div class="dropdown">
      <div class="topbar-user" data-bs-toggle="dropdown">
        <div class="topbar-avatar">
          <?= strtoupper(substr($_SESSION['usuario_nombre'] ?? 'A', 0, 1)) ?>
        </div>
        <span class="d-none d-md-inline" style="font-size:.82rem;font-weight:500">
          <?= limpiar($_SESSION['usuario_nombre'] ?? '') ?>
        </span>
        <i class="bi bi-chevron-down" style="font-size:.65rem;color:#888"></i>
      </div>
      <ul class="dropdown-menu dropdown-menu-end shadow" style="font-size:.85rem;min-width:180px">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/views/mi-cuenta.php"><i class="bi bi-person me-2"></i>Mi perfil</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/controllers/AuthController.php?accion=logout">
          <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
      </ul>
    </div>
  </div>
</header>

<!-- ── MAIN ───────────────────────────────────────────────── -->
<main class="admin-main">

<!-- Flash -->
<?php $flash = obtenerFlash(); if ($flash): ?>
<?php $bt = match($flash['tipo']) { 'exito'=>'success','error'=>'danger','advertencia'=>'warning',default=>'info' }; ?>
<div class="alert alert-<?= $bt ?> alert-dismissible fade show alert-admin mb-3 d-flex align-items-center gap-2" role="alert">
  <i class="bi bi-<?= $flash['tipo']==='exito' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
  <div><?= $flash['mensaje'] ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
