<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';

requiereLogin(APP_URL . '/views/checkout/paso1-entrega.php');

$carrito = $_SESSION['carrito'] ?? [];
if (empty($carrito)) {
    flash('advertencia', 'Tu carrito está vacío.');
    redirigir(APP_URL . '/views/catalogo.php');
}

$db          = Database::getConnection();
$zonas       = $db->query("SELECT * FROM zonas_delivery WHERE activo=1 ORDER BY costo ASC")->fetchAll();
$configTienda = $db->query("SELECT * FROM config_tienda WHERE activo=1 LIMIT 1")->fetch() ?: [
    'nombre'      => 'Cerámica COBOCE',
    'direccion'   => 'Av. Pando',
    'referencia'  => 'A lado de Centro de Salud Santa Clara',
    'ciudad'      => 'Cobija, Bolivia',
    'telefono'    => '73943006',
    'whatsapp'    => '73943006',
    'horario_sem' => 'Lun–Sáb: 8:00–18:00',
    'horario_dom' => 'Domingo: Cerrado',
    'maps_url'    => null,
];

// Limpiar confirmación de pedido anterior al iniciar nuevo checkout
unset($_SESSION['ultimo_pedido']);

$tiempoZona = [
    'Zona Centro'     => '30–60 min',
    'Zona Norte'      => '45–90 min',
    'Zona Sur'        => '45–90 min',
    'Zona Este'       => '60–120 min',
    'Zona Oeste'      => '60–120 min',
    'Fuera de Cobija' => '1–3 días hábiles',
];

$saved = $_SESSION['checkout'] ?? [];

// ── POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Sesión expirada. Vuelve a intentarlo.');
        redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
    }

    $tipo = $_POST['tipo_entrega'] ?? '';
    if (!in_array($tipo, ['delivery', 'retiro_tienda'], true)) {
        flash('error', 'Selecciona una opción de entrega.');
        redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
    }

    if ($tipo === 'delivery') {
        $zonaId    = limpiarInt($_POST['zona_id'] ?? 0);
        $direccion = trim($_POST['direccion_entrega'] ?? '');
        $referencia= trim($_POST['referencia'] ?? '');

        if (!$zonaId) {
            flash('error', 'Selecciona la zona de entrega.');
            redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
        }
        if (mb_strlen($direccion) < 5) {
            flash('error', 'Ingresa una dirección de entrega válida.');
            redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
        }

        $stmt = $db->prepare("SELECT * FROM zonas_delivery WHERE id = :id AND activo = 1");
        $stmt->execute([':id' => $zonaId]);
        $zona = $stmt->fetch();
        if (!$zona) {
            flash('error', 'La zona seleccionada no es válida.');
            redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
        }

        $_SESSION['checkout']['tipo_entrega']     = 'delivery';
        $_SESSION['checkout']['zona_id']          = (int)$zona['id'];
        $_SESSION['checkout']['zona_nombre']      = $zona['nombre'];
        $_SESSION['checkout']['costo_delivery']   = (float)$zona['costo'];
        $_SESSION['checkout']['direccion_entrega']= $direccion;
        $_SESSION['checkout']['referencia']       = $referencia;
        $_SESSION['checkout']['fecha_entrega']    = trim($_POST['fecha_entrega'] ?? '');

    } else {
        $_SESSION['checkout']['tipo_entrega']     = 'retiro_tienda';
        $_SESSION['checkout']['zona_id']          = null;
        $_SESSION['checkout']['zona_nombre']      = '';
        $_SESSION['checkout']['costo_delivery']   = 0.0;
        $_SESSION['checkout']['direccion_entrega']= '';
        $_SESSION['checkout']['referencia']       = '';
        $_SESSION['checkout']['fecha_entrega']    = trim($_POST['fecha_entrega'] ?? '');
    }

    redirigir(APP_URL . '/views/checkout/paso2-puntos.php');
}

