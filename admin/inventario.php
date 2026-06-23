<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$tituloAdmin = 'Inventario';
$paginaAdmin = 'inventario.php';

$db = Database::getConnection();

// ── POST: ajustar stock / cambiar stock mínimo ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarToken();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'ajustar_stock') {
        $pid      = (int)($_POST['producto_id'] ?? 0);
        $tipoMov  = in_array($_POST['tipo_mov'] ?? '', ['entrada','salida','ajuste'])
                    ? $_POST['tipo_mov'] : 'ajuste';
        $cantidad = max(0, (int)($_POST['cantidad'] ?? 0));
        $notas    = trim($_POST['notas'] ?? '');

        if ($pid > 0 && $cantidad >= 0) {
            try {
                $db->beginTransaction();
                $pm = new Producto();

                if ($tipoMov === 'ajuste') {
                    $stmtCurr = $db->prepare("SELECT stock FROM productos WHERE id=:id");
                    $stmtCurr->execute([':id' => $pid]);
                    $curr  = (int)$stmtCurr->fetchColumn();
                    $delta = $cantidad - $curr;
                    if ($delta !== 0) {
                        $pm->actualizarStock($pid, $delta, 'ajuste',
                            $_SESSION['usuario_id'] ?? null, $notas ?: 'Ajuste manual admin');
                    }
                } elseif ($tipoMov === 'entrada') {
                    $pm->actualizarStock($pid, +$cantidad, 'entrada',
                        $_SESSION['usuario_id'] ?? null, $notas ?: 'Entrada manual');
                } else {
                    $pm->actualizarStock($pid, -$cantidad, 'salida',
                        $_SESSION['usuario_id'] ?? null, $notas ?: 'Salida manual');
                }
                $db->commit();
                guardarFlash('exito', 'Stock actualizado correctamente.');
            } catch (Throwable $e) {
                $db->rollBack();
                guardarFlash('error', 'Error al actualizar stock: ' . $e->getMessage());
            }
        } else {
            guardarFlash('error', 'Datos inválidos para el ajuste.');
        }

        $vistaOrigen = $_POST['vista_origen'] ?? 'stock';
        header('Location: ' . APP_URL . '/admin/inventario.php?vista=' . $vistaOrigen);
        exit;
    }

    if ($accion === 'stock_minimo') {
        $pid = (int)($_POST['producto_id'] ?? 0);
        $sm  = max(0, (int)($_POST['stock_minimo'] ?? 0));
        if ($pid > 0) {
            $db->prepare("UPDATE productos SET stock_minimo=:sm WHERE id=:id")
               ->execute([':sm' => $sm, ':id' => $pid]);
            guardarFlash('exito', 'Stock mínimo actualizado.');
        }
        header('Location: ' . APP_URL . '/admin/inventario.php');
        exit;
    }
}

// ── Stats globales ───────────────────────────────────────────────
$totalActivos  = (int)$db->query("SELECT COUNT(*) FROM productos WHERE activo=1")->fetchColumn();
$totalBajo     = (int)$db->query("SELECT COUNT(*) FROM productos WHERE activo=1 AND stock>0 AND stock<=stock_minimo")->fetchColumn();
$totalSinStock = (int)$db->query("SELECT COUNT(*) FROM productos WHERE activo=1 AND stock=0")->fetchColumn();
$movHoy        = (int)$db->query("SELECT COUNT(*) FROM movimientos_inventario WHERE DATE(creado_en)=CURDATE()")->fetchColumn();

// ── Vista activa ─────────────────────────────────────────────────
$vista     = in_array($_GET['vista'] ?? '', ['stock','movimientos']) ? $_GET['vista'] : 'stock';
$POR_PAGINA = 20;

// ── Vista: Stock actual ──────────────────────────────────────────
$productos    = [];
$categorias   = [];
$totalProds   = 0;
$totalPaginas = 1;

