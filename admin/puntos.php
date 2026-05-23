<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

$tituloAdmin = 'Sistema de Puntos';
$paginaAdmin = 'puntos.php';
$db          = Database::getConnection();

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarToken();
    $accionPost = $_POST['accion'] ?? '';

    if ($accionPost === 'guardar_config') {
        $puntosPorBs  = max(0.01, round((float)($_POST['puntos_por_bs']  ?? 1),    2));
        $valorPuntoBs = max(0.01, round((float)($_POST['valor_punto_bs'] ?? 0.10), 2));
        $maxCanjePct  = min(100,  max(1, (int)($_POST['max_canje_pct']   ?? 30)));
        $db->prepare(
            "UPDATE config_puntos
             SET puntos_por_bs = :ppb, valor_punto_bs = :vpb, max_canje_pct = :mcp
             WHERE activo = 1"
        )->execute([':ppb' => $puntosPorBs, ':vpb' => $valorPuntoBs, ':mcp' => $maxCanjePct]);
        $_SESSION['flash_ok'] = 'Configuración de puntos actualizada correctamente.';

    } elseif ($accionPost === 'guardar_opcion') {
        $idOp      = (int)($_POST['id'] ?? 0);
        $puntos    = max(1, (int)($_POST['puntos'] ?? 0));
        $descuento = max(0.01, round((float)($_POST['descuento'] ?? 0), 2));
        if ($idOp) {
            $db->prepare("UPDATE opciones_canje SET puntos=:p, descuento=:d WHERE id=:id")
               ->execute([':p' => $puntos, ':d' => $descuento, ':id' => $idOp]);
            $_SESSION['flash_ok'] = 'Opción de canje actualizada.';
        } else {
            $db->prepare("INSERT INTO opciones_canje (puntos, descuento) VALUES (:p, :d)
                          ON DUPLICATE KEY UPDATE descuento=:d2")
               ->execute([':p' => $puntos, ':d' => $descuento, ':d2' => $descuento]);
            $_SESSION['flash_ok'] = 'Nueva opción de canje creada.';
        }

    } elseif ($accionPost === 'eliminar_opcion') {
        $idOp = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM opciones_canje WHERE id=:id")->execute([':id' => $idOp]);
        $_SESSION['flash_ok'] = 'Opción eliminada.';

    } elseif ($accionPost === 'toggle_opcion') {
        $idOp = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE opciones_canje SET activo = !activo WHERE id=:id")
           ->execute([':id' => $idOp]);
        $_SESSION['flash_ok'] = 'Estado de la opción actualizado.';
    }

    header('Location: ' . APP_URL . '/admin/puntos.php');
    exit;
}

// ── Filtros ────────────────────────────────────────────────
$tipo    = $_GET['tipo']   ?? '';
$q       = trim($_GET['q'] ?? '');
$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$porPag  = 25;
$offset  = ($pagina - 1) * $porPag;

$where  = '1=1';
$params = [];

