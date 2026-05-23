<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';

requiereLogin(APP_URL . '/views/checkout/paso3-pago.php');

$carrito = $_SESSION['carrito'] ?? [];
if (empty($carrito)) {
    flash('advertencia', 'Tu carrito está vacío.');
    redirigir(APP_URL . '/views/catalogo.php');
}
if (empty($_SESSION['checkout']['tipo_entrega'])) {
    flash('advertencia', 'Primero completa los datos de entrega.');
    redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
}

// ── Métodos de pago disponibles ────────────────────────────
$metodos = [
    'online' => [
        [
            'key'        => 'qr_tigo',
            'db_nombre'  => 'QR - Tigo Money',
            'nombre'     => 'QR Tigo Money',
            'desc'       => 'Escanea con tu app Tigo Money y paga al instante',
            'info_label' => 'Número Tigo Money',
            'info_val'   => '+591 7XX-XXXXX',
            'icono'      => 'bi-qr-code',
            'color'      => '#00A0D2',
            'principal'  => true,
        ],
        [
            'key'        => 'qr_bisa',
            'db_nombre'  => 'QR - Banco Bisa',
            'nombre'     => 'QR Banco Bisa',
            'desc'       => 'Escanea con la app Bisa Móvil o cualquier billetera',
            'info_label' => 'Cuenta Bisa',
            'info_val'   => '1000-XXXXXX-XXX',
            'icono'      => 'bi-bank',
            'color'      => '#003B8E',
            'principal'  => false,
        ],
        [
            'key'        => 'pix',
            'db_nombre'  => 'PIX',
            'nombre'     => 'PIX',
            'desc'       => 'Transferencia instantánea desde Brasil (chave PIX)',
            'info_label' => 'Chave PIX',
            'info_val'   => '+55 69 9XXXX-XXXX',
            'icono'      => 'bi-lightning-charge-fill',
            'color'      => '#32BCAD',
            'principal'  => false,
        ],
    ],
    'entrega' => [
        [
            'key'        => 'efectivo',
            'db_nombre'  => 'Efectivo contra entrega',
            'nombre'     => 'Efectivo',
            'desc'       => 'Paga en efectivo al recibir tu pedido',
            'info_label' => 'Nota',
            'info_val'   => 'Ten el monto exacto listo para agilizar la entrega.',
            'icono'      => 'bi-cash-stack',
            'color'      => '#1A6B3A',
            'principal'  => false,
        ],
        [
            'key'        => 'tarjeta',
            'db_nombre'  => 'Tarjeta POS',
            'nombre'     => 'Tarjeta débito / crédito',
            'desc'       => 'El repartidor lleva datáfono (POS)',
            'info_label' => 'Nota',
            'info_val'   => 'Aceptamos Visa, Mastercard y tarjetas de débito nacionales.',
            'icono'      => 'bi-credit-card-2-front',
            'color'      => '#6366F1',
            'principal'  => false,
        ],
    ],
];

// ── Datos tienda (para retiro en tienda) ──────────────────
$db           = Database::getConnection();
$configTienda = $db->query("SELECT * FROM config_tienda WHERE activo=1 LIMIT 1")->fetch() ?: [
    'direccion'  => 'Av. Pando',
    'referencia' => 'A lado de Centro de Salud Santa Clara',
    'ciudad'     => 'Cobija, Bolivia',
    'telefono'   => '73943006',
    'whatsapp'   => '73943006',
    'horario_sem'=> 'Lun–Sáb: 8:00–18:00',
    'horario_dom'=> 'Domingo: Cerrado',
    'maps_url'   => null,
];

// ── Calcular totales ───────────────────────────────────────
$subtotal    = 0.0;
$totalPuntos = 0;
foreach ($carrito as $item) {
    $p           = $item['oferta'] ?? $item['precio'];
    $subtotal   += $p * $item['cantidad'];
    $totalPuntos+= (int)$item['puntos'] * (int)ceil($item['cantidad']);
}
$ck              = $_SESSION['checkout'];
$costoDelivery   = (float)($ck['costo_delivery']   ?? 0.0);
$descuentoPuntos = (float)($ck['descuento_puntos'] ?? 0.0);
$puntosUsados    = (int)  ($ck['puntos_usados']    ?? 0);
$totalFinal      = $subtotal + $costoDelivery - $descuentoPuntos;

$saved = $ck;

// ── POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Sesión expirada. Vuelve a intentarlo.');
        redirigir(APP_URL . '/views/checkout/paso3-pago.php');
    }

    $metodoKey = trim($_POST['metodo_pago'] ?? '');

    // Buscar el método en las listas
    $metodoElegido = null;
    foreach (array_merge($metodos['online'], $metodos['entrega']) as $m) {
        if ($m['key'] === $metodoKey) { $metodoElegido = $m; break; }
    }
    if (!$metodoElegido) {
        flash('error', 'Selecciona un método de pago.');
        redirigir(APP_URL . '/views/checkout/paso3-pago.php');
    }

    $esOnline = in_array($metodoKey, ['qr_tigo', 'qr_bisa', 'pix'], true);

    // Comprobante (sólo para métodos online)
    $comprobanteGuardado = $ck['comprobante'] ?? null;
    if ($esOnline && !empty($_FILES['comprobante']['tmp_name'])) {
        $ext     = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (!in_array($ext, $allowed, true)) {
            flash('error', 'Formato no válido. Acepta: JPG, PNG, WEBP, PDF.');
            redirigir(APP_URL . '/views/checkout/paso3-pago.php');
        }
        if ($_FILES['comprobante']['size'] > 5 * 1024 * 1024) {
            flash('error', 'El comprobante no puede superar 5 MB.');
            redirigir(APP_URL . '/views/checkout/paso3-pago.php');
        }

        $dir = UPLOADS_PATH . '/comprobantes';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = 'comp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $dir . '/' . $filename)) {
            // Eliminar comprobante anterior si existía
            if ($comprobanteGuardado && file_exists(UPLOADS_PATH . '/' . $comprobanteGuardado)) {
                unlink(UPLOADS_PATH . '/' . $comprobanteGuardado);
            }
            $comprobanteGuardado = 'comprobantes/' . $filename;
        }
    }

    $_SESSION['checkout']['metodo_pago_key']    = $metodoKey;
    $_SESSION['checkout']['metodo_pago_nombre'] = $metodoElegido['db_nombre'];
    $_SESSION['checkout']['comprobante']        = $comprobanteGuardado;

    redirigir(APP_URL . '/views/checkout/paso4-confirmacion.php');
}

// Método ya guardado (para pre-seleccionar al volver)
$metodoGuardado = $saved['metodo_pago_key'] ?? '';