// ── Calcular resumen carrito ───────────────────────────────
$subtotal    = 0.0;
$totalPuntos = 0;
foreach ($carrito as $item) {
    $p           = $item['oferta'] ?? $item['precio'];
    $subtotal   += $p * $item['cantidad'];
    $totalPuntos+= (int)$item['puntos'] * (int)ceil($item['cantidad']);
}

// Datos pre-llenados desde sesión
$tipoGuardado = $saved['tipo_entrega']      ?? 'delivery';
$zonaGuardada = (int)($saved['zona_id']     ?? 0);
$dirGuardada  = $saved['direccion_entrega'] ?? '';
$refGuardada  = $saved['referencia']        ?? '';
$fechaGuardada= $saved['fecha_entrega']     ?? '';

$titulo = 'Checkout – Entrega';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ── BREADCRUMB ─────────────────────────────────────────── -->
<div style="background:white;border-bottom:1px solid var(--gris-borde)">
  <div class="container py-2">
    <nav><ol class="breadcrumb mb-0" style="font-size:.82rem">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php" class="text-verde">Inicio</a></li>
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/carrito.php" class="text-verde">Carrito</a></li>
      <li class="breadcrumb-item active">Checkout</li>
    </ol></nav>
  </div>
</div>

<div class="container py-4">

  <!-- ── STEPPER ──────────────────────────────────────────── -->
  <div class="checkout-stepper mb-4">
    <div class="stepper-track">

      <div class="stepper-step active">
        <div class="step-circle">
          <i class="bi bi-truck"></i>
        </div>
        <div class="step-label">Entrega</div>
      </div>

      <div class="stepper-line"></div>

      <div class="stepper-step">
        <div class="step-circle">
          <span>2</span>
        </div>
        <div class="step-label">Puntos</div>
      </div>

      <div class="stepper-line"></div>

      <div class="stepper-step">
        <div class="step-circle">
          <span>3</span>
        </div>
        <div class="step-label">Pago</div>
      </div>

      <div class="stepper-line"></div>

      <div class="stepper-step">
        <div class="step-circle">
          <span>4</span>
        </div>
        <div class="step-label">Confirmar</div>
      </div>

    </div>
  </div>

  <form method="POST" action="" id="formEntrega">
    <?= campoCSRF() ?>

    <div class="row g-4">

      <!-- ════════════════════════════════════════════════════
           COLUMNA IZQUIERDA — Opciones de entrega
           ════════════════════════════════════════════════════ -->
      <div class="col-lg-8">

        <!-- ── Tarjeta título paso ───────────────────────── -->
        <div class="checkout-card mb-3">
          <div class="checkout-card-header">
            <div class="step-badge">Paso 1 de 4</div>
            <h2 class="checkout-title">
              <i class="bi bi-truck me-2"></i>¿Cómo recibirás tu pedido?
            </h2>
          </div>
        </div>

        <!-- ── Selector tipo de entrega ─────────────────── -->
        <div class="checkout-card mb-3">
          <div class="row g-3">

            <!-- Opción: Delivery -->
            <div class="col-12 col-sm-6">
              <label class="entrega-option" id="optDelivery">
                <input type="radio" name="tipo_entrega" value="delivery"
                       id="tipoDelivery"
                       <?= $tipoGuardado === 'delivery' ? 'checked' : '' ?>
                       onchange="toggleEntrega()">
                <div class="entrega-option-inner">
                  <div class="entrega-icon">
                    <i class="bi bi-truck"></i>
                  </div>
                  <div>
                    <div class="entrega-nombre">Delivery a domicilio</div>
                    <div class="entrega-desc">
                      Recibe tu pedido en la puerta de tu casa
                    </div>
                  </div>
                  <i class="bi bi-check-circle-fill entrega-check"></i>
                </div>
              </label>
            </div>

            <!-- Opción: Retiro en tienda -->
            <div class="col-12 col-sm-6">
              <label class="entrega-option" id="optRetiro">
                <input type="radio" name="tipo_entrega" value="retiro_tienda"
                       id="tipoRetiro"
                       <?= $tipoGuardado === 'retiro_tienda' ? 'checked' : '' ?>
                       onchange="toggleEntrega()">
                <div class="entrega-option-inner">
                  <div class="entrega-icon retiro">
                    <i class="bi bi-shop"></i>
                  </div>
                  <div>
                    <div class="entrega-nombre">Recoger en tienda</div>
                    <div class="entrega-desc">
                      Sin costo de delivery — retira cuando quieras
                    </div>
                  </div>
                  <i class="bi bi-check-circle-fill entrega-check"></i>
                </div>
              </label>
            </div>

          </div>
        </div>

        <!-- ── Panel: Delivery ──────────────────────────── -->
        <div id="panelDelivery" class="checkout-card mb-3"
             style="<?= $tipoGuardado === 'retiro_tienda' ? 'display:none' : '' ?>">

          <h5 class="section-heading">
            <i class="bi bi-geo-alt me-2"></i>Datos de entrega
          </h5>

          <!-- Zona de delivery -->
          <div class="mb-3">
            <label class="form-label" for="zona_id">
              Zona de entrega <span class="text-danger">*</span>
            </label>
            <select name="zona_id" id="zona_id" class="form-select"
                    onchange="actualizarZona(this)">
              <option value="">— Selecciona tu zona —</option>
              <?php foreach ($zonas as $z): ?>
              <option value="<?= (int)$z['id'] ?>"
                      data-costo="<?= (float)$z['costo'] ?>"
                      data-nombre="<?= limpiar($z['nombre']) ?>"
                      data-tiempo="<?= limpiar($tiempoZona[$z['nombre']] ?? '1–2 horas') ?>"
                      <?= $zonaGuardada === (int)$z['id'] ? 'selected' : '' ?>>
                <?= limpiar($z['nombre']) ?> — Bs. <?= number_format((float)$z['costo'], 2) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Info zona seleccionada -->
          <div id="infoZona" class="zona-info-box mb-3"
               style="<?= $zonaGuardada ? '' : 'display:none' ?>">
            <div class="row g-2">
              <div class="col-6">
                <div class="zona-info-item">
                  <i class="bi bi-cash-coin"></i>
                  <div>
                    <div class="zona-info-label">Costo de delivery</div>
                    <div class="zona-info-val" id="infoCosto">
                      <?php if ($zonaGuardada):
                        foreach ($zonas as $z) {
                            if ((int)$z['id'] === $zonaGuardada) {
                                echo 'Bs. ' . number_format((float)$z['costo'], 2);
                                break;
                            }
                        }
                      endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-6">
                <div class="zona-info-item">
                  <i class="bi bi-clock"></i>
                  <div>
                    <div class="zona-info-label">Tiempo estimado</div>
                    <div class="zona-info-val" id="infoTiempo">
                      <?php if ($zonaGuardada):
                        foreach ($zonas as $z) {
                            if ((int)$z['id'] === $zonaGuardada) {
                                echo limpiar($tiempoZona[$z['nombre']] ?? '1–2 horas');
                                break;
                            }
                        }
                      endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Dirección -->
          <div class="mb-3">
            <label class="form-label" for="direccion_entrega">
              Dirección completa <span class="text-danger">*</span>
            </label>
            <textarea name="direccion_entrega" id="direccion_entrega"
                      class="form-control" rows="2"
                      placeholder="Ej: Av. Cobija #123, entre calle 5 y 6, Barrio Central"
                      maxlength="250"><?= limpiar($dirGuardada) ?></textarea>
            <div class="form-text">Incluye barrio, avenida y número de casa.</div>
          </div>

          <!-- Referencia -->
          <div class="mb-3">
            <label class="form-label" for="referencia">
              Punto de referencia <small class="text-muted">(opcional)</small>
            </label>
            <input type="text" name="referencia" id="referencia"
                   class="form-control"
                   placeholder="Ej: Frente al parque, casa pintada de amarillo"
                   maxlength="200"
                   value="<?= limpiar($refGuardada) ?>">
          </div>

          <!-- Fecha preferida -->
          <div class="mb-1">
            <label class="form-label" for="fecha_entrega_delivery">
              Fecha de entrega preferida <small class="text-muted">(opcional)</small>
            </label>
            <input type="date" name="fecha_entrega" id="fecha_entrega_delivery"
                   class="form-control"
                   min="<?= date('Y-m-d') ?>"
                   max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
                   value="<?= limpiar($fechaGuardada) ?>"
                   style="max-width:220px">
            <div class="form-text">Si no eliges fecha lo enviaremos lo antes posible.</div>
          </div>

        </div><!-- /panelDelivery -->

        <!-- ── Panel: Retiro en tienda ──────────────────── -->
        <div id="panelRetiro" class="checkout-card mb-3"
             style="<?= $tipoGuardado !== 'retiro_tienda' ? 'display:none' : '' ?>">

          <h5 class="section-heading">
            <i class="bi bi-shop me-2"></i>Información de la tienda
          </h5>

          <div class="retiro-info">
            <div class="row g-3">

              <div class="col-12 col-sm-6">
                <div class="retiro-item">
                  <i class="bi bi-geo-alt-fill"></i>
                  <div>
                    <div class="retiro-item-title">Dirección</div>
                    <div class="retiro-item-val">
                      <?= limpiar($configTienda['direccion']) ?>
                      <?php if (!empty($configTienda['referencia'])): ?>
                        <br><small style="font-weight:400;color:var(--texto-suave)"><?= limpiar($configTienda['referencia']) ?></small>
                      <?php endif; ?>
                      <br><?= limpiar($configTienda['ciudad']) ?>
                    </div>
                    <?php if (!empty($configTienda['maps_url'])): ?>
                    <a href="<?= limpiar($configTienda['maps_url']) ?>" target="_blank" rel="noopener"
                       class="text-verde" style="font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:.25rem;margin-top:.3rem">
                      <i class="bi bi-map-fill"></i> Ver en mapa
                    </a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-12 col-sm-6">
                <div class="retiro-item">
                  <i class="bi bi-clock-fill"></i>
                  <div>
                    <div class="retiro-item-title">Horario de atención</div>
                    <div class="retiro-item-val">
                      <?= limpiar($configTienda['horario_sem']) ?><br>
                      <?= limpiar($configTienda['horario_dom']) ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-sm-6">
                <div class="retiro-item">
                  <i class="bi bi-telephone-fill"></i>
                  <div>
                    <div class="retiro-item-title">Teléfono</div>
                    <div class="retiro-item-val">
                      <a href="tel:+591<?= limpiar($configTienda['telefono']) ?>"
                         style="color:inherit;text-decoration:none">
                        +591 <?= limpiar($configTienda['telefono']) ?>
                      </a>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-sm-6">
                <div class="retiro-item">
                  <i class="bi bi-whatsapp" style="color:#25D366"></i>
                  <div>
                    <div class="retiro-item-title">WhatsApp</div>
                    <div class="retiro-item-val">
                      <a href="https://wa.me/591<?= limpiar($configTienda['whatsapp']) ?>"
                         target="_blank" rel="noopener"
                         style="color:inherit;text-decoration:none">
                        +591 <?= limpiar($configTienda['whatsapp']) ?>
                      </a>
                    </div>
                  </div>
                </div>
              </div>

            </div>

            <div class="alert-retiro mt-3">
              <i class="bi bi-info-circle-fill me-2"></i>
              Te avisaremos por WhatsApp al <strong>+591 <?= limpiar($configTienda['whatsapp']) ?></strong>
              cuando tu pedido esté listo para retirar.
              Generalmente en <strong>1–2 horas hábiles</strong>.
            </div>

            <!-- Fecha preferida retiro -->
            <div class="mt-3">
              <label class="form-label" for="fecha_entrega_retiro">
                Fecha de retiro preferida <small class="text-muted">(opcional)</small>
              </label>
              <input type="date" name="fecha_entrega" id="fecha_entrega_retiro"
                     class="form-control"
                     min="<?= date('Y-m-d') ?>"
                     max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
                     value="<?= limpiar($fechaGuardada) ?>"
                     style="max-width:220px">
            </div>
          </div>

        </div><!-- /panelRetiro -->

        <!-- ── Navegación ────────────────────────────────── -->
        <div class="d-flex flex-wrap justify-content-between gap-2 mt-2">
          <a href="<?= APP_URL ?>/views/carrito.php"
             class="btn btn-outline-secondary px-4" style="border-radius:8px">
            <i class="bi bi-arrow-left me-2"></i>Volver al carrito
          </a>
          <button type="submit" class="btn-coboce" style="width:auto;padding:.72rem 2rem;border-radius:8px">
            Continuar al paso 2 <i class="bi bi-arrow-right ms-2"></i>
          </button>
        </div>

      </div><!-- /col izquierda -->


      <!-- ════════════════════════════════════════════════════
           COLUMNA DERECHA — Resumen del pedido
           ════════════════════════════════════════════════════ -->
      <div class="col-lg-4">
        <div class="order-summary-checkout" style="position:sticky;top:90px">

          <h5 class="fw-700 mb-3" style="color:var(--verde-dark)">
            <i class="bi bi-receipt me-2"></i>Resumen del pedido
          </h5>

          <!-- Items -->
          <div class="summary-items mb-3">
            <?php foreach ($carrito as $item):
              $pu = $item['oferta'] ?? $item['precio'];
            ?>
            <div class="summary-item">
              <div class="summary-item-img">
                <?php if ($item['imagen'] && file_exists(UPLOADS_PATH . '/' . $item['imagen'])): ?>
                  <img src="<?= UPLOADS_URL . '/' . limpiar($item['imagen']) ?>"
                       alt="<?= limpiar($item['nombre']) ?>">
                <?php else: ?>
                  <div class="summary-item-img-ph">
                    <i class="bi bi-image"></i>
                  </div>
                <?php endif; ?>
                <span class="summary-item-qty"><?= $item['cantidad'] <= 9 ? (int)$item['cantidad'] : '9+' ?></span>
              </div>
              <div class="flex-grow-1" style="min-width:0">
                <div class="summary-item-name"><?= limpiar(truncar($item['nombre'], 35)) ?></div>
                <div class="summary-item-sub">
                  <?= limpiar($item['unidad']) ?> ×<?= $item['cantidad'] ?>
                  <?php if ($item['oferta']): ?>
                    <span class="text-danger fw-600">Oferta</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="summary-item-precio fw-600">
                <?= precio($pu * $item['cantidad']) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Subtotales -->
          <div class="summary-totals">
            <div class="summary-row">
              <span>Subtotal</span>
              <span class="fw-600"><?= precio($subtotal) ?></span>
            </div>
            <div class="summary-row" id="rowDelivery">
              <span>Delivery</span>
              <span id="costoDeliverySummary" class="fw-600 text-muted">
                <?php if ($tipoGuardado === 'retiro_tienda'): ?>
                  <span class="text-success">Gratis</span>
                <?php elseif ($zonaGuardada && isset($saved['costo_delivery'])): ?>
                  <?= precio((float)$saved['costo_delivery']) ?>
                <?php else: ?>
                  <span style="font-size:.82rem">Por definir</span>
                <?php endif; ?>
              </span>
            </div>
            <?php if ($totalPuntos > 0): ?>
            <div class="summary-row" style="font-size:.85rem;color:var(--dorado-dark)">
              <span><i class="bi bi-star-fill me-1"></i>Puntos a ganar</span>
              <span>+<?= $totalPuntos ?> pts</span>
            </div>
            <?php endif; ?>
          </div>

          <div class="summary-total-final">
            <span>Total estimado</span>
            <span id="totalFinalSummary">
              <?php
              $deliveryCost = ($tipoGuardado === 'retiro_tienda')
                  ? 0.0
                  : (float)($saved['costo_delivery'] ?? 0.0);
              echo precio($subtotal + $deliveryCost);
              ?>
            </span>
          </div>

          <!-- Pasos restantes -->
          <div class="pasos-restantes mt-3">
            <div class="paso-mini completed">
              <i class="bi bi-check-circle-fill"></i>
              <span>Carrito</span>
            </div>
            <div class="paso-mini active">
              <i class="bi bi-truck"></i>
              <span>Entrega</span>
            </div>
            <div class="paso-mini">
              <i class="bi bi-star"></i>
              <span>Puntos</span>
            </div>
            <div class="paso-mini">
              <i class="bi bi-credit-card"></i>
              <span>Pago</span>
            </div>
            <div class="paso-mini">
              <i class="bi bi-bag-check"></i>
              <span>Confirmar</span>
            </div>
          </div>

        </div>
      </div><!-- /col derecha -->

    </div><!-- /row -->
  </form>

