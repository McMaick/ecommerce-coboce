<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';

requiereLogin(APP_URL . '/views/checkout/paso2-puntos.php');

$carrito = $_SESSION['carrito'] ?? [];
if (empty($carrito)) {
    flash('advertencia', 'Tu carrito está vacío.');
    redirigir(APP_URL . '/views/catalogo.php');
}
if (empty($_SESSION['checkout']['tipo_entrega'])) {
    flash('advertencia', 'Primero completa los datos de entrega.');
    redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
}

// ── Config y opciones desde la BD ─────────────────────────
$db = Database::getConnection();

$configPuntos  = $db->query("SELECT * FROM config_puntos WHERE activo=1 LIMIT 1")->fetch();
$maxCanjePct   = $configPuntos ? (int)$configPuntos['max_canje_pct'] : 30;

$opcionesCanje = array_map(
    fn($r) => ['puntos' => (int)$r['puntos'], 'descuento' => (float)$r['descuento']],
    $db->query(
        "SELECT puntos, descuento FROM opciones_canje
         WHERE activo = 1 ORDER BY puntos ASC"
    )->fetchAll()
);

// ── Calcular totales carrito ───────────────────────────────
$subtotal    = 0.0;
$totalPuntos = 0;
foreach ($carrito as $item) {
    $p           = $item['oferta'] ?? $item['precio'];
    $subtotal   += $p * $item['cantidad'];
    $totalPuntos+= (int)round($p * $item['cantidad'] * (float)($configPuntos['puntos_por_bs'] ?? 1.0));
}
$costoDelivery  = (float)($_SESSION['checkout']['costo_delivery'] ?? 0.0);
$totalPedido    = $subtotal + $costoDelivery;
$maxDescuento   = round($totalPedido * $maxCanjePct / 100, 2);

$puntosUsuario  = (int)$_SESSION['usuario_puntos'];
$saved          = $_SESSION['checkout'] ?? [];

// ── POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Sesión expirada. Vuelve a intentarlo.');
        redirigir(APP_URL . '/views/checkout/paso2-puntos.php');
    }

    $puntosCanjeados = limpiarInt($_POST['puntos_canjear'] ?? 0);

    if ($puntosCanjeados > 0) {
        // Validar que la opción existe
        $opcionValida = null;
        foreach ($opcionesCanje as $op) {
            if ((int)$op['puntos'] === $puntosCanjeados) {
                $opcionValida = $op;
                break;
            }
        }
        if (!$opcionValida) {
            flash('error', 'Opción de canje no válida.');
            redirigir(APP_URL . '/views/checkout/paso2-puntos.php');
        }
        if ($puntosCanjeados > $puntosUsuario) {
            flash('error', 'No tienes suficientes puntos para esta opción.');
            redirigir(APP_URL . '/views/checkout/paso2-puntos.php');
        }
        if ($opcionValida['descuento'] > $maxDescuento) {
            flash('error', 'El descuento supera el límite permitido (' . $maxCanjePct . '% del total).');
            redirigir(APP_URL . '/views/checkout/paso2-puntos.php');
        }

        $_SESSION['checkout']['puntos_usados']    = $puntosCanjeados;
        $_SESSION['checkout']['descuento_puntos'] = $opcionValida['descuento'];
    } else {
        $_SESSION['checkout']['puntos_usados']    = 0;
        $_SESSION['checkout']['descuento_puntos'] = 0.0;
    }

    redirigir(APP_URL . '/views/checkout/paso3-pago.php');
}

// Opción ya guardada en sesión (para pre-seleccionar si vuelve)
$puntosGuardados = (int)($saved['puntos_usados'] ?? 0);