if ($tipo && in_array($tipo, ['ganado','canjeado','ajuste','vencimiento'], true)) {
    $where .= " AND mp.tipo = :tipo";
    $params[':tipo'] = $tipo;
}
if ($q) {
    $where .= " AND (u.nombre LIKE :q OR u.apellido LIKE :q2 OR u.email LIKE :q3)";
    $like = "%$q%";
    $params[':q'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
}

// Total
$stmtC = $db->prepare(
    "SELECT COUNT(*) FROM movimientos_puntos mp
     JOIN usuarios u ON u.id = mp.usuario_id
     WHERE $where"
);
$stmtC->execute($params);
$totalRegs = (int)$stmtC->fetchColumn();
$totalPags = (int)ceil($totalRegs / $porPag);

// Movimientos
$stmtM = $db->prepare(
    "SELECT mp.*,
            u.nombre, u.apellido, u.email, u.puntos AS saldo_actual,
            p.codigo AS pedido_codigo
     FROM   movimientos_puntos mp
     JOIN   usuarios u ON u.id = mp.usuario_id
     LEFT JOIN pedidos p ON p.id = mp.pedido_id
     WHERE  $where
     ORDER  BY mp.creado_en DESC
     LIMIT  :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmtM->bindValue($k, $v);
$stmtM->bindValue(':lim', $porPag, PDO::PARAM_INT);
$stmtM->bindValue(':off', $offset, PDO::PARAM_INT);
$stmtM->execute();
$movimientos = $stmtM->fetchAll();

// Stats globales
$statsGlobales = $db->query(
    "SELECT
        SUM(CASE WHEN tipo='ganado'   THEN cantidad ELSE 0 END) AS total_ganados,
        SUM(CASE WHEN tipo='canjeado' THEN ABS(cantidad) ELSE 0 END) AS total_canjeados,
        COUNT(DISTINCT usuario_id) AS usuarios_con_movimientos
     FROM movimientos_puntos"
)->fetch();

$totalPuntosCirculando = (int)$db->query(
    "SELECT COALESCE(SUM(puntos),0) FROM usuarios WHERE activo=1"
)->fetchColumn();

$config        = $db->query("SELECT * FROM config_puntos WHERE activo=1 LIMIT 1")->fetch();
$opcionesCanje = $db->query("SELECT * FROM opciones_canje ORDER BY puntos ASC")->fetchAll();

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="mb-4">
  <h1 class="fw-800 mb-0" style="font-size:1.4rem;color:var(--verde-dark)">
    <i class="bi bi-star-fill text-warning me-2"></i>Sistema de Puntos
  </h1>
  <div style="font-size:.82rem;color:#6B7280">Historial de movimientos y configuración del programa de fidelidad</div>
</div>

<!-- ── STATS ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(201,168,76,.15);color:var(--dorado-dark)">
          <i class="bi bi-star-fill"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.4rem"><?= number_format($totalPuntosCirculando) ?></div>
          <div class="stat-lbl">Pts en circulación</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(26,107,58,.1);color:var(--verde)">
          <i class="bi bi-plus-circle-fill"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.4rem"><?= number_format((int)($statsGlobales['total_ganados'] ?? 0)) ?></div>
          <div class="stat-lbl">Pts otorgados total</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(220,53,69,.1);color:#dc3545">
          <i class="bi bi-gift-fill"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.4rem"><?= number_format((int)($statsGlobales['total_canjeados'] ?? 0)) ?></div>
          <div class="stat-lbl">Pts canjeados total</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(99,102,241,.1);color:#6366F1">
          <i class="bi bi-people-fill"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.4rem"><?= number_format((int)($statsGlobales['usuarios_con_movimientos'] ?? 0)) ?></div>
          <div class="stat-lbl">Clientes activos</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── CONFIG VIGENTE ─────────────────────────────────────── -->
<?php if (isset($_SESSION['flash_ok'])): ?>
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-check-circle-fill me-2"></i><?= limpiar($_SESSION['flash_ok']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash_ok']); endif; ?>

<?php if ($config): ?>
<div class="admin-card mb-4">
  <div class="admin-card-header">
    <span class="admin-card-title"><i class="bi bi-gear me-2"></i>Configuración vigente</span>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            data-bs-toggle="modal" data-bs-target="#modalConfig">
      <i class="bi bi-pencil-fill me-1"></i>Editar configuración
    </button>
  </div>
  <div class="row g-3">
    <div class="col-6 col-md-3">
      <div class="config-item">
        <div class="config-val"><?= number_format((float)$config['puntos_por_bs'], 2) ?></div>
        <div class="config-lbl">Pts por cada Bs. gastado</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="config-item">
        <div class="config-val">Bs. <?= number_format((float)$config['valor_punto_bs'], 2) ?></div>
        <div class="config-lbl">Valor de cada punto</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="config-item">
        <div class="config-val"><?= (int)$config['max_canje_pct'] ?>%</div>
        <div class="config-lbl">Máx. descuento por pedido</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="config-item">
        <div class="config-val">50 pts</div>
        <div class="config-lbl">Bonus de bienvenida</div>
      </div>
    </div>
  </div>
</div>

<!-- Modal editar configuración -->
<div class="modal fade" id="modalConfig" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <?= campoCSRF() ?>
        <input type="hidden" name="accion" value="guardar_config">
        <div class="modal-header">
          <h5 class="modal-title fw-700">
            <i class="bi bi-gear-fill me-2 text-warning"></i>Configurar Sistema de Puntos
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.85rem">
              Puntos ganados por cada Bs. gastado
            </label>
            <div class="input-group">
              <input type="number" name="puntos_por_bs" class="form-control"
                     min="0.01" max="100" step="0.01"
                     value="<?= number_format((float)$config['puntos_por_bs'], 2, '.', '') ?>"
                     required>
              <span class="input-group-text">pts / Bs.</span>
            </div>
            <div class="form-text">Ej: 1.00 = 1 punto por cada boliviano gastado</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.85rem">
              Valor de cada punto al canjear
            </label>
            <div class="input-group">
              <span class="input-group-text">Bs.</span>
              <input type="number" name="valor_punto_bs" class="form-control"
                     min="0.01" max="10" step="0.01"
                     value="<?= number_format((float)$config['valor_punto_bs'], 2, '.', '') ?>"
                     required>
            </div>
            <div class="form-text">Ej: 0.10 = cada punto vale Bs. 0.10 al canjear</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.85rem">
              Descuento máximo por pedido con puntos
            </label>
            <div class="input-group">
              <input type="number" name="max_canje_pct" class="form-control"
                     min="1" max="100" step="1"
                     value="<?= (int)$config['max_canje_pct'] ?>"
                     required>
              <span class="input-group-text">%</span>
            </div>
            <div class="form-text">Ej: 30 = el cliente puede pagar hasta 30% del pedido con puntos</div>
          </div>
          <div class="alert alert-info py-2 mb-0" style="font-size:.8rem">
            <i class="bi bi-info-circle me-1"></i>
            Los cambios aplican a los nuevos pedidos. Los pedidos en curso no se ven afectados.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-verde btn">
            <i class="bi bi-check-lg me-1"></i>Guardar configuración
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── CUADRANTES DE CANJE ────────────────────────────────── -->
<div class="admin-card mb-4">
  <div class="admin-card-header d-flex align-items-center justify-content-between">
    <span class="admin-card-title">
      <i class="bi bi-grid-2x2 me-2"></i>Cuadrantes de canje
    </span>
    <button type="button" class="btn btn-sm btn-verde btn"
            onclick="abrirModalOpcion(0,0,0)">
      <i class="bi bi-plus-lg me-1"></i>Nuevo cuadrante
    </button>
  </div>

  <?php if (empty($opcionesCanje)): ?>
  <p class="text-muted mb-0" style="font-size:.85rem">
    No hay cuadrantes configurados. Agrega el primero.
  </p>
  <?php else: ?>
  <div class="row g-3 mb-3">
    <?php foreach ($opcionesCanje as $op): ?>
    <div class="col-12 col-sm-6 col-md-3">
      <div class="opcion-card <?= !$op['activo'] ? 'inactiva' : '' ?>">

        <?php if (!$op['activo']): ?>
        <span class="opcion-badge-inactiva">Inactiva</span>
        <?php endif; ?>

        <div class="opcion-pts">
          <i class="bi bi-star-fill" style="color:var(--dorado)"></i>
          <?= number_format((int)$op['puntos']) ?> pts
        </div>
        <div class="opcion-arrow"><i class="bi bi-arrow-right"></i></div>
        <div class="opcion-desc">
          Bs. <?= number_format((float)$op['descuento'], 2) ?>
          <span>descuento</span>
        </div>

        <div class="opcion-actions">
          <!-- Editar -->
          <button type="button"
                  class="btn btn-xs btn-outline-secondary"
                  onclick="abrirModalOpcion(<?= $op['id'] ?>, <?= $op['puntos'] ?>, <?= $op['descuento'] ?>)"
                  title="Editar">
            <i class="bi bi-pencil-fill"></i>
          </button>
          <!-- Activar/Desactivar -->
          <form method="POST" class="d-inline">
            <?= campoCSRF() ?>
            <input type="hidden" name="accion" value="toggle_opcion">
            <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
            <button type="submit"
                    class="btn btn-xs <?= $op['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                    title="<?= $op['activo'] ? 'Desactivar' : 'Activar' ?>">
              <i class="bi bi-<?= $op['activo'] ? 'pause-fill' : 'play-fill' ?>"></i>
            </button>
          </form>
          <!-- Eliminar -->
          <form method="POST" class="d-inline"
                onsubmit="return confirm('¿Eliminar este cuadrante?')">
            <?= campoCSRF() ?>
            <input type="hidden" name="accion" value="eliminar_opcion">
            <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
            <button type="submit" class="btn btn-xs btn-outline-danger" title="Eliminar">
              <i class="bi bi-trash-fill"></i>
            </button>
          </form>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="text-muted mb-0" style="font-size:.78rem">
    <i class="bi bi-info-circle me-1"></i>
    Solo las opciones <strong>activas</strong> se muestran al cliente al momento de pagar.
    Ordenadas de menor a mayor por puntos.
  </p>
  <?php endif; ?>
</div>

<!-- Modal agregar / editar cuadrante -->
<div class="modal fade" id="modalOpcion" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
    <div class="modal-content">
      <form method="POST" id="formOpcion">
        <?= campoCSRF() ?>
        <input type="hidden" name="accion" value="guardar_opcion">
        <input type="hidden" name="id" id="opcionId" value="0">

        <div class="modal-header">
          <h5 class="modal-title fw-700" id="opcionModalTitulo">
            <i class="bi bi-plus-circle me-2 text-success"></i>Nuevo cuadrante
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.85rem">
              Puntos necesarios <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-star-fill text-warning"></i>
              </span>
              <input type="number" name="puntos" id="opcionPuntos"
                     class="form-control" min="1" max="999999" step="1"
                     required placeholder="Ej: 150"
                     oninput="actualizarPreview()">
              <span class="input-group-text">pts</span>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.85rem">
              Descuento que otorga <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <span class="input-group-text">Bs.</span>
              <input type="number" name="descuento" id="opcionDescuento"
                     class="form-control" min="0.01" max="99999" step="0.01"
                     required placeholder="Ej: 5.00"
                     oninput="actualizarPreview()">
            </div>
          </div>

          <!-- Preview -->
          <div style="background:#F0FDF4;border:1.5px dashed var(--verde);border-radius:10px;
                      padding:.85rem 1rem;text-align:center;font-size:.85rem">
            <span style="font-size:.72rem;color:#6B7280;display:block;margin-bottom:.4rem">
              Vista previa del cuadrante:
            </span>
            <div class="d-flex align-items-center justify-content-center gap-2 flex-wrap">
              <span id="prevPts" style="background:rgba(26,107,58,.12);color:var(--verde-dark);
                    padding:.25rem .75rem;border-radius:50px;font-weight:700">
                — pts
              </span>
              <i class="bi bi-arrow-right text-muted"></i>
              <span id="prevDesc" style="background:rgba(201,168,76,.15);color:var(--dorado-dark);
                    padding:.25rem .75rem;border-radius:50px;font-weight:800">
                Bs. —
              </span>
            </div>
          </div>

          <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:.78rem">
            <i class="bi bi-info-circle me-1"></i>
            Si el cliente no tiene los puntos suficientes, el cuadrante aparece
            bloqueado pero visible.
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary"
                  data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-verde btn">
            <i class="bi bi-check-lg me-1"></i>Guardar cuadrante
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── FILTROS ────────────────────────────────────────────── -->
<div class="admin-card mb-3">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-12 col-md-5">
      <label class="form-label mb-1" style="font-size:.78rem">Buscar usuario</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control"
               placeholder="Nombre, apellido o email…"
               value="<?= limpiar($q) ?>">
      </div>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1" style="font-size:.78rem">Tipo</label>
      <select name="tipo" class="form-select form-select-sm">
        <option value="">Todos</option>
        <option value="ganado"     <?= $tipo==='ganado'     ? 'selected':'' ?>>Ganados</option>
        <option value="canjeado"   <?= $tipo==='canjeado'   ? 'selected':'' ?>>Canjeados</option>
        <option value="ajuste"     <?= $tipo==='ajuste'     ? 'selected':'' ?>>Ajustes</option>
        <option value="vencimiento"<?= $tipo==='vencimiento'? 'selected':'' ?>>Vencimientos</option>
      </select>
    </div>
    <div class="col-6 col-md-4 d-flex gap-2">
      <button type="submit" class="btn-verde btn btn-sm flex-grow-1">
        <i class="bi bi-search me-1"></i>Filtrar
      </button>
      <a href="<?= APP_URL ?>/admin/puntos.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x-lg"></i>
      </a>
    </div>
  </form>
</div>

<!-- ── TABLA ──────────────────────────────────────────────── -->
<div class="admin-table">
  <?php if (empty($movimientos)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-star" style="font-size:3rem;opacity:.2"></i>
    <div class="mt-2" style="font-size:.85rem">No se encontraron movimientos.</div>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr>
        <th>Usuario</th>
        <th>Tipo</th>
        <th class="text-end">Movimiento</th>
        <th class="text-end">Saldo actual</th>
        <th>Pedido</th>
        <th>Descripción</th>
        <th>Fecha</th>
      </tr></thead>
      <tbody>
      <?php foreach ($movimientos as $mv):
        $bTipo = match($mv['tipo']) {
            'ganado'      => ['success',   'plus-circle-fill',   'Ganado'],
            'canjeado'    => ['danger',    'dash-circle-fill',   'Canjeado'],
            'ajuste'      => ['warning',   'pencil-fill',        'Ajuste'],
            'vencimiento' => ['secondary', 'x-circle-fill',      'Vencimiento'],
            default       => ['secondary', 'circle',             $mv['tipo']],
        };
      ?>
      <tr>
        <td>
          <div class="fw-600" style="font-size:.85rem">
            <?= limpiar($mv['nombre'] . ' ' . $mv['apellido']) ?>
          </div>
          <div style="font-size:.72rem;color:#6B7280"><?= limpiar($mv['email']) ?></div>
        </td>
        <td>
          <span class="badge bg-<?= $bTipo[0] ?>" style="border-radius:50px;font-size:.7rem">
            <i class="bi bi-<?= $bTipo[1] ?> me-1"></i><?= $bTipo[2] ?>
          </span>
        </td>
        <td class="text-end fw-700 <?= (int)$mv['cantidad'] >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= ((int)$mv['cantidad'] >= 0 ? '+' : '') . number_format((int)$mv['cantidad']) ?> pts
        </td>
        <td class="text-end" style="font-size:.85rem;color:var(--verde-dark);font-weight:600">
          <?= number_format((int)$mv['saldo_actual']) ?> pts
        </td>
        <td>
          <?php if ($mv['pedido_codigo']): ?>
          <a href="<?= APP_URL ?>/admin/pedidos.php?accion=detalle&id=<?= (int)$mv['pedido_id'] ?>"
             style="font-size:.78rem;color:var(--verde)">
            <code><?= limpiar($mv['pedido_codigo']) ?></code>
          </a>
          <?php else: ?>
          <span class="text-muted" style="font-size:.78rem">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.8rem;color:#4B5563;max-width:200px">
          <?= limpiar(truncar($mv['descripcion'] ?? '', 50)) ?>
        </td>
        <td style="font-size:.78rem;color:#6B7280;white-space:nowrap">
          <?= date('d/m/Y H:i', strtotime($mv['creado_en'])) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <?php if ($totalPags > 1): ?>
  <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top" style="font-size:.82rem">
    <span class="text-muted">Página <?= $pagina ?> de <?= $totalPags ?> — <?= number_format($totalRegs) ?> movimientos</span>
    <div class="d-flex gap-1 flex-wrap">
      <?php
      $urlBase = APP_URL . '/admin/puntos.php?' . http_build_query(array_filter(['q' => $q, 'tipo' => $tipo]));
      $inicio  = max(1, $pagina - 2);
      $fin     = min($totalPags, $pagina + 2);
      if ($inicio > 1): ?>
        <a href="<?= $urlBase ?>&pagina=1" class="btn btn-sm btn-outline-secondary" style="border-radius:6px">1</a>
        <?php if ($inicio > 2): ?><span class="align-self-center px-1 text-muted">…</span><?php endif; ?>
      <?php endif; ?>
      <?php for ($i = $inicio; $i <= $fin; $i++): ?>
      <a href="<?= $urlBase ?>&pagina=<?= $i ?>"
         class="btn btn-sm <?= $i===$pagina ? 'btn-verde btn' : 'btn-outline-secondary' ?>"
         style="border-radius:6px;min-width:32px"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($fin < $totalPags):
        if ($fin < $totalPags - 1): ?><span class="align-self-center px-1 text-muted">…</span><?php endif; ?>
        <a href="<?= $urlBase ?>&pagina=<?= $totalPags ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:6px"><?= $totalPags ?></a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.config-item {
  background: #F8F9FA; border: 1px solid #dee2e6;
  border-radius: 10px; padding: 1rem; text-align: center;
}
.config-val { font-size: 1.4rem; font-weight: 800; color: var(--verde-dark); }
.config-lbl { font-size: .72rem; color: #6B7280; margin-top: .25rem; }

/* ── Cuadrantes de canje ──────────────────────────────────── */
.opcion-card {
  position: relative;
  display: flex; flex-direction: column; align-items: center;
  gap: .5rem;
  padding: 1.1rem 1rem .9rem;
  background: white;
  border: 2px solid var(--gris-borde, #E5E7EB);
  border-radius: 12px;
  text-align: center;
  transition: border-color .2s, box-shadow .2s;
}
.opcion-card:not(.inactiva):hover {
  border-color: var(--dorado, #C9A84C);
  box-shadow: 0 4px 16px rgba(201,168,76,.15);
}
.opcion-card.inactiva {
  opacity: .55;
  background: #F9FAFB;
}
.opcion-pts {
  font-size: .95rem; font-weight: 700; color: var(--verde-dark, #145730);
  display: flex; align-items: center; gap: .35rem;
}
.opcion-arrow { color: #9CA3AF; font-size: .85rem; }
.opcion-desc {
  font-size: 1.15rem; font-weight: 800; color: var(--dorado-dark, #A8882E);
}
.opcion-desc span {
  display: block; font-size: .65rem; font-weight: 500;
  color: #6B7280; margin-top: .1rem;
}
.opcion-badge-inactiva {
  position: absolute; top: 8px; right: 8px;
  background: #6B7280; color: white;
  font-size: .6rem; font-weight: 700;
  padding: .15rem .5rem; border-radius: 50px;
  text-transform: uppercase; letter-spacing: .5px;
}
.opcion-actions {
  display: flex; gap: .35rem; margin-top: .3rem;
}
.btn-xs {
  padding: .2rem .45rem;
  font-size: .72rem;
  border-radius: 6px;
  line-height: 1.4;
}
</style>

<script>
function abrirModalOpcion(id, puntos, descuento) {
  document.getElementById('opcionId').value       = id || 0;
  document.getElementById('opcionPuntos').value   = puntos   || '';
  document.getElementById('opcionDescuento').value= descuento
      ? parseFloat(descuento).toFixed(2) : '';
  document.getElementById('opcionModalTitulo').innerHTML = id
      ? '<i class="bi bi-pencil-fill me-2 text-warning"></i>Editar cuadrante'
      : '<i class="bi bi-plus-circle me-2 text-success"></i>Nuevo cuadrante';
  actualizarPreview();
  bootstrap.Modal.getOrCreateInstance(
      document.getElementById('modalOpcion')).show();
}

function actualizarPreview() {
  const pts  = parseInt(document.getElementById('opcionPuntos').value)   || 0;
  const desc = parseFloat(document.getElementById('opcionDescuento').value) || 0;
  document.getElementById('prevPts').textContent  =
      pts  ? pts.toLocaleString() + ' pts' : '— pts';
  document.getElementById('prevDesc').textContent =
      desc ? 'Bs. ' + desc.toFixed(2) : 'Bs. —';
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