</div><!-- /container -->

<style>
/* ── STEPPER ──────────────────────────────────────────────── */
.checkout-stepper {
  background: white;
  border-radius: var(--radio);
  padding: 1.5rem 2rem;
  box-shadow: var(--sombra);
  border: 1px solid var(--gris-borde);
}
.stepper-track {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  max-width: 600px;
  margin: 0 auto;
}
.stepper-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .4rem;
  flex-shrink: 0;
}
.step-circle {
  width: 44px; height: 44px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--gris-borde);
  color: var(--texto-suave);
  font-size: .95rem;
  font-weight: 700;
  border: 2.5px solid var(--gris-borde);
  transition: var(--trans);
}
.stepper-step.active .step-circle {
  background: var(--verde);
  border-color: var(--verde);
  color: white;
  box-shadow: 0 4px 14px rgba(26,107,58,.35);
}
.stepper-step.completed .step-circle {
  background: var(--verde-dark);
  border-color: var(--verde-dark);
  color: white;
}
.step-label {
  font-size: .73rem;
  font-weight: 600;
  color: var(--texto-suave);
  text-transform: uppercase;
  letter-spacing: .5px;
  white-space: nowrap;
}
.stepper-step.active .step-label { color: var(--verde-dark); }
.stepper-step.completed .step-label { color: var(--verde-dark); }
.stepper-line {
  flex: 1;
  height: 2.5px;
  background: var(--gris-borde);
  margin: 0 6px;
  margin-bottom: 22px;
  min-width: 30px;
}