$titulo = 'Checkout – Puntos de fidelidad';
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

      <div class="stepper-step completed">
        <div class="step-circle"><i class="bi bi-check-lg"></i></div>
        <div class="step-label">Entrega</div>
      </div>

      <div class="stepper-line completed"></div>

      <div class="stepper-step active">
        <div class="step-circle"><i class="bi bi-star-fill"></i></div>
        <div class="step-label">Puntos</div>
      </div>

      <div class="stepper-line"></div>

      <div class="stepper-step">
        <div class="step-circle"><span>3</span></div>
        <div class="step-label">Pago</div>
      </div>

      <div class="stepper-line"></div>

      <div class="stepper-step">
        <div class="step-circle"><span>4</span></div>
        <div class="step-label">Confirmar</div>
      </div>

    </div>
  </div>

  <form method="POST" action="" id="formPuntos">
    <?= campoCSRF() ?>

    <div class="row g-4">

      <!-- ════════════════════════════════════════════════════
           COLUMNA IZQUIERDA
           ════════════════════════════════════════════════════ -->
      <div class="col-lg-8">

        <!-- Encabezado paso -->
        <div class="checkout-card mb-3">
          <div class="step-badge">Paso 2 de 4</div>
          <h2 class="checkout-title">
            <i class="bi bi-star-fill me-2" style="color:var(--dorado)"></i>Puntos de fidelidad
          </h2>
        </div>

        <!-- ── Billetera de puntos ───────────────────────── -->
        <div class="puntos-wallet mb-3">
          <div class="wallet-left">
            <div class="wallet-icon">
              <i class="bi bi-star-fill"></i>
            </div>
            <div>
              <div class="wallet-label">Tus puntos disponibles</div>
              <div class="wallet-pts"><?= number_format($puntosUsuario) ?> <span>pts</span></div>
            </div>
          </div>
          <div class="wallet-right">
            <div class="wallet-equiv-label">Equivale a hasta</div>
            <?php
            $maxCanjeable = 0.0;
            foreach (array_reverse($opcionesCanje) as $op) {
                if ($op['puntos'] <= $puntosUsuario && $op['descuento'] <= $maxDescuento) {
                    $maxCanjeable = $op['descuento'];
                    break;
                }
            }
            ?>
            <div class="wallet-equiv-val">
              <?php if ($maxCanjeable > 0): ?>
                Bs. <?= number_format($maxCanjeable, 2) ?>
                <span class="wallet-equiv-badge">de descuento</span>
              <?php else: ?>
                <span style="font-size:.85rem;opacity:.7">Sin opciones disponibles</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ── Opciones de canje ─────────────────────────── -->
        <div class="checkout-card mb-3">

          <h5 class="section-heading">
            <i class="bi bi-gift me-2"></i>Elige cuántos puntos canjear
          </h5>

          <!-- Opción: No canjear -->
          <label class="canje-option" id="optNoCanjear">
            <input type="radio" name="puntos_canjear" value="0"
                   id="noCanjeInput"
                   <?= $puntosGuardados === 0 ? 'checked' : '' ?>
                   onchange="actualizarResumen(0, 0)">
            <div class="canje-inner no-canje">
              <div class="canje-left">
                <div class="canje-icon-wrap no-canje-icon">
                  <i class="bi bi-x-circle"></i>
                </div>
                <div>
                  <div class="canje-titulo">No canjear puntos</div>
                  <div class="canje-subtitulo">
                    Guarda tus puntos para una próxima compra
                  </div>
                </div>
              </div>
              <div class="canje-descuento no-descuento">
                —
              </div>
              <i class="bi bi-check-circle-fill canje-check"></i>
            </div>
          </label>

          <div class="canje-divisor">
            <span>o elige una opción de canje</span>
          </div>

          <!-- Opciones con puntos -->
          <div class="row g-2 mt-1">
            <?php foreach ($opcionesCanje as $op):
              $tienePuntos  = $puntosUsuario >= $op['puntos'];
              $dentroLimite = $op['descuento'] <= $maxDescuento;
              $disponible   = $tienePuntos && $dentroLimite;
              $bloqueadoPor = !$tienePuntos ? 'puntos' : (!$dentroLimite ? 'limite' : '');
              $isSelected   = $puntosGuardados === $op['puntos'];
            ?>
            <div class="col-12 col-sm-6">
              <label class="canje-option-card <?= !$disponible ? 'disabled' : '' ?>"
                     id="opt<?= $op['puntos'] ?>">
                <input type="radio" name="puntos_canjear"
                       value="<?= $op['puntos'] ?>"
                       <?= !$disponible ? 'disabled' : '' ?>
                       <?= $isSelected && $disponible ? 'checked' : '' ?>
                       onchange="actualizarResumen(<?= $op['puntos'] ?>, <?= $op['descuento'] ?>)">
                <div class="canje-card-inner">

                  <div class="canje-pts-badge">
                    <i class="bi bi-star-fill me-1"></i><?= number_format($op['puntos']) ?> pts
                  </div>

                  <div class="canje-arrow">
                    <i class="bi bi-arrow-right"></i>
                  </div>

                  <div class="canje-desc-badge">
                    Bs. <?= number_format($op['descuento'], 2) ?>
                    <span class="canje-desc-label">descuento</span>
                  </div>

                  <?php if ($bloqueadoPor === 'puntos'): ?>
                  <div class="canje-bloqueado">
                    <i class="bi bi-lock-fill me-1"></i>
                    Necesitas <?= number_format($op['puntos'] - $puntosUsuario) ?> pts más
                  </div>
                  <?php elseif ($bloqueadoPor === 'limite'): ?>
                  <div class="canje-bloqueado">
                    <i class="bi bi-slash-circle me-1"></i>
                    Supera el límite (<?= $maxCanjePct ?>%)
                  </div>
                  <?php else: ?>
                  <i class="bi bi-check-circle-fill canje-card-check"></i>
                  <?php endif; ?>

                </div>
              </label>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Nota límite -->
          <div class="nota-limite mt-3">
            <i class="bi bi-info-circle me-1"></i>
            Puedes usar puntos para pagar hasta el
            <strong><?= $maxCanjePct ?>%</strong> del total de tu pedido
            (máx. <strong>Bs. <?= number_format($maxDescuento, 2) ?></strong> en este pedido).
          </div>

        </div>

        <!-- ── Cómo se acumulan puntos ──────────────────── -->
        <div class="checkout-card mb-3">
          <h5 class="section-heading">
            <i class="bi bi-info-circle me-2"></i>¿Cómo funcionan los puntos?
          </h5>
          <div class="row g-2">
            <div class="col-12 col-sm-4">
              <div class="puntos-info-item">
                <div class="puntos-info-icon gana">
                  <i class="bi bi-plus-circle-fill"></i>
                </div>
                <div class="puntos-info-text">
                  <strong>Ganas</strong>
                  <span>1 punto por cada Bs. que compras</span>
                </div>
              </div>
            </div>
            <div class="col-12 col-sm-4">
              <div class="puntos-info-item">
                <div class="puntos-info-icon canjea">
                  <i class="bi bi-gift-fill"></i>
                </div>
                <div class="puntos-info-text">
                  <strong>Canjeas</strong>
                  <span>En tu próxima o cualquier compra futura</span>
                </div>
              </div>
            </div>
            <div class="col-12 col-sm-4">
              <div class="puntos-info-item">
                <div class="puntos-info-icon limite">
                  <i class="bi bi-shield-check-fill"></i>
                </div>
                <div class="puntos-info-text">
                  <strong>Límite</strong>
                  <span>Máx. <?= $maxCanjePct ?>% del total por pedido</span>
                </div>
              </div>
            </div>
          </div>

          <?php if ($totalPuntos > 0): ?>
          <div class="ganar-alerta mt-3">
            <i class="bi bi-star-fill me-2"></i>
            Con este pedido ganarás <strong><?= number_format($totalPuntos) ?> puntos</strong>
            adicionales que se acreditarán al confirmar la entrega.
          </div>
          <?php endif; ?>
        </div>

        <!-- ── Navegación ────────────────────────────────── -->
        <div class="d-flex flex-wrap justify-content-between gap-2 mt-2">
          <a href="<?= APP_URL ?>/views/checkout/paso1-entrega.php"
             class="btn btn-outline-secondary px-4" style="border-radius:8px">
            <i class="bi bi-arrow-left me-2"></i>Paso anterior
          </a>
          <div class="d-flex gap-2 flex-wrap justify-content-end">
            <button type="submit" name="puntos_canjear" value="0"
                    class="btn btn-outline-secondary px-4"
                    style="border-radius:8px;font-weight:600"
                    onclick="document.getElementById('noCanjeInput').checked=true;actualizarResumen(0,0)">
              Continuar sin canjear
            </button>
            <button type="submit" class="btn-coboce"
                    style="width:auto;padding:.72rem 2rem;border-radius:8px">
              Continuar al paso 3 <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>
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

          <!-- Items colapsables -->
          <details class="summary-details mb-3" open>
            <summary class="summary-details-toggle">
              <?= count($carrito) ?> producto<?= count($carrito) !== 1 ? 's' : '' ?> en el carrito
              <i class="bi bi-chevron-down ms-auto toggle-icon"></i>
            </summary>
            <div class="summary-items mt-2">
              <?php foreach ($carrito as $item):
                $pu = $item['oferta'] ?? $item['precio'];
              ?>
              <div class="summary-item">
                <div class="summary-item-img">
                  <?php if ($item['imagen'] && file_exists(UPLOADS_PATH . '/' . $item['imagen'])): ?>
                    <img src="<?= UPLOADS_URL . '/' . limpiar($item['imagen']) ?>"
                         alt="<?= limpiar($item['nombre']) ?>">
                  <?php else: ?>
                    <div class="summary-item-img-ph"><i class="bi bi-image"></i></div>
                  <?php endif; ?>
                  <span class="summary-item-qty"><?= $item['cantidad'] <= 9 ? (int)$item['cantidad'] : '9+' ?></span>
                </div>
                <div class="flex-grow-1" style="min-width:0">
                  <div class="summary-item-name"><?= limpiar(truncar($item['nombre'], 35)) ?></div>
                  <div class="summary-item-sub"><?= limpiar($item['unidad']) ?> ×<?= $item['cantidad'] ?></div>
                </div>
                <div class="summary-item-precio fw-600"><?= precio($pu * $item['cantidad']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </details>

          <!-- Entrega confirmada (resumen paso 1) -->
          <div class="entrega-confirmada mb-3">
            <div class="entrega-conf-header">
              <i class="bi bi-check-circle-fill me-1"></i>
              <?php if ($_SESSION['checkout']['tipo_entrega'] === 'delivery'): ?>
                Delivery — <?= limpiar($_SESSION['checkout']['zona_nombre']) ?>
              <?php else: ?>
                Retiro en tienda
              <?php endif; ?>
            </div>
            <?php if (!empty($_SESSION['checkout']['direccion_entrega'])): ?>
            <div class="entrega-conf-dir">
              <i class="bi bi-geo-alt me-1"></i><?= limpiar($_SESSION['checkout']['direccion_entrega']) ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- Totales -->
          <div class="summary-totals">
            <div class="summary-row">
              <span>Subtotal</span>
              <span class="fw-600"><?= precio($subtotal) ?></span>
            </div>
            <div class="summary-row">
              <span>Delivery</span>
              <span class="fw-600">
                <?= $costoDelivery > 0 ? precio($costoDelivery) : '<span class="text-success">Gratis</span>' ?>
              </span>
            </div>
            <div class="summary-row" id="rowDescuentoPuntos"
                 style="<?= $puntosGuardados === 0 ? 'display:none' : '' ?>">
              <span style="color:var(--dorado-dark)">
                <i class="bi bi-star-fill me-1"></i>Descuento puntos
              </span>
              <span class="fw-700 text-danger" id="valDescuentoPuntos">
                <?= $puntosGuardados > 0
                    ? '− ' . precio((float)($saved['descuento_puntos'] ?? 0))
                    : '' ?>
              </span>
            </div>
          </div>

          <div class="summary-total-final">
            <span>Total</span>
            <span id="totalFinalSummary">
              <?php
              $descGuardado = (float)($saved['descuento_puntos'] ?? 0.0);
              echo precio($totalPedido - $descGuardado);
              ?>
            </span>
          </div>

          <?php if ($totalPuntos > 0): ?>
          <div class="mt-3 text-center" style="font-size:.78rem;color:var(--dorado-dark)">
            <i class="bi bi-star-fill me-1"></i>
            Ganarás <strong><?= number_format($totalPuntos) ?> pts</strong> con este pedido
          </div>
          <?php endif; ?>

          <!-- Mini stepper -->
          <div class="pasos-restantes mt-3">
            <div class="paso-mini completed">
              <i class="bi bi-check-circle-fill"></i><span>Entrega</span>
            </div>
            <div class="paso-mini active">
              <i class="bi bi-star-fill"></i><span>Puntos</span>
            </div>
            <div class="paso-mini">
              <i class="bi bi-credit-card"></i><span>Pago</span>
            </div>
            <div class="paso-mini">
              <i class="bi bi-bag-check"></i><span>Confirmar</span>
            </div>
          </div>

        </div>
      </div><!-- /col derecha -->

    </div><!-- /row -->
  </form>

</div><!-- /container -->

<style>
/* ── STEPPER (heredado + completado) ──────────────────────── */
.checkout-stepper {
  background: white; border-radius: var(--radio);
  padding: 1.5rem 2rem;
  box-shadow: var(--sombra); border: 1px solid var(--gris-borde);
}
.stepper-track {
  display: flex; align-items: center; justify-content: center;
  max-width: 600px; margin: 0 auto;
}
.stepper-step { display: flex; flex-direction: column; align-items: center; gap: .4rem; flex-shrink: 0; }
.step-circle {
  width: 44px; height: 44px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--gris-borde); color: var(--texto-suave);
  font-size: .95rem; font-weight: 700;
  border: 2.5px solid var(--gris-borde); transition: var(--trans);
}
.stepper-step.active .step-circle {
  background: var(--dorado); border-color: var(--dorado);
  color: var(--verde-dark);
  box-shadow: 0 4px 14px rgba(201,168,76,.4);
}
.stepper-step.completed .step-circle {
  background: var(--verde-dark); border-color: var(--verde-dark); color: white;
}
.step-label {
  font-size: .73rem; font-weight: 600; color: var(--texto-suave);
  text-transform: uppercase; letter-spacing: .5px; white-space: nowrap;
}
.stepper-step.active .step-label    { color: var(--dorado-dark); }
.stepper-step.completed .step-label { color: var(--verde-dark); }
.stepper-line {
  flex: 1; height: 2.5px; background: var(--gris-borde);
  margin: 0 6px; margin-bottom: 22px; min-width: 30px;
}
.stepper-line.completed { background: var(--verde-dark); }

