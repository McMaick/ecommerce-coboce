<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$tituloAdmin = 'Gestión de Pedidos';
$paginaAdmin = 'pedidos.php';
$db          = Database::getConnection();
$accion      = $_GET['accion'] ?? 'listar';

// ══════════════════════════════════════════════════════════════
// POST — acciones
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido.');
        redirigir(APP_URL . '/admin/pedidos.php');
    }

    $accionPost = $_POST['accion_form'] ?? '';
    $pedidoId   = limpiarInt($_POST['pedido_id'] ?? 0);

    // ── CAMBIAR ESTADO ────────────────────────────────────────
    if ($accionPost === 'cambiar_estado') {
        $estadosValidos = ['pendiente','confirmado','en_preparacion','en_camino','entregado','cancelado'];
        $nuevoEstado    = $_POST['estado'] ?? '';

        if (!$pedidoId || !in_array($nuevoEstado, $estadosValidos, true)) {
            flash('error', 'Datos inválidos.');
            redirigir(APP_URL . '/admin/pedidos.php?accion=detalle&id=' . $pedidoId);
        }

        // Verificar que el pedido existe y no está en estado final
        $stmt = $db->prepare("SELECT estado, puntos_ganados, usuario_id FROM pedidos WHERE id = :id");
        $stmt->execute([':id' => $pedidoId]);
        $pedActual = $stmt->fetch();

        if (!$pedActual) {
            flash('error', 'Pedido no encontrado.');
            redirigir(APP_URL . '/admin/pedidos.php');
        }
        if (in_array($pedActual['estado'], ['entregado', 'cancelado'], true)) {
            flash('error', 'No se puede cambiar el estado de un pedido finalizado.');
            redirigir(APP_URL . '/admin/pedidos.php?accion=detalle&id=' . $pedidoId);
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE pedidos SET estado = :e WHERE id = :id")
               ->execute([':e' => $nuevoEstado, ':id' => $pedidoId]);

            // Sincronizar asignaciones_delivery con el nuevo estado del pedido
            if ($nuevoEstado === 'entregado') {
                $db->prepare(
                    "UPDATE asignaciones_delivery
                     SET estado = 'entregado', entregado_en = NOW()
                     WHERE pedido_id = :pid AND estado IN ('asignado','recogido')"
                )->execute([':pid' => $pedidoId]);
            } elseif ($nuevoEstado === 'en_camino') {
                $db->prepare(
                    "UPDATE asignaciones_delivery
                     SET estado = 'recogido'
                     WHERE pedido_id = :pid AND estado = 'asignado'"
                )->execute([':pid' => $pedidoId]);
            } elseif ($nuevoEstado === 'cancelado') {
                $db->prepare(
                    "UPDATE asignaciones_delivery
                     SET estado = 'cancelado'
                     WHERE pedido_id = :pid AND estado IN ('asignado','recogido')"
                )->execute([':pid' => $pedidoId]);
            }

            // Si se cancela: devolver puntos usados y revertir puntos ganados
            if ($nuevoEstado === 'cancelado') {
                $stmtP = $db->prepare(
                    "SELECT puntos_usados, descuento_puntos, usuario_id FROM pedidos WHERE id = :id"
                );
                $stmtP->execute([':id' => $pedidoId]);
                $pedData = $stmtP->fetch();

                if ($pedData && ($pedData['puntos_usados'] > 0 || (int)$pedActual['puntos_ganados'] > 0)) {
                    $stmtU  = $db->prepare("SELECT puntos FROM usuarios WHERE id = :id");
                    $stmtU->execute([':id' => $pedData['usuario_id']]);
                    $ptosAct = (int)$stmtU->fetchColumn();

                    $devolver = (int)$pedData['puntos_usados'];
                    $retirar  = (int)$pedActual['puntos_ganados'];
                    $nuevoSaldo = max(0, $ptosAct + $devolver - $retirar);

                    $db->prepare("UPDATE usuarios SET puntos = :p WHERE id = :id")
                       ->execute([':p' => $nuevoSaldo, ':id' => $pedData['usuario_id']]);

                    if ($devolver > 0 || $retirar > 0) {
                        $db->prepare(
                            "INSERT INTO movimientos_puntos
                                (usuario_id, pedido_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                             VALUES (:uid, :pid, 'ajuste', :qty, :sa, :sd, :desc)"
                        )->execute([
                            ':uid'  => $pedData['usuario_id'],
                            ':pid'  => $pedidoId,
                            ':qty'  => $devolver - $retirar,
                            ':sa'   => $ptosAct,
                            ':sd'   => $nuevoSaldo,
                            ':desc' => 'Reversión por cancelación de pedido',
                        ]);
                    }

                    // Restaurar stock de los ítems
                    $items = $db->prepare(
                        "SELECT producto_id, cantidad FROM detalle_pedido WHERE pedido_id = :id"
                    );
                    $items->execute([':id' => $pedidoId]);
                    $modeloProd = new Producto();
                    foreach ($items->fetchAll() as $it) {
                        $modeloProd->actualizarStock(
                            (int)$it['producto_id'],
                            (int)ceil($it['cantidad']),
                            'ajuste',
                            (int)$_SESSION['usuario_id'],
                            'Cancelación pedido'
                        );
                    }
                }
            }

            $db->commit();
            $etiquetas = [
                'pendiente'      => 'Pendiente',
                'confirmado'     => 'Confirmado',
                'en_preparacion' => 'En preparación',
                'en_camino'      => 'En camino',
                'entregado'      => 'Entregado',
                'cancelado'      => 'Cancelado',
            ];
            flash('exito', 'Estado cambiado a <strong>' . ($etiquetas[$nuevoEstado] ?? $nuevoEstado) . '</strong>.');
        } catch (\Throwable $e) {
            $db->rollBack();
            flash('error', 'Error al cambiar el estado. Intenta nuevamente.');
        }
        redirigir(APP_URL . '/admin/pedidos.php?accion=detalle&id=' . $pedidoId);
    }

    // ── ASIGNAR REPARTIDOR ────────────────────────────────────
    if ($accionPost === 'asignar_repartidor') {
        $repId = limpiarInt($_POST['repartidor_id'] ?? 0);
        $notas = trim($_POST['notas_asignacion'] ?? '');

        if (!$pedidoId || !$repId) {
            flash('error', 'Selecciona un repartidor.');
            redirigir(APP_URL . '/admin/pedidos.php?accion=detalle&id=' . $pedidoId);
        }

        // Verificar que el repartidor existe y obtener sus datos
        $stmtR = $db->prepare(
            "SELECT id, nombre, apellido, telefono
             FROM usuarios WHERE id = :id AND rol_id = 3 AND activo = 1"
        );
        $stmtR->execute([':id' => $repId]);
        $repData = $stmtR->fetch();
        if (!$repData) {
            flash('error', 'Repartidor no válido.');
            redirigir(APP_URL . '/admin/pedidos.php?accion=detalle&id=' . $pedidoId);
        }

        // INSERT o UPDATE asignacion
        $existe = $db->prepare(
            "SELECT id FROM asignaciones_delivery WHERE pedido_id = :pid"
        );
        $existe->execute([':pid' => $pedidoId]);

        if ($existe->fetchColumn()) {
            $db->prepare(
                "UPDATE asignaciones_delivery
                 SET repartidor_id = :rid, notas = :n, estado = 'asignado'
                 WHERE pedido_id = :pid"
            )->execute([':rid' => $repId, ':n' => $notas ?: null, ':pid' => $pedidoId]);
        } else {
            $db->prepare(
                "INSERT INTO asignaciones_delivery (pedido_id, repartidor_id, notas)
                 VALUES (:pid, :rid, :n)"
            )->execute([':pid' => $pedidoId, ':rid' => $repId, ':n' => $notas ?: null]);
        }

        // Avanzar estado a en_camino si estaba en_preparacion
        $stEst = $db->prepare("SELECT estado FROM pedidos WHERE id = :id");
        $stEst->execute([':id' => $pedidoId]);
        if ($stEst->fetchColumn() === 'en_preparacion') {
            $db->prepare("UPDATE pedidos SET estado = 'en_camino' WHERE id = :id")
               ->execute([':id' => $pedidoId]);
        }

        // ── Preparar mensaje WhatsApp para el repartidor ──────
        if ($repData['telefono']) {
            $stmtPW = $db->prepare(
                "SELECT p.codigo, p.total, p.direccion_entrega, p.referencia, p.notas,
                        u.nombre AS cli_nombre, u.apellido AS cli_apellido, u.telefono AS cli_tel,
                        z.nombre AS zona_nombre
                 FROM pedidos p
                 JOIN usuarios u ON u.id = p.usuario_id
                 LEFT JOIN zonas_delivery z ON z.id = p.zona_id
                 WHERE p.id = :id LIMIT 1"
            );
            $stmtPW->execute([':id' => $pedidoId]);
            $pedWa = $stmtPW->fetch();

            $stmtIW = $db->prepare(
                "SELECT dp.cantidad, dp.precio_unit, pr.nombre AS prod_nombre, pr.unidad
                 FROM detalle_pedido dp
                 JOIN productos pr ON pr.id = dp.producto_id
                 WHERE dp.pedido_id = :pid"
            );
            $stmtIW->execute([':pid' => $pedidoId]);
            $itemsWa = $stmtIW->fetchAll();

            $msg  = "🚚 *Pedido asignado — Cerámica COBOCE*\n\n";
            $msg .= "Hola {$repData['nombre']}, tienes un nuevo pedido para entregar:\n\n";
            $msg .= "📦 *Código:* {$pedWa['codigo']}\n";
            $msg .= "👤 *Cliente:* {$pedWa['cli_nombre']} {$pedWa['cli_apellido']}\n";
            if ($pedWa['cli_tel'])      $msg .= "📞 *Tel. cliente:* {$pedWa['cli_tel']}\n";
            if ($pedWa['zona_nombre'])  $msg .= "📍 *Zona:* {$pedWa['zona_nombre']}\n";
            if ($pedWa['direccion_entrega']) $msg .= "🏠 *Dirección:* {$pedWa['direccion_entrega']}\n";
            if ($pedWa['referencia'])   $msg .= "📌 *Referencia:* {$pedWa['referencia']}\n";
            $msg .= "\n*Productos:*\n";
            foreach ($itemsWa as $it) {
                $msg .= "• {$it['cantidad']} {$it['unidad']} — {$it['prod_nombre']} (Bs. "
                      . number_format((float)$it['precio_unit'], 2) . " c/u)\n";
            }
            $msg .= "\n💰 *Total: Bs. " . number_format((float)$pedWa['total'], 2) . "*";
            if ($notas)              $msg .= "\n\n📝 *Notas de asignación:* {$notas}";
            if ($pedWa['notas'])    $msg .= "\n💬 *Notas del cliente:* {$pedWa['notas']}";
            $msg .= "\n\n_Distribuidora Cerámica COBOCE · Cobija, Bolivia_";

            $tel = preg_replace('/\D/', '', $repData['telefono']);
            if (!str_starts_with($tel, '591')) $tel = '591' . ltrim($tel, '0');

            $_SESSION['wa_notif'] = [
                'url'    => 'https://wa.me/' . $tel . '?text=' . rawurlencode($msg),
                'nombre' => $repData['nombre'] . ' ' . $repData['apellido'],
                'tel'    => $repData['telefono'],
                'msg'    => $msg,
            ];
        }

        flash('exito', 'Repartidor asignado correctamente.');
        redirigir(APP_URL . '/admin/pedidos.php?accion=detalle&id=' . $pedidoId);
    }
}