/* ── CARDS CHECKOUT ───────────────────────────────────────── */
.checkout-card {
  background: white;
  border-radius: var(--radio);
  padding: 1.5rem;
  border: 1px solid var(--gris-borde);
  box-shadow: var(--sombra);
}
.checkout-card-header { margin-bottom: 0; }
.step-badge {
  display: inline-block;
  background: rgba(26,107,58,.1);
  color: var(--verde);
  font-size: .7rem; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase;
  padding: .2rem .8rem; border-radius: 50px;
  margin-bottom: .5rem;
}
.checkout-title {
  font-size: 1.4rem; font-weight: 700;
  color: var(--verde-dark); margin: 0;
}
.section-heading {
  font-size: 1rem; font-weight: 700;
  color: var(--verde-dark);
  margin-bottom: 1.2rem;
  padding-bottom: .75rem;
  border-bottom: 1px solid var(--gris-borde);
}

/* ── OPCIONES ENTREGA ─────────────────────────────────────── */
.entrega-option { display: block; cursor: pointer; height: 100%; }
.entrega-option input { display: none; }
.entrega-option-inner {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.1rem 1.2rem;
  border: 2px solid var(--gris-borde);
  border-radius: var(--radio);
  background: white;
  transition: var(--trans);
  height: 100%;
  position: relative;
}
.entrega-option-inner:hover {
  border-color: var(--verde);
  background: rgba(26,107,58,.03);
}
.entrega-option input:checked + .entrega-option-inner {
  border-color: var(--verde);
  background: rgba(26,107,58,.05);
  box-shadow: 0 0 0 3px rgba(26,107,58,.12);
}
.entrega-icon {
  width: 52px; height: 52px; flex-shrink: 0;
  background: rgba(26,107,58,.1);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem; color: var(--verde);
  transition: var(--trans);
}
.entrega-icon.retiro {
  background: rgba(201,168,76,.12);
  color: var(--dorado-dark);
}
.entrega-option input:checked + .entrega-option-inner .entrega-icon {
  background: var(--verde);
  color: white;
}
.entrega-option input:checked + .entrega-option-inner .entrega-icon.retiro {
  background: var(--dorado);
  color: var(--verde-dark);
}
.entrega-nombre { font-weight: 700; font-size: .95rem; color: var(--texto); }
.entrega-desc   { font-size: .78rem; color: var(--texto-suave); margin-top: .15rem; }
.entrega-check  {
  margin-left: auto; flex-shrink: 0;
  font-size: 1.3rem; color: var(--verde);
  opacity: 0; transition: opacity .2s;
}
.entrega-option input:checked + .entrega-option-inner .entrega-check { opacity: 1; }