$titulo = 'Checkout – Método de pago';
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

      <div class="stepper-step completed">
        <div class="step-circle"><i class="bi bi-check-lg"></i></div>
        <div class="step-label">Puntos</div>
      </div>
      <div class="stepper-line completed"></div>

      <div class="stepper-step active">
        <div class="step-circle"><i class="bi bi-credit-card"></i></div>
        <div class="step-label">Pago</div>
      </div>
      <div class="stepper-line"></div>

      <div class="stepper-step">
        <div class="step-circle"><span>4</span></div>
        <div class="step-label">Confirmar</div>
      </div>

    </div>
  </div>

  <form method="POST" action="" enctype="multipart/form-data" id="formPago">
    <?= campoCSRF() ?>

    <div class="row g-4">

      <!-- ════════════════════════════════════════════════════
           COLUMNA IZQUIERDA
           ════════════════════════════════════════════════════ -->
      <div class="col-lg-8">

        <!-- Encabezado paso -->
        <div class="checkout-card mb-3">
          <div class="step-badge">Paso 3 de 4</div>
          <h2 class="checkout-title">
            <i class="bi bi-credit-card me-2"></i>¿Cómo quieres pagar?
          </h2>
        </div>

        <!-- ════════════════════════════════════
             GRUPO: PAGAR ONLINE
             ════════════════════════════════════ -->
        <div class="checkout-card mb-3">
          <h5 class="section-heading">
            <i class="bi bi-phone me-2"></i>Pagar online
            <span class="badge-recomendado ms-2">Recomendado</span>
          </h5>

          <div class="d-flex flex-column gap-2">
            <?php foreach ($metodos['online'] as $m): ?>
            <label class="metodo-option <?= $m['principal'] ? 'principal' : '' ?>"
                   id="opt_<?= $m['key'] ?>">
              <input type="radio" name="metodo_pago" value="<?= $m['key'] ?>"
                     <?= $metodoGuardado === $m['key'] ? 'checked' : '' ?>
                     onchange="seleccionarMetodo('<?= $m['key'] ?>', true)">

              <div class="metodo-inner">
                <div class="metodo-icono" style="--color:<?= $m['color'] ?>">
                  <i class="bi <?= $m['icono'] ?>"></i>
                </div>
                <div class="flex-grow-1">
                  <div class="metodo-nombre">
                    <?= $m['nombre'] ?>
                    <?php if ($m['principal']): ?>
                    <span class="tag-principal">Principal</span>
                    <?php endif; ?>
                  </div>
                  <div class="metodo-desc"><?= $m['desc'] ?></div>
                </div>
                <i class="bi bi-check-circle-fill metodo-check"></i>
              </div>
            </label>
            <?php endforeach; ?>
          </div>

          <!-- Panel de pago online (aparece al seleccionar uno) -->
          <div id="panelOnline" style="display:<?= in_array($metodoGuardado, ['qr_tigo','qr_bisa','pix']) ? 'block' : 'none' ?>">
            <div class="pago-online-wrap mt-3">

              <!-- Datos de transferencia -->
              <div class="pago-datos-wrap">
                <?php foreach ($metodos['online'] as $m): ?>
                <div class="pago-datos" id="datos_<?= $m['key'] ?>"
                     style="display:<?= $metodoGuardado === $m['key'] ? 'block' : 'none' ?>">

                  <!-- Número / cuenta -->
                  <div class="dato-item mb-3">
                    <div class="dato-label"><?= $m['info_label'] ?></div>
                    <div class="dato-val">
                      <?= limpiar($m['info_val']) ?>
                      <button type="button" class="btn-copiar"
                              onclick="copiar('<?= $m['info_val'] ?>', this)"
                              title="Copiar">
                        <i class="bi bi-clipboard"></i>
                      </button>
                    </div>
                  </div>

                  <!-- Monto exacto -->
                  <div class="dato-item mb-3">
                    <div class="dato-label">Monto exacto a transferir</div>
                    <div class="dato-monto">
                      Bs. <?= number_format($totalFinal, 2) ?>
                      <button type="button" class="btn-copiar"
                              onclick="copiar('<?= number_format($totalFinal, 2) ?>', this)"
                              title="Copiar monto">
                        <i class="bi bi-clipboard"></i>
                      </button>
                    </div>
                  </div>

                  <!-- QR placeholder -->
                  <div class="qr-wrap">
                    <div class="qr-placeholder" style="--qr-color:<?= $m['color'] ?>">
                      <?= qrSvgPlaceholder() ?>
                      <div class="qr-label" style="color:<?= $m['color'] ?>">
                        <i class="bi <?= $m['icono'] ?> me-1"></i><?= $m['nombre'] ?>
                      </div>
                    </div>
                    <div class="qr-instrucciones">
                      <p class="mb-2"><strong>Pasos:</strong></p>
                      <ol style="font-size:.82rem;padding-left:1.2rem;color:var(--texto-suave);line-height:1.9">
                        <li>Abre tu app de <?= $m['nombre'] ?></li>
                        <li>Escanea el QR o ingresa <?= strtolower($m['info_label']) ?></li>
                        <li>Transfiere <strong>Bs. <?= number_format($totalFinal, 2) ?></strong> exactos</li>
                        <li>Sube la captura de pantalla abajo</li>
                      </ol>
                    </div>
                  </div>

                </div>
                <?php endforeach; ?>
              </div>

              <!-- Upload comprobante -->
              <div class="upload-comp-wrap mt-3">
                <label class="form-label fw-700" style="color:var(--verde-dark)">
                  <i class="bi bi-upload me-1"></i>Subir comprobante de pago
                  <span class="text-muted fw-400" style="font-size:.78rem">(opcional — lo puedes enviar luego por WhatsApp)</span>
                </label>

                <?php if (!empty($saved['comprobante'])): ?>
                <div class="comp-guardado mb-2">
                  <i class="bi bi-check-circle-fill text-success me-1"></i>
                  Comprobante adjuntado: <strong><?= limpiar(basename($saved['comprobante'])) ?></strong>
                  <small class="text-muted ms-2">(puedes reemplazarlo)</small>
                </div>
                <?php endif; ?>

                <div class="upload-zone" id="uploadZone"
                     ondragover="event.preventDefault();this.classList.add('drag-over')"
                     ondragleave="this.classList.remove('drag-over')"
                     ondrop="handleDrop(event)">
                  <input type="file" name="comprobante" id="comprobanteInput"
                         accept=".jpg,.jpeg,.png,.webp,.pdf"
                         onchange="previewComp(this)" style="display:none">
                  <div id="uploadContent">
                    <i class="bi bi-cloud-upload" style="font-size:2.2rem;color:var(--verde-light);display:block;margin-bottom:.5rem"></i>
                    <div style="font-size:.88rem;font-weight:600;color:var(--texto)">
                      Arrastra tu comprobante aquí
                    </div>
                    <div style="font-size:.75rem;color:var(--texto-suave);margin:.3rem 0 .8rem">
                      JPG, PNG, WEBP o PDF · Máx. 5 MB
                    </div>
                    <button type="button" class="btn btn-sm fw-600 px-3"
                            style="background:var(--verde);color:white;border-radius:6px"
                            onclick="document.getElementById('comprobanteInput').click()">
                      <i class="bi bi-folder me-1"></i>Buscar archivo
                    </button>
                  </div>
                  <div id="previewContent" style="display:none">
                    <div class="preview-file">
                      <i class="bi bi-file-earmark-check-fill" style="font-size:2rem;color:var(--verde)"></i>
                      <div>
                        <div id="previewName" style="font-size:.85rem;font-weight:600"></div>
                        <div id="previewSize" style="font-size:.75rem;color:var(--texto-suave)"></div>
                      </div>
                      <button type="button" class="btn-remove-file"
                              onclick="limpiarComp()" title="Quitar">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

            </div><!-- /pago-online-wrap -->
          </div><!-- /panelOnline -->

        </div><!-- /checkout-card online -->


        <!-- ════════════════════════════════════
             GRUPO: PAGAR AL RETIRAR / AL RECIBIR
             ════════════════════════════════════ -->
        <?php if ($ck['tipo_entrega'] === 'retiro_tienda'): ?>
        <!-- Info tienda (solo visible en retiro) -->
        <div class="retiro-datos-card mb-3">
          <div class="retiro-datos-header">
            <i class="bi bi-shop-window me-2"></i><?= limpiar($configTienda['nombre'] ?? 'Cerámica COBOCE') ?>
          </div>
          <div class="retiro-datos-body">
            <div class="retiro-dato-item">
              <i class="bi bi-geo-alt-fill" style="color:var(--verde)"></i>
              <div>
                <strong><?= limpiar($configTienda['direccion']) ?></strong>
                <?php if (!empty($configTienda['referencia'])): ?>
                  — <?= limpiar($configTienda['referencia']) ?>
                <?php endif; ?>
                <span class="text-muted"> · <?= limpiar($configTienda['ciudad']) ?></span>
                <?php if (!empty($configTienda['maps_url'])): ?>
                  <a href="<?= limpiar($configTienda['maps_url']) ?>" target="_blank" rel="noopener"
                     class="ms-2 text-verde" style="font-size:.75rem;font-weight:600">
                    <i class="bi bi-map-fill"></i> Mapa
                  </a>
                <?php endif; ?>
              </div>
            </div>
            <div class="retiro-dato-item">
              <i class="bi bi-clock-fill" style="color:var(--verde)"></i>
              <div>
                <?= limpiar($configTienda['horario_sem']) ?>
                &nbsp;·&nbsp;
                <?= limpiar($configTienda['horario_dom']) ?>
              </div>
            </div>
            <div class="retiro-dato-item">
              <i class="bi bi-telephone-fill" style="color:var(--verde)"></i>
              <a href="tel:+591<?= limpiar($configTienda['telefono']) ?>"
                 style="color:inherit;text-decoration:none;font-weight:600">
                +591 <?= limpiar($configTienda['telefono']) ?>
              </a>
              &nbsp;&nbsp;
              <a href="https://wa.me/591<?= limpiar($configTienda['whatsapp']) ?>"
                 target="_blank" rel="noopener"
                 style="color:#25D366;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem">
                <i class="bi bi-whatsapp"></i> WhatsApp
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="checkout-card mb-3">
          <h5 class="section-heading">
            <?php if ($ck['tipo_entrega'] === 'retiro_tienda'): ?>
              <i class="bi bi-shop me-2"></i>Pagar en caja al retirar
            <?php else: ?>
              <i class="bi bi-truck me-2"></i>Pagar al recibir
            <?php endif; ?>
          </h5>

          <div class="d-flex flex-column gap-2">
            <?php foreach ($metodos['entrega'] as $m): ?>
            <label class="metodo-option" id="opt_<?= $m['key'] ?>">
              <input type="radio" name="metodo_pago" value="<?= $m['key'] ?>"
                     <?= $metodoGuardado === $m['key'] ? 'checked' : '' ?>
                     onchange="seleccionarMetodo('<?= $m['key'] ?>', false)">
              <div class="metodo-inner">
                <div class="metodo-icono" style="--color:<?= $m['color'] ?>">
                  <i class="bi <?= $m['icono'] ?>"></i>
                </div>
                <div class="flex-grow-1">
                  <div class="metodo-nombre"><?= $m['nombre'] ?></div>
                  <div class="metodo-desc"><?= $m['desc'] ?></div>
                </div>
                <i class="bi bi-check-circle-fill metodo-check"></i>
              </div>
            </label>
            <?php endforeach; ?>
          </div>

          <!-- Info pago en entrega/caja -->
          <div id="panelEntrega"
               style="display:<?= in_array($metodoGuardado, ['efectivo','tarjeta']) ? 'block' : 'none' ?>">
            <?php foreach ($metodos['entrega'] as $m): ?>
            <div id="datos_<?= $m['key'] ?>"
                 style="display:<?= $metodoGuardado === $m['key'] ? 'block' : 'none' ?>">
              <div class="info-entrega mt-3">
                <i class="bi <?= $m['icono'] ?> me-2" style="color:<?= $m['color'] ?>;font-size:1.2rem"></i>
                <div>
                  <div style="font-weight:600;font-size:.9rem;color:var(--texto)">
                    <?= $m['nombre'] ?> — Bs. <?= number_format($totalFinal, 2) ?>
                  </div>
                  <div style="font-size:.8rem;color:var(--texto-suave);margin-top:.2rem">
                    <?= limpiar($m['info_val']) ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

        </div><!-- /checkout-card pago presencial -->


        <!-- ── Seguridad ──────────────────────────────────── -->
        <div class="seguridad-strip mb-3">
          <div class="seg-item">
            <i class="bi bi-shield-lock-fill"></i>
            <span>Datos seguros</span>
          </div>
          <div class="seg-item">
            <i class="bi bi-lock-fill"></i>
            <span>Sesión encriptada</span>
          </div>
          <div class="seg-item">
            <i class="bi bi-patch-check-fill"></i>
            <span>Verificado COBOCE</span>
          </div>
          <div class="seg-item">
            <i class="bi bi-whatsapp"></i>
            <span>Soporte 24h</span>
          </div>
        </div>

        <!-- ── Navegación ────────────────────────────────── -->
        <div class="d-flex flex-wrap justify-content-between gap-2">
          <a href="<?= APP_URL ?>/views/checkout/paso2-puntos.php"
             class="btn btn-outline-secondary px-4" style="border-radius:8px">
            <i class="bi bi-arrow-left me-2"></i>Paso anterior
          </a>
          <button type="submit" class="btn-coboce"
                  style="width:auto;padding:.72rem 2rem;border-radius:8px">
            Revisar y confirmar <i class="bi bi-arrow-right ms-2"></i>
          </button>
        </div>

      </div><!-- /col izquierda -->


      <!-- ════════════════════════════════════════════════════
           COLUMNA DERECHA — Resumen final
           ════════════════════════════════════════════════════ -->
      <div class="col-lg-4">
        <div class="order-summary-checkout" style="position:sticky;top:90px">

          <h5 class="fw-700 mb-3" style="color:var(--verde-dark)">
            <i class="bi bi-receipt-cutoff me-2"></i>Resumen final
          </h5>

          <!-- Items -->
          <details class="summary-details mb-3" open>
            <summary class="summary-details-toggle">
              <?= count($carrito) ?> producto<?= count($carrito) !== 1 ? 's' : '' ?>
              <i class="bi bi-chevron-down ms-auto toggle-icon"></i>
            </summary>
            <div class="summary-items mt-1">
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
                  <div class="summary-item-name"><?= limpiar(truncar($item['nombre'], 32)) ?></div>
                  <div class="summary-item-sub"><?= limpiar($item['unidad']) ?> ×<?= $item['cantidad'] ?></div>
                </div>
                <div class="summary-item-precio"><?= precio($pu * $item['cantidad']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </details>

          <!-- Entrega y puntos confirmados -->
          <div class="resumen-pasos mb-3">
            <div class="resumen-paso-item">
              <i class="bi bi-check-circle-fill"></i>
              <div>
                <div class="rp-titulo">
                  <?= $ck['tipo_entrega'] === 'delivery' ? 'Delivery — ' . limpiar($ck['zona_nombre']) : 'Retiro en tienda' ?>
                </div>
                <?php if (!empty($ck['direccion_entrega'])): ?>
                <div class="rp-sub"><?= limpiar(truncar($ck['direccion_entrega'], 40)) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($puntosUsados > 0): ?>
            <div class="resumen-paso-item puntos">
              <i class="bi bi-star-fill"></i>
              <div>
                <div class="rp-titulo">Canjeando <?= number_format($puntosUsados) ?> puntos</div>
                <div class="rp-sub">Descuento: − <?= precio($descuentoPuntos) ?></div>
              </div>
            </div>
            <?php else: ?>
            <div class="resumen-paso-item puntos">
              <i class="bi bi-star"></i>
              <div>
                <div class="rp-titulo">Sin canje de puntos</div>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Desglose de totales -->
          <div class="desglose-total">

            <div class="desglose-row">
              <span>Subtotal productos</span>
              <span><?= precio($subtotal) ?></span>
            </div>

            <?php if ($costoDelivery > 0): ?>
            <div class="desglose-row">
              <span>Costo delivery</span>
              <span><?= precio($costoDelivery) ?></span>
            </div>
            <?php else: ?>
            <div class="desglose-row">
              <span>Delivery</span>
              <span class="text-success fw-600">Gratis</span>
            </div>
            <?php endif; ?>

            <?php if ($descuentoPuntos > 0): ?>
            <div class="desglose-row descuento">
              <span><i class="bi bi-star-fill me-1" style="color:var(--dorado)"></i>Descuento puntos (<?= number_format($puntosUsados) ?> pts)</span>
              <span>− <?= precio($descuentoPuntos) ?></span>
            </div>
            <?php endif; ?>

          </div>

          <!-- TOTAL FINAL destacado -->
          <div class="total-final-box">
            <div class="total-final-label">TOTAL A PAGAR</div>
            <div class="total-final-monto">
              Bs. <span><?= number_format($totalFinal, 2) ?></span>
            </div>
            <?php if ($totalPuntos > 0): ?>
            <div class="total-final-pts">
              <i class="bi bi-star-fill me-1"></i>
              Ganarás <strong><?= number_format($totalPuntos) ?> puntos</strong> con este pedido
            </div>
            <?php endif; ?>
          </div>

          <!-- Mini stepper -->
          <div class="pasos-restantes mt-3">
            <div class="paso-mini completed">
              <i class="bi bi-check-circle-fill"></i><span>Entrega</span>
            </div>
            <div class="paso-mini completed">
              <i class="bi bi-check-circle-fill"></i><span>Puntos</span>
            </div>
            <div class="paso-mini active">
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

<?php
function qrSvgPlaceholder(): string {
    // Grilla 7×7 que simula un QR de ejemplo
    $cells = [
        [1,1,1,1,1,1,1, 0, 1,1,0,1,0, 0, 1,1,1,1,1,1,1],
        [1,0,0,0,0,0,1, 0, 0,1,1,0,1, 0, 1,0,0,0,0,0,1],
        [1,0,1,1,1,0,1, 0, 1,0,0,1,0, 0, 1,0,1,1,1,0,1],
        [1,0,1,1,1,0,1, 0, 0,1,0,0,1, 0, 1,0,1,1,1,0,1],
        [1,0,1,1,1,0,1, 0, 1,1,0,1,1, 0, 1,0,1,1,1,0,1],
        [1,0,0,0,0,0,1, 0, 0,0,1,0,0, 0, 1,0,0,0,0,0,1],
        [1,1,1,1,1,1,1, 0, 1,0,1,0,1, 0, 1,1,1,1,1,1,1],
        [0,0,0,0,0,0,0, 0, 0,1,0,1,0, 0, 0,0,0,0,0,0,0],
        [1,0,1,0,1,1,0, 1, 0,1,1,0,1, 0, 1,1,0,1,0,1,0],
        [0,1,0,1,0,0,1, 0, 1,0,0,1,0, 1, 0,0,1,0,1,0,1],
        [1,1,0,0,1,0,1, 0, 0,1,0,0,1, 0, 1,0,0,1,1,0,0],
        [0,0,1,1,0,1,0, 1, 1,0,1,0,0, 1, 0,1,1,0,0,1,1],
        [1,0,0,1,1,0,0, 0, 0,0,1,1,0, 0, 1,0,0,0,1,0,1],
        [0,0,0,0,0,0,0, 1, 1,1,0,0,1, 0, 0,1,0,1,0,1,0],
        [1,1,1,1,1,1,1, 0, 1,0,0,0,1, 0, 1,0,1,0,0,1,1],
        [1,0,0,0,0,0,1, 0, 0,1,1,1,0, 1, 0,1,0,1,1,0,0],
        [1,0,1,1,1,0,1, 1, 1,0,0,0,1, 0, 1,1,1,0,0,1,0],
        [1,0,0,0,0,0,1, 0, 0,1,0,1,0, 0, 0,0,1,1,0,0,1],
        [1,1,1,1,1,1,1, 0, 1,1,1,0,1, 1, 1,0,0,0,1,1,0],
    ];
    $size = 8;
    $w    = count($cells[0]) * $size;
    $h    = count($cells)    * $size;
    $svg  = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>";
    $svg .= "<rect width='{$w}' height='{$h}' fill='white' rx='4'/>";
    foreach ($cells as $r => $row) {
        foreach ($row as $c => $val) {
            if ($val) {
                $svg .= "<rect x='" . ($c*$size) . "' y='" . ($r*$size) . "' width='{$size}' height='{$size}'/>";
            }
        }
    }
    $svg .= "</svg>";
    return $svg;
}

?>

<style>
/* ── STEPPER ──────────────────────────────────────────────── */
.checkout-stepper { background:white;border-radius:var(--radio);padding:1.5rem 2rem;box-shadow:var(--sombra);border:1px solid var(--gris-borde); }
.stepper-track    { display:flex;align-items:center;justify-content:center;max-width:600px;margin:0 auto; }
.stepper-step     { display:flex;flex-direction:column;align-items:center;gap:.4rem;flex-shrink:0; }
.step-circle      { width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--gris-borde);color:var(--texto-suave);font-size:.95rem;font-weight:700;border:2.5px solid var(--gris-borde);transition:var(--trans); }
.stepper-step.active .step-circle    { background:var(--verde);border-color:var(--verde);color:white;box-shadow:0 4px 14px rgba(26,107,58,.35); }
.stepper-step.completed .step-circle { background:var(--verde-dark);border-color:var(--verde-dark);color:white; }
.step-label       { font-size:.73rem;font-weight:600;color:var(--texto-suave);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap; }
.stepper-step.active .step-label    { color:var(--verde-dark); }
.stepper-step.completed .step-label { color:var(--verde-dark); }
.stepper-line     { flex:1;height:2.5px;background:var(--gris-borde);margin:0 6px;margin-bottom:22px;min-width:30px; }
.stepper-line.completed { background:var(--verde-dark); }

