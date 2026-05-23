<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

$tituloAdmin = 'Gestión de Delivery';
$paginaAdmin = 'delivery.php';
$db          = Database::getConnection();
$accion      = $_GET['accion'] ?? 'listar';

// ══════════════════════════════════════════════════════════════
// POST — asignar / actualizar estado
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido.');
        redirigir(APP_URL . '/admin/delivery.php');
    }

    $accionPost = $_POST['accion_form'] ?? '';

    // ── ASIGNAR REPARTIDOR ────────────────────────────────────
    if ($accionPost === 'asignar') {
        $pedidoId     = limpiarInt($_POST['pedido_id']     ?? 0);
        $repartidorId = limpiarInt($_POST['repartidor_id'] ?? 0);

        if (!$pedidoId || !$repartidorId) {
            flash('error', 'Selecciona un pedido y un repartidor.');
            redirigir(APP_URL . '/admin/delivery.php');
        }

        // Verificar que el pedido existe y es de tipo delivery
        $stmtP = $db->prepare(
            "SELECT id, estado, tipo_entrega, codigo FROM pedidos WHERE id = :id LIMIT 1"
        );
        $stmtP->execute([':id' => $pedidoId]);
        $pedido = $stmtP->fetch();

        if (!$pedido || $pedido['tipo_entrega'] !== 'delivery') {
            flash('error', 'Pedido inválido o no es de tipo delivery.');
            redirigir(APP_URL . '/admin/delivery.php');
        }

        // Upsert en asignaciones_delivery
        $stmtExiste = $db->prepare(
            "SELECT id FROM asignaciones_delivery WHERE pedido_id = :pid LIMIT 1"
        );
        $stmtExiste->execute([':pid' => $pedidoId]);
        $asignacion = $stmtExiste->fetch();

        if ($asignacion) {
            $db->prepare(
                "UPDATE asignaciones_delivery
                 SET repartidor_id = :rid, estado = 'asignado', asignado_en = NOW()
                 WHERE pedido_id = :pid"
            )->execute([':rid' => $repartidorId, ':pid' => $pedidoId]);
        } else {
            $db->prepare(
                "INSERT INTO asignaciones_delivery (pedido_id, repartidor_id, estado)
                 VALUES (:pid, :rid, 'asignado')"
            )->execute([':pid' => $pedidoId, ':rid' => $repartidorId]);
        }

        // Actualizar estado del pedido a "en_camino" si estaba confirmado/en_preparacion
        if (in_array($pedido['estado'], ['confirmado', 'en_preparacion'], true)) {
            $db->prepare("UPDATE pedidos SET estado = 'en_camino' WHERE id = :id")
               ->execute([':id' => $pedidoId]);
        }

        flash('exito', 'Repartidor asignado al pedido ' . $pedido['codigo'] . '.');
        redirigir(APP_URL . '/admin/delivery.php');
    }

    // ── MARCAR ENTREGADO ──────────────────────────────────────
    if ($accionPost === 'marcar_entregado') {
        $pedidoId = limpiarInt($_POST['pedido_id'] ?? 0);
        if (!$pedidoId) redirigir(APP_URL . '/admin/delivery.php');

        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE pedidos SET estado = 'entregado' WHERE id = :id"
            )->execute([':id' => $pedidoId]);

            $db->prepare(
                "UPDATE asignaciones_delivery
                 SET estado = 'entregado', entregado_en = NOW()
                 WHERE pedido_id = :pid"
            )->execute([':pid' => $pedidoId]);

            // Acreditar puntos ganados al usuario
            $stmtPed = $db->prepare(
                "SELECT usuario_id, puntos_ganados, codigo FROM pedidos WHERE id = :id"
            );
            $stmtPed->execute([':id' => $pedidoId]);
            $ped = $stmtPed->fetch();

            if ($ped && (int)$ped['puntos_ganados'] > 0) {
                $stmtSaldo = $db->prepare("SELECT puntos FROM usuarios WHERE id = :id");
                $stmtSaldo->execute([':id' => $ped['usuario_id']]);
                $saldoAntes   = (int)$stmtSaldo->fetchColumn();
                $saldoDespues = $saldoAntes + (int)$ped['puntos_ganados'];

                $db->prepare("UPDATE usuarios SET puntos = :p WHERE id = :id")
                   ->execute([':p' => $saldoDespues, ':id' => $ped['usuario_id']]);

                $db->prepare(
                    "INSERT INTO movimientos_puntos
                        (usuario_id, pedido_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                     VALUES (:uid, :pid, 'ganado', :qty, :sa, :sd, :desc)"
                )->execute([
                    ':uid'  => $ped['usuario_id'],
                    ':pid'  => $pedidoId,
                    ':qty'  => (int)$ped['puntos_ganados'],
                    ':sa'   => $saldoAntes,
                    ':sd'   => $saldoDespues,
                    ':desc' => 'Puntos por entrega confirmada — pedido ' . $ped['codigo'],
                ]);
            }

            $db->commit();
            flash('exito', 'Pedido marcado como entregado. Puntos acreditados al cliente.');
        } catch (\Throwable) {
            $db->rollBack();
            flash('error', 'Error al marcar el pedido como entregado.');
        }
        redirigir(APP_URL . '/admin/delivery.php');
    }

    redirigir(APP_URL . '/admin/delivery.php');
}