if ($vista === 'stock') {
    $q           = trim($_GET['q'] ?? '');
    $estadoStock = $_GET['estado_stock'] ?? '';
    $catId       = (int)($_GET['categoria_id'] ?? 0);
    $pagina      = max(1, (int)($_GET['pagina'] ?? 1));

    $wheres = ['p.activo=1'];
    $params = [];

    if ($q) {
        $wheres[] = '(p.nombre LIKE :q OR p.codigo LIKE :q2)';
        $params[':q']  = "%$q%";
        $params[':q2'] = "%$q%";
    }
    if ($catId) {
        $wheres[] = 'p.categoria_id=:cat';
        $params[':cat'] = $catId;
    }
    switch ($estadoStock) {
        case 'bajo':      $wheres[] = 'p.stock > 0 AND p.stock <= p.stock_minimo'; break;
        case 'sin_stock': $wheres[] = 'p.stock = 0';                               break;
        case 'normal':    $wheres[] = 'p.stock > p.stock_minimo';                  break;
    }
    $where = implode(' AND ', $wheres);

    $sc = $db->prepare("SELECT COUNT(*) FROM productos p WHERE $where");
    $sc->execute($params);
    $totalProds   = (int)$sc->fetchColumn();
    $totalPaginas = max(1, (int)ceil($totalProds / $POR_PAGINA));
    $offset       = ($pagina - 1) * $POR_PAGINA;

    $sp = $db->prepare(
        "SELECT p.*, c.nombre AS categoria
         FROM productos p JOIN categorias c ON c.id=p.categoria_id
         WHERE $where
         ORDER BY p.stock ASC, p.nombre ASC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) $sp->bindValue($k, $v);
    $sp->bindValue(':lim', $POR_PAGINA, PDO::PARAM_INT);
    $sp->bindValue(':off', $offset,     PDO::PARAM_INT);
    $sp->execute();
    $productos  = $sp->fetchAll();
    $categorias = $db->query("SELECT id, nombre FROM categorias WHERE activo=1 ORDER BY nombre")->fetchAll();
}

// ── Vista: Movimientos ───────────────────────────────────────────
$movimientos  = [];

if ($vista === 'movimientos') {
    $q      = trim($_GET['q'] ?? '');
    $tipo   = in_array($_GET['tipo'] ?? '', ['entrada','salida','ajuste']) ? $_GET['tipo'] : '';
    $desde  = $_GET['desde'] ?? '';
    $hasta  = $_GET['hasta'] ?? '';
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));

    $wheres = ['1=1'];
    $params = [];

    if ($q) {
        $wheres[] = '(p.nombre LIKE :q OR p.codigo LIKE :q2)';
        $params[':q']  = "%$q%";
        $params[':q2'] = "%$q%";
    }
    if ($tipo) {
        $wheres[] = 'mi.tipo=:tipo';
        $params[':tipo'] = $tipo;
    }
    if ($desde) {
        $wheres[] = 'DATE(mi.creado_en)>=:desde';
        $params[':desde'] = $desde;
    }
    if ($hasta) {
        $wheres[] = 'DATE(mi.creado_en)<=:hasta';
        $params[':hasta'] = $hasta;
    }
    $where = implode(' AND ', $wheres);

    $sc = $db->prepare(
        "SELECT COUNT(*) FROM movimientos_inventario mi
         JOIN productos p ON p.id=mi.producto_id
         WHERE $where"
    );
    $sc->execute($params);
    $totalMovs    = (int)$sc->fetchColumn();
    $totalPaginas = max(1, (int)ceil($totalMovs / $POR_PAGINA));
    $offset       = ($pagina - 1) * $POR_PAGINA;

    $sm = $db->prepare(
        "SELECT mi.*, p.nombre AS prod_nombre, p.codigo AS prod_codigo,
                u.nombre AS user_nombre, u.apellido AS user_apellido
         FROM movimientos_inventario mi
         JOIN productos p ON p.id=mi.producto_id
         LEFT JOIN usuarios u ON u.id=mi.usuario_id
         WHERE $where
         ORDER BY mi.creado_en DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) $sm->bindValue($k, $v);
    $sm->bindValue(':lim', $POR_PAGINA, PDO::PARAM_INT);
    $sm->bindValue(':off', $offset,     PDO::PARAM_INT);
    $sm->execute();
    $movimientos = $sm->fetchAll();
}

// Todos los productos para el modal de ajuste
$todosProds = $db->query(
    "SELECT id, codigo, nombre, stock, unidad FROM productos WHERE activo=1 ORDER BY nombre"
)->fetchAll();

require_once __DIR__ . '/includes/admin_header.php';
?>