/* ── CARDS ────────────────────────────────────────────────── */
.checkout-card { background:white;border-radius:var(--radio);padding:1.5rem;border:1px solid var(--gris-borde);box-shadow:var(--sombra); }
.step-badge    { display:inline-block;background:rgba(26,107,58,.1);color:var(--verde);font-size:.7rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:.2rem .8rem;border-radius:50px;margin-bottom:.5rem; }
.checkout-title { font-size:1.4rem;font-weight:700;color:var(--verde-dark);margin:0; }
.section-heading { font-size:1rem;font-weight:700;color:var(--verde-dark);margin-bottom:1.2rem;padding-bottom:.75rem;border-bottom:1px solid var(--gris-borde);display:flex;align-items:center; }

.badge-recomendado { background:rgba(26,107,58,.1);color:var(--verde);padding:.15rem .65rem;border-radius:50px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px; }

/* ── MÉTODOS DE PAGO ──────────────────────────────────────── */
.metodo-option { display:block;cursor:pointer; }
.metodo-option input { display:none; }
.metodo-inner {
  display:flex;align-items:center;gap:.9rem;
  padding:.9rem 1.1rem;
  border:2px solid var(--gris-borde);
  border-radius:var(--radio);
  background:white;
  transition:var(--trans);
  position:relative;
}
.metodo-inner:hover { border-color:var(--verde);background:rgba(26,107,58,.02); }
.metodo-option input:checked + .metodo-inner { border-color:var(--verde);background:rgba(26,107,58,.05);box-shadow:0 0 0 3px rgba(26,107,58,.1); }
.metodo-option.principal .metodo-inner { border-color:rgba(26,107,58,.35); }