/* ── CARDS CHECKOUT ───────────────────────────────────────── */
.checkout-card {
  background: white; border-radius: var(--radio);
  padding: 1.5rem; border: 1px solid var(--gris-borde); box-shadow: var(--sombra);
}
.step-badge {
  display: inline-block; background: rgba(201,168,76,.15);
  color: var(--dorado-dark); font-size: .7rem; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase;
  padding: .2rem .8rem; border-radius: 50px; margin-bottom: .5rem;
}
.checkout-title {
  font-size: 1.4rem; font-weight: 700; color: var(--verde-dark); margin: 0;
}
.section-heading {
  font-size: 1rem; font-weight: 700; color: var(--verde-dark);
  margin-bottom: 1.2rem; padding-bottom: .75rem;
  border-bottom: 1px solid var(--gris-borde);
}

/* ── BILLETERA DE PUNTOS ──────────────────────────────────── */
.puntos-wallet {
  background: linear-gradient(135deg, var(--verde-dark) 0%, var(--verde) 60%, var(--verde-light) 100%);
  border-radius: var(--radio);
  padding: 1.5rem 1.75rem;
  color: white;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  box-shadow: 0 6px 24px rgba(26,107,58,.3);
  position: relative;
  overflow: hidden;
}
.puntos-wallet::before {
  content: '';
  position: absolute; right: -40px; top: -40px;
  width: 180px; height: 180px;
  background: rgba(255,255,255,.06);
  border-radius: 50%;
}
.puntos-wallet::after {
  content: '';
  position: absolute; right: 40px; bottom: -50px;
  width: 120px; height: 120px;
  background: rgba(201,168,76,.12);
  border-radius: 50%;
}
.wallet-left { display: flex; align-items: center; gap: 1rem; position: relative; z-index: 1; }
.wallet-icon {
  width: 56px; height: 56px;
  background: rgba(201,168,76,.2);
  border: 2px solid rgba(201,168,76,.4);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem; color: var(--dorado-light);
  flex-shrink: 0;
}
.wallet-label { font-size: .75rem; opacity: .78; text-transform: uppercase; letter-spacing: .8px; }
.wallet-pts   { font-size: 2.2rem; font-weight: 800; line-height: 1.1; }
.wallet-pts span { font-size: 1rem; font-weight: 500; opacity: .75; }

