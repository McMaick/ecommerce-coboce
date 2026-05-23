<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/models/Producto.php';

requiereLogin(APP_URL . '/views/checkout/paso4-confirmacion.php');

$db     = Database::getConnection();
$pedido = null;

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

// ── Si el pedido ya fue procesado esta sesión, solo mostrar ──
if (!empty($_SESSION['ultimo_pedido'])) {
    $pedido = $_SESSION['ultimo_pedido'];
}

// ── Primera vez: validar datos y crear pedido ────────────────
if ($pedido === null) {

    $carrito = $_SESSION['carrito'] ?? [];
    if (empty($carrito)) {
        flash('advertencia', 'Tu carrito está vacío.');
        redirigir(APP_URL . '/views/catalogo.php');
    }

    $ck = $_SESSION['checkout'] ?? [];
    if (empty($ck['tipo_entrega'])) {
        flash('advertencia', 'Completa los datos de entrega primero.');
        redirigir(APP_URL . '/views/checkout/paso1-entrega.php');
    }
    if (empty($ck['metodo_pago_key'])) {
        flash('advertencia', 'Selecciona un método de pago primero.');
        redirigir(APP_URL . '/views/checkout/paso3-pago.php');
    }

    // ── Config puntos ─────────────────────────────────────────
    $configPuntos = $db->query("SELECT * FROM config_puntos WHERE activo=1 LIMIT 1")->fetch();
    $puntosPorBs  = (float)($configPuntos['puntos_por_bs'] ?? 1.0);

    // ── Calcular totales ──────────────────────────────────────
    $subtotal = 0.0;
    foreach ($carrito as $item) {
        $p         = $item['oferta'] ?? $item['precio'];
        $subtotal += $p * $item['cantidad'];
    }
    $puntosGanados = (int)round($subtotal * $puntosPorBs);
    $costoDelivery   = (float)($ck['costo_delivery']   ?? 0.0);
    $descuentoPuntos = (float)($ck['descuento_puntos'] ?? 0.0);
    $puntosUsados    = (int)  ($ck['puntos_usados']    ?? 0);
    $totalFinal      = $subtotal + $costoDelivery - $descuentoPuntos;

    $puntosAntes = (int)$_SESSION['usuario_puntos'];

    // ── Obtener o crear metodo_pago_id ────────────────────────
    $metodoNombre = $ck['metodo_pago_nombre'];
    $stmtM = $db->prepare("SELECT id FROM metodos_pago WHERE nombre = :n LIMIT 1");
    $stmtM->execute([':n' => $metodoNombre]);
    $metodoId = $stmtM->fetchColumn();
    if (!$metodoId) {
        $db->prepare("INSERT INTO metodos_pago (nombre) VALUES (:n)")->execute([':n' => $metodoNombre]);
        $metodoId = (int)$db->lastInsertId();
    }

    // ── Transacción principal ─────────────────────────────────
    try {
        $db->beginTransaction();

        $codigo       = generarCodigoPedido();
        $fechaEntrega = !empty($ck['fecha_entrega']) ? $ck['fecha_entrega'] : null;
        $zonaId       = !empty($ck['zona_id'])       ? (int)$ck['zona_id'] : null;

        // 1. Insertar pedido
        $stmtP = $db->prepare(
            "INSERT INTO pedidos (
                usuario_id, codigo, estado,
                tipo_entrega, zona_id, direccion_entrega, referencia, fecha_entrega,
                puntos_usados, descuento_puntos,
                metodo_pago_id, subtotal, costo_delivery, total,
                comprobante, notas, puntos_ganados
             ) VALUES (
                :uid, :cod, 'pendiente',
                :tipo, :zona, :dir, :ref, :fecha,
                :ptos_uso, :desc_ptos,
                :mpago, :sub, :cdeliv, :total,
                :comp, :notas, :ptos_gan
             )"
        );
        $stmtP->execute([
            ':uid'      => (int)$_SESSION['usuario_id'],
            ':cod'      => $codigo,
            ':tipo'     => $ck['tipo_entrega'],
            ':zona'     => $zonaId,
            ':dir'      => $ck['direccion_entrega'] ?? null,
            ':ref'      => $ck['referencia']        ?? null,
            ':fecha'    => $fechaEntrega,
            ':ptos_uso' => $puntosUsados,
            ':desc_ptos'=> $descuentoPuntos,
            ':mpago'    => $metodoId,
            ':sub'      => round($subtotal, 2),
            ':cdeliv'   => round($costoDelivery, 2),
            ':total'    => round($totalFinal, 2),
            ':comp'     => $ck['comprobante']        ?? null,
            ':notas'    => null,
            ':ptos_gan' => $puntosGanados,
        ]);
        $pedidoId = (int)$db->lastInsertId();

        // 2. Insertar detalle del pedido + bajar stock
        $modeloProd = new Producto();
        foreach ($carrito as $item) {
            $pu         = $item['oferta'] ?? $item['precio'];
            $subtItem   = round($pu * $item['cantidad'], 2);

            $db->prepare(
                "INSERT INTO detalle_pedido (pedido_id, producto_id, cantidad, precio_unit, subtotal)
                 VALUES (:pid, :prod, :qty, :pu, :sub)"
            )->execute([
                ':pid'  => $pedidoId,
                ':prod' => (int)$item['id'],
                ':qty'  => $item['cantidad'],
                ':pu'   => $pu,
                ':sub'  => $subtItem,
            ]);

            $modeloProd->actualizarStock(
                (int)$item['id'],
                -(int)ceil($item['cantidad']),
                'salida',
                (int)$_SESSION['usuario_id'],
                $codigo
            );
        }

        // 3. Movimientos de puntos
        $saldoTmp = $puntosAntes;

        if ($puntosUsados > 0) {
            $saldoTras = $saldoTmp - $puntosUsados;
            $db->prepare(
                "INSERT INTO movimientos_puntos
                    (usuario_id, pedido_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                 VALUES (:uid, :pid, 'canjeado', :qty, :sa, :sd, :desc)"
            )->execute([
                ':uid'  => (int)$_SESSION['usuario_id'],
                ':pid'  => $pedidoId,
                ':qty'  => -$puntosUsados,
                ':sa'   => $saldoTmp,
                ':sd'   => $saldoTras,
                ':desc' => 'Canje en pedido ' . $codigo,
            ]);
            $saldoTmp = $saldoTras;
        }

        if ($puntosGanados > 0) {
            $saldoTras = $saldoTmp + $puntosGanados;
            $db->prepare(
                "INSERT INTO movimientos_puntos
                    (usuario_id, pedido_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                 VALUES (:uid, :pid, 'ganado', :qty, :sa, :sd, :desc)"
            )->execute([
                ':uid'  => (int)$_SESSION['usuario_id'],
                ':pid'  => $pedidoId,
                ':qty'  => $puntosGanados,
                ':sa'   => $saldoTmp,
                ':sd'   => $saldoTras,
                ':desc' => 'Compra pedido ' . $codigo,
            ]);
            $saldoTmp = $saldoTras;
        }

        // 4. Actualizar saldo puntos del usuario
        $nuevoSaldo = max(0, $saldoTmp);
        $db->prepare("UPDATE usuarios SET puntos = :p WHERE id = :id")
           ->execute([':p' => $nuevoSaldo, ':id' => (int)$_SESSION['usuario_id']]);

        $db->commit();

        // ── Actualizar sesión ─────────────────────────────────
        $_SESSION['usuario_puntos'] = $nuevoSaldo;

        $_SESSION['ultimo_pedido'] = [
            'id'              => $pedidoId,
            'codigo'          => $codigo,
            'subtotal'        => $subtotal,
            'costo_delivery'  => $costoDelivery,
            'descuento_puntos'=> $descuentoPuntos,
            'total'           => $totalFinal,
            'puntos_usados'   => $puntosUsados,
            'puntos_ganados'  => $puntosGanados,
            'puntos_antes'    => $puntosAntes,
            'puntos_nuevo'    => $nuevoSaldo,
            'metodo_pago'     => $metodoNombre,
            'tipo_entrega'    => $ck['tipo_entrega'],
            'zona_nombre'     => $ck['zona_nombre']        ?? '',
            'direccion'       => $ck['direccion_entrega']  ?? '',
            'referencia'      => $ck['referencia']         ?? '',
            'fecha_entrega'   => $ck['fecha_entrega']      ?? '',
            'comprobante'     => $ck['comprobante']        ?? null,
            'items'           => $carrito,
        ];

        unset($_SESSION['checkout'], $_SESSION['carrito']);
        $pedido = $_SESSION['ultimo_pedido'];

    } catch (\Throwable $e) {
        $db->rollBack();
        flash('error', 'Ocurrió un error al registrar el pedido. Por favor intenta nuevamente.');
        redirigir(APP_URL . '/views/checkout/paso3-pago.php');
    }
}

// ── Iconos y colores de método de pago ───────────────────────
$iconoMetodo = match(true) {
    str_contains($pedido['metodo_pago'], 'Tigo')     => ['bi-qr-code',              '#00A0D2'],
    str_contains($pedido['metodo_pago'], 'Bisa')     => ['bi-bank',                 '#003B8E'],
    str_contains($pedido['metodo_pago'], 'PIX')      => ['bi-lightning-charge-fill','#32BCAD'],
    str_contains($pedido['metodo_pago'], 'Efectivo') => ['bi-cash-stack',           '#1A6B3A'],
    str_contains($pedido['metodo_pago'], 'Tarjeta')  => ['bi-credit-card-2-front',  '#6366F1'],
    default                                           => ['bi-credit-card',          '#6B7280'],
};

// ── Armar mensaje WhatsApp con detalles del pedido ──────────
$_nombre_cliente = ($_SESSION['usuario_nombre'] ?? '') . ' ' . ($_SESSION['usuario_apellido'] ?? '');
$_lineas = [];
foreach ($pedido['items'] as $_item) {
    $_pu = $_item['oferta'] ?? $_item['precio'];
    $_lineas[] = '• ' . $_item['nombre']
               . ' × ' . $_item['cantidad'] . ' ' . $_item['unidad']
               . ' = Bs. ' . number_format($_pu * $_item['cantidad'], 2);
}
$_entrega = $pedido['tipo_entrega'] === 'delivery'
    ? 'Delivery a: ' . $pedido['direccion']
      . ($pedido['zona_nombre'] ? ' (' . $pedido['zona_nombre'] . ')' : '')
    : 'Retiro en tienda';
if ($pedido['referencia']) $_entrega .= ' — Ref: ' . $pedido['referencia'];

$_msg = "🛍️ *NUEVO PEDIDO — Cerámica COBOCE*\n\n"
      . "📋 *Código:* " . $pedido['codigo'] . "\n"
      . "👤 *Cliente:* " . trim($_nombre_cliente) . "\n\n"
      . "*Productos:*\n" . implode("\n", $_lineas) . "\n\n"
      . "🚚 *Entrega:* " . $_entrega . "\n"
      . "💳 *Pago:* " . $pedido['metodo_pago'] . "\n";
if ($pedido['descuento_puntos'] > 0)
    $_msg .= "⭐ *Descuento puntos:* −Bs. " . number_format($pedido['descuento_puntos'], 2) . "\n";
$_msg .= "\n💰 *TOTAL: Bs. " . number_format($pedido['total'], 2) . "*";

$waUrl = 'https://wa.me/59173943006?text=' . rawurlencode($_msg);

$titulo = 'Pedido confirmado — ' . $pedido['codigo'];
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ── BREADCRUMB ─────────────────────────────────────────── -->
<div style="background:white;border-bottom:1px solid var(--gris-borde)">
  <div class="container py-2">
    <nav><ol class="breadcrumb mb-0" style="font-size:.82rem">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php" class="text-verde">Inicio</a></li>
      <li class="breadcrumb-item active">Pedido confirmado</li>
    </ol></nav>
  </div>
</div>

<div class="container py-4">

  <!-- ── STEPPER — todos completados ─────────────────────── -->
  <div class="checkout-stepper mb-4">
    <div class="stepper-track">
      <?php
      $pasos = [
          ['Entrega',  'bi-truck'],
          ['Puntos',   'bi-star-fill'],
          ['Pago',     'bi-credit-card'],
          ['Confirmar','bi-bag-check'],
      ];
      foreach ($pasos as $i => [$label, $icon]):
      ?>
      <div class="stepper-step completed">
        <div class="step-circle"><i class="bi bi-check-lg"></i></div>
        <div class="step-label"><?= $label ?></div>
      </div>
      <?php if ($i < count($pasos) - 1): ?>
      <div class="stepper-line completed"></div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       HERO DE ÉXITO
       ══════════════════════════════════════════════════════════ -->
  <div class="success-hero mb-4">
    <div class="success-anim">
      <div class="success-ring"></div>
      <i class="bi bi-check-lg success-icon"></i>
    </div>
    <h1 class="success-titulo">¡Pedido realizado con éxito!</h1>
    <p class="success-sub">
      Gracias, <strong><?= limpiar($_SESSION['usuario_nombre']) ?></strong>.
      Hemos recibido tu pedido y lo estamos procesando.
    </p>
    <div class="codigo-pedido-wrap">
      <span class="codigo-label">Número de pedido</span>
      <div class="codigo-val" id="codigoPedido">
        <?= limpiar($pedido['codigo']) ?>
        <button type="button" class="btn-copiar-codigo"
                onclick="copiarCodigo()"
                title="Copiar código">
          <i class="bi bi-clipboard" id="iconCopiar"></i>
        </button>
      </div>
      <span class="codigo-hint">Guarda este número para rastrear tu pedido</span>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       ¿QUÉ SIGUE?
       ══════════════════════════════════════════════════════════ -->
  <div class="que-sigue mb-4">
    <?php if (in_array($pedido['metodo_pago'], ['Efectivo contra entrega', 'Tarjeta POS'])): ?>
    <?php $pasos_sig = [
        ['bi-bag-check',   'var(--verde)',   'Pedido recibido',     'Tu pedido está registrado en nuestro sistema'],
        ['bi-box-seam',    '#6366F1',        'Preparación',         'Armamos tu pedido con cuidado'],
        ['bi-truck',       '#0EA5E9',        'En camino',           'El repartidor saldrá hacia tu dirección'],
        ['bi-house-check', 'var(--dorado-dark)', 'Entrega y pago', 'Recibes y pagas al momento de la entrega'],
    ]; ?>
    <?php else: ?>
    <?php $pasos_sig = [
        ['bi-search',      '#6366F1',        'Verificación de pago','Confirmamos tu transferencia (5–15 min)'],
        ['bi-box-seam',    'var(--verde)',   'Preparación',         'Armamos tu pedido con cuidado'],
        ['bi-truck',       '#0EA5E9',        'En camino',           'El repartidor saldrá hacia tu dirección'],
        ['bi-house-check', 'var(--dorado-dark)', 'Entregado',       'Recibes tu pedido y confirmamos la entrega'],
    ]; ?>
    <?php endif; ?>

    <?php foreach ($pasos_sig as $idx => [$ico, $col, $tit, $desc]): ?>
    <div class="que-sigue-paso">
      <div class="qs-icon" style="--qs-color:<?= $col ?>">
        <i class="bi <?= $ico ?>"></i>
      </div>
      <?php if ($idx < count($pasos_sig) - 1): ?>
      <div class="qs-linea"></div>
      <?php endif; ?>
      <div class="qs-texto">
        <div class="qs-titulo"><?= $tit ?></div>
        <div class="qs-desc"><?= $desc ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       CUERPO PRINCIPAL — 2 columnas
       ══════════════════════════════════════════════════════════ -->
  <div class="row g-4">

    <!-- ════════════════════════════════════════════════════════
         COLUMNA IZQUIERDA — Detalle del pedido
         ════════════════════════════════════════════════════════ -->
    <div class="col-lg-8">

      <!-- Productos -->
      <div class="confirm-card mb-3">
        <div class="confirm-card-header">
          <i class="bi bi-bag-check-fill me-2" style="color:var(--verde)"></i>
          Productos del pedido
          <span class="ms-auto badge bg-light text-dark border"
                style="font-size:.75rem;font-weight:600">
            <?= count($pedido['items']) ?> producto<?= count($pedido['items']) !== 1 ? 's' : '' ?>
          </span>
        </div>
        <div class="confirm-items">
          <?php foreach ($pedido['items'] as $item):
            $pu         = $item['oferta'] ?? $item['precio'];
            $subtItem   = $pu * $item['cantidad'];
            $iconsCat   = \Producto::ICONOS_CAT;
            $iconProd   = $iconsCat[$item['categoria'] ?? ''] ?? 'bi-image';
          ?>
          <div class="confirm-item">
            <div class="confirm-item-img">
              <?php if ($item['imagen'] && file_exists(UPLOADS_PATH . '/' . $item['imagen'])): ?>
                <img src="<?= UPLOADS_URL . '/' . limpiar($item['imagen']) ?>"
                     alt="<?= limpiar($item['nombre']) ?>">
              <?php else: ?>
                <div class="confirm-item-ph"><i class="bi <?= $iconProd ?>"></i></div>
              <?php endif; ?>
              <span class="confirm-item-qty"><?= $item['cantidad'] <= 9 ? (int)$item['cantidad'] : '9+' ?></span>
            </div>
            <div class="flex-grow-1" style="min-width:0">
              <div class="confirm-item-nombre"><?= limpiar($item['nombre']) ?></div>
              <div class="confirm-item-meta">
                <?= limpiar($item['categoria'] ?? '') ?> ·
                <?= $item['cantidad'] ?> <?= limpiar($item['unidad']) ?>
                <?php if ($item['oferta']): ?>
                  · <span class="text-danger fw-600" style="font-size:.72rem">Precio de oferta</span>
                <?php endif; ?>
              </div>
              <?php if ($item['puntos'] > 0): ?>
              <div class="confirm-item-pts">
                <i class="bi bi-star-fill me-1"></i>
                +<?= (int)($item['puntos'] * ceil($item['cantidad'])) ?> pts ganados
              </div>
              <?php endif; ?>
            </div>
            <div class="confirm-item-precio">
              <?php if ($item['oferta']): ?>
                <span class="text-danger fw-700"><?= precio($pu) ?></span>
                <small class="d-block text-muted text-decoration-line-through"
                       style="font-size:.72rem"><?= precio($item['precio']) ?></small>
              <?php else: ?>
                <span class="fw-700"><?= precio($pu) ?></span>
              <?php endif; ?>
              <div class="confirm-item-sub"><?= precio($subtItem) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Entrega y Pago — dos columnas -->
      <div class="row g-3 mb-3">

        <!-- Entrega -->
        <div class="col-12 col-sm-6">
          <div class="confirm-card h-100">
            <div class="confirm-card-header">
              <?php if ($pedido['tipo_entrega'] === 'delivery'): ?>
                <i class="bi bi-truck me-2" style="color:#0EA5E9"></i>Entrega a domicilio
              <?php else: ?>
                <i class="bi bi-shop me-2" style="color:var(--verde)"></i>Retiro en tienda
              <?php endif; ?>
            </div>
            <div class="confirm-info-list">
              <?php if ($pedido['tipo_entrega'] === 'delivery'): ?>
              <div class="ci-row">
                <span class="ci-label">Zona</span>
                <span class="ci-val"><?= limpiar($pedido['zona_nombre']) ?></span>
              </div>
              <div class="ci-row">
                <span class="ci-label">Dirección</span>
                <span class="ci-val"><?= limpiar($pedido['direccion']) ?></span>
              </div>
              <?php if ($pedido['referencia']): ?>
              <div class="ci-row">
                <span class="ci-label">Referencia</span>
                <span class="ci-val"><?= limpiar($pedido['referencia']) ?></span>
              </div>
              <?php endif; ?>
              <?php else: ?>
              <div class="ci-row">
                <span class="ci-label">Dirección</span>
                <span class="ci-val">
                  <?= limpiar($configTienda['direccion']) ?>
                  <?php if (!empty($configTienda['referencia'])): ?>
                    <br><small style="color:#6b7280;font-weight:400"><?= limpiar($configTienda['referencia']) ?></small>
                  <?php endif; ?>
                  <br><small style="color:#6b7280;font-weight:400"><?= limpiar($configTienda['ciudad']) ?></small>
                  <?php if (!empty($configTienda['maps_url'])): ?>
                    <br><a href="<?= limpiar($configTienda['maps_url']) ?>" target="_blank" rel="noopener"
                           class="text-verde" style="font-size:.72rem;font-weight:600;display:inline-flex;align-items:center;gap:.2rem;margin-top:.2rem">
                      <i class="bi bi-map-fill"></i> Ver en mapa
                    </a>
                  <?php endif; ?>
                </span>
              </div>
              <div class="ci-row">
                <span class="ci-label">Horario</span>
                <span class="ci-val">
                  <?= limpiar($configTienda['horario_sem']) ?><br>
                  <?= limpiar($configTienda['horario_dom']) ?>
                </span>
              </div>
              <div class="ci-row">
                <span class="ci-label">Teléfono</span>
                <span class="ci-val">
                  <a href="tel:+591<?= limpiar($configTienda['telefono']) ?>"
                     style="color:inherit;text-decoration:none;font-weight:600">
                    +591 <?= limpiar($configTienda['telefono']) ?>
                  </a>
                </span>
              </div>
              <div class="ci-row">
                <span class="ci-label">WhatsApp</span>
                <span class="ci-val">
                  <a href="https://wa.me/591<?= limpiar($configTienda['whatsapp']) ?>"
                     target="_blank" rel="noopener"
                     style="color:#25D366;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:.3rem">
                    <i class="bi bi-whatsapp"></i>
                    +591 <?= limpiar($configTienda['whatsapp']) ?>
                  </a>
                </span>
              </div>
              <?php endif; ?>
              <?php if ($pedido['fecha_entrega']): ?>
              <div class="ci-row">
                <span class="ci-label"><?= $pedido['tipo_entrega'] === 'delivery' ? 'Fecha' : 'Retiro' ?></span>
                <span class="ci-val">
                  <?= date('d/m/Y', strtotime($pedido['fecha_entrega'])) ?>
                </span>
              </div>
              <?php endif; ?>
              <div class="ci-row">
                <span class="ci-label">Costo</span>
                <span class="ci-val fw-700">
                  <?= $pedido['costo_delivery'] > 0 ? precio($pedido['costo_delivery']) : '<span class="text-success">Gratis</span>' ?>
                </span>
              </div>
            </div>

            <?php if ($pedido['tipo_entrega'] === 'retiro_tienda'): ?>
            <div style="background:#f0fdf4;border-top:1px solid #bbf7d0;padding:.65rem 1.25rem;
                        font-size:.78rem;color:#166534;display:flex;gap:.5rem;align-items:flex-start">
              <i class="bi bi-info-circle-fill" style="flex-shrink:0;margin-top:.05rem"></i>
              Te avisaremos por WhatsApp al
              <strong>+591 <?= limpiar($configTienda['whatsapp']) ?></strong>
              cuando tu pedido esté listo.
            </div>
            <?php endif; ?>

          </div>
        </div>

        <!-- Pago -->
        <div class="col-12 col-sm-6">
          <div class="confirm-card h-100">
            <div class="confirm-card-header">
              <i class="bi bi-credit-card me-2" style="color:<?= $iconoMetodo[1] ?>"></i>Pago
            </div>
            <div class="confirm-info-list">
              <div class="ci-row">
                <span class="ci-label">Método</span>
                <span class="ci-val">
                  <i class="bi <?= $iconoMetodo[0] ?> me-1"
                     style="color:<?= $iconoMetodo[1] ?>"></i>
                  <?= limpiar($pedido['metodo_pago']) ?>
                </span>
              </div>
              <div class="ci-row">
                <span class="ci-label">Estado pago</span>
                <span class="ci-val">
                  <?php if (in_array($pedido['metodo_pago'], ['Efectivo contra entrega','Tarjeta POS'])): ?>
                    <span class="badge bg-warning text-dark">Al momento de entrega</span>
                  <?php elseif (!empty($pedido['comprobante'])): ?>
                    <span class="badge bg-info text-white">Comprobante adjunto</span>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark">Pendiente verificación</span>
                  <?php endif; ?>
                </span>
              </div>
              <?php if (!empty($pedido['comprobante'])): ?>
              <div class="ci-row">
                <span class="ci-label">Comprobante</span>
                <span class="ci-val">
                  <a href="<?= UPLOADS_URL . '/' . limpiar($pedido['comprobante']) ?>"
                     target="_blank" class="link-verde"
                     style="font-size:.8rem">
                    <i class="bi bi-file-earmark-check me-1"></i>Ver archivo
                  </a>
                </span>
              </div>
              <?php endif; ?>
              <?php if (!in_array($pedido['metodo_pago'], ['Efectivo contra entrega','Tarjeta POS']) && empty($pedido['comprobante'])): ?>
              <div class="ci-row">
                <span class="ci-label" colspan="2">
                  <a href="<?= $waUrl ?>" target="_blank" class="text-decoration-none">
                    <div class="whatsapp-comp">
                      <i class="bi bi-whatsapp me-1"></i>
                      Envía tu comprobante por WhatsApp al
                      <strong>+591 73943006</strong>
                      indicando el código
                      <strong><?= limpiar($pedido['codigo']) ?></strong>
                    </div>
                  </a>
                </span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /col izquierda -->


    <!-- ════════════════════════════════════════════════════════
         COLUMNA DERECHA — Totales + Puntos
         ════════════════════════════════════════════════════════ -->
    <div class="col-lg-4">

      <!-- Resumen de totales -->
      <div class="confirm-card mb-3">
        <div class="confirm-card-header">
          <i class="bi bi-receipt-cutoff me-2" style="color:var(--verde)"></i>Resumen de pago
        </div>
        <div class="confirm-info-list">
          <div class="ci-row">
            <span class="ci-label">Subtotal</span>
            <span class="ci-val fw-600"><?= precio($pedido['subtotal']) ?></span>
          </div>
          <div class="ci-row">
            <span class="ci-label">Delivery</span>
            <span class="ci-val fw-600">
              <?= $pedido['costo_delivery'] > 0
                  ? precio($pedido['costo_delivery'])
                  : '<span class="text-success">Gratis</span>' ?>
            </span>
          </div>
          <?php if ($pedido['descuento_puntos'] > 0): ?>
          <div class="ci-row" style="color:var(--verde-dark)">
            <span class="ci-label">
              <i class="bi bi-star-fill me-1" style="color:var(--dorado)"></i>
              Descuento puntos
            </span>
            <span class="ci-val fw-700 text-danger">
              − <?= precio($pedido['descuento_puntos']) ?>
            </span>
          </div>
          <?php endif; ?>
        </div>
        <div class="ci-total-final">
          <span>TOTAL PAGADO</span>
          <span>Bs. <?= number_format($pedido['total'], 2) ?></span>
        </div>
      </div>

      <!-- ── TARJETA DE PUNTOS ─────────────────────────── -->
      <div class="puntos-earned-card mb-3">

        <div class="pec-header">
          <i class="bi bi-star-fill pec-star"></i>
          <div class="pec-title">Puntos de fidelidad</div>
        </div>

        <!-- Puntos usados -->
        <?php if ($pedido['puntos_usados'] > 0): ?>
        <div class="pec-row usados">
          <div class="pec-row-left">
            <i class="bi bi-dash-circle"></i>
            <div>
              <div class="pec-row-titulo">Puntos canjeados</div>
              <div class="pec-row-sub">Descuento aplicado: <?= precio($pedido['descuento_puntos']) ?></div>
            </div>
          </div>
          <div class="pec-row-val usados-val">
            −<?= number_format($pedido['puntos_usados']) ?>
            <span>pts</span>
          </div>
        </div>
        <?php endif; ?>

        <!-- Puntos ganados -->
        <?php if ($pedido['puntos_ganados'] > 0): ?>
        <div class="pec-row ganados">
          <div class="pec-row-left">
            <i class="bi bi-plus-circle-fill"></i>
            <div>
              <div class="pec-row-titulo">Puntos ganados</div>
              <div class="pec-row-sub">Por esta compra</div>
            </div>
          </div>
          <div class="pec-row-val ganados-val">
            +<?= number_format($pedido['puntos_ganados']) ?>
            <span>pts</span>
          </div>
        </div>
        <?php else: ?>
        <div style="font-size:.8rem;color:rgba(255,255,255,.7);text-align:center;padding:.5rem 0">
          Esta compra no genera puntos adicionales
        </div>
        <?php endif; ?>

        <!-- Saldo nuevo -->
        <div class="pec-saldo">
          <div class="pec-saldo-label">Tu nuevo saldo</div>
          <div class="pec-saldo-val">
            <?= number_format($pedido['puntos_nuevo']) ?>
            <span>puntos</span>
          </div>
          <?php if ($pedido['puntos_ganados'] > 0): ?>
          <div class="pec-saldo-equiv">
            ≈ Bs. <?= number_format($pedido['puntos_nuevo'] * VALOR_PUNTO_BS, 2) ?> en descuentos
          </div>
          <?php endif; ?>
        </div>

        <!-- Barra de progreso hacia siguiente nivel -->
        <?php
        $umbralRows = $db->query("SELECT puntos FROM opciones_canje WHERE activo=1 ORDER BY puntos ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $siguiente = null;
        foreach ($umbralRows as $o) {
            if ($pedido['puntos_nuevo'] < (int)$o) { $siguiente = (int)$o; break; }
        }
        if ($siguiente !== null):
            $pct = min(100, round($pedido['puntos_nuevo'] / $siguiente * 100));
        ?>
        <div class="pec-progreso">
          <div class="pec-prog-label">
            Progreso al siguiente canje (<?= number_format($siguiente) ?> pts)
          </div>
          <div class="pec-prog-bar">
            <div class="pec-prog-fill" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="pec-prog-num">
            <?= number_format($pedido['puntos_nuevo']) ?> / <?= number_format($siguiente) ?> pts
            (<?= $pct ?>%)
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /puntos-earned-card -->

      <!-- CTA Buttons -->
      <div class="d-flex flex-column gap-2">
        <a href="<?= APP_URL ?>/views/mis-pedidos.php"
           class="btn-coboce text-center text-decoration-none"
           style="border-radius:8px;display:block;padding:.75rem">
          <i class="bi bi-bag-check me-2"></i>Ver mis pedidos
        </a>
        <a href="<?= APP_URL ?>/views/catalogo.php"
           class="btn btn-outline-secondary w-100"
           style="border-radius:8px;padding:.72rem">
          <i class="bi bi-grid me-2"></i>Seguir comprando
        </a>
        <a href="<?= $waUrl ?>"
           target="_blank"
           class="btn w-100 fw-600"
           style="background:#25D366;color:white;border-radius:8px;padding:.72rem">
          <i class="bi bi-whatsapp me-2"></i>Enviar pedido por WhatsApp
        </a>
      </div>

    </div><!-- /col derecha -->

  </div><!-- /row -->
</div><!-- /container -->

<style>
/* ── STEPPER ──────────────────────────────────────────────── */
.checkout-stepper { background:white;border-radius:var(--radio);padding:1.5rem 2rem;box-shadow:var(--sombra);border:1px solid var(--gris-borde); }
.stepper-track    { display:flex;align-items:center;justify-content:center;max-width:600px;margin:0 auto; }
.stepper-step     { display:flex;flex-direction:column;align-items:center;gap:.4rem;flex-shrink:0; }
.step-circle      { width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--gris-borde);color:var(--texto-suave);font-size:.95rem;font-weight:700;border:2.5px solid var(--gris-borde); }
.stepper-step.completed .step-circle { background:var(--verde-dark);border-color:var(--verde-dark);color:white; }
.step-label { font-size:.73rem;font-weight:600;color:var(--texto-suave);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap; }
.stepper-step.completed .step-label { color:var(--verde-dark); }
.stepper-line { flex:1;height:2.5px;background:var(--gris-borde);margin:0 6px;margin-bottom:22px;min-width:30px; }
.stepper-line.completed { background:var(--verde-dark); }

/* ── HERO ÉXITO ───────────────────────────────────────────── */
.success-hero {
  text-align:center;
  background:linear-gradient(160deg,var(--verde-dark) 0%,var(--verde) 55%,var(--verde-light) 100%);
  border-radius:16px;
  padding:3rem 2rem;
  color:white;
  position:relative;
  overflow:hidden;
}
.success-hero::before {
  content:'';position:absolute;inset:0;
  background-image:repeating-linear-gradient(45deg,transparent,transparent 25px,rgba(255,255,255,.025) 25px,rgba(255,255,255,.025) 26px);
}
.success-anim {
  position:relative;display:inline-flex;
  align-items:center;justify-content:center;
  width:90px;height:90px;margin-bottom:1.25rem;
}
.success-ring {
  position:absolute;inset:0;
  border-radius:50%;
  border:3px solid rgba(201,168,76,.5);
  animation:pulse-ring 2s ease-out infinite;
}
@keyframes pulse-ring {
  0%   { transform:scale(1);  opacity:1; }
  100% { transform:scale(1.6);opacity:0; }
}
.success-icon {
  width:90px;height:90px;border-radius:50%;
  background:var(--dorado);
  display:flex;align-items:center;justify-content:center;
  font-size:2.5rem;color:var(--verde-dark);
  font-weight:900;position:relative;z-index:1;
  box-shadow:0 6px 24px rgba(201,168,76,.45);
  animation:pop-in .5s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes pop-in {
  from { transform:scale(0); opacity:0; }
  to   { transform:scale(1); opacity:1; }
}
.success-titulo { font-size:clamp(1.5rem,3.5vw,2.2rem);font-weight:800;margin-bottom:.5rem;position:relative; }
.success-sub    { font-size:1rem;opacity:.88;margin-bottom:1.5rem;position:relative; }

.codigo-pedido-wrap { display:inline-block;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:12px;padding:1rem 1.5rem;position:relative; }
.codigo-label { display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:1.5px;opacity:.7;margin-bottom:.35rem; }
.codigo-val   { font-size:1.5rem;font-weight:800;letter-spacing:2px;display:flex;align-items:center;justify-content:center;gap:.6rem; }
.codigo-hint  { display:block;font-size:.72rem;opacity:.65;margin-top:.35rem; }
.btn-copiar-codigo {
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  border-radius:6px;padding:.25rem .5rem;cursor:pointer;
  color:rgba(255,255,255,.85);font-size:.9rem;transition:var(--trans);
}
.btn-copiar-codigo:hover { background:var(--dorado);color:var(--verde-dark);border-color:var(--dorado); }

/* ── QUÉ SIGUE ────────────────────────────────────────────── */
.que-sigue {
  display:flex;align-items:flex-start;justify-content:center;
  gap:0;
  background:white;border-radius:var(--radio);
  border:1px solid var(--gris-borde);
  box-shadow:var(--sombra);
  padding:1.5rem;
  flex-wrap:wrap;
}
.que-sigue-paso { display:flex;flex-direction:column;align-items:center;flex:1;min-width:100px;position:relative; }
.qs-icon {
  width:52px;height:52px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:1.3rem;
  background:color-mix(in srgb, var(--qs-color) 12%, transparent);
  color:var(--qs-color);
  margin-bottom:.6rem;
  border:2px solid color-mix(in srgb, var(--qs-color) 25%, transparent);
}
.qs-linea {
  position:absolute;top:26px;left:calc(50% + 26px);
  width:calc(100% - 52px);height:2px;
  background:linear-gradient(90deg,var(--gris-borde),var(--gris-borde));
  z-index:0;
}
.qs-texto { text-align:center; }
.qs-titulo { font-weight:700;font-size:.84rem;color:var(--texto); }
.qs-desc   { font-size:.72rem;color:var(--texto-suave);margin-top:.2rem;line-height:1.4; }

/* ── CARDS CONFIRMACIÓN ───────────────────────────────────── */
.confirm-card { background:white;border-radius:var(--radio);border:1px solid var(--gris-borde);box-shadow:var(--sombra);overflow:hidden; }
.confirm-card-header { display:flex;align-items:center;padding:.85rem 1.25rem;background:var(--gris-bg);border-bottom:1px solid var(--gris-borde);font-weight:700;font-size:.9rem;color:var(--verde-dark); }

/* Items confirmación */
.confirm-items { padding:.5rem .75rem; }
.confirm-item { display:flex;align-items:center;gap:.9rem;padding:.75rem .5rem;border-bottom:1px solid var(--gris-borde); }
.confirm-item:last-child { border-bottom:none; }
.confirm-item-img { position:relative;width:58px;height:58px;flex-shrink:0;border-radius:8px;overflow:hidden;border:1px solid var(--gris-borde); }
.confirm-item-img img { width:100%;height:100%;object-fit:cover; }
.confirm-item-ph  { width:100%;height:100%;background:var(--gris-bg);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#ccc; }
.confirm-item-qty { position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:var(--verde);color:white;border-radius:50%;font-size:.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid white; }
.confirm-item-nombre { font-weight:600;font-size:.9rem;color:var(--texto); }
.confirm-item-meta   { font-size:.75rem;color:var(--texto-suave);margin-top:.15rem; }
.confirm-item-pts    { font-size:.72rem;color:var(--dorado-dark);margin-top:.2rem; }
.confirm-item-precio { text-align:right;flex-shrink:0; }
.confirm-item-sub    { font-size:.8rem;font-weight:700;color:var(--verde-dark);margin-top:.2rem; }

/* Info rows */
.confirm-info-list { padding:.75rem 1.25rem; }
.ci-row { display:flex;justify-content:space-between;align-items:flex-start;font-size:.83rem;margin-bottom:.55rem;gap:.5rem; }
.ci-row:last-child { margin-bottom:0; }
.ci-label { color:var(--texto-suave);flex-shrink:0;min-width:90px; }
.ci-val   { color:var(--texto);font-weight:500;text-align:right; }
.ci-total-final {
  display:flex;justify-content:space-between;align-items:center;
  background:var(--verde-dark);color:white;
  padding:.85rem 1.25rem;
  font-size:1rem;font-weight:800;
  border-radius:0 0 var(--radio) var(--radio);
}
.whatsapp-comp {
  background:rgba(37,211,102,.1);border:1px solid rgba(37,211,102,.3);
  border-radius:8px;padding:.6rem .85rem;font-size:.78rem;color:var(--texto);line-height:1.5;
}
.whatsapp-comp i { color:#25D366; }

/* ── TARJETA PUNTOS GANADOS ───────────────────────────────── */
.puntos-earned-card {
  background:linear-gradient(160deg,var(--verde-dark) 0%,#1A5C32 50%,#0f3d22 100%);
  border-radius:var(--radio);
  padding:1.5rem;
  color:white;
  box-shadow:0 8px 30px rgba(26,107,58,.35);
  position:relative;overflow:hidden;
}
.puntos-earned-card::before {
  content:'';position:absolute;
  right:-30px;top:-30px;
  width:150px;height:150px;
  background:rgba(201,168,76,.08);border-radius:50%;
}
.pec-header { display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem; }
.pec-star   { font-size:1.5rem;color:var(--dorado); }
.pec-title  { font-size:1rem;font-weight:700;opacity:.95; }

.pec-row {
  display:flex;align-items:center;justify-content:space-between;
  padding:.75rem;border-radius:8px;margin-bottom:.6rem;
  gap:.75rem;
}
.pec-row.usados { background:rgba(220,53,69,.15);border:1px solid rgba(220,53,69,.25); }
.pec-row.ganados{ background:rgba(201,168,76,.15);border:1px solid rgba(201,168,76,.3); }
.pec-row-left   { display:flex;align-items:flex-start;gap:.6rem; }
.pec-row-left > i { font-size:1.2rem;flex-shrink:0;margin-top:.1rem; }
.pec-row.usados  .pec-row-left > i { color:#ff6b7a; }
.pec-row.ganados .pec-row-left > i { color:var(--dorado); }
.pec-row-titulo { font-size:.84rem;font-weight:700; }
.pec-row-sub    { font-size:.72rem;opacity:.75;margin-top:.1rem; }
.pec-row-val    { font-size:1.3rem;font-weight:800;white-space:nowrap;flex-shrink:0; }
.pec-row-val span { font-size:.7rem;font-weight:400;opacity:.8; }
.usados-val  { color:#ff6b7a; }
.ganados-val { color:var(--dorado-light); }

.pec-saldo {
  text-align:center;
  padding:1rem;
  background:rgba(255,255,255,.07);
  border-radius:10px;
  border:1px solid rgba(255,255,255,.12);
  margin-top:.5rem;
}
.pec-saldo-label { font-size:.7rem;text-transform:uppercase;letter-spacing:1px;opacity:.7;margin-bottom:.3rem; }
.pec-saldo-val   { font-size:2rem;font-weight:800;line-height:1.1; }
.pec-saldo-val span { font-size:.85rem;font-weight:500;opacity:.8; }
.pec-saldo-equiv { font-size:.75rem;opacity:.65;margin-top:.3rem; }

.pec-progreso { margin-top:1rem; }
.pec-prog-label { font-size:.72rem;opacity:.75;margin-bottom:.4rem; }
.pec-prog-bar   { background:rgba(255,255,255,.12);border-radius:50px;height:8px;overflow:hidden; }
.pec-prog-fill  { height:100%;background:linear-gradient(90deg,var(--dorado),var(--dorado-light));border-radius:50px;transition:width .8s ease; }
.pec-prog-num   { font-size:.7rem;opacity:.65;margin-top:.3rem;text-align:right; }

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media (max-width:768px) {
  .que-sigue { gap:1rem; }
  .qs-linea  { display:none; }
  .que-sigue-paso { flex-direction:row;align-items:flex-start;gap:.75rem;width:100%;min-width:100%; }
  .qs-icon   { flex-shrink:0;margin-bottom:0; }
  .qs-texto  { text-align:left; }
}
@media (max-width:576px) {
  .step-label  { display:none; }
  .stepper-line { min-width:18px; }
  .success-hero { padding:2rem 1.25rem; }
  .codigo-val   { font-size:1.1rem; }
}
</style>

<script>
function copiarCodigo() {
    const codigo = '<?= $pedido['codigo'] ?>';
    navigator.clipboard.writeText(codigo).then(() => {
        const ic = document.getElementById('iconCopiar');
        ic.className = 'bi bi-check-lg';
        setTimeout(() => ic.className = 'bi bi-clipboard', 2500);
    });
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
