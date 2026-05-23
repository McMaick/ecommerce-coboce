<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

$tituloAdmin = 'Reportes y Estadísticas';
$paginaAdmin = 'reportes.php';
$db          = Database::getConnection();

// ── Período seleccionado ────────────────────────────────────
$periodo = $_GET['periodo'] ?? 'mes';
$fechaDesde = match($periodo) {
    'semana' => date('Y-m-d', strtotime('-7 days')),
    'mes'    => date('Y-m-01'),
    'año'    => date('Y-01-01'),
    'todo'   => '2000-01-01',
    default  => date('Y-m-01'),
};
$fechaHasta = date('Y-m-d');

// Fechas personalizadas
if ($periodo === 'custom') {
    $fechaDesde = $_GET['desde'] ?? date('Y-m-01');
    $fechaHasta = $_GET['hasta'] ?? date('Y-m-d');
}

// ── KPIs principales ───────────────────────────────────────
$kpis = $db->prepare(
    "SELECT
        COUNT(*)                                                  AS total_pedidos,
        COUNT(CASE WHEN estado='entregado'  THEN 1 END)          AS entregados,
        COUNT(CASE WHEN estado='cancelado'  THEN 1 END)          AS cancelados,
        COUNT(CASE WHEN estado='pendiente'  THEN 1 END)          AS pendientes,
        COALESCE(SUM(CASE WHEN estado!='cancelado' THEN total END),0) AS ingresos_brutos,
        COALESCE(SUM(CASE WHEN estado='entregado'  THEN total END),0) AS ingresos_confirmados,
        COALESCE(AVG(CASE WHEN estado!='cancelado' THEN total END),0) AS ticket_promedio,
        COALESCE(SUM(CASE WHEN estado!='cancelado' THEN puntos_ganados END),0) AS puntos_entregados
     FROM pedidos
     WHERE DATE(creado_en) BETWEEN :desde AND :hasta"
);
$kpis->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$kpis = $kpis->fetch();

// ── Ventas por día (para el gráfico) ──────────────────────
$ventasDia = $db->prepare(
    "SELECT DATE(creado_en) AS dia,
            COUNT(*) AS pedidos,
            COALESCE(SUM(CASE WHEN estado!='cancelado' THEN total END),0) AS total
     FROM   pedidos
     WHERE  DATE(creado_en) BETWEEN :desde AND :hasta
     GROUP  BY DATE(creado_en)
     ORDER  BY dia ASC"
);
$ventasDia->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$ventasDia = $ventasDia->fetchAll();

// ── Top productos ──────────────────────────────────────────
$topProductos = $db->prepare(
    "SELECT p.nombre, p.unidad, SUM(dp.cantidad) AS qty_vendida,
            SUM(dp.subtotal) AS total_ventas, COUNT(DISTINCT dp.pedido_id) AS n_pedidos
     FROM   detalle_pedido dp
     JOIN   pedidos ped ON ped.id = dp.pedido_id
     JOIN   productos p  ON p.id  = dp.producto_id
     WHERE  ped.estado != 'cancelado'
       AND  DATE(ped.creado_en) BETWEEN :desde AND :hasta
     GROUP  BY dp.producto_id
     ORDER  BY total_ventas DESC
     LIMIT  10"
);
$topProductos->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$topProductos = $topProductos->fetchAll();

// ── Ventas por categoría ───────────────────────────────────
$ventasCat = $db->prepare(
    "SELECT c.nombre AS categoria,
            COUNT(DISTINCT dp.pedido_id) AS n_pedidos,
            SUM(dp.subtotal) AS total
     FROM   detalle_pedido dp
     JOIN   pedidos ped ON ped.id  = dp.pedido_id
     JOIN   productos p  ON p.id   = dp.producto_id
     JOIN   categorias c ON c.id   = p.categoria_id
     WHERE  ped.estado != 'cancelado'
       AND  DATE(ped.creado_en) BETWEEN :desde AND :hasta
     GROUP  BY c.id
     ORDER  BY total DESC"
);
$ventasCat->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$ventasCat = $ventasCat->fetchAll();

// ── Métodos de pago ────────────────────────────────────────
$metodosPago = $db->prepare(
    "SELECT mp.nombre, COUNT(p.id) AS cantidad,
            COALESCE(SUM(p.total),0) AS total
     FROM   pedidos p
     LEFT JOIN metodos_pago mp ON mp.id = p.metodo_pago_id
     WHERE  p.estado != 'cancelado'
       AND  DATE(p.creado_en) BETWEEN :desde AND :hasta
     GROUP  BY p.metodo_pago_id
     ORDER  BY total DESC"
);
$metodosPago->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$metodosPago = $metodosPago->fetchAll();