.metodo-icono {
  width:48px;height:48px;border-radius:12px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:1.4rem;
  background:color-mix(in srgb, var(--color) 12%, transparent);
  color:var(--color);
  transition:var(--trans);
}
.metodo-option input:checked + .metodo-inner .metodo-icono { background:var(--color);color:white; }

.metodo-nombre { font-weight:700;font-size:.92rem;color:var(--texto);display:flex;align-items:center;gap:.4rem; }
.metodo-desc   { font-size:.78rem;color:var(--texto-suave);margin-top:.1rem; }
.tag-principal { background:var(--verde);color:white;font-size:.62rem;font-weight:700;padding:.1rem .55rem;border-radius:50px;text-transform:uppercase;letter-spacing:.5px; }
.metodo-check  { margin-left:auto;flex-shrink:0;font-size:1.2rem;color:var(--verde);opacity:0;transition:opacity .2s; }
.metodo-option input:checked + .metodo-inner .metodo-check { opacity:1; }

/* ── PANEL ONLINE ─────────────────────────────────────────── */
.pago-online-wrap {
  background:var(--gris-bg);
  border:1px solid var(--gris-borde);
  border-radius:var(--radio);
  padding:1.25rem;
  animation:fadeIn .3s ease;
}
@keyframes fadeIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }

.dato-item { background:white;border:1px solid var(--gris-borde);border-radius:8px;padding:.75rem 1rem; }
.dato-label { font-size:.7rem;text-transform:uppercase;letter-spacing:.8px;color:var(--texto-suave);margin-bottom:.2rem; }
.dato-val   { font-size:1rem;font-weight:700;color:var(--texto);display:flex;align-items:center;gap:.5rem; }
.dato-monto { font-size:1.3rem;font-weight:800;color:var(--verde-dark);display:flex;align-items:center;gap:.5rem; }
.btn-copiar { background:none;border:1px solid var(--gris-borde);border-radius:5px;padding:.1rem .4rem;cursor:pointer;color:var(--texto-suave);font-size:.85rem;transition:var(--trans); }
.btn-copiar:hover { background:var(--verde);color:white;border-color:var(--verde); }

.qr-wrap { display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap; }
.qr-placeholder {
  flex-shrink:0;
  padding:12px;
  background:white;
  border-radius:12px;
  border:2px solid color-mix(in srgb, var(--qr-color) 30%, transparent);
  text-align:center;
  box-shadow:0 4px 16px rgba(0,0,0,.08);
}
.qr-placeholder svg { display:block; }
.qr-label { font-size:.72rem;font-weight:700;margin-top:.5rem;text-transform:uppercase;letter-spacing:.5px; }
.qr-instrucciones { flex:1;min-width:160px; }