/* ── ZONA INFO BOX ────────────────────────────────────────── */
.zona-info-box {
  background: rgba(26,107,58,.05);
  border: 1px solid rgba(26,107,58,.2);
  border-radius: var(--radio);
  padding: 1rem;
}
.zona-info-item {
  display: flex; align-items: center; gap: .75rem;
}
.zona-info-item > i {
  font-size: 1.5rem; color: var(--verde);
  flex-shrink: 0;
}
.zona-info-label { font-size: .72rem; color: var(--texto-suave); text-transform: uppercase; letter-spacing: .5px; }
.zona-info-val   { font-size: 1rem; font-weight: 700; color: var(--verde-dark); }

/* ── PANEL RETIRO ─────────────────────────────────────────── */
.retiro-item {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: .85rem;
  background: var(--gris-bg);
  border-radius: 8px;
  border: 1px solid var(--gris-borde);
}
.retiro-item > i { font-size: 1.3rem; color: var(--verde); flex-shrink: 0; margin-top: .1rem; }
.retiro-item-title { font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: var(--texto-suave); margin-bottom: .15rem; }
.retiro-item-val   { font-size: .88rem; font-weight: 600; color: var(--texto); line-height: 1.4; }

.alert-retiro {
  background: rgba(201,168,76,.1);
  border: 1px solid rgba(201,168,76,.35);
  border-radius: 8px;
  padding: .85rem 1rem;
  font-size: .84rem;
  color: var(--verde-dark);
}

