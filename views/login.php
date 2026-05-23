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
  <title>Iniciar sesión | <?= APP_NAME ?></title>

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

      <!-- Logo -->
      <a href="<?= APP_URL ?>/index.php" class="d-flex align-items-center gap-2 mb-4" style="color:white">
        <div class="logo-icon">C</div>
        <div>
          <div class="logo-brand">COBOCE</div>
          <div class="logo-sub">Cerámica &amp; Porcelánato</div>
        </div>
      </a>

      <h2>Bienvenido de <span>vuelta</span></h2>
      <p class="mt-2 mb-4">
        Accede a tu cuenta para gestionar pedidos, revisar tus puntos de fidelidad
        y disfrutar de ofertas exclusivas para clientes registrados.
      </p>

      <!-- Beneficios -->
      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-star-fill"></i></div>
        <div class="auth-benefit-text">
          <strong>Programa de Puntos</strong>
          <span>Gana puntos en cada compra y canjéalos</span>
        </div>
      </div>
      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-truck"></i></div>
        <div class="auth-benefit-text">
          <strong>Delivery en Cobija</strong>
          <span>Entregamos en todas las zonas de la ciudad</span>
        </div>
      </div>
      <div class="auth-benefit">
        <div class="auth-benefit-icon"><i class="bi bi-box-seam"></i></div>
        <div class="auth-benefit-text">
          <strong>Seguimiento de pedidos</strong>
          <span>Rastrea tu pedido en tiempo real</span>
        </div>
      </div>

      <!-- Separador decorativo -->
      <div class="mt-5 pt-3" style="border-top:1px solid rgba(255,255,255,.15)">
        <p style="font-size:.82rem;opacity:.65">
          ¿Aún no tienes cuenta?
          <a href="<?= APP_URL ?>/views/registro.php" style="color:var(--dorado-light);font-weight:600">
            Regístrate gratis
          </a>
        </p>
      </div>

    </div>
  </div>

  <!-- ── LADO DERECHO ───────────────────────────────────── -->
  <div class="auth-right">
    <div class="auth-card">

      <!-- Logo (mobile) -->
      <div class="auth-card-logo d-lg-none">
        <a href="<?= APP_URL ?>/index.php" class="d-flex align-items-center gap-2">
          <div class="logo-icon">C</div>
          <div class="logo-brand" style="color:var(--verde-dark)">COBOCE</div>
        </a>
      </div>

      <div class="auth-card-header">
        <h1 class="auth-title">Iniciar sesión</h1>
        <p class="auth-subtitle">Ingresa tus credenciales para continuar</p>
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

      <!-- Formulario Login -->
      <form action="<?= APP_URL ?>/controllers/AuthController.php" method="POST"
            data-loading novalidate>

        <?= campoCSRF() ?>
        <input type="hidden" name="accion" value="login">

        <!-- Email -->
        <div class="mb-3">
          <label for="email" class="form-label">
            <i class="bi bi-envelope me-1"></i>Correo electrónico
          </label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="tu@email.com"
                   value="<?= limpiar($_POST['email'] ?? '') ?>"
                   required autofocus autocomplete="email">
          </div>
        </div>

        <!-- Contraseña -->
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label for="password" class="form-label mb-0">
              <i class="bi bi-lock me-1"></i>Contraseña
            </label>
            <a href="#" class="link-verde" style="font-size:.8rem"
               data-bs-toggle="modal" data-bs-target="#modalReset">¿Olvidaste tu contraseña?</a>
          </div>
          <div class="password-wrap">
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" id="password" name="password" class="form-control"
                     placeholder="Tu contraseña"
                     required autocomplete="current-password"
                     style="border-radius:0 8px 8px 0 !important">
            </div>
            <button type="button" class="btn-pwd-toggle" style="right:.75rem">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <!-- Recordar -->
        <div class="mb-4 d-flex align-items-center justify-content-between">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="recordar" name="recordar">
            <label class="form-check-label" for="recordar" style="font-size:.85rem">
              Mantener sesión iniciada
            </label>
          </div>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-coboce">
          <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar sesión
        </button>

      </form>

      <div class="auth-divider">o continúa con</div>

      <!-- Registro link -->
      <div class="text-center" style="font-size:.88rem">
        ¿No tienes cuenta?
        <a href="<?= APP_URL ?>/views/registro.php" class="link-verde ms-1">
          Regístrate gratis
        </a>
      </div>

      <!-- Volver -->
      <div class="text-center mt-3">
        <a href="<?= APP_URL ?>/index.php" class="text-muted" style="font-size:.82rem">
          <i class="bi bi-arrow-left me-1"></i>Volver a la tienda
        </a>
      </div>

    </div>
  </div>

</div><!-- /auth-page -->

