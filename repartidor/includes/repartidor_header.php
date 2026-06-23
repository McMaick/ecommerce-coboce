<?php
// Protección: solo repartidores (rol delivery)
requiereDelivery();

$paginaRepartidor = $paginaRepartidor ?? basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($tituloRepartidor) ? limpiar($tituloRepartidor) . ' — Repartidor' : 'Panel Repartidor' ?> | <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --verde: #1A6B3A; --verde-dark: #145730; --verde-light: #238048;
      --dorado: #C9A84C; --dorado-dark: #A8882E;
      --topbar-h: 56px;
      --trans: all .22s ease;
    }
    *, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
    body { font-family: 'Poppins', sans-serif; background: #F0F2F5; color: #2D2D2D; padding-bottom: 2rem; }
    a { text-decoration: none; color: inherit; }

    .rep-topbar {
      position: sticky; top: 0; left: 0; right: 0;
      height: var(--topbar-h); background: var(--verde-dark);
      display: flex; align-items: center; gap: .75rem;
      padding: 0 1rem; z-index: 1030; color: white;
    }
    .rep-topbar .brand { font-weight: 700; font-size: .95rem; letter-spacing: .5px; }
    .rep-topbar .sub { font-size: .68rem; opacity: .7; }
    .rep-topbar-actions { margin-left: auto; display: flex; align-items: center; gap: .5rem; }
    .rep-avatar-mini {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--dorado); color: var(--verde-dark);
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: .8rem; flex-shrink: 0;
    }
    .rep-logout {
      width: 34px; height: 34px; border-radius: 8px;
      background: rgba(255,255,255,.1); color: rgba(255,255,255,.8);
      display: flex; align-items: center; justify-content: center;
    }

    .rep-main { padding: 1rem; max-width: 720px; margin: 0 auto; }

    .stat-card {
      background: white; border-radius: 12px;
      padding: 1rem; border: 1px solid #dee2e6;
      box-shadow: 0 2px 10px rgba(0,0,0,.05);
    }
    .stat-num { font-size: 1.5rem; font-weight: 800; line-height: 1; }
    .stat-lbl { font-size: .75rem; color: #6B7280; margin-top: .2rem; }

    .alert-rep { border-radius: 10px; border: none; padding: .75rem 1rem; font-size: .88rem; }

    .btn-verde { background: var(--verde); color: white; border: none; border-radius: 8px; font-weight: 600; padding: .55rem 1rem; font-family: 'Poppins', sans-serif; transition: var(--trans); }
    .btn-verde:hover { background: var(--verde-dark); color: white; }
  </style>
</head>
<body>

<header class="rep-topbar">
  <div class="rep-avatar-mini"><?= strtoupper(substr($_SESSION['usuario_nombre'] ?? 'R', 0, 1)) ?></div>
  <div>
    <div class="brand"><?= limpiar(($_SESSION['usuario_nombre'] ?? '') . ' ' . ($_SESSION['usuario_apellido'] ?? '')) ?></div>
    <div class="sub">Panel Repartidor — COBOCE</div>
  </div>
  <div class="rep-topbar-actions">
    <a href="<?= APP_URL ?>/controllers/AuthController.php?accion=logout" class="rep-logout" title="Cerrar sesión">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</header>

<main class="rep-main">

<?php $flash = obtenerFlash(); if ($flash): ?>
<?php $bt = match($flash['tipo']) { 'exito'=>'success','error'=>'danger','advertencia'=>'warning',default=>'info' }; ?>
<div class="alert alert-<?= $bt ?> alert-dismissible fade show alert-rep mb-3 d-flex align-items-center gap-2" role="alert">
  <i class="bi bi-<?= $flash['tipo']==='exito' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
  <div><?= $flash['mensaje'] ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