/* ── RESUMEN CHECKOUT ─────────────────────────────────────── */
.order-summary-checkout {
  background: white;
  border-radius: var(--radio);
  padding: 1.5rem;
  border: 1px solid var(--gris-borde);
  box-shadow: var(--sombra);
}
.summary-items {
  max-height: 280px;
  overflow-y: auto;
  border: 1px solid var(--gris-borde);
  border-radius: 8px;
  padding: .5rem;
}
.summary-item {
  display: flex; align-items: center; gap: .75rem;
  padding: .5rem;
  border-bottom: 1px solid var(--gris-borde);
}
.summary-item:last-child { border-bottom: none; }
.summary-item-img {
  position: relative;
  width: 52px; height: 52px; flex-shrink: 0;
  border-radius: 6px; overflow: hidden;
  border: 1px solid var(--gris-borde);
}
.summary-item-img img { width: 100%; height: 100%; object-fit: cover; }
.summary-item-img-ph {
  width: 100%; height: 100%;
  background: var(--gris-bg);
  display: flex; align-items: center; justify-content: center;
  color: #ccc; font-size: 1.2rem;
}
.summary-item-qty {
  position: absolute;
  top: -6px; right: -6px;
  width: 20px; height: 20px;
  background: var(--verde);
  color: white;
  border-radius: 50%;
  font-size: .65rem; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid white;
}
.summary-item-name { font-size: .82rem; font-weight: 600; color: var(--texto); line-height: 1.3; }
.summary-item-sub  { font-size: .72rem; color: var(--texto-suave); }
.summary-item-precio { font-size: .88rem; color: var(--verde-dark); flex-shrink: 0; }