// ══════════════════════════════════════════════════════════════
// GET — DETALLE
// ══════════════════════════════════════════════════════════════
if ($accion === 'detalle') {
    $pedidoId = limpiarInt($_GET['id'] ?? 0);
    if (!$pedidoId) {
        flash('error', 'Pedido no encontrado.');
        redirigir(APP_URL . '/admin/pedidos.php');
    }

    $stmtPed = $db->prepare(
        "SELECT p.*,
                u.nombre     AS cli_nombre,   u.apellido  AS cli_apellido,
                u.email      AS cli_email,    u.telefono  AS cli_tel,
                mp.nombre    AS metodo_pago_nom,
                z.nombre     AS zona_nombre
         FROM   pedidos p
         JOIN   usuarios u  ON u.id  = p.usuario_id
         LEFT JOIN metodos_pago   mp ON mp.id = p.metodo_pago_id
         LEFT JOIN zonas_delivery z  ON z.id  = p.zona_id
         WHERE  p.id = :id LIMIT 1"
    );
    $stmtPed->execute([':id' => $pedidoId]);
    $ped = $stmtPed->fetch();

    if (!$ped) {
        flash('error', 'Pedido no encontrado.');
        redirigir(APP_URL . '/admin/pedidos.php');
    }

    // Ítems del pedido
    $items = $db->prepare(
        "SELECT dp.*, pr.nombre AS prod_nombre, pr.imagen, pr.unidad,
                c.nombre AS categoria
         FROM   detalle_pedido dp
         JOIN   productos  pr ON pr.id = dp.producto_id
         JOIN   categorias c  ON c.id  = pr.categoria_id
         WHERE  dp.pedido_id = :pid"
    );
    $items->execute([':pid' => $pedidoId]);
    $itemsPed = $items->fetchAll();

    // Asignación delivery
    $stmtAsig = $db->prepare(
        "SELECT ad.*, u.nombre AS rep_nombre, u.apellido AS rep_apellido,
                u.telefono AS rep_tel
         FROM   asignaciones_delivery ad
         JOIN   usuarios u ON u.id = ad.repartidor_id
         WHERE  ad.pedido_id = :pid LIMIT 1"
    );
    $stmtAsig->execute([':pid' => $pedidoId]);
    $asignacion = $stmtAsig->fetch() ?: null;

    // Lista de repartidores disponibles
    $repartidores = $db->query(
        "SELECT id, nombre, apellido, telefono
         FROM usuarios WHERE rol_id = 3 AND activo = 1
         ORDER BY nombre ASC"
    )->fetchAll();
}