// ── Clientes nuevos ────────────────────────────────────────
$clientesNuevos = (int)$db->prepare(
    "SELECT COUNT(*) FROM usuarios WHERE rol_id=2 AND DATE(creado_en) BETWEEN :desde AND :hasta"
)->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta])
    ? $db->query("SELECT COUNT(*) FROM usuarios WHERE rol_id=2 AND DATE(creado_en) BETWEEN '$fechaDesde' AND '$fechaHasta'")->fetchColumn()
    : 0;

$stmtCN = $db->prepare(
    "SELECT COUNT(*) FROM usuarios WHERE rol_id=2 AND DATE(creado_en) BETWEEN :desde AND :hasta"
);
$stmtCN->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
$clientesNuevos = (int)$stmtCN->fetchColumn();

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- ── HEADER + SELECTOR PERÍODO ─────────────────────────── -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="fw-800 mb-0" style="font-size:1.4rem;color:var(--verde-dark)">
      <i class="bi bi-bar-chart-line me-2"></i>Reportes
    </h1>
    <div style="font-size:.82rem;color:#6B7280">
      <?= date('d/m/Y', strtotime($fechaDesde)) ?> — <?= date('d/m/Y', strtotime($fechaHasta)) ?>
    </div>
  </div>

  <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="form-label mb-1" style="font-size:.75rem">Período</label>
      <select name="periodo" class="form-select form-select-sm" onchange="this.form.submit()"
              style="min-width:140px">
        <option value="semana" <?= $periodo==='semana' ?'selected':'' ?>>Últimos 7 días</option>
        <option value="mes"    <?= $periodo==='mes'    ?'selected':'' ?>>Este mes</option>
        <option value="año"    <?= $periodo==='año'    ?'selected':'' ?>>Este año</option>
        <option value="todo"   <?= $periodo==='todo'   ?'selected':'' ?>>Todo el tiempo</option>
        <option value="custom" <?= $periodo==='custom' ?'selected':'' ?>>Personalizado</option>
      </select>
    </div>
    <?php if ($periodo === 'custom'): ?>
    <div>
      <label class="form-label mb-1" style="font-size:.75rem">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm"
             value="<?= $fechaDesde ?>">
    </div>
    <div>
      <label class="form-label mb-1" style="font-size:.75rem">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm"
             value="<?= $fechaHasta ?>">
    </div>
    <button type="submit" class="btn-verde btn btn-sm align-self-end">Aplicar</button>
    <?php endif; ?>
  </form>
</div>

<!-- ── KPIs ───────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,107,58,.1);color:var(--verde);margin-bottom:.6rem">
        <i class="bi bi-cash-coin"></i>
      </div>
      <div class="stat-num"><?= precio((float)$kpis['ingresos_confirmados']) ?></div>
      <div class="stat-lbl">Ingresos confirmados</div>
      <div class="stat-trend text-muted">
        Brutos: <?= precio((float)$kpis['ingresos_brutos']) ?>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(99,102,241,.1);color:#6366F1;margin-bottom:.6rem">
        <i class="bi bi-bag-check"></i>
      </div>
      <div class="stat-num"><?= number_format((int)$kpis['total_pedidos']) ?></div>
      <div class="stat-lbl">Pedidos totales</div>
      <div class="stat-trend">
        <span class="text-success"><?= (int)$kpis['entregados'] ?> entregados</span> ·
        <span class="text-danger"><?= (int)$kpis['cancelados'] ?> cancelados</span>
      </div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(14,165,233,.1);color:#0284C7;margin-bottom:.6rem">
        <i class="bi bi-receipt"></i>
      </div>
      <div class="stat-num"><?= precio((float)$kpis['ticket_promedio']) ?></div>
      <div class="stat-lbl">Ticket promedio</div>
      <div class="stat-trend text-muted">Por pedido (sin cancelados)</div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(201,168,76,.15);color:var(--dorado-dark);margin-bottom:.6rem">
        <i class="bi bi-person-plus-fill"></i>
      </div>
      <div class="stat-num"><?= number_format($clientesNuevos) ?></div>
      <div class="stat-lbl">Clientes nuevos</div>
      <div class="stat-trend" style="color:var(--dorado-dark)">
        <i class="bi bi-star-fill" style="font-size:.7rem"></i>
        <?= number_format((int)$kpis['puntos_entregados']) ?> pts entregados
      </div>
    </div>
  </div>