.summary-totals {
  border-top: 1px solid var(--gris-borde);
  padding-top: .75rem;
}
.summary-row {
  display: flex; justify-content: space-between;
  font-size: .88rem; margin-bottom: .4rem;
  color: var(--texto);
}
.summary-total-final {
  display: flex; justify-content: space-between;
  border-top: 2px solid var(--verde);
  padding-top: .75rem; margin-top: .5rem;
  font-size: 1.05rem; font-weight: 800;
  color: var(--verde-dark);
}

/* ── PASOS MINI ───────────────────────────────────────────── */
.pasos-restantes {
  display: flex;
  gap: 0;
  background: var(--gris-bg);
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--gris-borde);
}
.paso-mini {
  flex: 1;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: .5rem .25rem;
  font-size: .6rem;
  color: var(--texto-suave);
  gap: .2rem;
  border-right: 1px solid var(--gris-borde);
}
.paso-mini:last-child { border-right: none; }
.paso-mini i { font-size: .9rem; }
.paso-mini.active {
  background: var(--verde);
  color: white;
}
.paso-mini.completed {
  background: rgba(26,107,58,.08);
  color: var(--verde);
}

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media (max-width: 576px) {
  .step-label { display: none; }
  .stepper-line { min-width: 18px; }
  .checkout-card { padding: 1.1rem; }
  .entrega-option-inner { padding: .85rem; gap: .75rem; }
  .entrega-icon { width: 42px; height: 42px; font-size: 1.1rem; }
}
</style>

