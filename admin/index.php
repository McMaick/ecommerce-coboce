<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$tituloAdmin = 'Dashboard';
$paginaAdmin = 'index.php';

require_once __DIR__ . '/includes/admin_header.php';

// ── Stats desde BD ─────────────────────────────────────────
$db = Database::getConnection();

$totalProductos  = (int) $db->query("SELECT COUNT(*) FROM productos WHERE activo=1")->fetchColumn();
$totalPedidos    = (int) $db->query("SELECT COUNT(*) FROM pedidos")->fetchColumn();
$totalUsuarios   = (int) $db->query("SELECT COUNT(*) FROM usuarios WHERE rol_id=2")->fetchColumn();
$pendientes      = (int) $db->query("SELECT COUNT(*) FROM pedidos WHERE estado='pendiente'")->fetchColumn();
$ventasMes       = (float)($db->query(
    "SELECT COALESCE(SUM(total),0) FROM pedidos
     WHERE estado NOT IN ('cancelado') AND MONTH(creado_en)=MONTH(NOW()) AND YEAR(creado_en)=YEAR(NOW())"
)->fetchColumn());

$stockBajoProds  = (new Producto())->stockBajo();
$nStockBajo      = count($stockBajoProds);

// Últimos pedidos
$ultimosPedidos  = $db->query(
    "SELECT p.*, u.nombre, u.apellido
     FROM pedidos p JOIN usuarios u ON u.id=p.usuario_id
     ORDER BY p.creado_en DESC LIMIT 7"
)->fetchAll();

// Últimos usuarios
$ultimosUsuarios = $db->query(
    "SELECT * FROM usuarios WHERE rol_id=2 ORDER BY creado_en DESC LIMIT 5"
)->fetchAll();
?>

<!-- ── STATS CARDS ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:var(--verde-dark)"><?= $totalProductos ?></div>
          <div class="stat-lbl">Productos activos</div>
        </div>
        <div class="stat-icon" style="background:rgba(26,107,58,.1)">
          <i class="bi bi-box-seam" style="color:var(--verde)"></i>
        </div>
      </div>
      <?php if ($nStockBajo): ?>
      <div class="stat-trend text-warning">
        <i class="bi bi-exclamation-triangle me-1"></i><?= $nStockBajo ?> con stock bajo
      </div>
      <?php else: ?>
      <div class="stat-trend text-success">
        <i class="bi bi-check-circle me-1"></i>Stock en orden
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#6366F1"><?= $totalPedidos ?></div>
          <div class="stat-lbl">Pedidos totales</div>
        </div>
        <div class="stat-icon" style="background:rgba(99,102,241,.1)">
          <i class="bi bi-bag-check" style="color:#6366F1"></i>
        </div>
      </div>
      <?php if ($pendientes): ?>
      <div class="stat-trend text-danger">
        <i class="bi bi-clock me-1"></i><?= $pendientes ?> pendiente<?= $pendientes!==1?'s':'' ?>
      </div>
      <?php else: ?>
      <div class="stat-trend text-success"><i class="bi bi-check-circle me-1"></i>Sin pendientes</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#0EA5E9"><?= $totalUsuarios ?></div>
          <div class="stat-lbl">Clientes</div>
        </div>
        <div class="stat-icon" style="background:rgba(14,165,233,.1)">
          <i class="bi bi-people" style="color:#0EA5E9"></i>
        </div>
      </div>
      <div class="stat-trend text-muted">
        <i class="bi bi-person-plus me-1"></i>Registrados en el sistema
      </div>
    </div>
  </div>

  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:var(--dorado-dark)"><?= number_format($ventasMes, 0) ?></div>
          <div class="stat-lbl">Ventas del mes (Bs.)</div>
        </div>
        <div class="stat-icon" style="background:rgba(201,168,76,.15)">
          <i class="bi bi-cash-coin" style="color:var(--dorado-dark)"></i>
        </div>
      </div>
      <div class="stat-trend text-muted">
        <i class="bi bi-calendar me-1"></i><?= date('F Y') ?>
      </div>
    </div>
  </div>

</div>