<style>
  .inv-tab-btn {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.55rem 1.2rem; border-radius:8px; font-size:.85rem; font-weight:600;
    border:1.5px solid #dee2e6; background:white; color:#6B7280; cursor:pointer;
    transition:all .18s;
  }
  .inv-tab-btn.active {
    background:var(--verde); border-color:var(--verde); color:white;
  }
  .inv-tab-btn:not(.active):hover {
    background:#F0F2F5; color:var(--verde-dark);
  }

  /* Tabla inventario */
  .inv-table th { font-size:.74rem; font-weight:700; color:#6B7280; text-transform:uppercase;
                  letter-spacing:.5px; background:#F8F9FA; padding:.65rem .85rem; border-bottom:1px solid #dee2e6; }
  .inv-table td { font-size:.86rem; vertical-align:middle; padding:.6rem .85rem; border-color:#F0F2F5; }
  .inv-table tr:hover td { background:#FAFBFC; }

  /* Stock badges */
  .badge-stock-ok   { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; border-radius:20px; padding:.25rem .7rem; font-size:.74rem; font-weight:700; }
  .badge-stock-low  { background:#fff7ed; color:#92400e; border:1px solid #fed7aa; border-radius:20px; padding:.25rem .7rem; font-size:.74rem; font-weight:700; }
  .badge-stock-out  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:20px; padding:.25rem .7rem; font-size:.74rem; font-weight:700; }

  /* Tipo movimiento */
  .badge-entrada { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:20px; padding:.2rem .65rem; font-size:.74rem; font-weight:700; }
  .badge-salida  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:20px; padding:.2rem .65rem; font-size:.74rem; font-weight:700; }
  .badge-ajuste  { background:#ede9fe; color:#5b21b6; border:1px solid #c4b5fd; border-radius:20px; padding:.2rem .65rem; font-size:.74rem; font-weight:700; }

  .btn-ajustar {
    font-size:.75rem; padding:.3rem .7rem; border-radius:6px;
    background:#F0F2F5; border:1px solid #dee2e6; color:#374151; font-weight:600;
    transition:all .15s; cursor:pointer;
    font-family:'Poppins',sans-serif;
  }
  .btn-ajustar:hover { background:var(--verde); border-color:var(--verde); color:white; }

  .stock-num { font-size:1rem; font-weight:700; }
  .stock-min-txt { font-size:.72rem; color:#9CA3AF; }

  /* Mini barra stock */
  .stock-bar-wrap { width:80px; height:6px; background:#F0F2F5; border-radius:3px; overflow:hidden; display:inline-block; vertical-align:middle; margin-left:.4rem; }
  .stock-bar-fill { height:100%; border-radius:3px; }

  /* Modal ajuste */
  .tipo-mov-btn {
    flex:1; padding:.55rem; border-radius:8px; border:1.5px solid #dee2e6;
    background:white; font-size:.82rem; font-weight:600; color:#6B7280;
    cursor:pointer; text-align:center; transition:all .15s;
    font-family:'Poppins',sans-serif;
  }
  .tipo-mov-btn.active-entrada { background:#dcfce7; border-color:#22c55e; color:#166534; }
  .tipo-mov-btn.active-salida  { background:#fee2e2; border-color:#ef4444; color:#991b1b; }
  .tipo-mov-btn.active-ajuste  { background:#ede9fe; border-color:#8b5cf6; color:#5b21b6; }

  .stock-info-box {
    background:#F8F9FA; border:1px solid #dee2e6; border-radius:8px; padding:.75rem 1rem;
    font-size:.84rem;
  }

  .filter-bar { background:white; border-radius:10px; border:1px solid #dee2e6; padding:1rem 1.25rem; margin-bottom:1rem; }

  @media (max-width:768px) {
    .inv-table td:nth-child(3), .inv-table th:nth-child(3),
    .inv-table td:nth-child(5), .inv-table th:nth-child(5) { display:none; }
  }
</style>

<!-- ── Stats cards ────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:var(--verde-dark)"><?= $totalActivos ?></div>
          <div class="stat-lbl">Productos activos</div>
        </div>
        <div class="stat-icon" style="background:rgba(26,107,58,.1)">
          <i class="bi bi-box-seam" style="color:var(--verde)"></i>
        </div>
      </div>
      <div class="stat-trend text-muted"><i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i>Total en catálogo</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#f59e0b"><?= $totalBajo ?></div>
          <div class="stat-lbl">Stock bajo</div>
        </div>
        <div class="stat-icon" style="background:rgba(245,158,11,.1)">
          <i class="bi bi-exclamation-triangle" style="color:#f59e0b"></i>
        </div>
      </div>
      <div class="stat-trend <?= $totalBajo ? 'text-warning' : 'text-success' ?>">
        <?= $totalBajo ? '<i class="bi bi-arrow-up me-1"></i>Requiere reposición' : '<i class="bi bi-check-circle me-1"></i>Sin alertas' ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#ef4444"><?= $totalSinStock ?></div>
          <div class="stat-lbl">Sin stock</div>
        </div>
        <div class="stat-icon" style="background:rgba(239,68,68,.1)">
          <i class="bi bi-x-circle" style="color:#ef4444"></i>
        </div>
      </div>
      <div class="stat-trend <?= $totalSinStock ? 'text-danger' : 'text-success' ?>">
        <?= $totalSinStock ? '<i class="bi bi-exclamation-octagon me-1"></i>Agotados' : '<i class="bi bi-check-circle me-1"></i>Sin agotados' ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#6366f1"><?= $movHoy ?></div>
          <div class="stat-lbl">Movimientos hoy</div>
        </div>
        <div class="stat-icon" style="background:rgba(99,102,241,.1)">
          <i class="bi bi-arrow-left-right" style="color:#6366f1"></i>
        </div>
      </div>
      <div class="stat-trend text-muted"><i class="bi bi-calendar me-1"></i><?= date('d/m/Y') ?></div>
    </div>
  </div>
</div>

<!-- ── Tabs + acción ─────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div class="d-flex gap-2">
    <a href="?vista=stock" class="inv-tab-btn <?= $vista==='stock' ? 'active' : '' ?>">
      <i class="bi bi-boxes"></i> Stock actual
    </a>
    <a href="?vista=movimientos" class="inv-tab-btn <?= $vista==='movimientos' ? 'active' : '' ?>">
      <i class="bi bi-arrow-left-right"></i> Movimientos
    </a>
  </div>
  <button class="btn-verde btn btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjuste">
    <i class="bi bi-plus-circle me-1"></i> Ajuste de stock
  </button>
</div>

<?php if ($vista === 'stock'): ?>
<!-- ══════════════════════════════════════════════════════════
     VISTA: STOCK ACTUAL
════════════════════════════════════════════════════════════ -->

<!-- Filtros -->
<form method="get" class="filter-bar">
  <input type="hidden" name="vista" value="stock">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-sm-4">
      <label class="form-label mb-1">Buscar producto</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Nombre o código…"
               value="<?= limpiar($_GET['q'] ?? '') ?>">
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <label class="form-label mb-1">Estado stock</label>
      <select name="estado_stock" class="form-select form-select-sm">
        <option value="">Todos</option>
        <option value="normal"    <?= ($_GET['estado_stock']??'')==='normal'    ? 'selected':'' ?>>Normal</option>
        <option value="bajo"      <?= ($_GET['estado_stock']??'')==='bajo'      ? 'selected':'' ?>>Stock bajo</option>
        <option value="sin_stock" <?= ($_GET['estado_stock']??'')==='sin_stock' ? 'selected':'' ?>>Sin stock</option>
      </select>
    </div>
    <div class="col-6 col-sm-3">
      <label class="form-label mb-1">Categoría</label>
      <select name="categoria_id" class="form-select form-select-sm">
        <option value="">Todas</option>
        <?php foreach ($categorias as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= (int)($_GET['categoria_id']??0)===(int)$cat['id'] ? 'selected':'' ?>>
          <?= limpiar($cat['nombre']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-sm-2 d-flex gap-2">
      <button type="submit" class="btn-verde btn btn-sm flex-grow-1">Filtrar</button>
      <?php if (!empty($_GET['q']) || !empty($_GET['estado_stock']) || !empty($_GET['categoria_id'])): ?>
      <a href="?vista=stock" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Tabla productos -->
<div class="admin-card p-0" style="overflow:hidden">
  <?php if (empty($productos)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-box-seam" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
    <div style="font-size:.9rem">No se encontraron productos</div>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table inv-table mb-0">
      <thead>
        <tr>
          <th style="width:110px">Código</th>
          <th>Producto</th>
          <th style="width:120px">Categoría</th>
          <th style="width:180px">Stock actual</th>
          <th style="width:110px">Stock mín.</th>
          <th style="width:90px">Estado</th>
          <th style="width:90px" class="text-center">Ajustar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($productos as $p):
          $stock    = (int)$p['stock'];
          $minimo   = (int)$p['stock_minimo'];
          $pct      = $minimo > 0 ? min(100, round($stock / ($minimo * 2) * 100)) : 100;
          $colorBar = $stock === 0 ? '#ef4444' : ($stock <= $minimo ? '#f59e0b' : '#22c55e');
          if      ($stock === 0)   { $badgeClass = 'badge-stock-out'; $badgeLabel = 'Sin stock'; }
          elseif  ($stock <= $minimo) { $badgeClass = 'badge-stock-low'; $badgeLabel = 'Stock bajo'; }
          else                    { $badgeClass = 'badge-stock-ok';  $badgeLabel = 'Normal'; }
        ?>
        <tr>
          <td>
            <?php if ($p['codigo']): ?>
            <code style="font-size:.78rem;background:#F0F2F5;padding:.15rem .35rem;border-radius:4px"><?= limpiar($p['codigo']) ?></code>
            <?php else: ?>
            <span class="text-muted" style="font-size:.78rem">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;font-size:.86rem;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= limpiar($p['nombre']) ?>
            </div>
            <div style="font-size:.73rem;color:#9CA3AF"><?= limpiar($p['unidad']) ?> / unidad</div>
          </td>
          <td style="font-size:.82rem;color:#6B7280"><?= limpiar($p['categoria']) ?></td>
          <td>
            <span class="stock-num" style="color:<?= $colorBar ?>"><?= $stock ?></span>
            <span style="font-size:.74rem;color:#9CA3AF"> <?= limpiar($p['unidad']) ?></span>
            <div class="stock-bar-wrap">
              <div class="stock-bar-fill" style="width:<?= $pct ?>%;background:<?= $colorBar ?>"></div>
            </div>
          </td>
          <td>
            <!-- Editar stock mínimo inline -->
            <form method="post" class="d-inline" id="smForm<?= $p['id'] ?>">
              <?= campoCSRF() ?>
              <input type="hidden" name="accion" value="stock_minimo">
              <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
              <div class="input-group input-group-sm" style="width:90px">
                <input type="number" name="stock_minimo" min="0" max="9999"
                       value="<?= $minimo ?>" class="form-control"
                       style="font-size:.78rem;padding:.25rem .4rem;border-radius:6px 0 0 6px"
                       onchange="document.getElementById('smForm<?= $p['id'] ?>').submit()">
              </div>
            </form>
          </td>
          <td><span class="<?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
          <td class="text-center">
            <button type="button" class="btn-ajustar"
                    onclick="abrirAjuste(<?= $p['id'] ?>, '<?= limpiar(addslashes($p['nombre'])) ?>', <?= $stock ?>, '<?= limpiar($p['unidad']) ?>')">
              <i class="bi bi-pencil-square me-1"></i>Ajustar
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Pie: total + paginación -->
<?php if ($totalProds > 0): ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
  <div style="font-size:.8rem;color:#6B7280">
    <?= $totalProds ?> producto<?= $totalProds!==1?'s':'' ?>
    <?php $ini = ($pagina-1)*$POR_PAGINA+1; $fin = min($pagina*$POR_PAGINA, $totalProds); ?>
    — mostrando <?= $ini ?>–<?= $fin ?>
  </div>
  <?php if ($totalPaginas > 1): ?>
  <nav>
    <ul class="pagination pagination-sm mb-0">
      <?php
        $base = '?vista=stock&' . http_build_query(array_filter([
          'q'            => $_GET['q'] ?? '',
          'estado_stock' => $_GET['estado_stock'] ?? '',
          'categoria_id' => $_GET['categoria_id'] ?? '',
        ]));
      ?>
      <li class="page-item <?= $pagina<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= $base ?>&pagina=<?= $pagina-1 ?>">‹</a>
      </li>
      <?php for ($i=1; $i<=$totalPaginas; $i++): ?>
      <li class="page-item <?= $i===$pagina?'active':'' ?>">
        <a class="page-link" href="<?= $base ?>&pagina=<?= $i ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>
      <li class="page-item <?= $pagina>=$totalPaginas?'disabled':'' ?>">
        <a class="page-link" href="<?= $base ?>&pagina=<?= $pagina+1 ?>">›</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($vista === 'movimientos'): ?>
<!-- ══════════════════════════════════════════════════════════
     VISTA: MOVIMIENTOS
════════════════════════════════════════════════════════════ -->

<!-- Filtros -->
<form method="get" class="filter-bar">
  <input type="hidden" name="vista" value="movimientos">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-sm-4">
      <label class="form-label mb-1">Buscar producto</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Nombre o código…"
               value="<?= limpiar($_GET['q'] ?? '') ?>">
      </div>
    </div>
    <div class="col-6 col-sm-2">
      <label class="form-label mb-1">Tipo</label>
      <select name="tipo" class="form-select form-select-sm">
        <option value="">Todos</option>
        <option value="entrada" <?= ($_GET['tipo']??'')==='entrada'?'selected':'' ?>>Entrada</option>
        <option value="salida"  <?= ($_GET['tipo']??'')==='salida' ?'selected':'' ?>>Salida</option>
        <option value="ajuste"  <?= ($_GET['tipo']??'')==='ajuste' ?'selected':'' ?>>Ajuste</option>
      </select>
    </div>
    <div class="col-6 col-sm-2">
      <label class="form-label mb-1">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm" value="<?= limpiar($_GET['desde'] ?? '') ?>">
    </div>
    <div class="col-6 col-sm-2">
      <label class="form-label mb-1">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm" value="<?= limpiar($_GET['hasta'] ?? '') ?>">
    </div>
    <div class="col-6 col-sm-2 d-flex gap-2">
      <button type="submit" class="btn-verde btn btn-sm flex-grow-1">Filtrar</button>
      <?php if (!empty($_GET['q']) || !empty($_GET['tipo']) || !empty($_GET['desde']) || !empty($_GET['hasta'])): ?>
      <a href="?vista=movimientos" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Tabla movimientos -->
<div class="admin-card p-0" style="overflow:hidden">
  <?php if (empty($movimientos)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-left-right" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
    <div style="font-size:.9rem">No se encontraron movimientos</div>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table inv-table mb-0">
      <thead>
        <tr>
          <th style="width:140px">Fecha</th>
          <th>Producto</th>
          <th style="width:90px">Tipo</th>
          <th style="width:80px" class="text-center">Cantidad</th>
          <th style="width:160px" class="text-center">Stock antes → después</th>
          <th>Referencia</th>
          <th style="width:130px">Usuario</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($movimientos as $m):
          $badgeMov = match($m['tipo']) {
            'entrada' => 'badge-entrada',
            'salida'  => 'badge-salida',
            default   => 'badge-ajuste',
          };
          $cantMov = match($m['tipo']) {
            'entrada' => '+' . (int)$m['cantidad'],
            'salida'  => '−' . (int)$m['cantidad'],
            default   => (int)$m['stock_despues'] - (int)$m['stock_antes'] >= 0
                          ? '+' . ((int)$m['stock_despues'] - (int)$m['stock_antes'])
                          : (string)((int)$m['stock_despues'] - (int)$m['stock_antes']),
          };
        ?>
        <tr>
          <td style="font-size:.78rem">
            <div><?= date('d/m/Y', strtotime($m['creado_en'])) ?></div>
            <div class="text-muted"><?= date('H:i', strtotime($m['creado_en'])) ?></div>
          </td>
          <td>
            <div style="font-weight:600;font-size:.85rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= limpiar($m['prod_nombre']) ?>
            </div>
            <?php if ($m['prod_codigo']): ?>
            <code style="font-size:.72rem;color:#9CA3AF"><?= limpiar($m['prod_codigo']) ?></code>
            <?php endif; ?>
          </td>
          <td><span class="<?= $badgeMov ?>"><?= $m['tipo'] ?></span></td>
          <td class="text-center fw-700" style="font-size:.92rem;
            color:<?= $m['tipo']==='entrada' ? '#16a34a' : ($m['tipo']==='salida' ? '#dc2626' : '#7c3aed') ?>">
            <?= $cantMov ?>
          </td>
          <td class="text-center" style="font-size:.82rem">
            <span style="color:#6B7280"><?= (int)$m['stock_antes'] ?></span>
            <i class="bi bi-arrow-right mx-1" style="font-size:.7rem;color:#9CA3AF"></i>
            <span style="font-weight:700;color:<?= (int)$m['stock_despues']===0?'#ef4444':((int)$m['stock_despues']<=(int)$m['stock_antes']&&$m['tipo']!='entrada'?'#f59e0b':'#16a34a') ?>"><?= (int)$m['stock_despues'] ?></span>
          </td>
          <td style="font-size:.8rem;color:#6B7280;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= $m['referencia'] ? limpiar($m['referencia']) : '<span class="text-muted">—</span>' ?>
          </td>
          <td style="font-size:.8rem">
            <?php if ($m['user_nombre']): ?>
            <div><?= limpiar($m['user_nombre'] . ' ' . $m['user_apellido']) ?></div>
            <?php else: ?>
            <span style="color:#9CA3AF">Sistema</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Paginación movimientos -->
<?php if (isset($totalMovs) && $totalMovs > 0): ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
  <div style="font-size:.8rem;color:#6B7280">
    <?= $totalMovs ?> movimiento<?= $totalMovs!==1?'s':'' ?>
    <?php $ini2 = ($pagina-1)*$POR_PAGINA+1; $fin2 = min($pagina*$POR_PAGINA, $totalMovs); ?>
    — mostrando <?= $ini2 ?>–<?= $fin2 ?>
  </div>
  <?php if ($totalPaginas > 1): ?>
  <nav>
    <ul class="pagination pagination-sm mb-0">
      <?php
        $base2 = '?vista=movimientos&' . http_build_query(array_filter([
          'q'     => $_GET['q'] ?? '',
          'tipo'  => $_GET['tipo'] ?? '',
          'desde' => $_GET['desde'] ?? '',
          'hasta' => $_GET['hasta'] ?? '',
        ]));
      ?>
      <li class="page-item <?= $pagina<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= $base2 ?>&pagina=<?= $pagina-1 ?>">‹</a>
      </li>
      <?php for ($i=1; $i<=$totalPaginas; $i++): ?>
      <li class="page-item <?= $i===$pagina?'active':'' ?>">
        <a class="page-link" href="<?= $base2 ?>&pagina=<?= $i ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>
      <li class="page-item <?= $pagina>=$totalPaginas?'disabled':'' ?>">
        <a class="page-link" href="<?= $base2 ?>&pagina=<?= $pagina+1 ?>">›</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // fin vistas ?>


<!-- ══════════════════════════════════════════════════════════
     MODAL: AJUSTE DE STOCK
════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalAjuste" tabindex="-1" aria-labelledby="modalAjusteLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.18)">
      <div class="modal-header" style="border-bottom:1px solid #dee2e6;padding:1.25rem 1.5rem">
        <h5 class="modal-title" id="modalAjusteLabel" style="font-weight:700;color:var(--verde-dark)">
          <i class="bi bi-pencil-square me-2"></i>Ajuste de stock
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="formAjuste">
        <div class="modal-body" style="padding:1.5rem">
          <?= campoCSRF() ?>
          <input type="hidden" name="accion" value="ajustar_stock">
          <input type="hidden" name="vista_origen" id="vistaOrigen" value="<?= limpiar($vista) ?>">

          <!-- Producto -->
          <div class="mb-3">
            <label class="form-label">Producto <span class="text-danger">*</span></label>
            <select name="producto_id" id="ajPid" class="form-select" required
                    onchange="actualizarInfoStock()">
              <option value="">— Selecciona un producto —</option>
              <?php foreach ($todosProds as $tp): ?>
              <option value="<?= $tp['id'] ?>"
                      data-stock="<?= (int)$tp['stock'] ?>"
                      data-unidad="<?= limpiar($tp['unidad']) ?>">
                <?= $tp['codigo'] ? '['.limpiar($tp['codigo']).'] ' : '' ?><?= limpiar(truncar($tp['nombre'], 45)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Info stock actual -->
          <div id="ajStockInfo" class="stock-info-box mb-3" style="display:none">
            <div class="d-flex justify-content-between">
              <span style="color:#6B7280">Stock actual:</span>
              <strong id="ajStockActual" style="color:var(--verde-dark)">—</strong>
            </div>
          </div>

          <!-- Tipo de movimiento -->
          <div class="mb-3">
            <label class="form-label">Tipo de movimiento</label>
            <div class="d-flex gap-2">
              <button type="button" class="tipo-mov-btn active-entrada" id="btnEntrada"
                      onclick="setTipo('entrada')">
                <i class="bi bi-arrow-down-circle me-1"></i>Entrada
              </button>
              <button type="button" class="tipo-mov-btn" id="btnSalida"
                      onclick="setTipo('salida')">
                <i class="bi bi-arrow-up-circle me-1"></i>Salida
              </button>
              <button type="button" class="tipo-mov-btn" id="btnAjuste"
                      onclick="setTipo('ajuste')">
                <i class="bi bi-sliders me-1"></i>Ajuste
              </button>
            </div>
            <input type="hidden" name="tipo_mov" id="tipoMov" value="entrada">
          </div>

          <!-- Cantidad -->
          <div class="mb-3">
            <label class="form-label" id="ajCantLabel">Cantidad a ingresar <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="number" name="cantidad" id="ajCantidad" class="form-control"
                     min="0" placeholder="0" required
                     oninput="calcularNuevoStock()">
              <span class="input-group-text" id="ajUnidad">uds</span>
            </div>
            <div id="ajNuevoStock" class="mt-1" style="font-size:.8rem;color:#6B7280;display:none">
              Stock resultante: <strong id="ajNuevoStockVal" style="color:var(--verde-dark)">—</strong>
            </div>
          </div>

          <!-- Notas / Referencia -->
          <div class="mb-1">
            <label class="form-label">Referencia / Notas <span class="text-muted">(opcional)</span></label>
            <input type="text" name="notas" class="form-control" maxlength="100"
                   placeholder="Ej: Compra proveedor, Devolución, etc.">
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #dee2e6;padding:1rem 1.5rem;gap:.75rem">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-verde btn">
            <i class="bi bi-check-lg me-1"></i>Guardar ajuste
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Modal ajuste ─────────────────────────────────────────────────
let _stockActual = 0;
let _tipo        = 'entrada';

function setTipo(t) {
  _tipo = t;
  document.getElementById('tipoMov').value = t;

  ['entrada','salida','ajuste'].forEach(x => {
    const btn = document.getElementById('btn' + x.charAt(0).toUpperCase() + x.slice(1));
    btn.className = 'tipo-mov-btn' + (x === t ? ' active-' + x : '');
  });

  const lbl = document.getElementById('ajCantLabel');
  if (t === 'ajuste') {
    lbl.innerHTML = 'Nuevo stock (valor absoluto) <span class="text-danger">*</span>';
    document.getElementById('ajCantidad').placeholder = _stockActual;
  } else {
    lbl.innerHTML = (t === 'entrada' ? 'Cantidad a ingresar' : 'Cantidad a retirar') + ' <span class="text-danger">*</span>';
    document.getElementById('ajCantidad').placeholder = '0';
  }
  calcularNuevoStock();
}

function actualizarInfoStock() {
  const sel   = document.getElementById('ajPid');
  const opt   = sel.options[sel.selectedIndex];
  const stock = parseInt(opt.dataset.stock ?? 0);
  const unid  = opt.dataset.unidad ?? 'uds';
  _stockActual = stock;

  const infoBox = document.getElementById('ajStockInfo');
  if (sel.value) {
    infoBox.style.display = '';
    document.getElementById('ajStockActual').textContent = stock + ' ' + unid;
    document.getElementById('ajUnidad').textContent = unid;
  } else {
    infoBox.style.display = 'none';
  }
  // Actualizar placeholder para ajuste
  if (_tipo === 'ajuste') {
    document.getElementById('ajCantidad').placeholder = stock;
  }
  calcularNuevoStock();
}

function calcularNuevoStock() {
  const cant = parseInt(document.getElementById('ajCantidad').value) || 0;
  const box  = document.getElementById('ajNuevoStock');
  const val  = document.getElementById('ajNuevoStockVal');

  if (!document.getElementById('ajPid').value || cant < 0) {
    box.style.display = 'none'; return;
  }

  let nuevo;
  if (_tipo === 'entrada')  nuevo = _stockActual + cant;
  else if (_tipo === 'salida') nuevo = Math.max(0, _stockActual - cant);
  else                       nuevo = cant; // ajuste = valor absoluto

  box.style.display = '';
  val.textContent   = nuevo;
  val.style.color   = nuevo === 0 ? '#ef4444' : (nuevo < _stockActual && _tipo !== 'entrada' ? '#f59e0b' : 'var(--verde-dark)');
}

// Abrir modal pre-relleno desde la tabla de stock
function abrirAjuste(pid, nombre, stock, unidad) {
  const sel = document.getElementById('ajPid');
  sel.value = pid;
  _stockActual = stock;

  const infoBox = document.getElementById('ajStockInfo');
  infoBox.style.display = '';
  document.getElementById('ajStockActual').textContent = stock + ' ' + unidad;
  document.getElementById('ajUnidad').textContent = unidad;

  if (_tipo === 'ajuste') document.getElementById('ajCantidad').placeholder = stock;
  document.getElementById('ajCantidad').value = '';
  document.getElementById('ajNuevoStock').style.display = 'none';

  const modal = new bootstrap.Modal(document.getElementById('modalAjuste'));
  modal.show();
}

// Inicializar tipo botones al cargar
setTipo('entrada');
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
