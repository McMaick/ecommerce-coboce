<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

requiereDelivery();

$tituloRepartidor   = 'Mis entregas';
$paginaRepartidor    = 'index.php';
$db                  = Database::getConnection();
$repartidorId        = (int) $_SESSION['usuario_id'];

// ══════════════════════════════════════════════════════════════
// POST — el repartidor recoge o entrega un pedido
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido.');
        redirigir(APP_URL . '/repartidor/index.php');
    }

    $pedidoId   = limpiarInt($_POST['pedido_id'] ?? 0);
    $accionForm = $_POST['accion_form'] ?? '';

    // Verificar que el pedido esté realmente asignado a este repartidor
    $stmtAsig = $db->prepare(
        "SELECT ad.id, ad.estado, p.codigo, p.usuario_id, p.puntos_ganados
         FROM   asignaciones_delivery ad
         JOIN   pedidos p ON p.id = ad.pedido_id
         WHERE  ad.pedido_id = :pid AND ad.repartidor_id = :rid
         LIMIT 1"
    );
    $stmtAsig->execute([':pid' => $pedidoId, ':rid' => $repartidorId]);
    $asig = $stmtAsig->fetch();

    if (!$asig) {
        flash('error', 'Ese pedido no está asignado a tu cuenta.');
        redirigir(APP_URL . '/repartidor/index.php');
    }

    // ── MARCAR RECOGIDO ────────────────────────────────────────
    if ($accionForm === 'recogido' && $asig['estado'] === 'asignado') {
        $db->prepare("UPDATE asignaciones_delivery SET estado='recogido' WHERE id=:id")
           ->execute([':id' => $asig['id']]);
        flash('exito', 'Pedido ' . $asig['codigo'] . ' marcado como recogido del local.');
        redirigir(APP_URL . '/repartidor/index.php');
    }

    // ── MARCAR ENTREGADO ───────────────────────────────────────
    if ($accionForm === 'entregado' && in_array($asig['estado'], ['asignado', 'recogido'], true)) {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE pedidos SET estado='entregado' WHERE id=:id")
               ->execute([':id' => $pedidoId]);

            $db->prepare("UPDATE asignaciones_delivery SET estado='entregado', entregado_en=NOW() WHERE id=:id")
               ->execute([':id' => $asig['id']]);

            if ((int)$asig['puntos_ganados'] > 0) {
                $stmtSaldo = $db->prepare("SELECT puntos FROM usuarios WHERE id=:id");
                $stmtSaldo->execute([':id' => $asig['usuario_id']]);
                $saldoAntes   = (int)$stmtSaldo->fetchColumn();
                $saldoDespues = $saldoAntes + (int)$asig['puntos_ganados'];

                $db->prepare("UPDATE usuarios SET puntos=:p WHERE id=:id")
                   ->execute([':p' => $saldoDespues, ':id' => $asig['usuario_id']]);

                $db->prepare(
                    "INSERT INTO movimientos_puntos
                        (usuario_id, pedido_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                     VALUES (:uid, :pid, 'ganado', :qty, :sa, :sd, :desc)"
                )->execute([
                    ':uid'  => $asig['usuario_id'],
                    ':pid'  => $pedidoId,
                    ':qty'  => (int)$asig['puntos_ganados'],
                    ':sa'   => $saldoAntes,
                    ':sd'   => $saldoDespues,
                    ':desc' => 'Puntos por entrega confirmada — pedido ' . $asig['codigo'],
                ]);
            }

            $db->commit();
            flash('exito', 'Pedido ' . $asig['codigo'] . ' entregado. Puntos acreditados al cliente.');
        } catch (\Throwable) {
            $db->rollBack();
            flash('error', 'Error al confirmar la entrega.');
        }
        redirigir(APP_URL . '/repartidor/index.php');
    }

    redirigir(APP_URL . '/repartidor/index.php');
}

// ══════════════════════════════════════════════════════════════
// GET — pedidos asignados a este repartidor
// ══════════════════════════════════════════════════════════════
$pedidosAsignados = $db->prepare(
    "SELECT p.id, p.codigo, p.total, p.direccion_entrega, p.referencia, p.creado_en,
            u.nombre, u.apellido, u.telefono AS tel_cliente,
            zd.nombre AS zona_nombre,
            ad.id AS asig_id, ad.estado AS estado_asig, ad.asignado_en
     FROM   asignaciones_delivery ad
     JOIN   pedidos p  ON p.id = ad.pedido_id
     JOIN   usuarios u ON u.id = p.usuario_id
     LEFT JOIN zonas_delivery zd ON zd.id = p.zona_id
     WHERE  ad.repartidor_id = :rid
       AND  ad.estado IN ('asignado','recogido')
     ORDER  BY ad.asignado_en ASC"
);
$pedidosAsignados->execute([':rid' => $repartidorId]);
$pedidosAsignados = $pedidosAsignados->fetchAll();