</div>

<div class="row g-4">

  <!-- ── GRÁFICO VENTAS POR DÍA ─────────────────────────── -->
  <div class="col-12 col-lg-8">
    <div class="admin-card h-100">
      <div class="admin-card-header">
        <span class="admin-card-title"><i class="bi bi-graph-up me-2"></i>Ventas por día</span>
        <span style="font-size:.78rem;color:#6B7280"><?= count($ventasDia) ?> días con actividad</span>
      </div>

      <?php if (empty($ventasDia)): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-bar-chart" style="font-size:3rem;opacity:.2"></i>
        <div class="mt-2" style="font-size:.85rem">Sin datos en este período.</div>
      </div>
      <?php else: ?>

      <!-- Gráfico de barras en CSS puro -->
      <?php
      $maxTotal = max(array_column($ventasDia, 'total')) ?: 1;
      ?>
      <div class="chart-wrap">
        <?php foreach ($ventasDia as $dia): ?>
        <?php $pct = round((float)$dia['total'] / $maxTotal * 100); ?>
        <div class="chart-bar-col">
          <div class="chart-bar-val"><?= $dia['total'] > 0 ? 'Bs.' . number_format((float)$dia['total'], 0) : '' ?></div>
          <div class="chart-bar-track">
            <div class="chart-bar-fill" style="height:<?= $pct ?>%"
                 title="<?= date('d/m', strtotime($dia['dia'])) ?> — Bs. <?= number_format((float)$dia['total'], 2) ?> (<?= (int)$dia['pedidos'] ?> pedidos)">
            </div>
          </div>
          <div class="chart-bar-lbl"><?= date('d/m', strtotime($dia['dia'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php endif; ?>
    </div>
  </div>

  <!-- ── TOP CATEGORÍAS ────────────────────────────────────── -->
  <div class="col-12 col-lg-4">
    <div class="admin-card h-100">
      <div class="admin-card-header">
        <span class="admin-card-title"><i class="bi bi-pie-chart me-2"></i>Por categoría</span>
      </div>
      <?php if (empty($ventasCat)): ?>
      <div class="text-center py-4 text-muted" style="font-size:.85rem">Sin datos.</div>
      <?php else:
        $maxCat = max(array_column($ventasCat, 'total')) ?: 1;
        $colores = ['var(--verde)','var(--dorado-dark)','#6366F1','#0EA5E9','#f59e0b'];
      ?>
      <div class="d-flex flex-column gap-3">
        <?php foreach ($ventasCat as $i => $cat): ?>
        <?php $pct = round((float)$cat['total'] / $maxCat * 100); ?>
        <div>
          <div class="d-flex justify-content-between mb-1" style="font-size:.82rem">
            <span class="fw-600"><?= limpiar($cat['categoria']) ?></span>
            <span class="text-muted"><?= precio((float)$cat['total']) ?></span>
          </div>
          <div style="height:8px;background:#e9ecef;border-radius:50px;overflow:hidden">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $colores[$i % count($colores)] ?>;border-radius:50px;transition:width .6s ease"></div>
          </div>
          <div style="font-size:.7rem;color:#6B7280;margin-top:.2rem">
            <?= (int)$cat['n_pedidos'] ?> pedido<?= (int)$cat['n_pedidos'] !== 1 ? 's' : '' ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── TOP PRODUCTOS ─────────────────────────────────────── -->
  <div class="col-12 col-lg-7">
    <div class="admin-card">
      <div class="admin-card-header">
        <span class="admin-card-title"><i class="bi bi-trophy me-2"></i>Top productos</span>
        <span style="font-size:.78rem;color:#6B7280">Por ingresos</span>
      </div>
      <?php if (empty($topProductos)): ?>
      <div class="text-center py-4 text-muted" style="font-size:.85rem">Sin datos en este período.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
          <thead><tr>
            <th>#</th>
            <th>Producto</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Ingresos</th>
            <th class="text-end">Pedidos</th>
          </tr></thead>
          <tbody>
          <?php foreach ($topProductos as $i => $prod): ?>
          <tr>
            <td>
              <?php if ($i < 3): ?>
              <span class="trophy" style="color:<?= ['#C9A84C','#9CA3AF','#CD7F32'][$i] ?>">
                <i class="bi bi-trophy-fill"></i>
              </span>
              <?php else: ?>
              <span class="text-muted" style="font-size:.8rem"><?= $i + 1 ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-600"><?= limpiar(truncar($prod['nombre'], 40)) ?></div>
            </td>
            <td class="text-end text-muted">
              <?= number_format((float)$prod['qty_vendida'], 1) ?> <?= limpiar($prod['unidad']) ?>
            </td>
            <td class="text-end fw-700" style="color:var(--verde-dark)">
              <?= precio((float)$prod['total_ventas']) ?>
            </td>
            <td class="text-end text-muted"><?= (int)$prod['n_pedidos'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── MÉTODOS DE PAGO ────────────────────────────────────── -->
  <div class="col-12 col-lg-5">
    <div class="admin-card">
      <div class="admin-card-header">
        <span class="admin-card-title"><i class="bi bi-credit-card me-2"></i>Métodos de pago</span>
      </div>
      <?php if (empty($metodosPago)): ?>
      <div class="text-center py-4 text-muted" style="font-size:.85rem">Sin datos.</div>
      <?php else:
        $totalMP = array_sum(array_column($metodosPago, 'total')) ?: 1;
      ?>
      <div class="d-flex flex-column gap-3">
        <?php foreach ($metodosPago as $mp):
          $pct = round((float)$mp['total'] / $totalMP * 100);
          $ico = match(true) {
              str_contains($mp['nombre'] ?? '', 'Tigo')     => ['bi-qr-code',        '#00A0D2'],
              str_contains($mp['nombre'] ?? '', 'Bisa')     => ['bi-bank',           '#003B8E'],
              str_contains($mp['nombre'] ?? '', 'PIX')      => ['bi-lightning-charge-fill','#32BCAD'],
              str_contains($mp['nombre'] ?? '', 'Efectivo') => ['bi-cash-stack',     '#198754'],
              default                                        => ['bi-credit-card',    '#6B7280'],
          };
        ?>
        <div class="d-flex align-items-center gap-3">
          <div style="width:36px;height:36px;border-radius:8px;background:<?= $ico[1] ?>22;
                      color:<?= $ico[1] ?>;display:flex;align-items:center;justify-content:center;
                      font-size:1rem;flex-shrink:0">
            <i class="bi <?= $ico[0] ?>"></i>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between mb-1" style="font-size:.82rem">
              <span class="fw-600"><?= limpiar($mp['nombre'] ?? 'Sin método') ?></span>
              <span class="text-muted"><?= $pct ?>% · <?= precio((float)$mp['total']) ?></span>
            </div>
            <div style="height:6px;background:#e9ecef;border-radius:50px;overflow:hidden">
              <div style="height:100%;width:<?= $pct ?>%;background:<?= $ico[1] ?>;border-radius:50px"></div>
            </div>
            <div style="font-size:.7rem;color:#6B7280;margin-top:.2rem">
              <?= (int)$mp['cantidad'] ?> pedido<?= (int)$mp['cantidad'] !== 1 ? 's' : '' ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<style>
/* ── GRÁFICO BARRAS ───────────────────────────────────────── */
.chart-wrap {
  display: flex;
  align-items: flex-end;
  gap: 4px;
  height: 220px;
  padding: 0 .5rem .5rem;
  overflow-x: auto;
}
.chart-bar-col {
  display: flex; flex-direction: column; align-items: center;
  flex: 1; min-width: 32px;
}
.chart-bar-val {
  font-size: .55rem; color: var(--verde-dark); font-weight: 700;
  margin-bottom: 2px; white-space: nowrap;
  writing-mode: vertical-rl; transform: rotate(180deg);
  max-height: 60px; overflow: hidden;
}
.chart-bar-track {
  width: 100%; flex: 1;
  display: flex; align-items: flex-end;
  background: #f0f4f0; border-radius: 6px 6px 0 0;
}
.chart-bar-fill {
  width: 100%; min-height: 4px;
  background: linear-gradient(180deg, var(--verde-light), var(--verde-dark));
  border-radius: 6px 6px 0 0;
  transition: height .5s ease;
  cursor: pointer;
}
.chart-bar-fill:hover { filter: brightness(1.15); }
.chart-bar-lbl {
  font-size: .6rem; color: #6B7280;
  margin-top: 3px; white-space: nowrap;
}
</style>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