/* ── UPLOAD COMPROBANTE ───────────────────────────────────── */
.comp-guardado { background:rgba(26,107,58,.07);border:1px solid rgba(26,107,58,.2);border-radius:8px;padding:.5rem .85rem;font-size:.8rem;color:var(--verde-dark); }
.upload-zone {
  border:2px dashed var(--gris-borde);
  border-radius:var(--radio);
  padding:1.5rem;
  text-align:center;
  background:white;
  transition:var(--trans);
  cursor:pointer;
}
.upload-zone:hover, .upload-zone.drag-over {
  border-color:var(--verde);
  background:rgba(26,107,58,.04);
}
.preview-file { display:flex;align-items:center;gap:.75rem;padding:.4rem; }
.btn-remove-file { background:none;border:1px solid #dee2e6;border-radius:6px;padding:.2rem .5rem;cursor:pointer;color:var(--texto-suave);margin-left:auto;transition:var(--trans); }
.btn-remove-file:hover { background:#dc3545;color:white;border-color:#dc3545; }

/* ── INFO ENTREGA ─────────────────────────────────────────── */
.info-entrega {
  display:flex;align-items:flex-start;gap:.75rem;
  background:var(--gris-bg);border:1px solid var(--gris-borde);
  border-radius:8px;padding:1rem;
  animation:fadeIn .3s ease;
}
.alert-retiro-pago {
  background:rgba(14,165,233,.07);border:1px solid rgba(14,165,233,.25);
  border-radius:var(--radio);padding:1rem 1.25rem;
  font-size:.84rem;color:#0369a1;
  display:flex;align-items:flex-start;gap:.5rem;
}

/* ── TARJETA DATOS TIENDA (retiro) ───────────────────────── */
.retiro-datos-card {
  border:1px solid rgba(26,107,58,.2);border-radius:var(--radio);overflow:hidden;
}
.retiro-datos-header {
  background:rgba(26,107,58,.06);border-bottom:1px solid rgba(26,107,58,.15);
  padding:.65rem 1.1rem;font-size:.84rem;font-weight:700;color:var(--verde-dark);
  display:flex;align-items:center;
}
.retiro-datos-body { padding:.85rem 1.1rem;display:flex;flex-direction:column;gap:.6rem; }
.retiro-dato-item  { display:flex;align-items:flex-start;gap:.6rem;font-size:.83rem;color:var(--texto); }
.retiro-dato-item > i { font-size:1rem;flex-shrink:0;margin-top:.1rem; }

/* ── SEGURIDAD STRIP ──────────────────────────────────────── */
.seguridad-strip {
  display:flex;gap:.5rem;flex-wrap:wrap;
  background:white;border:1px solid var(--gris-borde);
  border-radius:var(--radio);padding:.75rem 1.25rem;
}
.seg-item { display:flex;align-items:center;gap:.4rem;font-size:.75rem;color:var(--texto-suave);flex:1;min-width:100px; }
.seg-item i { color:var(--verde);font-size:.95rem; }

/* ── RESUMEN SIDEBAR ──────────────────────────────────────── */
.order-summary-checkout { background:white;border-radius:var(--radio);padding:1.5rem;border:1px solid var(--gris-borde);box-shadow:var(--sombra); }
.summary-details { border:1px solid var(--gris-borde);border-radius:8px;overflow:hidden; }
.summary-details-toggle { list-style:none;display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;background:var(--gris-bg);font-size:.82rem;font-weight:600;color:var(--verde-dark);cursor:pointer; }
.summary-details-toggle::-webkit-details-marker { display:none; }
.toggle-icon { margin-left:auto;transition:transform .25s; }
details[open] .toggle-icon { transform:rotate(180deg); }
.summary-items { max-height:220px;overflow-y:auto;padding:.35rem .5rem; }
.summary-item { display:flex;align-items:center;gap:.65rem;padding:.4rem .5rem;border-bottom:1px solid var(--gris-borde); }
.summary-item:last-child { border-bottom:none; }
.summary-item-img { position:relative;width:44px;height:44px;flex-shrink:0;border-radius:6px;overflow:hidden;border:1px solid var(--gris-borde); }
.summary-item-img img { width:100%;height:100%;object-fit:cover; }
.summary-item-img-ph { width:100%;height:100%;background:var(--gris-bg);display:flex;align-items:center;justify-content:center;color:#ccc;font-size:1rem; }
.summary-item-qty { position:absolute;top:-5px;right:-5px;width:17px;height:17px;background:var(--verde);color:white;border-radius:50%;font-size:.58rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:1.5px solid white; }
.summary-item-name   { font-size:.78rem;font-weight:600;color:var(--texto);line-height:1.25; }
.summary-item-sub    { font-size:.68rem;color:var(--texto-suave); }
.summary-item-precio { font-size:.82rem;font-weight:600;color:var(--verde-dark);flex-shrink:0; }

/* Resumen pasos anteriores */
.resumen-pasos { display:flex;flex-direction:column;gap:.4rem; }
.resumen-paso-item { display:flex;align-items:flex-start;gap:.6rem;font-size:.8rem;padding:.55rem .75rem;background:rgba(26,107,58,.05);border:1px solid rgba(26,107,58,.15);border-radius:7px; }
.resumen-paso-item i { color:var(--verde);flex-shrink:0;margin-top:.1rem; }
.resumen-paso-item.puntos i { color:var(--dorado); }
.rp-titulo { font-weight:700;color:var(--verde-dark); }
.rp-sub    { color:var(--texto-suave);font-size:.73rem;margin-top:.1rem; }

/* Desglose totales */
.desglose-total { border-top:1px solid var(--gris-borde);padding-top:.75rem;margin-bottom:.75rem; }
.desglose-row { display:flex;justify-content:space-between;font-size:.87rem;margin-bottom:.35rem;color:var(--texto); }
.desglose-row.descuento { color:var(--verde-dark);font-weight:600; }
.desglose-row.descuento span:last-child { color:#dc3545; }

/* Total final */
.total-final-box {
  background:linear-gradient(135deg,var(--verde-dark),var(--verde));
  border-radius:var(--radio);
  padding:1.1rem 1.25rem;
  color:white;
  text-align:center;
}
.total-final-label { font-size:.7rem;text-transform:uppercase;letter-spacing:1.5px;opacity:.8;margin-bottom:.3rem; }
.total-final-monto { font-size:1rem;font-weight:600;opacity:.9; }
.total-final-monto span { font-size:2rem;font-weight:800;display:block;line-height:1.1;color:var(--dorado-light); }
.total-final-pts { margin-top:.6rem;font-size:.75rem;opacity:.85;background:rgba(201,168,76,.15);border-radius:50px;padding:.25rem .75rem;display:inline-block; }

/* Mini stepper */
.pasos-restantes { display:flex;background:var(--gris-bg);border-radius:8px;overflow:hidden;border:1px solid var(--gris-borde); }
.paso-mini { flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:.5rem .25rem;font-size:.6rem;color:var(--texto-suave);gap:.2rem;border-right:1px solid var(--gris-borde); }
.paso-mini:last-child { border-right:none; }
.paso-mini i { font-size:.9rem; }
.paso-mini.active    { background:var(--verde);color:white;font-weight:700; }
.paso-mini.completed { background:rgba(26,107,58,.08);color:var(--verde); }

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media (max-width:576px) {
  .step-label { display:none; }
  .stepper-line { min-width:18px; }
  .checkout-card { padding:1.1rem; }
  .qr-wrap { flex-direction:column;align-items:center; }
  .seguridad-strip { gap:.35rem; }
  .seg-item span { display:none; }
}
</style>

<script>
const METODOS_ONLINE   = ['qr_tigo', 'qr_bisa', 'pix'];
const METODOS_ENTREGA  = ['efectivo', 'tarjeta'];

function seleccionarMetodo(key, esOnline) {
    // Mostrar/ocultar paneles de grupo
    document.getElementById('panelOnline').style.display  = esOnline ? '' : 'none';
    const pe = document.getElementById('panelEntrega');
    if (pe) pe.style.display = esOnline ? 'none' : '';

    // Mostrar datos del método específico
    const todos = [...METODOS_ONLINE, ...METODOS_ENTREGA];
    todos.forEach(k => {
        const el = document.getElementById('datos_' + k);
        if (el) el.style.display = (k === key) ? '' : 'none';
    });
}

function copiar(texto, btn) {
    navigator.clipboard.writeText(texto).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.style.background = 'var(--verde)';
        btn.style.color = 'white';
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    });
}

function previewComp(input) {
    if (!input.files.length) return;
    const f = input.files[0];
    document.getElementById('previewName').textContent = f.name;
    document.getElementById('previewSize').textContent = (f.size / 1024).toFixed(1) + ' KB';
    document.getElementById('uploadContent').style.display  = 'none';
    document.getElementById('previewContent').style.display = '';
}

function limpiarComp() {
    document.getElementById('comprobanteInput').value = '';
    document.getElementById('uploadContent').style.display  = '';
    document.getElementById('previewContent').style.display = 'none';
}

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadZone').classList.remove('drag-over');
    const input = document.getElementById('comprobanteInput');
    input.files = e.dataTransfer.files;
    previewComp(input);
}

// Inicializar con método pre-guardado
(function() {
    const checked = document.querySelector('input[name="metodo_pago"]:checked');
    if (checked) {
        const key = checked.value;
        seleccionarMetodo(key, METODOS_ONLINE.includes(key));
    }
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