// ══════════════════════════════════════════════════════════════
// GET — datos
// ══════════════════════════════════════════════════════════════

// Repartidores disponibles
$repartidores = $db->query(
    "SELECT u.id, u.nombre, u.apellido, u.telefono,
            COUNT(ad.id) AS activos
     FROM   usuarios u
     LEFT JOIN asignaciones_delivery ad
               ON ad.repartidor_id = u.id AND ad.estado = 'asignado'
     WHERE  u.rol_id = 3 AND u.activo = 1
     GROUP  BY u.id
     ORDER  BY u.nombre ASC"
)->fetchAll();

// Pedidos delivery pendientes de asignación
$pedidosPendientes = $db->query(
    "SELECT p.*, u.nombre, u.apellido, u.telefono AS tel_cliente,
            zd.nombre AS zona_nombre
     FROM   pedidos p
     JOIN   usuarios u ON u.id = p.usuario_id
     LEFT JOIN zonas_delivery zd ON zd.id = p.zona_id
     LEFT JOIN asignaciones_delivery ad ON ad.pedido_id = p.id
     WHERE  p.tipo_entrega = 'delivery'
       AND  p.estado IN ('confirmado','en_preparacion')
       AND  ad.id IS NULL
     ORDER  BY p.creado_en ASC"
)->fetchAll();

// Pedidos en camino (ya asignados)
$pedidosEnCamino = $db->query(
    "SELECT p.*, u.nombre, u.apellido, u.telefono AS tel_cliente,
            zd.nombre AS zona_nombre,
            ur.nombre AS rep_nombre, ur.apellido AS rep_apellido, ur.telefono AS tel_rep,
            ad.repartidor_id, ad.estado AS estado_asig, ad.asignado_en, ad.id AS asig_id
     FROM   pedidos p
     JOIN   usuarios u  ON u.id  = p.usuario_id
     LEFT JOIN zonas_delivery zd ON zd.id = p.zona_id
     JOIN   asignaciones_delivery ad ON ad.pedido_id = p.id
     JOIN   usuarios ur ON ur.id = ad.repartidor_id
     WHERE  p.tipo_entrega = 'delivery'
       AND  p.estado = 'en_camino'
     ORDER  BY ad.asignado_en DESC"
)->fetchAll();

// Stats
$totalPendientes = count($pedidosPendientes);
$totalEnCamino   = count($pedidosEnCamino);
$totalRepartidores = count($repartidores);
$entregadosHoy   = (int)$db->query(
    "SELECT COUNT(*) FROM pedidos
     WHERE tipo_entrega='delivery' AND estado='entregado'
       AND DATE(actualizado_en) = CURDATE()"
)->fetchColumn();

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h1 class="fw-800 mb-0" style="font-size:1.4rem;color:var(--verde-dark)">
      <i class="bi bi-truck me-2"></i>Delivery
    </h1>
    <div style="font-size:.82rem;color:#6B7280">
      Asignación y seguimiento de entregas a domicilio
    </div>
  </div>
  <?php if (empty($repartidores)): ?>
  <a href="<?= APP_URL ?>/admin/usuarios.php?rol=delivery"
     class="btn btn-sm btn-outline-warning" style="border-radius:8px">
    <i class="bi bi-person-plus me-1"></i>Agregar repartidor
  </a>
  <?php endif; ?>
</div>

<!-- ── STATS ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(234,179,8,.1);color:#CA8A04">
          <i class="bi bi-clock-history"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= $totalPendientes ?></div>
          <div class="stat-lbl">Sin asignar</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(14,165,233,.1);color:#0284C7">
          <i class="bi bi-truck"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= $totalEnCamino ?></div>
          <div class="stat-lbl">En camino</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(26,107,58,.1);color:var(--verde)">
          <i class="bi bi-house-check"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= $entregadosHoy ?></div>
          <div class="stat-lbl">Entregados hoy</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(99,102,241,.1);color:#6366F1">
          <i class="bi bi-person-badge"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= $totalRepartidores ?></div>
          <div class="stat-lbl">Repartidores</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECCIÓN: SIN ASIGNAR
     ══════════════════════════════════════════════════════════ -->