.wallet-right {
  text-align: right; position: relative; z-index: 1; flex-shrink: 0;
}
.wallet-equiv-label { font-size: .72rem; opacity: .72; margin-bottom: .2rem; }
.wallet-equiv-val   { font-size: 1.4rem; font-weight: 700; color: var(--dorado-light); }
.wallet-equiv-badge {
  display: block;
  font-size: .68rem; font-weight: 500; opacity: .8;
  color: rgba(255,255,255,.8);
}

/* ── OPCIÓN NO CANJEAR ────────────────────────────────────── */
.canje-option { display: block; cursor: pointer; }
.canje-option input { display: none; }
.canje-inner {
  display: flex; align-items: center; gap: 1rem;
  padding: .9rem 1.1rem;
  border: 2px solid var(--gris-borde);
  border-radius: var(--radio);
  background: white; transition: var(--trans);
  position: relative;
}
.canje-inner:hover { border-color: var(--verde); background: rgba(26,107,58,.03); }
.canje-option input:checked + .canje-inner {
  border-color: var(--verde);
  background: rgba(26,107,58,.05);
  box-shadow: 0 0 0 3px rgba(26,107,58,.1);
}
.canje-icon-wrap {
  width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem;
}
.no-canje-icon { background: rgba(108,117,125,.1); color: #6c757d; }
.canje-option input:checked + .canje-inner .no-canje-icon {
  background: rgba(108,117,125,.2); color: #495057;
}
.canje-titulo    { font-weight: 700; font-size: .92rem; color: var(--texto); }
.canje-subtitulo { font-size: .78rem; color: var(--texto-suave); margin-top: .1rem; }
.canje-descuento {
  margin-left: auto; flex-shrink: 0;
  font-size: 1.1rem; font-weight: 700; color: var(--texto-suave);
}
.canje-check {
  flex-shrink: 0; font-size: 1.2rem; color: var(--verde);
  opacity: 0; transition: opacity .2s;
}
.canje-option input:checked + .canje-inner .canje-check { opacity: 1; }

.canje-divisor {
  display: flex; align-items: center; gap: .75rem;
  color: var(--texto-suave); font-size: .78rem; margin: .9rem 0 .5rem;
}
.canje-divisor::before, .canje-divisor::after {
  content: ''; flex: 1; height: 1px; background: var(--gris-borde);
}

/* ── TARJETAS DE CANJE ────────────────────────────────────── */
.canje-option-card { display: block; cursor: pointer; height: 100%; }
.canje-option-card input { display: none; }
.canje-option-card.disabled { cursor: not-allowed; opacity: .6; }

.canje-card-inner {
  display: flex; align-items: center; justify-content: space-between; gap: .6rem;
  flex-wrap: wrap;
  padding: 1rem 1.1rem;
  border: 2px solid var(--gris-borde);
  border-radius: var(--radio);
  background: white; transition: var(--trans);
  position: relative; min-height: 80px;
}
.canje-option-card:not(.disabled) .canje-card-inner:hover {
  border-color: var(--dorado);
  background: rgba(201,168,76,.04);
}
.canje-option-card:not(.disabled) input:checked + .canje-card-inner {
  border-color: var(--dorado);
  background: rgba(201,168,76,.07);
  box-shadow: 0 0 0 3px rgba(201,168,76,.2);
}
.canje-option-card.disabled .canje-card-inner { background: var(--gris-bg); }

.canje-pts-badge {
  background: rgba(26,107,58,.1);
  color: var(--verde-dark);
  padding: .3rem .75rem; border-radius: 50px;
  font-size: .82rem; font-weight: 700;
  white-space: nowrap;
}
.canje-option-card:not(.disabled) input:checked + .canje-card-inner .canje-pts-badge {
  background: var(--verde); color: white;
}
.canje-arrow { color: var(--texto-suave); font-size: .9rem; }

.canje-desc-badge {
  background: rgba(201,168,76,.15);
  color: var(--dorado-dark);
  padding: .3rem .75rem; border-radius: 50px;
  font-size: 1rem; font-weight: 800;
  text-align: center;
  white-space: nowrap;
}
.canje-desc-label {
  display: block; font-size: .65rem; font-weight: 500; opacity: .75;
}
.canje-option-card:not(.disabled) input:checked + .canje-card-inner .canje-desc-badge {
  background: var(--dorado); color: var(--verde-dark);
}
.canje-card-check {
  position: absolute; top: 8px; right: 8px;
  font-size: 1.1rem; color: var(--dorado-dark);
  opacity: 0; transition: opacity .2s;
}
.canje-option-card:not(.disabled) input:checked + .canje-card-inner .canje-card-check { opacity: 1; }

.canje-bloqueado {
  width: 100%;
  font-size: .7rem; color: var(--texto-suave);
  text-align: center; padding-top: .3rem;
}

/* ── NOTA LÍMITE ──────────────────────────────────────────── */
.nota-limite {
  background: rgba(14,165,233,.07);
  border: 1px solid rgba(14,165,233,.25);
  border-radius: 8px; padding: .7rem 1rem;
  font-size: .8rem; color: #0369a1;
}

/* ── CÓMO FUNCIONAN ───────────────────────────────────────── */
.puntos-info-item {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: .85rem;
  background: var(--gris-bg);
  border-radius: 8px;
  border: 1px solid var(--gris-borde);
}
.puntos-info-icon {
  width: 38px; height: 38px; border-radius: 8px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem;
}
.puntos-info-icon.gana   { background: rgba(26,107,58,.12); color: var(--verde); }
.puntos-info-icon.canjea { background: rgba(201,168,76,.15); color: var(--dorado-dark); }
.puntos-info-icon.limite { background: rgba(14,165,233,.12); color: #0369a1; }
.puntos-info-text strong { display: block; font-size: .85rem; font-weight: 700; color: var(--texto); }
.puntos-info-text span   { font-size: .75rem; color: var(--texto-suave); }

.ganar-alerta {
  background: rgba(201,168,76,.1);
  border: 1px solid rgba(201,168,76,.3);
  border-radius: 8px; padding: .75rem 1rem;
  font-size: .84rem; color: var(--verde-dark);
}

/* ── RESUMEN ──────────────────────────────────────────────── */
.order-summary-checkout {
  background: white; border-radius: var(--radio);
  padding: 1.5rem; border: 1px solid var(--gris-borde); box-shadow: var(--sombra);
}
.summary-details { border: 1px solid var(--gris-borde); border-radius: 8px; overflow: hidden; }
.summary-details-toggle {
  list-style: none; display: flex; align-items: center; gap: .5rem;
  padding: .7rem 1rem; background: var(--gris-bg);
  font-size: .82rem; font-weight: 600; color: var(--verde-dark);
  cursor: pointer;
}
.summary-details-toggle::-webkit-details-marker { display: none; }
.toggle-icon { margin-left: auto; transition: transform .25s; }
details[open] .toggle-icon { transform: rotate(180deg); }
.summary-items { max-height: 240px; overflow-y: auto; padding: .4rem .5rem; }
.summary-item { display: flex; align-items: center; gap: .75rem; padding: .45rem .5rem; border-bottom: 1px solid var(--gris-borde); }
.summary-item:last-child { border-bottom: none; }
.summary-item-img { position: relative; width: 46px; height: 46px; flex-shrink: 0; border-radius: 6px; overflow: hidden; border: 1px solid var(--gris-borde); }
.summary-item-img img { width: 100%; height: 100%; object-fit: cover; }
.summary-item-img-ph { width: 100%; height: 100%; background: var(--gris-bg); display: flex; align-items: center; justify-content: center; color: #ccc; font-size: 1.1rem; }
.summary-item-qty { position: absolute; top: -5px; right: -5px; width: 18px; height: 18px; background: var(--verde); color: white; border-radius: 50%; font-size: .6rem; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 1.5px solid white; }
.summary-item-name    { font-size: .8rem; font-weight: 600; color: var(--texto); line-height: 1.3; }
.summary-item-sub     { font-size: .7rem; color: var(--texto-suave); }
.summary-item-precio  { font-size: .85rem; color: var(--verde-dark); flex-shrink: 0; }

.entrega-confirmada {
  background: rgba(26,107,58,.06);
  border: 1px solid rgba(26,107,58,.2);
  border-radius: 8px; padding: .7rem 1rem;
}
.entrega-conf-header {
  font-size: .82rem; font-weight: 700; color: var(--verde-dark);
  display: flex; align-items: center; gap: .3rem;
}
.entrega-conf-header i { color: var(--verde); }
.entrega-conf-dir { font-size: .77rem; color: var(--texto-suave); margin-top: .25rem; padding-left: 1.1rem; }

.summary-totals { border-top: 1px solid var(--gris-borde); padding-top: .75rem; }
.summary-row { display: flex; justify-content: space-between; font-size: .88rem; margin-bottom: .4rem; color: var(--texto); }
.summary-total-final { display: flex; justify-content: space-between; border-top: 2px solid var(--verde); padding-top: .75rem; margin-top: .5rem; font-size: 1.05rem; font-weight: 800; color: var(--verde-dark); }

/* ── PASOS MINI ───────────────────────────────────────────── */
.pasos-restantes { display: flex; background: var(--gris-bg); border-radius: 8px; overflow: hidden; border: 1px solid var(--gris-borde); }
.paso-mini { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: .5rem .25rem; font-size: .6rem; color: var(--texto-suave); gap: .2rem; border-right: 1px solid var(--gris-borde); }
.paso-mini:last-child { border-right: none; }
.paso-mini i { font-size: .9rem; }
.paso-mini.active    { background: var(--dorado); color: var(--verde-dark); font-weight: 700; }
.paso-mini.completed { background: rgba(26,107,58,.08); color: var(--verde); }

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media (max-width: 576px) {
  .step-label { display: none; }
  .stepper-line { min-width: 18px; }
  .checkout-card { padding: 1.1rem; }
  .puntos-wallet { flex-direction: column; text-align: center; }
  .wallet-left { flex-direction: column; }
  .wallet-right { text-align: center; }
  .canje-card-inner { justify-content: center; }
}
</style>

<script>
const SUBTOTAL_BASE  = <?= $subtotal ?>;
const COSTO_DELIVERY = <?= $costoDelivery ?>;
const TOTAL_BASE     = SUBTOTAL_BASE + COSTO_DELIVERY;
const BS             = '<?= MONEDA ?> ';

function fmt(n) {
    return BS + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function actualizarResumen(puntos, descuento) {
    const rowDesc = document.getElementById('rowDescuentoPuntos');
    const valDesc = document.getElementById('valDescuentoPuntos');
    const total   = document.getElementById('totalFinalSummary');

    if (puntos > 0 && descuento > 0) {
        rowDesc.style.display = '';
        valDesc.textContent   = '− ' + fmt(descuento);
        total.textContent     = fmt(TOTAL_BASE - descuento);
    } else {
        rowDesc.style.display = 'none';
        total.textContent     = fmt(TOTAL_BASE);
    }
}

// Inicializar con valor pre-guardado si existe
(function() {
    const sel = document.querySelector('input[name="puntos_canjear"]:checked');
    if (sel && parseInt(sel.value) > 0) {
        const op = <?= json_encode(array_combine(
            array_map('intval',   array_column($opcionesCanje, 'puntos')),
            array_map('floatval', array_column($opcionesCanje, 'descuento'))
        ) ?: new stdClass()) ?>;
        const pts = parseInt(sel.value);
        if (op[pts]) actualizarResumen(pts, op[pts]);
    }
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