<!-- ── FILA PRINCIPAL ───────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <!-- Últimos pedidos -->
  <div class="col-12 col-lg-8">
    <div class="admin-card">
      <div class="admin-card-header">
        <h6 class="admin-card-title"><i class="bi bi-bag-check me-2"></i>Últimos pedidos</h6>
        <a href="<?= APP_URL ?>/admin/pedidos.php" class="btn-verde btn btn-sm">Ver todos</a>
      </div>
      <?php if (empty($ultimosPedidos)): ?>
      <div class="text-center py-4 text-muted" style="font-size:.88rem">
        <i class="bi bi-bag-x" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
        No hay pedidos aún
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table admin-table mb-0">
          <thead><tr>
            <th>Código</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Fecha</th>
          </tr></thead>
          <tbody>
            <?php foreach ($ultimosPedidos as $p):
              $colorEstado = match($p['estado']) {
                'entregado'      => 'success',
                'en_camino'      => 'info',
                'en_preparacion' => 'primary',
                'confirmado'     => 'secondary',
                'cancelado'      => 'danger',
                default          => 'warning',
              };
            ?>
            <tr>
              <td><code style="font-size:.8rem"><?= limpiar($p['codigo']) ?></code></td>
              <td><?= limpiar($p['nombre'] . ' ' . $p['apellido']) ?></td>
              <td class="fw-600">Bs. <?= number_format((float)$p['total'], 2) ?></td>
              <td><span class="badge bg-<?= $colorEstado ?>"><?= str_replace('_',' ', $p['estado']) ?></span></td>
              <td class="text-muted" style="font-size:.78rem"><?= tiempoRelativo($p['creado_en']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stock bajo -->
  <div class="col-12 col-lg-4">
    <div class="admin-card">
      <div class="admin-card-header">
        <h6 class="admin-card-title"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Stock bajo</h6>
        <a href="<?= APP_URL ?>/admin/inventario.php" class="btn btn-sm btn-outline-secondary" style="font-size:.78rem">Gestionar</a>
      </div>
      <?php if (empty($stockBajoProds)): ?>
      <div class="text-center py-3 text-success" style="font-size:.88rem">
        <i class="bi bi-check-circle-fill fs-3 d-block mb-2"></i>
        Todo el inventario está en orden
      </div>
      <?php else: ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach (array_slice($stockBajoProds, 0, 6) as $sp): ?>
        <div class="d-flex align-items-center justify-content-between p-2 rounded"
             style="background:#fff8f0;border:1px solid #fde8cc">
          <div style="font-size:.82rem;font-weight:500;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= limpiar(truncar($sp['nombre'], 28)) ?>
          </div>
          <span class="<?= (int)$sp['stock']===0 ? 'stock-out' : 'stock-low' ?> ms-2" style="font-size:.8rem;white-space:nowrap">
            <?= (int)$sp['stock'] === 0 ? 'Sin stock' : (int)$sp['stock'] . ' ' . limpiar($sp['unidad']) ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php if (count($stockBajoProds) > 6): ?>
        <div class="text-center" style="font-size:.78rem;color:var(--texto-suave)">
          +<?= count($stockBajoProds) - 6 ?> productos más
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ── ACCIONES RÁPIDAS ─────────────────────────────────────── -->
<div class="admin-card mb-4">
  <div class="admin-card-header">
    <h6 class="admin-card-title"><i class="bi bi-lightning me-2"></i>Acciones rápidas</h6>
  </div>
  <div class="row g-2">
    <?php
    $acciones = [
      ['url'=>APP_URL.'/admin/productos.php?accion=nuevo', 'icon'=>'bi-plus-circle-fill', 'label'=>'Nuevo producto',   'color'=>'#1A6B3A'],
      ['url'=>APP_URL.'/admin/pedidos.php',                'icon'=>'bi-bag-check-fill',   'label'=>'Ver pedidos',      'color'=>'#6366F1'],
      ['url'=>APP_URL.'/admin/inventario.php',             'icon'=>'bi-graph-up-arrow',   'label'=>'Inventario',       'color'=>'#0EA5E9'],
      ['url'=>APP_URL.'/admin/usuarios.php',               'icon'=>'bi-people-fill',      'label'=>'Gestionar clientes','color'=>'#EC4899'],
      ['url'=>APP_URL.'/admin/delivery.php',               'icon'=>'bi-truck',            'label'=>'Delivery',         'color'=>'#F59E0B'],
      ['url'=>APP_URL.'/admin/reportes.php',               'icon'=>'bi-bar-chart-line',   'label'=>'Reportes',         'color'=>'#10B981'],
    ];
    foreach ($acciones as $a):
    ?>
    <div class="col-6 col-sm-4 col-md-2">
      <a href="<?= $a['url'] ?>" class="d-flex flex-column align-items-center gap-2 p-3 rounded text-center text-decoration-none"
         style="background:#F8F9FA;border:1px solid #dee2e6;transition:all .2s;color:<?= $a['color'] ?>"
         onmouseover="this.style.background='<?= $a['color'] ?>20';this.style.borderColor='<?= $a['color'] ?>'"
         onmouseout="this.style.background='#F8F9FA';this.style.borderColor='#dee2e6'">
        <i class="bi <?= $a['icon'] ?>" style="font-size:1.5rem"></i>
        <span style="font-size:.78rem;font-weight:600;color:#374151"><?= $a['label'] ?></span>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
