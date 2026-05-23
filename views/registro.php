<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

if (estaLogueado()) redirigir(APP_URL . '/index.php');

$flash = obtenerFlash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear cuenta | <?= APP_NAME ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="auth-page">

  <!-- ── LADO IZQUIERDO ─────────────────────────────────── -->
  <div class="auth-left">
    <div class="auth-left-content">

      <a href="<?= APP_URL ?>/index.php" class="d-flex align-items-center gap-2 mb-4" style="color:white">
        <div class="logo-icon">C</div>
        <div>
          <div class="logo-brand">COBOCE</div>
          <div class="logo-sub">Cerámica &amp; Porcelánato</div>
        </div>
      </a>

      <h2>Únete a la <span>familia COBOCE</span></h2>
      <p class="mt-2 mb-4">
        Crea tu cuenta gratis y empieza a disfrutar de todos los beneficios
        de ser cliente registrado en la distribuidora líder de cerámica en Cobija.
      </p>

      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-gift-fill"></i></div>
        <div class="auth-benefit-text">
          <strong>Bono de bienvenida</strong>
          <span>50 puntos al registrarte</span>
        </div>
      </div>
      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-percent"></i></div>
        <div class="auth-benefit-text">
          <strong>Ofertas exclusivas</strong>
          <span>Precios especiales para clientes</span>
        </div>
      </div>
      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-shield-check-fill"></i></div>
        <div class="auth-benefit-text">
          <strong>Compra segura</strong>
          <span>Tus datos están protegidos</span>
        </div>
      </div>
      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-clock-history"></i></div>
        <div class="auth-benefit-text">
          <strong>Historial de pedidos</strong>
          <span>Revisa y repite tus compras fácilmente</span>
        </div>
      </div>

      <div class="mt-5 pt-3" style="border-top:1px solid rgba(255,255,255,.15)">
        <p style="font-size:.82rem;opacity:.65">
          ¿Ya tienes cuenta?
          <a href="<?= APP_URL ?>/views/login.php" style="color:var(--dorado-light);font-weight:600">
            Inicia sesión
          </a>
        </p>
      </div>

    </div>
  </div>

  <!-- ── LADO DERECHO ───────────────────────────────────── -->
  <div class="auth-right" style="align-items:flex-start;padding-top:2rem;padding-bottom:2rem;overflow-y:auto">
    <div class="auth-card" style="max-width:520px">

      <!-- Logo mobile -->
      <div class="auth-card-logo d-lg-none">
        <a href="<?= APP_URL ?>/index.php" class="d-flex align-items-center gap-2">
          <div class="logo-icon">C</div>
          <div class="logo-brand" style="color:var(--verde-dark)">COBOCE</div>
        </a>
      </div>

      <div class="auth-card-header">
        <h1 class="auth-title">Crear cuenta</h1>
        <p class="auth-subtitle">Completa el formulario — ¡es rápido y gratis!</p>
      </div>

      <!-- Flash -->
      <?php if ($flash): ?>
      <?php $bt = match($flash['tipo']) {
          'exito' => 'success', 'error' => 'danger',
          'advertencia' => 'warning', default => 'info'
      }; ?>
      <div class="alert alert-<?= $bt ?> alert-dismissible fade show d-flex align-items-start gap-2 mb-3" role="alert">
        <i class="bi bi-<?= $flash['tipo'] === 'exito' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> mt-1 flex-shrink-0"></i>
        <div><?= $flash['mensaje'] ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <!-- Formulario Registro -->
      <form action="<?= APP_URL ?>/controllers/AuthController.php" method="POST"
            data-loading novalidate>

        <?= campoCSRF() ?>
        <input type="hidden" name="accion" value="registro">

        <!-- Nombre + Apellido -->
        <div class="row g-3 mb-3">
          <div class="col-6">
            <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
            <input type="text" id="nombre" name="nombre" class="form-control"
                   placeholder="Juan"
                   value="<?= limpiar($_POST['nombre'] ?? '') ?>"
                   required maxlength="80" autofocus autocomplete="given-name">
          </div>
          <div class="col-6">
            <label for="apellido" class="form-label">Apellido <span class="text-danger">*</span></label>
            <input type="text" id="apellido" name="apellido" class="form-control"
                   placeholder="Mamani"
                   value="<?= limpiar($_POST['apellido'] ?? '') ?>"
                   required maxlength="80" autocomplete="family-name">
          </div>
        </div>

        <!-- Email -->
        <div class="mb-3">
          <label for="email" class="form-label">Correo electrónico <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="tu@email.com"
                   value="<?= limpiar($_POST['email'] ?? '') ?>"
                   required autocomplete="email">
          </div>
        </div>

        <!-- Teléfono + CI -->
        <div class="row g-3 mb-3">
          <div class="col-6">
            <label for="telefono" class="form-label">Teléfono</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-telephone"></i></span>
              <input type="tel" id="telefono" name="telefono" class="form-control"
                     placeholder="7XX-XXXXX"
                     value="<?= limpiar($_POST['telefono'] ?? '') ?>"
                     maxlength="20" autocomplete="tel">
            </div>
          </div>
          <div class="col-6">
            <label for="ci" class="form-label">Carnet de Identidad</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
              <input type="text" id="ci" name="ci" class="form-control"
                     placeholder="12345678"
                     value="<?= limpiar($_POST['ci'] ?? '') ?>"
                     maxlength="20">
            </div>
          </div>
        </div>

        <!-- Contraseña -->
        <div class="mb-3">
          <label for="password" class="form-label">Contraseña <span class="text-danger">*</span></label>
          <div class="password-wrap">
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" id="password" name="password" class="form-control"
                     placeholder="Mínimo 8 caracteres"
                     required minlength="8" autocomplete="new-password"
                     style="border-radius:0 8px 8px 0 !important">
            </div>
            <button type="button" class="btn-pwd-toggle" style="right:.75rem">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <!-- Medidor de fortaleza -->
          <div class="mt-2 px-1">
            <div style="background:#e9ecef;height:4px;border-radius:2px;overflow:hidden">
              <div id="pwd-strength-bar" class="pwd-strength"></div>
            </div>
            <small class="text-muted" style="font-size:.72rem">
              Usa mayúsculas, números y símbolos para una contraseña más segura
            </small>
          </div>
        </div>

        <!-- Confirmar contraseña -->
        <div class="mb-4">
          <label for="password_confirmar" class="form-label">
            Confirmar contraseña <span class="text-danger">*</span>
          </label>
          <div class="password-wrap">
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
              <input type="password" id="password_confirmar" name="password_confirmar" class="form-control"
                     placeholder="Repite tu contraseña"
                     required autocomplete="new-password"
                     style="border-radius:0 8px 8px 0 !important">
            </div>
            <button type="button" class="btn-pwd-toggle" style="right:.75rem">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div id="pwd-match-msg" class="mt-1" style="font-size:.75rem"></div>
        </div>

        <!-- Términos -->
        <div class="mb-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="terminos" name="terminos"
                   required <?= isset($_POST['terminos']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="terminos" style="font-size:.85rem">
              Acepto los
              <a href="#" class="link-verde">términos y condiciones</a>
              y la
              <a href="#" class="link-verde">política de privacidad</a>
              de <?= APP_NAME ?>
            </label>
          </div>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-coboce">
          <i class="bi bi-person-check me-2"></i>Crear mi cuenta gratis
        </button>

      </form>

      <div class="text-center mt-4" style="font-size:.88rem">
        ¿Ya tienes cuenta?
        <a href="<?= APP_URL ?>/views/login.php" class="link-verde ms-1">Inicia sesión</a>
      </div>

      <div class="text-center mt-2">
        <a href="<?= APP_URL ?>/index.php" class="text-muted" style="font-size:.82rem">
          <i class="bi bi-arrow-left me-1"></i>Volver a la tienda
        </a>
      </div>

    </div>
  </div>

</div><!-- /auth-page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
// Validación de coincidencia de contraseñas en tiempo real
const pwd1 = document.getElementById('password');
const pwd2 = document.getElementById('password_confirmar');
const msg  = document.getElementById('pwd-match-msg');

function checkMatch() {
  if (!pwd2.value) { msg.textContent = ''; return; }
  if (pwd1.value === pwd2.value) {
    msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Las contraseñas coinciden</span>';
    pwd2.classList.remove('is-invalid');
  } else {
    msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Las contraseñas no coinciden</span>';
    pwd2.classList.add('is-invalid');
  }
}
pwd1.addEventListener('input', checkMatch);
pwd2.addEventListener('input', checkMatch);
</script>
</body>
</html>
