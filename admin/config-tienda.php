<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

$tituloAdmin = 'Configuración de Tienda';
$paginaAdmin = 'config-tienda.php';
$db          = Database::getConnection();

// ── Crear tabla si aún no existe (primer uso) ──────────────
$db->exec("CREATE TABLE IF NOT EXISTS config_tienda (
    id           TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(100) NOT NULL DEFAULT 'Cerámica COBOCE',
    direccion    VARCHAR(200) NOT NULL DEFAULT '',
    referencia   VARCHAR(200)          DEFAULT NULL,
    ciudad       VARCHAR(80)           DEFAULT 'Cobija, Bolivia',
    telefono     VARCHAR(20)           DEFAULT NULL,
    whatsapp     VARCHAR(20)           DEFAULT NULL,
    horario_sem  VARCHAR(100)          DEFAULT 'Lun–Sáb: 8:00–18:00',
    horario_dom  VARCHAR(100)          DEFAULT 'Domingo: Cerrado',
    maps_url     VARCHAR(500)          DEFAULT NULL,
    activo       TINYINT(1)            DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Insertar fila inicial si está vacía ────────────────────
$total = $db->query("SELECT COUNT(*) FROM config_tienda")->fetchColumn();
if ((int)$total === 0) {
    $db->exec("INSERT INTO config_tienda
        (nombre, direccion, referencia, ciudad, telefono, whatsapp, horario_sem, horario_dom)
        VALUES ('Cerámica COBOCE','Av. Pando','A lado de Centro de Salud Santa Clara',
                'Cobija, Bolivia','73943006','73943006','Lun–Sáb: 8:00–18:00','Domingo: Cerrado')");
}

// ── POST: guardar cambios ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarToken();

    $fields = [
        'nombre'      => trim($_POST['nombre']      ?? ''),
        'direccion'   => trim($_POST['direccion']   ?? ''),
        'referencia'  => trim($_POST['referencia']  ?? '') ?: null,
        'ciudad'      => trim($_POST['ciudad']      ?? ''),
        'telefono'    => preg_replace('/\D/', '', $_POST['telefono']  ?? ''),
        'whatsapp'    => preg_replace('/\D/', '', $_POST['whatsapp']  ?? ''),
        'horario_sem' => trim($_POST['horario_sem'] ?? ''),
        'horario_dom' => trim($_POST['horario_dom'] ?? ''),
        'maps_url'    => trim($_POST['maps_url']    ?? '') ?: null,
    ];

    if (empty($fields['nombre']) || empty($fields['direccion'])) {
        $_SESSION['flash_error'] = 'El nombre y la dirección son obligatorios.';
    } else {
        $db->prepare(
            "UPDATE config_tienda SET
                nombre=:n, direccion=:d, referencia=:r, ciudad=:c,
                telefono=:t, whatsapp=:w, horario_sem=:hs, horario_dom=:hd, maps_url=:mu
             WHERE activo=1 LIMIT 1"
        )->execute([
            ':n'  => $fields['nombre'],
            ':d'  => $fields['direccion'],
            ':r'  => $fields['referencia'],
            ':c'  => $fields['ciudad'],
            ':t'  => $fields['telefono'],
            ':w'  => $fields['whatsapp'],
            ':hs' => $fields['horario_sem'],
            ':hd' => $fields['horario_dom'],
            ':mu' => $fields['maps_url'],
        ]);
        $_SESSION['flash_ok'] = 'Datos de la tienda actualizados correctamente.';
    }
    header('Location: ' . APP_URL . '/admin/config-tienda.php');
    exit;
}

// ── Cargar configuración actual ────────────────────────────
$tienda = $db->query("SELECT * FROM config_tienda WHERE activo=1 LIMIT 1")->fetch();

require_once dirname(__DIR__) . '/admin/includes/admin_header.php';
?>

<div class="admin-main">
<div class="container-fluid py-4 px-4">

  <!-- ── Cabecera ──────────────────────────────────────────── -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,var(--verde-dark),var(--verde));
                display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:white;flex-shrink:0">
      <i class="bi bi-shop-window"></i>
    </div>
    <div>
      <h1 style="font-size:1.4rem;font-weight:800;color:var(--verde-dark);margin:0">
        Configuración de Tienda
      </h1>
      <div style="font-size:.8rem;color:#6b7280">
        Datos del local físico — se muestran en el checkout de retiro en tienda
      </div>
    </div>
  </div>

  <!-- ── Flash ─────────────────────────────────────────────── -->
  <?php if (!empty($_SESSION['flash_ok'])): ?>
  <div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="bi bi-check-circle-fill"></i>
    <?= limpiar($_SESSION['flash_ok']) ?>
  </div>
  <?php unset($_SESSION['flash_ok']); endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= limpiar($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); endif; ?>

  <div class="row g-4">

    <!-- ── Formulario ─────────────────────────────────────── -->
    <div class="col-lg-7">
      <form method="POST" action="">
        <?= campoCSRF() ?>

        <div class="admin-card mb-4">
          <div class="admin-card-header">
            <i class="bi bi-building me-2"></i>Datos del local
          </div>
          <div class="admin-card-body">

            <div class="mb-3">
              <label class="form-label fw-600">Nombre del negocio</label>
              <input type="text" name="nombre" class="form-control"
                     value="<?= limpiar($tienda['nombre'] ?? 'Cerámica COBOCE') ?>"
                     placeholder="Cerámica COBOCE" required>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-12 col-sm-7">
                <label class="form-label fw-600">
                  <i class="bi bi-geo-alt me-1 text-danger"></i>Dirección
                </label>
                <input type="text" name="direccion" class="form-control"
                       value="<?= limpiar($tienda['direccion'] ?? '') ?>"
                       placeholder="Av. Pando" required>
              </div>
              <div class="col-12 col-sm-5">
                <label class="form-label fw-600">Ciudad</label>
                <input type="text" name="ciudad" class="form-control"
                       value="<?= limpiar($tienda['ciudad'] ?? 'Cobija, Bolivia') ?>"
                       placeholder="Cobija, Bolivia">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-600">Referencia / punto de referencia</label>
              <input type="text" name="referencia" class="form-control"
                     value="<?= limpiar($tienda['referencia'] ?? '') ?>"
                     placeholder="A lado de Centro de Salud Santa Clara">
              <div class="form-text">Ayuda al cliente a encontrar la tienda más fácilmente.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-600">
                <i class="bi bi-map me-1" style="color:#0EA5E9"></i>
                URL Google Maps <small class="text-muted">(opcional)</small>
              </label>
              <input type="url" name="maps_url" class="form-control"
                     value="<?= limpiar($tienda['maps_url'] ?? '') ?>"
                     placeholder="https://maps.google.com/...">
              <div class="form-text">Si la completas, aparecerá un botón "Ver en mapa" en el checkout.</div>
            </div>

          </div>
        </div>

        <div class="admin-card mb-4">
          <div class="admin-card-header">
            <i class="bi bi-telephone me-2"></i>Contacto
          </div>
          <div class="admin-card-body">

            <div class="row g-3">
              <div class="col-12 col-sm-6">
                <label class="form-label fw-600">
                  <i class="bi bi-telephone-fill me-1 text-muted"></i>Teléfono
                </label>
                <div class="input-group">
                  <span class="input-group-text">+591</span>
                  <input type="text" name="telefono" class="form-control"
                         value="<?= limpiar($tienda['telefono'] ?? '') ?>"
                         placeholder="73943006" maxlength="15">
                </div>
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label fw-600">
                  <i class="bi bi-whatsapp me-1" style="color:#25D366"></i>WhatsApp
                </label>
                <div class="input-group">
                  <span class="input-group-text">+591</span>
                  <input type="text" name="whatsapp" class="form-control"
                         value="<?= limpiar($tienda['whatsapp'] ?? '') ?>"
                         placeholder="73943006" maxlength="15">
                </div>
                <div class="form-text">Se usa para que el cliente confirme su pedido.</div>
              </div>
            </div>

          </div>
        </div>

        <div class="admin-card mb-4">
          <div class="admin-card-header">
            <i class="bi bi-clock me-2"></i>Horario de atención
          </div>
          <div class="admin-card-body">

            <div class="row g-3">
              <div class="col-12 col-sm-6">
                <label class="form-label fw-600">Lunes a sábado</label>
                <input type="text" name="horario_sem" class="form-control"
                       value="<?= limpiar($tienda['horario_sem'] ?? 'Lun–Sáb: 8:00–18:00') ?>"
                       placeholder="Lun–Sáb: 8:00–18:00">
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label fw-600">Domingo / feriados</label>
                <input type="text" name="horario_dom" class="form-control"
                       value="<?= limpiar($tienda['horario_dom'] ?? 'Domingo: Cerrado') ?>"
                       placeholder="Domingo: Cerrado">
              </div>
            </div>

          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-success px-4 fw-600">
            <i class="bi bi-floppy me-2"></i>Guardar cambios
          </button>
          <a href="<?= APP_URL ?>/views/checkout/paso1-entrega.php"
             target="_blank"
             class="btn btn-outline-secondary px-4">
            <i class="bi bi-eye me-2"></i>Ver en tienda
          </a>
        </div>

      </form>
    </div>

    <!-- ── Vista previa ───────────────────────────────────── -->
    <div class="col-lg-5">
      <div class="admin-card" style="position:sticky;top:80px">
        <div class="admin-card-header">
          <i class="bi bi-phone me-2"></i>Vista previa — Checkout del cliente
        </div>
        <div class="admin-card-body p-3">

          <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
                      color:#6b7280;margin-bottom:.75rem">
            Así ve el cliente al elegir "Recoger en tienda"
          </div>

          <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">

            <div style="background:#f0fdf4;padding:.7rem 1rem;border-bottom:1px solid #e5e7eb;
                        font-size:.82rem;font-weight:700;color:#145730;display:flex;align-items:center;gap:.5rem">
              <i class="bi bi-shop" style="color:#1A6B3A"></i>
              Información de la tienda
            </div>

            <div style="padding:.9rem 1rem;display:flex;flex-direction:column;gap:.75rem">

              <div style="display:flex;gap:.75rem;align-items:flex-start">
                <i class="bi bi-geo-alt-fill" style="color:#1A6B3A;font-size:1.1rem;margin-top:.1rem;flex-shrink:0"></i>
                <div>
                  <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7280">Dirección</div>
                  <div id="prev-dir" style="font-size:.88rem;font-weight:600;color:#2D2D2D;line-height:1.4">
                    <?= limpiar($tienda['direccion'] ?? 'Av. Pando') ?>
                    <?php if ($tienda['referencia'] ?? ''): ?>
                      <br><span style="font-weight:400;font-size:.8rem;color:#6b7280"><?= limpiar($tienda['referencia']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div id="prev-ciudad" style="font-size:.78rem;color:#6b7280;margin-top:.1rem">
                    <?= limpiar($tienda['ciudad'] ?? 'Cobija, Bolivia') ?>
                  </div>
                </div>
              </div>

              <div style="display:flex;gap:.75rem;align-items:flex-start">
                <i class="bi bi-clock-fill" style="color:#1A6B3A;font-size:1.1rem;margin-top:.1rem;flex-shrink:0"></i>
                <div>
                  <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7280">Horario</div>
                  <div id="prev-hor" style="font-size:.88rem;font-weight:600;color:#2D2D2D;line-height:1.5">
                    <?= limpiar($tienda['horario_sem'] ?? 'Lun–Sáb: 8:00–18:00') ?><br>
                    <?= limpiar($tienda['horario_dom'] ?? 'Domingo: Cerrado') ?>
                  </div>
                </div>
              </div>

              <div style="display:flex;gap:.75rem;align-items:flex-start">
                <i class="bi bi-telephone-fill" style="color:#1A6B3A;font-size:1.1rem;margin-top:.1rem;flex-shrink:0"></i>
                <div>
                  <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7280">Teléfono</div>
                  <div id="prev-tel" style="font-size:.88rem;font-weight:600;color:#2D2D2D">
                    +591 <?= limpiar($tienda['telefono'] ?? '73943006') ?>
                  </div>
                </div>
              </div>

              <div style="display:flex;gap:.75rem;align-items:flex-start">
                <i class="bi bi-whatsapp" style="color:#25D366;font-size:1.1rem;margin-top:.1rem;flex-shrink:0"></i>
                <div>
                  <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7280">WhatsApp</div>
                  <div id="prev-wa" style="font-size:.88rem;font-weight:600;color:#2D2D2D">
                    +591 <?= limpiar($tienda['whatsapp'] ?? '73943006') ?>
                  </div>
                </div>
              </div>

            </div>

            <div style="background:#fffbeb;border-top:1px solid #fde68a;padding:.65rem 1rem;
                        font-size:.78rem;color:#92400e;display:flex;gap:.5rem;align-items:flex-start">
              <i class="bi bi-info-circle-fill" style="flex-shrink:0;margin-top:.05rem"></i>
              Te avisaremos por WhatsApp cuando tu pedido esté listo para retirar.
              Generalmente en <strong>1–2 horas hábiles</strong>.
            </div>

          </div>

          <div class="mt-3 text-center">
            <a href="<?= APP_URL ?>/views/checkout/paso1-entrega.php"
               target="_blank"
               style="font-size:.78rem;color:#1A6B3A;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem">
              <i class="bi bi-box-arrow-up-right"></i>
              Ver checkout completo
            </a>
          </div>

        </div>
      </div>
    </div>

  </div>
</div>
</div>

<style>
.admin-card { background:white;border-radius:12px;border:1px solid #e5e7eb;box-shadow:0 1px 4px rgba(0,0,0,.06); }
.admin-card-header { padding:.85rem 1.25rem;background:#f9fafb;border-bottom:1px solid #e5e7eb;
                     font-weight:700;font-size:.88rem;color:#145730;border-radius:12px 12px 0 0;
                     display:flex;align-items:center; }
.admin-card-body { padding:1.25rem; }
.fw-600 { font-weight:600; }
</style>

<?php require_once dirname(__DIR__) . '/admin/includes/admin_footer.php'; ?>