<script>
const SUBTOTAL = <?= $subtotal ?>;
const BS       = '<?= MONEDA ?> ';
const ZONAS    = <?= json_encode(array_map(fn($z) => [
    'id'     => (int)$z['id'],
    'nombre' => $z['nombre'],
    'costo'  => (float)$z['costo'],
    'tiempo' => $tiempoZona[$z['nombre']] ?? '1–2 horas',
], $zonas)) ?>;

function fmt(n) {
    return BS + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function toggleEntrega() {
    const esDelivery = document.getElementById('tipoDelivery').checked;
    document.getElementById('panelDelivery').style.display = esDelivery ? '' : 'none';
    document.getElementById('panelRetiro').style.display   = esDelivery ? 'none' : '';

    if (!esDelivery) {
        document.getElementById('costoDeliverySummary').innerHTML =
            '<span class="text-success">Gratis</span>';
        document.getElementById('totalFinalSummary').textContent = fmt(SUBTOTAL);
    } else {
        const sel   = document.getElementById('zona_id');
        const costo = sel.selectedIndex > 0
            ? parseFloat(sel.options[sel.selectedIndex].dataset.costo)
            : 0;
        actualizarTotalSummary(costo);
        if (!sel.value) {
            document.getElementById('costoDeliverySummary').innerHTML =
                '<span style="font-size:.82rem">Por definir</span>';
        }
    }
}

function actualizarZona(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const box    = document.getElementById('infoZona');
    const costo  = parseFloat(opt.dataset.costo || 0);
    const tiempo = opt.dataset.tiempo || '';

    if (sel.value) {
        document.getElementById('infoCosto').textContent = 'Bs. ' +
            costo.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        document.getElementById('infoTiempo').textContent = tiempo;
        box.style.display = '';
        actualizarTotalSummary(costo);
        document.getElementById('costoDeliverySummary').textContent = fmt(costo);
    } else {
        box.style.display = 'none';
        document.getElementById('costoDeliverySummary').innerHTML =
            '<span style="font-size:.82rem">Por definir</span>';
        document.getElementById('totalFinalSummary').textContent = fmt(SUBTOTAL);
    }
}

function actualizarTotalSummary(costo) {
    document.getElementById('totalFinalSummary').textContent = fmt(SUBTOTAL + costo);
    document.getElementById('costoDeliverySummary').textContent = fmt(costo);
}

// Sincronizar los dos inputs de fecha (delivery y retiro) para que el name no se duplique
document.getElementById('formEntrega').addEventListener('submit', function() {
    const esDelivery = document.getElementById('tipoDelivery').checked;
    if (esDelivery) {
        const r = document.getElementById('fecha_entrega_retiro');
        if (r) r.removeAttribute('name');
    } else {
        const d = document.getElementById('fecha_entrega_delivery');
        if (d) d.removeAttribute('name');
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