// ══════════════════════════════════════════════════════════════
// GET — LISTA (con filtros y paginación)
// ══════════════════════════════════════════════════════════════
if ($accion === 'listar') {

    // Filtros
    $fEstado  = $_GET['estado']  ?? '';
    $fDesde   = $_GET['desde']   ?? '';
    $fHasta   = $_GET['hasta']   ?? '';
    $fBuscar  = trim($_GET['q']  ?? '');
    $pagina   = max(1, limpiarInt($_GET['pagina'] ?? 1));
    $porPag   = 20;

    $where  = '1=1';
    $params = [];

    if ($fEstado) {
        $where .= ' AND p.estado = :est';
        $params[':est'] = $fEstado;
    }
    if ($fDesde) {
        $where .= ' AND DATE(p.creado_en) >= :desde';
        $params[':desde'] = $fDesde;
    }
    if ($fHasta) {
        $where .= ' AND DATE(p.creado_en) <= :hasta';
        $params[':hasta'] = $fHasta;
    }
    if ($fBuscar) {
        $where .= ' AND (p.codigo LIKE :q OR u.nombre LIKE :q2 OR u.apellido LIKE :q3)';
        $like = '%' . $fBuscar . '%';
        $params[':q'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
    }

    $offset = ($pagina - 1) * $porPag;
    $stmtLst = $db->prepare(
        "SELECT p.id, p.codigo, p.estado, p.tipo_entrega, p.total,
                p.creado_en, p.metodo_pago_id,
                u.nombre AS cli_nombre, u.apellido AS cli_apellido,
                mp.nombre AS metodo_pago_nom,
                z.nombre  AS zona_nombre
         FROM   pedidos p
         JOIN   usuarios u  ON u.id  = p.usuario_id
         LEFT JOIN metodos_pago   mp ON mp.id = p.metodo_pago_id
         LEFT JOIN zonas_delivery z  ON z.id  = p.zona_id
         WHERE  $where
         ORDER  BY p.creado_en DESC
         LIMIT  :lim OFFSET :off"
    );
    foreach ($params as $k => $v) $stmtLst->bindValue($k, $v);
    $stmtLst->bindValue(':lim', $porPag, PDO::PARAM_INT);
    $stmtLst->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmtLst->execute();
    $pedidos = $stmtLst->fetchAll();

    // Contar total
    $stmtCnt = $db->prepare(
        "SELECT COUNT(*) FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id WHERE $where"
    );
    foreach ($params as $k => $v) $stmtCnt->bindValue($k, $v);
    $stmtCnt->execute();
    $totalPeds = (int)$stmtCnt->fetchColumn();
    $totalPags = (int)ceil($totalPeds / $porPag);

    // Stats por estado
    $statsRaw = $db->query(
        "SELECT estado, COUNT(*) AS cnt FROM pedidos GROUP BY estado"
    )->fetchAll();
    $statsCnt = [];
    foreach ($statsRaw as $s) $statsCnt[$s['estado']] = (int)$s['cnt'];
}

// ── Helpers ───────────────────────────────────────────────────
function badgeEstado(string $e): string {
    return match($e) {
        'pendiente'      => '<span class="badge bg-warning text-dark">Pendiente</span>',
        'confirmado'     => '<span class="badge bg-secondary">Confirmado</span>',
        'en_preparacion' => '<span class="badge bg-primary">En preparación</span>',
        'en_camino'      => '<span class="badge bg-info text-dark">En camino</span>',
        'entregado'      => '<span class="badge bg-success">Entregado</span>',
        'cancelado'      => '<span class="badge bg-danger">Cancelado</span>',
        default          => '<span class="badge bg-light text-dark">' . htmlspecialchars($e) . '</span>',
    };
}

function iconoEstado(string $e): string {
    return match($e) {
        'pendiente'      => 'bi-clock text-warning',
        'confirmado'     => 'bi-check-circle text-secondary',
        'en_preparacion' => 'bi-box-seam text-primary',
        'en_camino'      => 'bi-truck text-info',
        'entregado'      => 'bi-bag-check-fill text-success',
        'cancelado'      => 'bi-x-circle-fill text-danger',
        default          => 'bi-circle text-muted',
    };
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<?php
// ── Modal WhatsApp tras asignación ────────────────────────
if (isset($_SESSION['wa_notif'])):
    $waN = $_SESSION['wa_notif'];
    unset($_SESSION['wa_notif']);
?>
<div class="modal fade" id="modalWA" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;border:none">
      <div class="modal-header" style="background:#25D366;color:white;border:none">
        <h5 class="modal-title fw-700">
          <i class="bi bi-whatsapp me-2"></i>Notificar al repartidor
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-4 text-center">
        <div class="mb-3">
          <div style="width:56px;height:56px;background:#E7F9EE;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem">
            <i class="bi bi-person-badge-fill" style="font-size:1.6rem;color:#25D366"></i>
          </div>
          <div class="fw-700" style="font-size:1rem"><?= limpiar($waN['nombre']) ?></div>
          <div class="text-muted" style="font-size:.83rem"><?= limpiar($waN['tel']) ?></div>
        </div>
        <p style="font-size:.85rem;color:#4B5563" class="mb-4">
          Se preparó el mensaje con todos los detalles del pedido.<br>
          Haz clic para enviárselo por WhatsApp.
        </p>
        <!-- Preview del mensaje -->
        <div style="background:#F0FFF4;border:1px solid #BBF7D0;border-radius:10px;padding:.9rem 1rem;text-align:left;font-size:.77rem;color:#374151;white-space:pre-wrap;max-height:180px;overflow-y:auto;font-family:monospace" class="mb-4"><?= limpiar($waN['msg']) ?></div>
        <a href="<?= htmlspecialchars($waN['url'], ENT_QUOTES) ?>" target="_blank"
           class="btn btn-lg w-100 fw-700"
           style="background:#25D366;color:white;border-radius:10px;font-size:1rem;border:none"
           onclick="bootstrap.Modal.getInstance(document.getElementById('modalWA')).hide()">
          <i class="bi bi-whatsapp me-2"></i>Abrir WhatsApp y enviar
        </a>
      </div>
      <div class="modal-footer" style="border:none;padding-top:0">
        <button type="button" class="btn btn-sm btn-outline-secondary w-100" data-bs-dismiss="modal">
          Cerrar sin enviar
        </button>
      </div>
    </div>
  </div>
</div>
<script>
window.addEventListener('load', function() {
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalWA')).show();
});
</script>
<?php endif; ?>

<?php if ($accion === 'listar'): ?>
<!-- ══════════════════════════════════════════════════════════
     VISTA: LISTA DE PEDIDOS
     ══════════════════════════════════════════════════════════ -->

<!-- Stats rápidas -->
<div class="row g-3 mb-4">
  <?php
  $statsUI = [
      ['pendiente',      'Pendientes',      'bi-clock',           '#F59E0B', '#FFF9EC'],
      ['confirmado',     'Confirmados',     'bi-check-circle',    '#6B7280', '#F4F6F8'],
      ['en_preparacion', 'En preparación',  'bi-box-seam',        '#6366F1', '#F0F0FF'],
      ['en_camino',      'En camino',       'bi-truck',           '#0EA5E9', '#F0F8FF'],
      ['entregado',      'Entregados',      'bi-bag-check-fill',  '#10B981', '#F0FFF8'],
      ['cancelado',      'Cancelados',      'bi-x-circle-fill',   '#EF4444', '#FFF5F5'],
  ];
  foreach ($statsUI as [$key, $label, $icon, $color, $bg]):
    $n = $statsCnt[$key] ?? 0;
  ?>
  <div class="col-6 col-sm-4 col-lg-2">
    <a href="?estado=<?= $key ?>" class="d-block text-decoration-none">
      <div class="stat-mini" style="--sm-color:<?= $color ?>;--sm-bg:<?= $bg ?> <?= $fEstado === $key ? ';outline:2px solid '.$color : '' ?>">
        <i class="bi <?= $icon ?>"></i>
        <div class="sm-num"><?= $n ?></div>
        <div class="sm-lbl"><?= $label ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filtros -->
<div class="admin-card mb-4">
  <form method="GET" action="" class="row g-2 align-items-end">
    <input type="hidden" name="accion" value="listar">

    <div class="col-12 col-sm-6 col-md-3">
      <label class="form-label">Buscar</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control"
               placeholder="Código o cliente…"
               value="<?= limpiar($fBuscar) ?>">
      </div>
    </div>

    <div class="col-6 col-sm-4 col-md-2">
      <label class="form-label">Estado</label>
      <select name="estado" class="form-select form-select-sm">
        <option value="">Todos</option>
        <?php foreach (['pendiente','confirmado','en_preparacion','en_camino','entregado','cancelado'] as $e): ?>
        <option value="<?= $e ?>" <?= $fEstado === $e ? 'selected' : '' ?>>
          <?= ucfirst(str_replace('_',' ',$e)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-sm-4 col-md-2">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm"
             value="<?= limpiar($fDesde) ?>">
    </div>

    <div class="col-6 col-sm-4 col-md-2">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm"
             value="<?= limpiar($fHasta) ?>">
    </div>

    <div class="col-6 col-sm-4 col-md-3 d-flex gap-2">
      <button type="submit" class="btn-verde btn btn-sm px-3 flex-grow-1">
        <i class="bi bi-funnel me-1"></i>Filtrar
      </button>
      <a href="?" class="btn btn-sm btn-outline-secondary px-2" title="Limpiar">
        <i class="bi bi-x-lg"></i>
      </a>
    </div>
  </form>
</div>

<!-- Tabla -->
<div class="admin-card">
  <div class="admin-card-header">
    <h6 class="admin-card-title">
      <i class="bi bi-bag-check me-2"></i>Pedidos
      <span class="badge bg-light text-dark border ms-1"><?= number_format($totalPeds) ?></span>
    </h6>
    <small class="text-muted">
      Mostrando <?= count($pedidos) ?> de <?= $totalPeds ?>
    </small>
  </div>

  <?php if (empty($pedidos)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-bag-x" style="font-size:3rem;opacity:.25;display:block;margin-bottom:.75rem"></i>
    No hay pedidos con los filtros aplicados
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.85rem">
      <thead>
        <tr>
          <th>Código</th>
          <th>Cliente</th>
          <th>Entrega</th>
          <th class="text-end">Total</th>
          <th>Método</th>
          <th>Estado</th>
          <th>Fecha</th>
          <th class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pedidos as $p): ?>
        <tr>
          <td>
            <code style="font-size:.8rem;background:#f8f9fa;padding:.15rem .4rem;border-radius:4px">
              <?= limpiar($p['codigo']) ?>
            </code>
          </td>
          <td class="fw-600"><?= limpiar($p['cli_nombre'] . ' ' . $p['cli_apellido']) ?></td>
          <td>
            <?php if ($p['tipo_entrega'] === 'delivery'): ?>
              <span class="text-info"><i class="bi bi-truck me-1"></i><?= limpiar($p['zona_nombre'] ?? '—') ?></span>
            <?php else: ?>
              <span class="text-success"><i class="bi bi-shop me-1"></i>Tienda</span>
            <?php endif; ?>
          </td>
          <td class="text-end fw-700">Bs. <?= number_format((float)$p['total'], 2) ?></td>
          <td style="font-size:.78rem">
            <?= limpiar(truncar($p['metodo_pago_nom'] ?? '—', 20)) ?>
          </td>
          <td><?= badgeEstado($p['estado']) ?></td>
          <td class="text-muted" style="font-size:.78rem;white-space:nowrap">
            <?= date('d/m/Y', strtotime($p['creado_en'])) ?><br>
            <span style="font-size:.7rem"><?= date('H:i', strtotime($p['creado_en'])) ?></span>
          </td>
          <td class="text-center">
            <a href="?accion=detalle&id=<?= (int)$p['id'] ?>"
               class="btn btn-sm btn-verde px-2 py-1" title="Ver detalle">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <?php if ($totalPags > 1): ?>
  <div class="d-flex justify-content-center align-items-center gap-2 p-3 border-top">
    <?php
    $qBase = http_build_query(array_filter([
        'accion' => 'listar',
        'q'      => $fBuscar,
        'estado' => $fEstado,
        'desde'  => $fDesde,
        'hasta'  => $fHasta,
    ]));
    ?>
    <?php if ($pagina > 1): ?>
    <a href="?<?= $qBase ?>&pagina=<?= $pagina-1 ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-chevron-left"></i>
    </a>
    <?php endif; ?>
    <?php for ($i = max(1,$pagina-2); $i <= min($totalPags,$pagina+2); $i++): ?>
    <a href="?<?= $qBase ?>&pagina=<?= $i ?>"
       class="btn btn-sm <?= $i===$pagina ? 'btn-verde' : 'btn-outline-secondary' ?>">
      <?= $i ?>
    </a>
    <?php endfor; ?>
    <?php if ($pagina < $totalPags): ?>
    <a href="?<?= $qBase ?>&pagina=<?= $pagina+1 ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-chevron-right"></i>
    </a>
    <?php endif; ?>
    <small class="text-muted ms-2">Página <?= $pagina ?> de <?= $totalPags ?></small>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>


<?php elseif ($accion === 'detalle'): ?>
<!-- ══════════════════════════════════════════════════════════
     VISTA: DETALLE DEL PEDIDO
     ══════════════════════════════════════════════════════════ -->

<!-- Breadcrumb interno -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/admin/pedidos.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Volver a pedidos
  </a>
  <span class="text-muted" style="font-size:.82rem">
    <i class="bi bi-chevron-right mx-1"></i>
    Pedido <code><?= limpiar($ped['codigo']) ?></code>
  </span>
  <div class="ms-auto d-flex gap-2">
    <button onclick="imprimirPedido()" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
  </div>
</div>

<!-- Header del pedido -->
<div class="ped-header mb-3">
  <div class="ped-header-left">
    <div class="ped-codigo"><?= limpiar($ped['codigo']) ?></div>
    <div class="ped-fecha">
      <i class="bi bi-calendar3 me-1"></i>
      <?= date('d/m/Y H:i', strtotime($ped['creado_en'])) ?>
    </div>
  </div>
  <div class="ped-estado-wrap">
    <div class="ped-estado-badge <?= $ped['estado'] ?>">
      <i class="bi <?= iconoEstado($ped['estado']) ?> me-1"></i>
      <?= str_replace('_',' ', ucfirst($ped['estado'])) ?>
    </div>
  </div>
</div>

<!-- Timeline de estados -->
<div class="estado-timeline mb-4">
  <?php
  $timelineEstados = [
      ['pendiente',      'bi-clock',         'Pendiente'],
      ['confirmado',     'bi-check-circle',   'Confirmado'],
      ['en_preparacion', 'bi-box-seam',       'Preparación'],
      ['en_camino',      'bi-truck',          'En camino'],
      ['entregado',      'bi-bag-check-fill', 'Entregado'],
  ];
  $estadosOrden = ['pendiente','confirmado','en_preparacion','en_camino','entregado','cancelado'];
  $posActual    = array_search($ped['estado'], $estadosOrden);

  foreach ($timelineEstados as $idx => [$est, $ico, $lbl]):
    $posEst  = array_search($est, $estadosOrden);
    $pasado  = $ped['estado'] !== 'cancelado' && $posActual >= $posEst;
    $actual  = $ped['estado'] === $est;
    $clase   = $actual ? 'tl-actual' : ($pasado ? 'tl-hecho' : 'tl-pendiente');
  ?>
  <div class="tl-paso <?= $clase ?>">
    <div class="tl-circulo">
      <i class="bi <?= $ico ?>"></i>
    </div>
    <div class="tl-label"><?= $lbl ?></div>
  </div>
  <?php if ($idx < count($timelineEstados)-1): ?>
  <div class="tl-linea <?= ($ped['estado'] !== 'cancelado' && $posActual > $posEst) ? 'tl-linea-hecha' : '' ?>"></div>
  <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($ped['estado'] === 'cancelado'): ?>
  <div class="tl-cancelado ms-2">
    <i class="bi bi-x-circle-fill me-1"></i>Cancelado
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">

  <!-- ════════════ COLUMNA IZQUIERDA ════════════ -->
  <div class="col-lg-8">

    <!-- Productos -->
    <div class="admin-card mb-3" id="printArea">
      <div class="admin-card-header">
        <h6 class="admin-card-title">
          <i class="bi bi-bag me-2"></i>Productos del pedido
        </h6>
        <span class="badge bg-light text-dark border">
          <?= count($itemsPed) ?> ítem<?= count($itemsPed) !== 1 ? 's' : '' ?>
        </span>
      </div>

      <!-- Cabecera impresión -->
      <div class="print-header d-none">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h4 style="color:#145730;font-weight:800">COBOCE — Cerámica &amp; Porcelánato</h4>
            <div style="font-size:.82rem;color:#666">Cobija, Bolivia · ventas@coboce-cobija.com</div>
          </div>
          <div class="text-end">
            <div style="font-size:.72rem;color:#888;text-transform:uppercase">Pedido</div>
            <div style="font-size:1.2rem;font-weight:800;color:#145730"><?= limpiar($ped['codigo']) ?></div>
            <div style="font-size:.78rem;color:#666"><?= date('d/m/Y H:i', strtotime($ped['creado_en'])) ?></div>
          </div>
        </div>
        <hr>
        <div class="row mb-3" style="font-size:.82rem">
          <div class="col-6">
            <strong>Cliente:</strong> <?= limpiar($ped['cli_nombre'] . ' ' . $ped['cli_apellido']) ?><br>
            <?php if ($ped['cli_tel']): ?>
            <strong>Tel:</strong> <?= limpiar($ped['cli_tel']) ?>
            <?php endif; ?>
          </div>
          <div class="col-6">
            <strong>Entrega:</strong>
            <?= $ped['tipo_entrega'] === 'delivery'
                ? 'Delivery — ' . limpiar($ped['zona_nombre'] ?? '')
                : 'Retiro en tienda' ?>
            <?php if ($ped['direccion_entrega']): ?>
            <br><strong>Dir:</strong> <?= limpiar($ped['direccion_entrega']) ?>
            <?php endif; ?>
          </div>
        </div>
        <hr>
      </div>

      <div class="table-responsive">
        <table class="table mb-0" style="font-size:.86rem">
          <thead>
            <tr>
              <th style="width:50px"></th>
              <th>Producto</th>
              <th class="text-center">Cantidad</th>
              <th class="text-end">Precio unit.</th>
              <th class="text-end">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($itemsPed as $it): ?>
            <tr>
              <td>
                <?php if ($it['imagen'] && file_exists(UPLOADS_PATH . '/' . $it['imagen'])): ?>
                  <img src="<?= UPLOADS_URL . '/' . limpiar($it['imagen']) ?>"
                       style="width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6">
                <?php else: ?>
                  <div style="width:42px;height:42px;background:#f0f2f5;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#ccc">
                    <i class="bi bi-image"></i>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-600"><?= limpiar($it['prod_nombre']) ?></div>
                <small class="text-muted"><?= limpiar($it['categoria']) ?></small>
              </td>
              <td class="text-center fw-700">
                <?= $it['cantidad'] ?> <?= limpiar($it['unidad']) ?>
              </td>
              <td class="text-end">Bs. <?= number_format((float)$it['precio_unit'], 2) ?></td>
              <td class="text-end fw-700">Bs. <?= number_format((float)$it['subtotal'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3"></td>
              <td class="text-end text-muted" style="font-size:.8rem">Subtotal</td>
              <td class="text-end fw-600">Bs. <?= number_format((float)$ped['subtotal'], 2) ?></td>
            </tr>
            <?php if ((float)$ped['costo_delivery'] > 0): ?>
            <tr>
              <td colspan="3"></td>
              <td class="text-end text-muted" style="font-size:.8rem">Delivery</td>
              <td class="text-end fw-600">Bs. <?= number_format((float)$ped['costo_delivery'], 2) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ((float)$ped['descuento_puntos'] > 0): ?>
            <tr>
              <td colspan="3"></td>
              <td class="text-end text-muted" style="font-size:.8rem">
                <i class="bi bi-star-fill text-warning me-1"></i>Puntos (<?= number_format((int)$ped['puntos_usados']) ?> pts)
              </td>
              <td class="text-end text-danger fw-600">− Bs. <?= number_format((float)$ped['descuento_puntos'], 2) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="border-top:2px solid #1A6B3A">
              <td colspan="3"></td>
              <td class="text-end fw-700" style="color:#145730">TOTAL</td>
              <td class="text-end fw-800" style="color:#145730;font-size:1rem">
                Bs. <?= number_format((float)$ped['total'], 2) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Pie impresión -->
      <div class="print-footer d-none mt-3 pt-3" style="border-top:1px solid #dee2e6;font-size:.75rem;color:#888">
        <div class="row">
          <div class="col-6">
            <strong>Firma del cliente:</strong><br>
            <div style="border-bottom:1px solid #999;margin-top:2rem;margin-bottom:.3rem"></div>
            <?= limpiar($ped['cli_nombre'] . ' ' . $ped['cli_apellido']) ?>
          </div>
          <div class="col-6 text-end">
            <strong>Sello / Firma empresa:</strong><br>
            <div style="border-bottom:1px solid #999;margin-top:2rem;margin-bottom:.3rem"></div>
            COBOCE Distribuidora Cobija
          </div>
        </div>
        <div class="text-center mt-2" style="font-size:.7rem;opacity:.6">
          Documento generado el <?= date('d/m/Y H:i') ?> · <?= APP_NAME ?>
        </div>
      </div>
    </div><!-- /productos card -->

    <!-- Info cliente + entrega -->
    <div class="row g-3 mb-3">
      <div class="col-12 col-sm-6">
        <div class="admin-card h-100">
          <h6 class="fw-700 mb-3" style="color:#145730;font-size:.88rem">
            <i class="bi bi-person me-2"></i>Cliente
          </h6>
          <div class="info-rows">
            <div class="ir-row">
              <span class="ir-label">Nombre</span>
              <span class="ir-val fw-600">
                <?= limpiar($ped['cli_nombre'] . ' ' . $ped['cli_apellido']) ?>
              </span>
            </div>
            <div class="ir-row">
              <span class="ir-label">Email</span>
              <span class="ir-val"><?= limpiar($ped['cli_email']) ?></span>
            </div>
            <?php if ($ped['cli_tel']): ?>
            <div class="ir-row">
              <span class="ir-label">Teléfono</span>
              <span class="ir-val">
                <a href="https://wa.me/<?= preg_replace('/\D/','',$ped['cli_tel']) ?>"
                   target="_blank" style="color:#25D366">
                  <i class="bi bi-whatsapp me-1"></i><?= limpiar($ped['cli_tel']) ?>
                </a>
              </span>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6">
        <div class="admin-card h-100">
          <h6 class="fw-700 mb-3" style="color:#145730;font-size:.88rem">
            <i class="bi bi-truck me-2"></i>Entrega y Pago
          </h6>
          <div class="info-rows">
            <div class="ir-row">
              <span class="ir-label">Tipo</span>
              <span class="ir-val">
                <?= $ped['tipo_entrega'] === 'delivery' ? 'Delivery' : 'Retiro en tienda' ?>
              </span>
            </div>
            <?php if ($ped['tipo_entrega'] === 'delivery'): ?>
            <div class="ir-row">
              <span class="ir-label">Zona</span>
              <span class="ir-val"><?= limpiar($ped['zona_nombre'] ?? '—') ?></span>
            </div>
            <div class="ir-row">
              <span class="ir-label">Dirección</span>
              <span class="ir-val"><?= limpiar($ped['direccion_entrega'] ?? '—') ?></span>
            </div>
            <?php if ($ped['referencia']): ?>
            <div class="ir-row">
              <span class="ir-label">Referencia</span>
              <span class="ir-val"><?= limpiar($ped['referencia']) ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="ir-row">
              <span class="ir-label">Pago</span>
              <span class="ir-val fw-600"><?= limpiar($ped['metodo_pago_nom'] ?? '—') ?></span>
            </div>
            <?php if ($ped['comprobante']): ?>
            <div class="ir-row">
              <span class="ir-label">Comprobante</span>
              <span class="ir-val">
                <a href="<?= UPLOADS_URL . '/' . limpiar($ped['comprobante']) ?>"
                   target="_blank" style="color:var(--verde);font-size:.82rem">
                  <i class="bi bi-file-earmark-check me-1"></i>Ver archivo
                </a>
              </span>
            </div>
            <?php endif; ?>
            <?php if ($ped['puntos_ganados'] > 0): ?>
            <div class="ir-row">
              <span class="ir-label">Puntos ganados</span>
              <span class="ir-val" style="color:var(--dorado-dark);font-weight:700">
                <i class="bi bi-star-fill me-1"></i><?= number_format((int)$ped['puntos_ganados']) ?> pts
              </span>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Notas del pedido -->
    <?php if ($ped['notas']): ?>
    <div class="admin-card mb-3">
      <h6 class="fw-700 mb-2" style="color:#145730;font-size:.88rem">
        <i class="bi bi-chat-text me-2"></i>Notas del pedido
      </h6>
      <p class="mb-0" style="font-size:.88rem"><?= limpiar($ped['notas']) ?></p>
    </div>
    <?php endif; ?>

  </div><!-- /col izquierda -->


  <!-- ════════════ COLUMNA DERECHA — Acciones ════════════ -->
  <div class="col-lg-4">

    <!-- ── Cambiar estado ──────────────────────────── -->
    <?php if (!in_array($ped['estado'], ['entregado','cancelado'])): ?>
    <div class="admin-card mb-3">
      <h6 class="admin-card-title mb-3">
        <i class="bi bi-arrow-repeat me-2"></i>Cambiar estado
      </h6>
      <form method="POST" action="">
        <?= campoCSRF() ?>
        <input type="hidden" name="pedido_id"   value="<?= (int)$ped['id'] ?>">
        <input type="hidden" name="accion_form" value="cambiar_estado">

        <div class="mb-3">
          <label class="form-label">Nuevo estado</label>
          <select name="estado" class="form-select" id="selectEstado">
            <?php
            $transiciones = [
                'pendiente'      => ['confirmado','cancelado'],
                'confirmado'     => ['en_preparacion','cancelado'],
                'en_preparacion' => ['en_camino','cancelado'],
                'en_camino'      => ['entregado'],
            ];
            $opciones = $transiciones[$ped['estado']] ?? [];
            $etiquetas = [
                'confirmado'     => 'Confirmado',
                'en_preparacion' => 'En preparación',
                'en_camino'      => 'En camino',
                'entregado'      => 'Entregado',
                'cancelado'      => 'Cancelado',
            ];
            foreach ($opciones as $opt):
            ?>
            <option value="<?= $opt ?>"><?= $etiquetas[$opt] ?? $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="btn-verde btn w-100">
          <i class="bi bi-check-circle me-1"></i>Confirmar cambio
        </button>
      </form>
    </div>
    <?php else: ?>
    <div class="admin-card mb-3 text-center py-3">
      <i class="bi <?= iconoEstado($ped['estado']) ?>" style="font-size:2.5rem;display:block;margin-bottom:.5rem"></i>
      <div class="fw-700" style="font-size:.9rem">
        Pedido <?= $ped['estado'] === 'entregado' ? 'entregado' : 'cancelado' ?> — estado final
      </div>
      <small class="text-muted">No se puede cambiar el estado</small>
    </div>
    <?php endif; ?>

    <!-- ── Asignar repartidor ──────────────────────── -->
    <?php if ($ped['tipo_entrega'] === 'delivery' && !in_array($ped['estado'], ['entregado','cancelado'])): ?>
    <div class="admin-card mb-3">
      <h6 class="admin-card-title mb-3">
        <i class="bi bi-person-badge me-2"></i>Repartidor
      </h6>

      <?php if ($asignacion): ?>
      <div class="asignacion-actual mb-3">
        <div class="asig-avatar">
          <?= strtoupper(substr($asignacion['rep_nombre'], 0, 1)) ?>
        </div>
        <div>
          <div class="fw-700" style="font-size:.88rem">
            <?= limpiar($asignacion['rep_nombre'] . ' ' . $asignacion['rep_apellido']) ?>
          </div>
          <?php if ($asignacion['rep_tel']): ?>
          <a href="https://wa.me/<?= preg_replace('/\D/','',$asignacion['rep_tel']) ?>"
             target="_blank" style="font-size:.75rem;color:#25D366">
            <i class="bi bi-whatsapp me-1"></i><?= limpiar($asignacion['rep_tel']) ?>
          </a>
          <?php endif; ?>
          <div style="margin-top:.2rem">
            <span class="badge bg-<?= $asignacion['estado'] === 'entregado' ? 'success' : ($asignacion['estado'] === 'recogido' ? 'info' : 'warning text-dark') ?>">
              <?= ucfirst($asignacion['estado']) ?>
            </span>
          </div>
        </div>
      </div>
      <small class="text-muted d-block mb-2">Reasignar repartidor:</small>
      <?php endif; ?>

      <form method="POST" action="">
        <?= campoCSRF() ?>
        <input type="hidden" name="pedido_id"   value="<?= (int)$ped['id'] ?>">
        <input type="hidden" name="accion_form" value="asignar_repartidor">

        <?php if (empty($repartidores)): ?>
        <div class="text-muted" style="font-size:.82rem">
          <i class="bi bi-info-circle me-1"></i>
          No hay repartidores registrados.
          <a href="<?= APP_URL ?>/admin/usuarios.php" class="text-verde">Agregar</a>
        </div>
        <?php else: ?>
        <div class="mb-2">
          <label class="form-label">Seleccionar repartidor</label>
          <select name="repartidor_id" class="form-select form-select-sm">
            <option value="">— Elegir —</option>
            <?php foreach ($repartidores as $r): ?>
            <option value="<?= (int)$r['id'] ?>"
                    <?= ($asignacion && (int)$asignacion['repartidor_id'] === (int)$r['id']) ? 'selected' : '' ?>>
              <?= limpiar($r['nombre'] . ' ' . $r['apellido']) ?>
              <?= $r['telefono'] ? '— ' . limpiar($r['telefono']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Notas <small class="text-muted">(opcional)</small></label>
          <input type="text" name="notas_asignacion" class="form-control form-control-sm"
                 placeholder="Instrucciones para el repartidor…"
                 value="<?= limpiar($asignacion['notas'] ?? '') ?>">
        </div>
        <button type="submit" class="btn-verde btn btn-sm w-100">
          <i class="bi bi-person-check me-1"></i>
          <?= $asignacion ? 'Reasignar' : 'Asignar repartidor' ?>
        </button>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>

    <!-- ── Resumen de totales ──────────────────────── -->
    <div class="admin-card mb-3">
      <h6 class="admin-card-title mb-3">
        <i class="bi bi-receipt me-2"></i>Resumen
      </h6>
      <div class="info-rows">
        <div class="ir-row">
          <span class="ir-label">Subtotal</span>
          <span class="ir-val">Bs. <?= number_format((float)$ped['subtotal'], 2) ?></span>
        </div>
        <div class="ir-row">
          <span class="ir-label">Delivery</span>
          <span class="ir-val">
            <?= (float)$ped['costo_delivery'] > 0
                ? 'Bs. ' . number_format((float)$ped['costo_delivery'], 2)
                : '<span class="text-success">Gratis</span>' ?>
          </span>
        </div>
        <?php if ((float)$ped['descuento_puntos'] > 0): ?>
        <div class="ir-row">
          <span class="ir-label"><i class="bi bi-star-fill text-warning me-1"></i>Puntos</span>
          <span class="ir-val text-danger">− Bs. <?= number_format((float)$ped['descuento_puntos'], 2) ?></span>
        </div>
        <?php endif; ?>
      </div>
      <div class="ir-total mt-2">
        <span>TOTAL</span>
        <span>Bs. <?= number_format((float)$ped['total'], 2) ?></span>
      </div>
    </div>

    <!-- ── Acciones rápidas ────────────────────────── -->
    <div class="d-flex flex-column gap-2">
      <button onclick="imprimirPedido()"
              class="btn btn-outline-secondary btn-sm w-100">
        <i class="bi bi-printer me-2"></i>Imprimir pedido
      </button>
      <?php if ($ped['cli_tel']): ?>
      <a href="https://wa.me/<?= preg_replace('/\D/','',$ped['cli_tel']) ?>?text=Hola+<?= urlencode($ped['cli_nombre']) ?>%2C+tu+pedido+<?= urlencode($ped['codigo']) ?>+est%C3%A1+<?= urlencode(str_replace('_',' ',$ped['estado'])) ?>."
         target="_blank" class="btn btn-sm w-100 fw-600"
         style="background:#25D366;color:white;border-radius:8px">
        <i class="bi bi-whatsapp me-2"></i>Notificar al cliente
      </a>
      <?php endif; ?>
    </div>

  </div><!-- /col acciones -->
</div><!-- /row detalle -->
<?php endif; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

<style>
/* ── STATS MINI ───────────────────────────────────────────── */
.stat-mini {
  background: var(--sm-bg);
  border: 1.5px solid color-mix(in srgb, var(--sm-color) 20%, transparent);
  border-radius: 10px; padding: .9rem .75rem;
  text-align: center; transition: all .22s;
}
.stat-mini:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,.1); }
.stat-mini i  { font-size: 1.4rem; color: var(--sm-color); display: block; margin-bottom: .3rem; }
.sm-num { font-size: 1.5rem; font-weight: 800; color: var(--sm-color); line-height: 1; }
.sm-lbl { font-size: .68rem; font-weight: 600; color: #6B7280; text-transform: uppercase;
          letter-spacing: .5px; margin-top: .2rem; }

/* ── HEADER PEDIDO ────────────────────────────────────────── */
.ped-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: .75rem;
  background: white; border: 1px solid #dee2e6;
  border-radius: 12px; padding: 1.1rem 1.5rem;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.ped-codigo { font-size: 1.4rem; font-weight: 800; color: #145730; letter-spacing: 1px; }
.ped-fecha  { font-size: .8rem; color: #6B7280; margin-top: .2rem; }
.ped-estado-badge {
  display: inline-flex; align-items: center;
  padding: .45rem 1.1rem; border-radius: 50px;
  font-weight: 700; font-size: .85rem;
}
.ped-estado-badge.pendiente       { background: #FFF9EC; color: #B45309; border: 1.5px solid #F59E0B; }
.ped-estado-badge.confirmado      { background: #F4F6F8; color: #374151; border: 1.5px solid #9CA3AF; }
.ped-estado-badge.en_preparacion  { background: #EEF2FF; color: #4338CA; border: 1.5px solid #6366F1; }
.ped-estado-badge.en_camino       { background: #F0F8FF; color: #0369A1; border: 1.5px solid #0EA5E9; }
.ped-estado-badge.entregado       { background: #F0FFF4; color: #15803D; border: 1.5px solid #22C55E; }
.ped-estado-badge.cancelado       { background: #FFF5F5; color: #DC2626; border: 1.5px solid #EF4444; }

/* ── TIMELINE ESTADOS ─────────────────────────────────────── */
.estado-timeline {
  display: flex; align-items: center;
  background: white; border: 1px solid #dee2e6;
  border-radius: 12px; padding: 1.1rem 1.5rem;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
  overflow-x: auto; gap: 0;
}
.tl-paso { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; gap: .3rem; min-width: 60px; }
.tl-circulo {
  width: 40px; height: 40px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; border: 2px solid #dee2e6;
  background: #f8f9fa; color: #9CA3AF; transition: all .22s;
}
.tl-label { font-size: .65rem; font-weight: 600; color: #9CA3AF;
            text-transform: uppercase; letter-spacing: .5px; text-align: center; }
.tl-hecho  .tl-circulo { background: var(--verde-dark); border-color: var(--verde-dark); color: white; }
.tl-hecho  .tl-label   { color: var(--verde-dark); }
.tl-actual .tl-circulo { background: var(--dorado); border-color: var(--dorado);
                          color: var(--verde-dark); box-shadow: 0 0 0 4px rgba(201,168,76,.2); }
.tl-actual .tl-label   { color: var(--dorado-dark); font-weight: 700; }
.tl-linea  { flex: 1; height: 2px; background: #dee2e6; margin: 0 4px; margin-bottom: 22px; min-width: 20px; }
.tl-linea-hecha { background: var(--verde-dark); }
.tl-cancelado {
  display: flex; align-items: center;
  color: #DC2626; font-size: .82rem; font-weight: 700;
  background: #FFF5F5; border: 1.5px solid #EF4444;
  border-radius: 50px; padding: .3rem .85rem;
}

/* ── INFO ROWS ────────────────────────────────────────────── */
.info-rows { display: flex; flex-direction: column; gap: .45rem; }
.ir-row { display: flex; justify-content: space-between; align-items: flex-start;
          font-size: .83rem; gap: .5rem; }
.ir-label { color: #6B7280; flex-shrink: 0; min-width: 85px; }
.ir-val   { color: #2D2D2D; text-align: right; }
.ir-total {
  display: flex; justify-content: space-between; align-items: center;
  border-top: 2px solid var(--verde-dark); padding-top: .6rem; margin-top: .3rem;
  font-weight: 800; font-size: 1rem; color: var(--verde-dark);
}

/* ── ASIGNACIÓN ACTUAL ────────────────────────────────────── */
.asignacion-actual {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: .75rem; background: rgba(26,107,58,.06);
  border: 1px solid rgba(26,107,58,.2); border-radius: 8px;
}
.asig-avatar {
  width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
  background: var(--verde); color: white;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 1rem;
}

/* ── PRINT STYLES ─────────────────────────────────────────── */
@media print {
  .admin-sidebar, .admin-topbar, .admin-card-header .btn,
  .col-lg-4, .estado-timeline, .ped-estado-wrap,
  .btn, .d-flex.gap-2, nav, a.btn { display: none !important; }
  .admin-main  { margin: 0 !important; padding: 0 !important; }
  .col-lg-8    { width: 100% !important; max-width: 100% !important; }
  .print-header, .print-footer { display: block !important; }
  .admin-card  { box-shadow: none !important; border: none !important; }
  body         { background: white !important; }
  table        { font-size: .8rem !important; }
}

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media (max-width: 768px) {
  .estado-timeline { padding: .75rem; }
  .tl-label { display: none; }
}
</style>

<?php
$scriptsAdmin = <<<JS
<script>
function imprimirPedido() {
    document.querySelectorAll('.print-header,.print-footer').forEach(el => {
        el.classList.remove('d-none');
    });
    window.print();
    document.querySelectorAll('.print-header,.print-footer').forEach(el => {
        el.classList.add('d-none');
    });
}
</script>
JS;
echo $scriptsAdmin;
?>
