<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

requiereLogin(APP_URL . '/views/mis-pedidos.php');

$db  = Database::getConnection();
$uid = (int) $_SESSION['usuario_id'];

// ── Vista detalle ─────────────────────────────────────────────
$pedidoId = (int)($_GET['id'] ?? 0);

if ($pedidoId) {
    // Verificar que el pedido pertenece al usuario
    $stmt = $db->prepare(
        "SELECT p.*, mp.nombre AS metodo_nombre,
                zd.nombre AS zona_nombre, zd.costo AS zona_costo
         FROM pedidos p
         LEFT JOIN metodos_pago mp ON mp.id = p.metodo_pago_id
         LEFT JOIN zonas_delivery zd ON zd.id = p.zona_id
         WHERE p.id = :id AND p.usuario_id = :uid
         LIMIT 1"
    );
    $stmt->execute([':id' => $pedidoId, ':uid' => $uid]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        guardarFlash('error', 'Pedido no encontrado.');
        redirigir(APP_URL . '/views/mis-pedidos.php');
    }

    $detalle = $db->prepare(
        "SELECT dp.*, pr.nombre AS prod_nombre, pr.imagen,
                pr.unidad, pr.codigo, c.nombre AS categoria
         FROM detalle_pedido dp
         JOIN productos pr ON pr.id = dp.producto_id
         LEFT JOIN categorias c ON c.id = pr.categoria_id
         WHERE dp.pedido_id = :pid"
    );
    $detalle->execute([':pid' => $pedidoId]);
    $items = $detalle->fetchAll();

    $titulo = 'Pedido ' . limpiar($pedido['codigo']);

} else {
    // ── Vista lista ───────────────────────────────────────────
    $estadoFiltro = $_GET['estado'] ?? '';
    $pagina       = max(1, (int)($_GET['pagina'] ?? 1));
    $POR_PAGINA   = 8;

    $estados = ['pendiente','confirmado','en_preparacion','en_camino','entregado','cancelado'];
    $whereEstado = in_array($estadoFiltro, $estados) ? 'AND p.estado = :estado' : '';

    $params = [':uid' => $uid];
    if ($whereEstado) $params[':estado'] = $estadoFiltro;

    $total = $db->prepare(
        "SELECT COUNT(*) FROM pedidos p WHERE p.usuario_id = :uid $whereEstado"
    );
    $total->execute($params);
    $totalPedidos = (int)$total->fetchColumn();
    $totalPags    = max(1, (int)ceil($totalPedidos / $POR_PAGINA));
    $offset       = ($pagina - 1) * $POR_PAGINA;

    $stmt = $db->prepare(
        "SELECT p.*, mp.nombre AS metodo_nombre
         FROM pedidos p
         LEFT JOIN metodos_pago mp ON mp.id = p.metodo_pago_id
         WHERE p.usuario_id = :uid $whereEstado
         ORDER BY p.creado_en DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $POR_PAGINA, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,     PDO::PARAM_INT);
    $stmt->execute();
    $pedidos = $stmt->fetchAll();

    $titulo = 'Mis pedidos';
}