<!-- ── MODAL RECUPERAR CONTRASEÑA ────────────────────────── -->
<div class="modal fade" id="modalReset" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content" style="border-radius:16px;border:none;overflow:hidden">

      <div class="modal-header" style="background:var(--verde-dark);color:white;border:none">
        <h5 class="modal-title fw-700">
          <i class="bi bi-shield-lock me-2"></i>Recuperar contraseña
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                onclick="resetModal()"></button>
      </div>

      <!-- Paso 1: ingresar email -->
      <div id="resetPaso1" class="modal-body p-4">
        <p style="font-size:.88rem;color:#4B5563" class="mb-3">
          Ingresa tu correo electrónico y te generaremos una contraseña temporal para que puedas ingresar.
        </p>
        <div class="mb-3">
          <label class="form-label fw-600" style="font-size:.85rem">
            <i class="bi bi-envelope me-1"></i>Correo electrónico
          </label>
          <input type="email" id="resetEmail" class="form-control"
                 placeholder="tu@email.com" autocomplete="email">
          <div id="resetError" class="text-danger mt-1" style="font-size:.8rem;display:none"></div>
        </div>
        <button type="button" id="btnReset" class="btn-coboce w-100" onclick="enviarReset()">
          <i class="bi bi-send me-2"></i>Generar contraseña temporal
        </button>
      </div>

      <!-- Paso 2: mostrar contraseña temporal -->
      <div id="resetPaso2" class="modal-body p-4 text-center" style="display:none">
        <div style="width:60px;height:60px;background:#EFF6F2;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
          <i class="bi bi-check-circle-fill" style="font-size:1.8rem;color:var(--verde)"></i>
        </div>
        <h6 class="fw-700 mb-1">¡Contraseña generada!</h6>
        <p style="font-size:.83rem;color:#6B7280" class="mb-3">
          Hola <strong id="resetNombre"></strong>, usa esta contraseña temporal para ingresar:
        </p>
        <div id="resetPassBox" style="
          background:#F0FDF4;border:2px dashed var(--verde);border-radius:10px;
          padding:.9rem 1.2rem;font-size:1.6rem;font-weight:800;
          letter-spacing:4px;color:var(--verde-dark);
          cursor:pointer;user-select:all
        " title="Clic para copiar" onclick="copiarPass(this)">
        </div>
        <div id="resetCopiadoMsg" style="font-size:.75rem;color:var(--verde);margin-top:.4rem;opacity:0;transition:opacity .3s">
          ¡Copiado!
        </div>
        <p style="font-size:.78rem;color:#9CA3AF;margin-top:1rem">
          <i class="bi bi-info-circle me-1"></i>
          Cámbiala desde tu perfil después de iniciar sesión.
        </p>
        <button type="button" class="btn-coboce w-100 mt-2" data-bs-dismiss="modal" onclick="resetModal()">
          <i class="bi bi-box-arrow-in-right me-2"></i>Ir a iniciar sesión
        </button>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
function enviarReset() {
  const email = document.getElementById('resetEmail').value.trim();
  const errEl = document.getElementById('resetError');
  errEl.style.display = 'none';

  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errEl.textContent = 'Ingresa un correo válido.';
    errEl.style.display = 'block';
    return;
  }

  const btn = document.getElementById('btnReset');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando…';

  fetch('<?= APP_URL ?>/controllers/ResetPasswordController.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'email=' + encodeURIComponent(email)
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send me-2"></i>Generar contraseña temporal';

    if (!data.ok) {
      errEl.textContent = data.msg;
      errEl.style.display = 'block';
      return;
    }

    document.getElementById('resetPaso1').style.display = 'none';
    document.getElementById('resetPaso2').style.display = 'block';

    if (data.pass) {
      document.getElementById('resetNombre').textContent = data.nombre;
      document.getElementById('resetPassBox').textContent = data.pass;
    } else {
      // Email no encontrado — mostrar mensaje neutro sin revelar
      document.getElementById('resetPaso2').innerHTML = `
        <div style="padding:1.5rem 0">
          <i class="bi bi-envelope-check" style="font-size:3rem;color:var(--verde)"></i>
          <h6 class="fw-700 mt-3">Revisa tu correo</h6>
          <p style="font-size:.85rem;color:#6B7280">
            Si existe una cuenta con ese correo, recibirás instrucciones para recuperar tu acceso.
          </p>
          <button type="button" class="btn-coboce w-100" data-bs-dismiss="modal" onclick="resetModal()">
            <i class="bi bi-arrow-left me-2"></i>Volver al login
          </button>
        </div>`;
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send me-2"></i>Generar contraseña temporal';
    errEl.textContent = 'Error de conexión. Intenta de nuevo.';
    errEl.style.display = 'block';
  });
}

function copiarPass(el) {
  navigator.clipboard.writeText(el.textContent.trim()).then(() => {
    const msg = document.getElementById('resetCopiadoMsg');
    msg.style.opacity = '1';
    setTimeout(() => msg.style.opacity = '0', 2000);
  });
}

function resetModal() {
  document.getElementById('resetEmail').value = '';
  document.getElementById('resetError').style.display = 'none';
  document.getElementById('resetPaso1').style.display = 'block';
  document.getElementById('resetPaso2').style.display = 'none';
  document.getElementById('btnReset').disabled = false;
  document.getElementById('btnReset').innerHTML = '<i class="bi bi-send me-2"></i>Generar contraseña temporal';
}

// Enviar con Enter en el campo de email
document.getElementById('resetEmail').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') enviarReset();
});
</script>
</body>
</html>