$stmtHoy = $db->prepare(
    "SELECT COUNT(*) FROM asignaciones_delivery
     WHERE repartidor_id = :rid AND estado = 'entregado' AND DATE(entregado_en) = CURDATE()"
);
$stmtHoy->execute([':rid' => $repartidorId]);
$entregadosHoy = (int) $stmtHoy->fetchColumn();

require_once __DIR__ . '/includes/repartidor_header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-6">
    <div class="stat-card text-center">
      <div class="stat-num" style="color:#0EA5E9"><?= count($pedidosAsignados) ?></div>
      <div class="stat-lbl">Pendientes</div>
    </div>
  </div>
  <div class="col-6">
    <div class="stat-card text-center">
      <div class="stat-num" style="color:var(--verde)"><?= $entregadosHoy ?></div>
      <div class="stat-lbl">Entregados hoy</div>
    </div>
  </div>
</div>

<?php if (empty($pedidosAsignados)): ?>
<div class="stat-card text-center py-5 text-muted">
  <i class="bi bi-check-circle" style="font-size:2.5rem;color:#198754;opacity:.4"></i>
  <div class="mt-2 fw-600" style="color:#198754">¡Sin entregas pendientes!</div>
  <div style="font-size:.82rem">Cuando el admin te asigne un pedido, aparecerá aquí.</div>
</div>
<?php else: ?>

<?php foreach ($pedidosAsignados as $p): ?>
<div class="stat-card mb-3">
  <div class="d-flex align-items-start justify-content-between mb-2">
    <div>
      <code style="font-weight:700;color:var(--verde-dark);background:rgba(26,107,58,.08);padding:.2rem .6rem;border-radius:6px">
        <?= limpiar($p['codigo']) ?>
      </code>
      <div style="font-size:.72rem;color:#6B7280;margin-top:.25rem">
        Asignado <?= tiempoRelativo($p['asignado_en']) ?>
      </div>
    </div>
    <span class="badge <?= $p['estado_asig'] === 'recogido' ? '' : 'bg-warning text-dark' ?>"
          style="<?= $p['estado_asig'] === 'recogido' ? 'background:#0EA5E9' : '' ?>;border-radius:50px;font-size:.72rem">
      <?= $p['estado_asig'] === 'recogido' ? 'Recogido, en camino' : 'Por recoger' ?>
    </span>
  </div>

  <div class="d-flex flex-column gap-1 mb-3" style="font-size:.85rem">
    <div><i class="bi bi-person me-1" style="color:var(--verde)"></i> <?= limpiar($p['nombre'] . ' ' . $p['apellido']) ?>
      <?php if ($p['tel_cliente']): ?>
      <a href="tel:<?= limpiar($p['tel_cliente']) ?>" class="ms-2" style="color:#0284C7"><i class="bi bi-telephone"></i> <?= limpiar($p['tel_cliente']) ?></a>
      <?php endif; ?>
    </div>
    <div><i class="bi bi-geo-alt me-1" style="color:var(--verde)"></i>
      <strong><?= limpiar($p['zona_nombre'] ?? '—') ?></strong>
      <?php if ($p['direccion_entrega']): ?> — <?= limpiar($p['direccion_entrega']) ?><?php endif; ?>
    </div>
    <?php if ($p['referencia']): ?>
    <div><i class="bi bi-pin-map me-1" style="color:var(--verde)"></i><?= limpiar($p['referencia']) ?></div>
    <?php endif; ?>
    <div class="fw-700" style="color:var(--verde-dark)"><i class="bi bi-cash-coin me-1"></i><?= precio((float)$p['total']) ?></div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($p['estado_asig'] === 'asignado'): ?>
    <form method="POST" class="flex-grow-1">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion_form" value="recogido">
      <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
      <button type="submit" class="btn btn-sm w-100" style="border-radius:8px;background:#0EA5E9;color:white">
        <i class="bi bi-box-seam me-1"></i>Recogí el pedido
      </button>
    </form>
    <?php else: ?>
    <form method="POST" class="flex-grow-1">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion_form" value="entregado">
      <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
      <button type="submit" class="btn btn-sm btn-verde w-100"
              onclick="return confirm('¿Confirmar entrega del pedido <?= limpiar($p['codigo']) ?>?')">
        <i class="bi bi-house-check me-1"></i>Confirmar entrega
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/repartidor_footer.php'; ?>