// ── Helpers locales ───────────────────────────────────────────
function badgePedido(string $estado): string {
    return match($estado) {
        'entregado'      => '<span class="ped-badge ped-badge-ok">Entregado</span>',
        'en_camino'      => '<span class="ped-badge ped-badge-info">En camino</span>',
        'en_preparacion' => '<span class="ped-badge ped-badge-prep">En preparación</span>',
        'confirmado'     => '<span class="ped-badge ped-badge-conf">Confirmado</span>',
        'cancelado'      => '<span class="ped-badge ped-badge-cancel">Cancelado</span>',
        default          => '<span class="ped-badge ped-badge-pend">Pendiente</span>',
    };
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
  /* ── Breadcrumb bar ───────────────────────────────── */
  .page-bar { background:white; border-bottom:1px solid var(--gris-borde,#dee2e6); }
  .page-bar .breadcrumb { font-size:.82rem; margin:0; }
  .page-bar .breadcrumb-item a { color:var(--verde,#1A6B3A); text-decoration:none; }

  /* ── Badges estado ────────────────────────────────── */
  .ped-badge { display:inline-block; padding:.25rem .75rem; border-radius:20px; font-size:.74rem; font-weight:700; }
  .ped-badge-ok     { background:#d1fae5; color:#065f46; }
  .ped-badge-info   { background:#dbeafe; color:#1e40af; }
  .ped-badge-prep   { background:#ede9fe; color:#5b21b6; }
  .ped-badge-conf   { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
  .ped-badge-cancel { background:#fee2e2; color:#991b1b; }
  .ped-badge-pend   { background:#fefce8; color:#92400e; border:1px solid #fde68a; }

  /* ── Tarjeta pedido (lista) ───────────────────────── */
  .ped-card {
    background:white; border:1px solid #dee2e6; border-radius:12px;
    padding:1.25rem 1.5rem; transition:all .2s;
    text-decoration:none; color:inherit; display:block;
  }
  .ped-card:hover { border-color:var(--verde,#1A6B3A); box-shadow:0 4px 20px rgba(0,0,0,.08); color:inherit; }
  .ped-code { font-family:monospace; font-size:.82rem; background:#F0F2F5; padding:.2rem .5rem; border-radius:5px; color:#374151; }
  .ped-total { font-size:1.15rem; font-weight:800; color:var(--verde-dark,#145730); }
  .ped-meta  { font-size:.78rem; color:#6B7280; }

  /* ── Filtros estado ───────────────────────────────── */
  .estado-pill {
    display:inline-block; padding:.3rem .9rem; border-radius:20px;
    font-size:.78rem; font-weight:600; border:1.5px solid #dee2e6;
    background:white; color:#6B7280; text-decoration:none; transition:all .15s;
  }
  .estado-pill:hover { border-color:var(--verde,#1A6B3A); color:var(--verde-dark,#145730); }
  .estado-pill.active { background:var(--verde,#1A6B3A); border-color:var(--verde,#1A6B3A); color:white; }

  /* ── Timeline ─────────────────────────────────────── */
  .timeline { display:flex; align-items:flex-start; gap:0; overflow-x:auto; padding:.25rem 0 1rem; }
  .tl-step  { flex:1; min-width:90px; text-align:center; position:relative; }
  .tl-step::before {
    content:''; position:absolute; top:18px; left:50%; right:-50%;
    height:3px; background:#dee2e6; z-index:0;
  }
  .tl-step:last-child::before { display:none; }
  .tl-step.done::before  { background:var(--verde,#1A6B3A); }
  .tl-dot {
    width:38px; height:38px; border-radius:50%; border:3px solid #dee2e6;
    background:white; display:flex; align-items:center; justify-content:center;
    margin:0 auto .5rem; position:relative; z-index:1; font-size:1rem;
    transition:all .3s;
  }
  .tl-step.done .tl-dot  { border-color:var(--verde,#1A6B3A); background:var(--verde,#1A6B3A); color:white; }
  .tl-step.active .tl-dot{ border-color:var(--verde,#1A6B3A); background:white; color:var(--verde,#1A6B3A); }
  .tl-step.cancel .tl-dot{ border-color:#ef4444; background:#ef4444; color:white; }
  .tl-lbl { font-size:.7rem; font-weight:600; color:#9CA3AF; line-height:1.3; }
  .tl-step.done .tl-lbl, .tl-step.active .tl-lbl { color:var(--verde-dark,#145730); }
  .tl-step.cancel .tl-lbl { color:#ef4444; }

  /* ── Detalle secciones ────────────────────────────── */
  .det-card {
    background:white; border:1px solid #dee2e6; border-radius:12px; padding:1.25rem 1.5rem;
  }
  .det-card-title {
    font-size:.82rem; font-weight:700; color:#6B7280; text-transform:uppercase;
    letter-spacing:.6px; margin-bottom:.9rem; padding-bottom:.6rem;
    border-bottom:1px solid #F0F2F5;
  }
  .det-row { display:flex; justify-content:space-between; font-size:.87rem; margin-bottom:.45rem; }
  .det-row .lbl { color:#6B7280; }
  .det-row .val { font-weight:600; text-align:right; max-width:60%; }

  /* ── Tabla items ──────────────────────────────────── */
  .items-table th { font-size:.74rem; font-weight:700; color:#6B7280; text-transform:uppercase;
                    letter-spacing:.5px; background:#F8F9FA; padding:.6rem .85rem; }
  .items-table td { font-size:.86rem; vertical-align:middle; padding:.7rem .85rem; border-color:#F0F2F5; }

  /* ── Vacío ────────────────────────────────────────── */
  .empty-state { text-align:center; padding:3.5rem 1rem; }
  .empty-icon  { width:100px; height:100px; border-radius:50%; background:#F0F2F5;
                 display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; }
</style>

<!-- Breadcrumb -->
<div class="page-bar">
  <div class="container py-2">
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Inicio</a></li>
      <?php if ($pedidoId && isset($pedido)): ?>
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/mis-pedidos.php">Mis pedidos</a></li>
      <li class="breadcrumb-item active"><?= limpiar($pedido['codigo']) ?></li>
      <?php else: ?>
      <li class="breadcrumb-item active">Mis pedidos</li>
      <?php endif; ?>
    </ol></nav>
  </div>
</div>

<div class="container py-4">

<?php if ($pedidoId && isset($pedido)):
// ════════════════════════════════════════════════════════
// VISTA DETALLE
// ════════════════════════════════════════════════════════

  $estadoActual = $pedido['estado'];
  $pasos = [
    ['key'=>'pendiente',      'label'=>'Pedido<br>recibido',    'icon'=>'bi-bag-check'],
    ['key'=>'confirmado',     'label'=>'Confirmado',             'icon'=>'bi-check-circle'],
    ['key'=>'en_preparacion', 'label'=>'En<br>preparación',     'icon'=>'bi-box-seam'],
    ['key'=>'en_camino',      'label'=>'En camino',             'icon'=>'bi-truck'],
    ['key'=>'entregado',      'label'=>'Entregado',              'icon'=>'bi-house-check'],
  ];
  $ordenEstado = ['pendiente'=>0,'confirmado'=>1,'en_preparacion'=>2,'en_camino'=>3,'entregado'=>4];
  $nivelActual = $estadoActual === 'cancelado' ? -1 : ($ordenEstado[$estadoActual] ?? 0);
?>

<!-- Cabecera detalle -->
<div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <h1 style="font-size:1.5rem;font-weight:800;color:var(--verde-dark,#145730);margin-bottom:.3rem">
      <i class="bi bi-bag-check me-2"></i>Pedido <?= limpiar($pedido['codigo']) ?>
    </h1>
    <div class="text-muted" style="font-size:.82rem">
      Realizado el <?= date('d \d\e F \d\e Y', strtotime($pedido['creado_en'])) ?>
      · <?= badgePedido($estadoActual) ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/views/mis-pedidos.php"
     class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
    <i class="bi bi-arrow-left"></i> Volver
  </a>
</div>

<?php if ($estadoActual !== 'cancelado'): ?>
<!-- Timeline -->
<div class="det-card mb-4">
  <div class="det-card-title"><i class="bi bi-diagram-3 me-2"></i>Estado del pedido</div>
  <div class="timeline">
    <?php foreach ($pasos as $i => $paso):
      $nivel = $ordenEstado[$paso['key']] ?? 0;
      if ($nivelActual > $nivel)      $cls = 'done';
      elseif ($nivelActual === $nivel) $cls = 'active';
      else                             $cls = '';
    ?>
    <div class="tl-step <?= $cls ?>">
      <div class="tl-dot"><i class="bi <?= $paso['icon'] ?>"></i></div>
      <div class="tl-lbl"><?= $paso['label'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="border-radius:10px">
  <i class="bi bi-x-octagon-fill fs-5"></i>
  <div><strong>Pedido cancelado.</strong> Si tienes dudas, contáctanos.</div>
</div>
<?php endif; ?>

<div class="row g-3">
  <!-- Columna izquierda: productos + totales -->
  <div class="col-12 col-lg-8">

    <!-- Productos -->
    <div class="det-card mb-3">
      <div class="det-card-title"><i class="bi bi-box-seam me-2"></i>Productos</div>
      <div class="table-responsive">
        <table class="table items-table mb-0">
          <thead><tr>
            <th>Producto</th>
            <th class="text-center">Cant.</th>
            <th class="text-end">Precio unit.</th>
            <th class="text-end">Subtotal</th>
          </tr></thead>
          <tbody>
            <?php foreach ($items as $item):
              $iconProd = Producto::ICONOS_CAT[$item['categoria'] ?? ''] ?? 'bi-image';
            ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div style="width:40px;height:40px;border-radius:8px;background:#F0F2F5;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?php if ($item['imagen'] && file_exists(UPLOADS_PATH . '/' . $item['imagen'])): ?>
                      <img src="<?= UPLOADS_URL . '/' . limpiar($item['imagen']) ?>"
                           style="width:40px;height:40px;object-fit:cover;border-radius:8px" alt="">
                    <?php else: ?>
                      <i class="bi <?= $iconProd ?>" style="color:#9CA3AF"></i>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div style="font-weight:600;font-size:.86rem"><?= limpiar($item['prod_nombre']) ?></div>
                    <?php if ($item['codigo']): ?>
                    <code style="font-size:.72rem;color:#9CA3AF"><?= limpiar($item['codigo']) ?></code>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="text-center"><?= number_format((float)$item['cantidad'], 2) ?> <?= limpiar($item['unidad']) ?></td>
              <td class="text-end"><?= precio((float)$item['precio_unit']) ?></td>
              <td class="text-end fw-bold"><?= precio((float)$item['subtotal']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Resumen totales -->
    <div class="det-card">
      <div class="det-card-title"><i class="bi bi-receipt me-2"></i>Resumen</div>
      <div class="det-row"><span class="lbl">Subtotal productos</span><span class="val"><?= precio((float)$pedido['subtotal'] - (float)$pedido['costo_delivery'] + (float)$pedido['descuento_puntos']) ?></span></div>
      <?php if ((float)$pedido['costo_delivery'] > 0): ?>
      <div class="det-row"><span class="lbl">Costo delivery</span><span class="val"><?= precio((float)$pedido['costo_delivery']) ?></span></div>
      <?php endif; ?>
      <?php if ((float)$pedido['descuento_puntos'] > 0): ?>
      <div class="det-row text-success"><span class="lbl"><i class="bi bi-star-fill me-1"></i>Descuento puntos (<?= (int)$pedido['puntos_usados'] ?> pts)</span><span class="val">−<?= precio((float)$pedido['descuento_puntos']) ?></span></div>
      <?php endif; ?>
      <div class="det-row border-top pt-2 mt-1" style="font-size:1rem">
        <span style="font-weight:700">Total pagado</span>
        <span style="font-weight:800;color:var(--verde-dark,#145730);font-size:1.15rem"><?= precio((float)$pedido['total']) ?></span>
      </div>
      <?php if ((int)$pedido['puntos_ganados'] > 0): ?>
      <div class="mt-2 p-2 rounded d-flex align-items-center gap-2"
           style="background:#fefce8;border:1px solid #fde68a;font-size:.82rem">
        <i class="bi bi-star-fill text-warning"></i>
        <span>Ganaste <strong><?= (int)$pedido['puntos_ganados'] ?> puntos</strong> con este pedido</span>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Columna derecha: info entrega y pago -->
  <div class="col-12 col-lg-4">

    <!-- Entrega -->
    <div class="det-card mb-3">
      <div class="det-card-title"><i class="bi bi-truck me-2"></i>Entrega</div>
      <div class="det-row">
        <span class="lbl">Tipo</span>
        <span class="val"><?= $pedido['tipo_entrega'] === 'delivery' ? 'Delivery a domicilio' : 'Retiro en tienda' ?></span>
      </div>
      <?php if ($pedido['tipo_entrega'] === 'delivery'): ?>
        <?php if ($pedido['zona_nombre']): ?>
        <div class="det-row"><span class="lbl">Zona</span><span class="val"><?= limpiar($pedido['zona_nombre']) ?></span></div>
        <?php endif; ?>
        <?php if ($pedido['direccion_entrega']): ?>
        <div class="det-row"><span class="lbl">Dirección</span><span class="val"><?= limpiar($pedido['direccion_entrega']) ?></span></div>
        <?php endif; ?>
        <?php if ($pedido['referencia']): ?>
        <div class="det-row"><span class="lbl">Referencia</span><span class="val"><?= limpiar($pedido['referencia']) ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($pedido['fecha_entrega']): ?>
      <div class="det-row"><span class="lbl">Fecha estimada</span><span class="val"><?= date('d/m/Y', strtotime($pedido['fecha_entrega'])) ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Pago -->
    <div class="det-card">
      <div class="det-card-title"><i class="bi bi-credit-card me-2"></i>Pago</div>
      <?php if ($pedido['metodo_nombre']): ?>
      <div class="det-row"><span class="lbl">Método</span><span class="val"><?= limpiar($pedido['metodo_nombre']) ?></span></div>
      <?php endif; ?>
      <?php if ($pedido['comprobante']): ?>
      <div class="mt-2">
        <div class="lbl mb-1" style="font-size:.78rem;color:#6B7280">Comprobante</div>
        <a href="<?= UPLOADS_URL ?>/comprobantes/<?= limpiar($pedido['comprobante']) ?>"
           target="_blank" class="d-flex align-items-center gap-2 text-decoration-none"
           style="font-size:.82rem;color:var(--verde,#1A6B3A)">
          <i class="bi bi-file-image"></i> Ver comprobante
        </a>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div><!-- /row detalle -->

<?php else:
// ════════════════════════════════════════════════════════
// VISTA LISTA
// ════════════════════════════════════════════════════════
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <h1 style="font-size:1.5rem;font-weight:800;color:var(--verde-dark,#145730)">
    <i class="bi bi-bag-check me-2"></i>Mis pedidos
  </h1>
  <a href="<?= APP_URL ?>/views/catalogo.php" class="btn btn-sm"
     style="background:var(--verde,#1A6B3A);color:white;border-radius:8px;font-weight:600">
    <i class="bi bi-grid me-1"></i>Seguir comprando
  </a>
</div>

<!-- Filtro por estado -->
<div class="d-flex flex-wrap gap-2 mb-4">
  <a href="?" class="estado-pill <?= !$estadoFiltro ? 'active' : '' ?>">Todos</a>
  <a href="?estado=pendiente"      class="estado-pill <?= $estadoFiltro==='pendiente'?'active':'' ?>">Pendiente</a>
  <a href="?estado=confirmado"     class="estado-pill <?= $estadoFiltro==='confirmado'?'active':'' ?>">Confirmado</a>
  <a href="?estado=en_preparacion" class="estado-pill <?= $estadoFiltro==='en_preparacion'?'active':'' ?>">En preparación</a>
  <a href="?estado=en_camino"      class="estado-pill <?= $estadoFiltro==='en_camino'?'active':'' ?>">En camino</a>
  <a href="?estado=entregado"      class="estado-pill <?= $estadoFiltro==='entregado'?'active':'' ?>">Entregados</a>
  <a href="?estado=cancelado"      class="estado-pill <?= $estadoFiltro==='cancelado'?'active':'' ?>">Cancelados</a>
</div>

<?php if (empty($pedidos)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-bag-x" style="font-size:3rem;color:#D1D5DB"></i></div>
  <h4 style="font-weight:700;color:#374151;margin-bottom:.5rem">
    <?= $estadoFiltro ? 'No hay pedidos ' . str_replace('_', ' ', $estadoFiltro) . 's' : 'Aún no tienes pedidos' ?>
  </h4>
  <p class="text-muted mb-4">¡Explora nuestro catálogo y realiza tu primera compra!</p>
  <a href="<?= APP_URL ?>/views/catalogo.php"
     style="background:var(--verde,#1A6B3A);color:white;padding:.75rem 2rem;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block">
    <i class="bi bi-grid me-2"></i>Ver catálogo
  </a>
</div>

<?php else: ?>
<div class="d-flex flex-column gap-3">
  <?php foreach ($pedidos as $p):
    $iconoTipo = $p['tipo_entrega'] === 'delivery' ? 'bi-truck' : 'bi-shop';
  ?>
  <a href="?id=<?= (int)$p['id'] ?>" class="ped-card">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-3">
        <div style="width:44px;height:44px;border-radius:10px;background:#F0F2F5;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="bi <?= $iconoTipo ?>" style="font-size:1.3rem;color:#6B7280"></i>
        </div>
        <div>
          <span class="ped-code"><?= limpiar($p['codigo']) ?></span>
          <div class="ped-meta mt-1">
            <?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?>
            <?php if ($p['metodo_nombre']): ?>· <?= limpiar($p['metodo_nombre']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="text-end">
        <?= badgePedido($p['estado']) ?>
        <div class="ped-total mt-1"><?= precio((float)$p['total']) ?></div>
      </div>
    </div>
    <?php if ((int)$p['puntos_ganados'] > 0): ?>
    <div class="mt-2" style="font-size:.76rem;color:#92400e;background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:.2rem .6rem;display:inline-flex;align-items:center;gap:.3rem">
      <i class="bi bi-star-fill text-warning"></i> +<?= (int)$p['puntos_ganados'] ?> puntos ganados
    </div>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Paginación -->
<?php if ($totalPags > 1):
  $baseUrl = '?' . ($estadoFiltro ? 'estado=' . $estadoFiltro . '&' : '');
?>
<nav class="mt-4">
  <ul class="pagination justify-content-center">
    <li class="page-item <?= $pagina<=1?'disabled':'' ?>">
      <a class="page-link" href="<?= $baseUrl ?>pagina=<?= $pagina-1 ?>">‹</a>
    </li>
    <?php for ($i=1;$i<=$totalPags;$i++): ?>
    <li class="page-item <?= $i===$pagina?'active':'' ?>">
      <a class="page-link" href="<?= $baseUrl ?>pagina=<?= $i ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
    <li class="page-item <?= $pagina>=$totalPags?'disabled':'' ?>">
      <a class="page-link" href="<?= $baseUrl ?>pagina=<?= $pagina+1 ?>">›</a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<?php endif; // fin if empty pedidos ?>
<?php endif; // fin vista lista / detalle ?>

</div><!-- /container -->

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