<div class="section-header mb-3">
  <div class="section-header-bar"></div>
  <h2 class="section-header-title">
    <i class="bi bi-exclamation-circle-fill text-warning me-2"></i>
    Pedidos sin asignar
    <?php if ($totalPendientes > 0): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $totalPendientes ?></span>
    <?php endif; ?>
  </h2>
</div>

<?php if (empty($pedidosPendientes)): ?>
<div class="admin-card mb-4 text-center py-4 text-muted">
  <i class="bi bi-check-circle" style="font-size:2.2rem;color:#198754;opacity:.5"></i>
  <div class="mt-2 fw-600" style="color:#198754">¡Todo asignado!</div>
  <div style="font-size:.82rem">No hay pedidos delivery pendientes de asignación.</div>
</div>
<?php else: ?>

<?php if (empty($repartidores)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3" style="border-radius:10px">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div>
    No tienes repartidores disponibles.
    <a href="<?= APP_URL ?>/admin/usuarios.php" class="alert-link">
      Ve a Usuarios → cambia el rol de alguien a «Delivery»
    </a>.
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($pedidosPendientes as $p): ?>
  <div class="col-12 col-lg-6">
    <div class="delivery-card pendiente">
      <!-- Cabecera -->
      <div class="dc-head">
        <div>
          <code class="dc-codigo"><?= limpiar($p['codigo']) ?></code>
          <div class="dc-fecha"><?= tiempoRelativo($p['creado_en']) ?></div>
        </div>
        <span class="badge bg-warning text-dark" style="border-radius:50px;font-size:.72rem">
          <?= str_replace('_', ' ', ucfirst($p['estado'])) ?>
        </span>
      </div>

      <!-- Info cliente + zona -->
      <div class="dc-info">
        <div class="dc-info-row">
          <i class="bi bi-person"></i>
          <span><?= limpiar($p['nombre'] . ' ' . $p['apellido']) ?></span>
          <?php if ($p['tel_cliente']): ?>
            <a href="tel:<?= limpiar($p['tel_cliente']) ?>" class="dc-tel">
              <i class="bi bi-telephone"></i> <?= limpiar($p['tel_cliente']) ?>
            </a>
          <?php endif; ?>
        </div>
        <div class="dc-info-row">
          <i class="bi bi-geo-alt"></i>
          <span>
            <strong><?= limpiar($p['zona_nombre'] ?? '—') ?></strong>
            <?php if ($p['direccion_entrega']): ?>
              — <?= limpiar(truncar($p['direccion_entrega'], 60)) ?>
            <?php endif; ?>
          </span>
        </div>
        <?php if ($p['referencia']): ?>
        <div class="dc-info-row">
          <i class="bi bi-pin-map"></i>
          <span><?= limpiar($p['referencia']) ?></span>
        </div>
        <?php endif; ?>
        <div class="dc-info-row">
          <i class="bi bi-cash-coin"></i>
          <span class="fw-700" style="color:var(--verde-dark)">
            Total: <?= precio((float)$p['total']) ?>
          </span>
        </div>
      </div>

      <!-- Formulario asignación -->
      <?php if (!empty($repartidores)): ?>
      <form method="POST" class="dc-asignar">
        <?= campoCSRF() ?>
        <input type="hidden" name="accion_form" value="asignar">
        <input type="hidden" name="pedido_id"   value="<?= (int)$p['id'] ?>">
        <select name="repartidor_id" class="form-select form-select-sm" required>
          <option value="">— Seleccionar repartidor —</option>
          <?php foreach ($repartidores as $r): ?>
          <option value="<?= (int)$r['id'] ?>">
            <?= limpiar($r['nombre'] . ' ' . $r['apellido']) ?>
            <?= (int)$r['activos'] > 0 ? '(' . (int)$r['activos'] . ' activo' . ((int)$r['activos'] > 1 ? 's' : '') . ')' : '(libre)' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-verde btn w-100 mt-2">
          <i class="bi bi-send me-1"></i>Asignar repartidor
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     SECCIÓN: EN CAMINO
     ══════════════════════════════════════════════════════════ -->
<div class="section-header mb-3">
  <div class="section-header-bar" style="background:#0EA5E9"></div>
  <h2 class="section-header-title">
    <i class="bi bi-truck me-2" style="color:#0EA5E9"></i>
    En camino
    <?php if ($totalEnCamino > 0): ?>
      <span class="badge ms-1" style="background:#0EA5E9;font-size:.72rem"><?= $totalEnCamino ?></span>
    <?php endif; ?>
  </h2>
</div>

<?php if (empty($pedidosEnCamino)): ?>
<div class="admin-card text-center py-4 text-muted">
  <i class="bi bi-truck" style="font-size:2.2rem;opacity:.2"></i>
  <div class="mt-2" style="font-size:.85rem">No hay pedidos en camino ahora mismo.</div>
</div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($pedidosEnCamino as $p): ?>
  <div class="col-12 col-lg-6">
    <div class="delivery-card en-camino">
      <!-- Cabecera -->
      <div class="dc-head">
        <div>
          <code class="dc-codigo"><?= limpiar($p['codigo']) ?></code>
          <div class="dc-fecha">Asignado <?= tiempoRelativo($p['asignado_en']) ?></div>
        </div>
        <span class="badge" style="background:#0EA5E9;border-radius:50px;font-size:.72rem">
          <i class="bi bi-truck me-1"></i>En camino
        </span>
      </div>

      <!-- Info cliente -->
      <div class="dc-info">
        <div class="dc-info-row">
          <i class="bi bi-person"></i>
          <span><?= limpiar($p['nombre'] . ' ' . $p['apellido']) ?></span>
          <?php if ($p['tel_cliente']): ?>
            <a href="tel:<?= limpiar($p['tel_cliente']) ?>" class="dc-tel">
              <i class="bi bi-telephone"></i> <?= limpiar($p['tel_cliente']) ?>
            </a>
          <?php endif; ?>
        </div>
        <div class="dc-info-row">
          <i class="bi bi-geo-alt"></i>
          <span>
            <strong><?= limpiar($p['zona_nombre'] ?? '—') ?></strong>
            <?php if ($p['direccion_entrega']): ?>
              — <?= limpiar(truncar($p['direccion_entrega'], 55)) ?>
            <?php endif; ?>
          </span>
        </div>
        <div class="dc-info-row">
          <i class="bi bi-cash-coin"></i>
          <span class="fw-700" style="color:var(--verde-dark)"><?= precio((float)$p['total']) ?></span>
        </div>
      </div>

      <!-- Repartidor asignado -->
      <div class="dc-repartidor">
        <div class="dc-rep-avatar">
          <?= strtoupper(substr($p['rep_nombre'], 0, 1)) ?>
        </div>
        <div class="flex-grow-1">
          <div class="fw-600" style="font-size:.85rem">
            <?= limpiar($p['rep_nombre'] . ' ' . $p['rep_apellido']) ?>
          </div>
          <div style="font-size:.75rem;color:#6B7280">Repartidor asignado</div>
        </div>
        <?php if ($p['tel_rep']): ?>
        <a href="https://wa.me/<?= preg_replace('/\D/', '', $p['tel_rep']) ?>"
           target="_blank"
           class="btn btn-sm"
           style="background:#25D366;color:white;border-radius:8px;padding:.3rem .6rem"
           title="WhatsApp">
          <i class="bi bi-whatsapp"></i>
        </a>
        <?php endif; ?>
      </div>

      <!-- Acciones -->
      <div class="d-flex gap-2 mt-2">
        <form method="POST" class="flex-grow-1">
          <?= campoCSRF() ?>
          <input type="hidden" name="accion_form" value="marcar_entregado">
          <input type="hidden" name="pedido_id"   value="<?= (int)$p['id'] ?>">
          <button type="submit"
                  class="btn btn-sm btn-success w-100"
                  style="border-radius:8px"
                  onclick="return confirm('¿Confirmar entrega del pedido <?= limpiar($p['codigo']) ?>?')">
            <i class="bi bi-house-check me-1"></i>Marcar entregado
          </button>
        </form>

        <form method="POST">
          <?= campoCSRF() ?>
          <input type="hidden" name="accion_form" value="asignar">
          <input type="hidden" name="pedido_id"   value="<?= (int)$p['id'] ?>">
          <div class="d-flex gap-1">
            <select name="repartidor_id" class="form-select form-select-sm"
                    style="border-radius:8px;min-width:140px">
              <?php foreach ($repartidores as $r): ?>
              <option value="<?= (int)$r['id'] ?>"
                      <?= (int)$r['id'] === (int)$p['repartidor_id'] ? 'selected' : '' ?>>
                <?= limpiar($r['nombre'] . ' ' . $r['apellido']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary"
                    style="border-radius:8px" title="Reasignar">
              <i class="bi bi-arrow-repeat"></i>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     PANEL DE REPARTIDORES
     ══════════════════════════════════════════════════════════ -->
<div class="section-header mb-3 mt-4">
  <div class="section-header-bar" style="background:#6366F1"></div>
  <h2 class="section-header-title">
    <i class="bi bi-person-badge me-2" style="color:#6366F1"></i>
    Repartidores
  </h2>
</div>

<?php if (empty($repartidores)): ?>
<div class="admin-card text-center py-5">
  <i class="bi bi-person-badge" style="font-size:3rem;opacity:.15"></i>
  <h5 class="mt-3 text-muted">Sin repartidores registrados</h5>
  <p class="text-muted mb-3" style="font-size:.85rem">
    Para asignar pedidos necesitas al menos un usuario con rol «Delivery».
  </p>
  <a href="<?= APP_URL ?>/admin/usuarios.php" class="btn-verde btn">
    <i class="bi bi-people me-1"></i>Gestionar usuarios
  </a>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($repartidores as $r):
    $activos = (int)$r['activos'];
  ?>
  <div class="col-12 col-sm-6 col-lg-4">
    <div class="rep-card">
      <div class="rep-avatar">
        <?= strtoupper(substr($r['nombre'], 0, 1)) ?>
      </div>
      <div class="flex-grow-1">
        <div class="fw-700"><?= limpiar($r['nombre'] . ' ' . $r['apellido']) ?></div>
        <?php if ($r['telefono']): ?>
        <div style="font-size:.78rem;color:#6B7280">
          <i class="bi bi-telephone me-1"></i><?= limpiar($r['telefono']) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="text-end">
        <div class="fw-800" style="font-size:1.2rem;color:<?= $activos > 0 ? '#0284C7' : '#198754' ?>">
          <?= $activos ?>
        </div>
        <div style="font-size:.7rem;color:#6B7280">activo<?= $activos !== 1 ? 's' : '' ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<style>
/* ── SECTION HEADER ───────────────────────────────────────── */
.section-header {
  display: flex; align-items: center; gap: .75rem;
}
.section-header-bar {
  width: 4px; height: 24px; border-radius: 4px;
  background: var(--dorado); flex-shrink: 0;
}
.section-header-title {
  font-size: 1rem; font-weight: 700; color: #1f2937;
  margin: 0; display: flex; align-items: center;
}

/* ── DELIVERY CARD ────────────────────────────────────────── */
.delivery-card {
  background: white;
  border-radius: 12px;
  border: 1px solid #dee2e6;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
  padding: 1.1rem 1.25rem;
  display: flex; flex-direction: column; gap: .85rem;
}
.delivery-card.pendiente { border-left: 4px solid #f59e0b; }
.delivery-card.en-camino { border-left: 4px solid #0EA5E9; }

.dc-head {
  display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem;
}
.dc-codigo {
  font-size: .95rem; font-weight: 700; color: var(--verde-dark);
  background: rgba(26,107,58,.08); padding: .2rem .6rem; border-radius: 6px;
}
.dc-fecha { font-size: .72rem; color: #6B7280; margin-top: .25rem; }

.dc-info { display: flex; flex-direction: column; gap: .4rem; }
.dc-info-row {
  display: flex; align-items: flex-start; gap: .5rem; font-size: .83rem;
}
.dc-info-row > i { color: var(--verde); flex-shrink: 0; margin-top: .1rem; }
.dc-tel {
  margin-left: auto; font-size: .78rem; color: #0284C7;
  display: flex; align-items: center; gap: .25rem;
  white-space: nowrap; flex-shrink: 0;
}

.dc-asignar { border-top: 1px solid #f0f2f5; padding-top: .75rem; }

.dc-repartidor {
  display: flex; align-items: center; gap: .75rem;
  background: rgba(14,165,233,.07);
  border: 1px solid rgba(14,165,233,.2);
  border-radius: 8px; padding: .6rem .85rem;
}
.dc-rep-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: #0EA5E9; color: white;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: .9rem; flex-shrink: 0;
}

/* ── REPARTIDOR CARD ──────────────────────────────────────── */
.rep-card {
  background: white; border-radius: 12px;
  border: 1px solid #dee2e6; box-shadow: 0 2px 10px rgba(0,0,0,.05);
  padding: 1rem 1.25rem;
  display: flex; align-items: center; gap: .85rem;
}
.rep-avatar {
  width: 48px; height: 48px; border-radius: 50%;
  background: linear-gradient(135deg, #6366F1, #8B5CF6);
  color: white; display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 1.2rem; flex-shrink: 0;
}
</style>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
