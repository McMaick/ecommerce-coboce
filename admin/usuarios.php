<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

$tituloAdmin = 'Gestión de Usuarios';
$paginaAdmin = 'usuarios.php';
$db          = Database::getConnection();
$accion      = $_GET['accion'] ?? 'listar';

// ══════════════════════════════════════════════════════════════
// POST — acciones
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido.');
        redirigir(APP_URL . '/admin/usuarios.php');
    }

    $accionPost = $_POST['accion_form'] ?? '';
    $uid        = limpiarInt($_POST['usuario_id'] ?? 0);

    // ── CAMBIAR ROL ───────────────────────────────────────────
    if ($accionPost === 'cambiar_rol' && $uid) {
        $rolId = limpiarInt($_POST['rol_id'] ?? 0);
        $roles = [1 => 'admin', 2 => 'cliente', 3 => 'delivery'];
        if (isset($roles[$rolId])) {
            $db->prepare("UPDATE usuarios SET rol_id = :r WHERE id = :id")
               ->execute([':r' => $rolId, ':id' => $uid]);
            flash('exito', 'Rol actualizado a "' . $roles[$rolId] . '" correctamente.');
        } else {
            flash('error', 'Rol no válido.');
        }
        redirigir(APP_URL . '/admin/usuarios.php?accion=detalle&id=' . $uid);
    }

    // ── ACTIVAR / DESACTIVAR ──────────────────────────────────
    if ($accionPost === 'toggle_activo' && $uid) {
        $stmt = $db->prepare("SELECT activo FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $uid]);
        $actual = (int)$stmt->fetchColumn();
        $nuevo  = $actual ? 0 : 1;
        $db->prepare("UPDATE usuarios SET activo = :a WHERE id = :id")
           ->execute([':a' => $nuevo, ':id' => $uid]);
        flash($nuevo ? 'exito' : 'advertencia', 'Usuario ' . ($nuevo ? 'activado' : 'desactivado') . ' correctamente.');
        redirigir(APP_URL . '/admin/usuarios.php?accion=detalle&id=' . $uid);
    }

    // ── CREAR USUARIO ─────────────────────────────────────────
    if ($accionPost === 'crear_usuario') {
        $nombre   = trim($_POST['nombre']   ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $telefono = trim($_POST['telefono'] ?? '');
        $ci       = trim($_POST['ci']       ?? '');
        $password = $_POST['password']      ?? '';
        $rolId    = limpiarInt($_POST['rol_id'] ?? 2);

        $errores = [];
        if (!$nombre)                                  $errores[] = 'El nombre es obligatorio.';
        if (!$apellido)                                $errores[] = 'El apellido es obligatorio.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))$errores[] = 'El email no es válido.';
        if (strlen($password) < 6)                     $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        if (!in_array($rolId, [1, 2, 3], true))        $errores[] = 'Rol no válido.';

        if ($errores) {
            flash('error', implode(' ', $errores));
            redirigir(APP_URL . '/admin/usuarios.php');
        }

        $existe = $db->prepare("SELECT 1 FROM usuarios WHERE email = :e LIMIT 1");
        $existe->execute([':e' => $email]);
        if ($existe->fetchColumn()) {
            flash('error', 'Ya existe un usuario con ese email.');
            redirigir(APP_URL . '/admin/usuarios.php');
        }

        $db->prepare(
            "INSERT INTO usuarios (rol_id, nombre, apellido, email, telefono, ci, password_hash, activo)
             VALUES (:rol, :nom, :ape, :email, :tel, :ci, :hash, 1)"
        )->execute([
            ':rol'   => $rolId,
            ':nom'   => $nombre,
            ':ape'   => $apellido,
            ':email' => $email,
            ':tel'   => $telefono ?: null,
            ':ci'    => $ci       ?: null,
            ':hash'  => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $rolesNombres = [1 => 'admin', 2 => 'cliente', 3 => 'repartidor'];
        flash('exito', 'Usuario "' . $nombre . ' ' . $apellido . '" creado como ' . ($rolesNombres[$rolId] ?? '') . '.');
        redirigir(APP_URL . '/admin/usuarios.php');
    }

    // ── AJUSTAR PUNTOS ────────────────────────────────────────
    if ($accionPost === 'ajustar_puntos' && $uid) {
        $cantPuntos = limpiarInt($_POST['puntos_cantidad'] ?? 0);
        $tipoAdj    = $_POST['tipo_ajuste'] ?? 'ajuste';
        $notaAdj    = trim($_POST['nota_ajuste'] ?? '');

        if ($cantPuntos <= 0) {
            flash('error', 'La cantidad de puntos debe ser mayor a 0.');
            redirigir(APP_URL . '/admin/usuarios.php?accion=detalle&id=' . $uid);
        }

        $stmtU = $db->prepare("SELECT puntos FROM usuarios WHERE id = :id");
        $stmtU->execute([':id' => $uid]);
        $saldoAntes = (int)$stmtU->fetchColumn();

        $delta       = $tipoAdj === 'sumar' ? $cantPuntos : -$cantPuntos;
        $saldoDespues= max(0, $saldoAntes + $delta);

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE usuarios SET puntos = :p WHERE id = :id")
               ->execute([':p' => $saldoDespues, ':id' => $uid]);
            $db->prepare(
                "INSERT INTO movimientos_puntos
                    (usuario_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                 VALUES (:uid, 'ajuste', :qty, :sa, :sd, :desc)"
            )->execute([
                ':uid'  => $uid,
                ':qty'  => $delta,
                ':sa'   => $saldoAntes,
                ':sd'   => $saldoDespues,
                ':desc' => $notaAdj ?: 'Ajuste manual por administrador',
            ]);
            $db->commit();
            flash('exito', 'Puntos ajustados: saldo nuevo = ' . number_format($saldoDespues) . ' pts.');
        } catch (\Throwable) {
            $db->rollBack();
            flash('error', 'Error al ajustar los puntos.');
        }
        redirigir(APP_URL . '/admin/usuarios.php?accion=detalle&id=' . $uid);
    }

    redirigir(APP_URL . '/admin/usuarios.php');
}

// ══════════════════════════════════════════════════════════════
// GET — vistas
// ══════════════════════════════════════════════════════════════

// ── DETALLE DE USUARIO ─────────────────────────────────────
if ($accion === 'detalle') {
    $uid = limpiarInt($_GET['id'] ?? 0);
    if (!$uid) redirigir(APP_URL . '/admin/usuarios.php');

    $stmt = $db->prepare(
        "SELECT u.*, r.nombre AS rol
         FROM   usuarios u JOIN roles r ON r.id = u.rol_id
         WHERE  u.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $uid]);
    $usuario = $stmt->fetch();
    if (!$usuario) { flash('error', 'Usuario no encontrado.'); redirigir(APP_URL . '/admin/usuarios.php'); }

    // Pedidos del usuario
    $pedidosU = $db->prepare(
        "SELECT p.*, mp.nombre AS metodo_nombre
         FROM   pedidos p
         LEFT JOIN metodos_pago mp ON mp.id = p.metodo_pago_id
         WHERE  p.usuario_id = :uid
         ORDER  BY p.creado_en DESC LIMIT 20"
    );
    $pedidosU->execute([':uid' => $uid]);
    $pedidosU = $pedidosU->fetchAll();

    // Historial de puntos
    $histPuntos = $db->prepare(
        "SELECT * FROM movimientos_puntos
         WHERE  usuario_id = :uid
         ORDER  BY creado_en DESC LIMIT 20"
    );
    $histPuntos->execute([':uid' => $uid]);
    $histPuntos = $histPuntos->fetchAll();

    // Roles disponibles
    $roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();

    // ── Stats y datos extra para REPARTIDOR ───────────────────
    $statsDelivery = null;
    $entregaActual = null;
    $histDelivery  = [];

    if ($usuario['rol'] === 'delivery') {
        $statsDelivery = $db->prepare(
            "SELECT
                COUNT(*)                                              AS total_entregas,
                SUM(ad.estado = 'entregado')                         AS completadas,
                SUM(ad.estado IN ('asignado','recogido'))            AS en_curso,
                COALESCE(SUM(CASE WHEN ad.estado='entregado' THEN p.total ELSE 0 END), 0) AS bs_transportados,
                SUM(MONTH(ad.asignado_en) = MONTH(NOW())
                    AND YEAR(ad.asignado_en) = YEAR(NOW())
                    AND ad.estado = 'entregado')                     AS este_mes
             FROM asignaciones_delivery ad
             JOIN pedidos p ON p.id = ad.pedido_id
             WHERE ad.repartidor_id = :uid"
        );
        $statsDelivery->execute([':uid' => $uid]);
        $statsDelivery = $statsDelivery->fetch();

        // Entrega activa actual
        $stmtAct = $db->prepare(
            "SELECT p.codigo, p.total, p.direccion_entrega, p.referencia,
                    u.nombre, u.apellido, u.telefono,
                    zd.nombre AS zona_nombre, ad.asignado_en, ad.estado AS estado_asig
             FROM asignaciones_delivery ad
             JOIN pedidos p ON p.id = ad.pedido_id
             JOIN usuarios u ON u.id = p.usuario_id
             LEFT JOIN zonas_delivery zd ON zd.id = p.zona_id
             WHERE ad.repartidor_id = :uid AND ad.estado IN ('asignado','recogido')
             ORDER BY ad.asignado_en DESC LIMIT 1"
        );
        $stmtAct->execute([':uid' => $uid]);
        $entregaActual = $stmtAct->fetch() ?: null;

        // Historial de entregas
        $stmtHist = $db->prepare(
            "SELECT p.codigo, p.total, p.direccion_entrega,
                    u.nombre, u.apellido, u.telefono,
                    zd.nombre AS zona_nombre,
                    ad.estado AS estado_asig, ad.asignado_en, ad.entregado_en
             FROM asignaciones_delivery ad
             JOIN pedidos p ON p.id = ad.pedido_id
             JOIN usuarios u ON u.id = p.usuario_id
             LEFT JOIN zonas_delivery zd ON zd.id = p.zona_id
             WHERE ad.repartidor_id = :uid
             ORDER BY ad.asignado_en DESC LIMIT 30"
        );
        $stmtHist->execute([':uid' => $uid]);
        $histDelivery = $stmtHist->fetchAll();
    }

    require_once __DIR__ . '/includes/admin_header.php';
    ?>

    <div class="d-flex align-items-center gap-3 mb-4">
      <a href="<?= APP_URL ?>/admin/usuarios.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px">
        <i class="bi bi-arrow-left me-1"></i>Volver
      </a>
      <div>
        <h1 class="fw-800 mb-0" style="font-size:1.4rem;color:var(--verde-dark)">
          <?= limpiar($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
        </h1>
        <div style="font-size:.82rem;color:#6B7280"><?= limpiar($usuario['email']) ?></div>
      </div>
      <div class="ms-auto d-flex gap-2">
        <!-- Toggle activo -->
        <form method="POST">
          <?= campoCSRF() ?>
          <input type="hidden" name="accion_form"  value="toggle_activo">
          <input type="hidden" name="usuario_id"   value="<?= $uid ?>">
          <button type="submit"
                  class="btn btn-sm <?= $usuario['activo'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                  style="border-radius:8px"
                  onclick="return confirm('<?= $usuario['activo'] ? '¿Desactivar este usuario?' : '¿Activar este usuario?' ?>')">
            <i class="bi bi-<?= $usuario['activo'] ? 'person-dash' : 'person-check' ?> me-1"></i>
            <?= $usuario['activo'] ? 'Desactivar' : 'Activar' ?>
          </button>
        </form>
      </div>
    </div>

    <div class="row g-4">

      <!-- ── COLUMNA IZQUIERDA ──────────────────────────── -->
      <div class="col-lg-4">

        <!-- Perfil -->
        <div class="admin-card mb-3">
          <div class="admin-card-header">
            <span class="admin-card-title"><i class="bi bi-person-circle me-2"></i>Perfil</span>
            <span class="badge <?= $usuario['activo'] ? 'bg-success' : 'bg-danger' ?>"
                  style="border-radius:50px;font-size:.72rem">
              <?= $usuario['activo'] ? 'Activo' : 'Inactivo' ?>
            </span>
          </div>

          <div class="text-center mb-3">
            <div style="width:72px;height:72px;border-radius:50%;background:var(--verde);
                        color:white;display:flex;align-items:center;justify-content:center;
                        font-size:1.8rem;font-weight:800;margin:0 auto .75rem">
              <?= strtoupper(substr($usuario['nombre'], 0, 1)) ?>
            </div>
            <div class="fw-700" style="font-size:1rem">
              <?= limpiar($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
            </div>
            <div style="font-size:.8rem;color:#6B7280"><?= limpiar($usuario['email']) ?></div>
            <span class="badge mt-1 px-3"
                  style="border-radius:50px;font-size:.72rem;
                         background:<?= match($usuario['rol']) {
                             'admin'    => 'rgba(99,102,241,.15)',
                             'delivery' => 'rgba(14,165,233,.15)',
                             default    => 'rgba(26,107,58,.12)',
                         } ?>;
                         color:<?= match($usuario['rol']) {
                             'admin'    => '#4338CA',
                             'delivery' => '#0284C7',
                             default    => 'var(--verde-dark)',
                         } ?>">
              <?= ucfirst($usuario['rol']) ?>
            </span>
          </div>

          <table class="table table-sm" style="font-size:.82rem">
            <tr><th class="text-muted fw-500 border-0" style="width:38%">Teléfono</th>
                <td class="border-0"><?= $usuario['telefono'] ? limpiar($usuario['telefono']) : '<span class="text-muted">—</span>' ?></td></tr>
            <tr><th class="text-muted fw-500">CI</th>
                <td><?= $usuario['ci'] ? limpiar($usuario['ci']) : '<span class="text-muted">—</span>' ?></td></tr>
            <tr><th class="text-muted fw-500">Ciudad</th>
                <td><?= limpiar($usuario['ciudad'] ?? 'Cobija') ?></td></tr>
            <tr><th class="text-muted fw-500">Dirección</th>
                <td><?= $usuario['direccion'] ? limpiar(truncar($usuario['direccion'], 50)) : '<span class="text-muted">—</span>' ?></td></tr>
            <tr><th class="text-muted fw-500">Registrado</th>
                <td><?= date('d/m/Y', strtotime($usuario['creado_en'])) ?></td></tr>
          </table>
        </div>

        <!-- Cambiar rol -->
        <div class="admin-card mb-3">
          <div class="admin-card-header">
            <span class="admin-card-title"><i class="bi bi-shield me-2"></i>Rol del usuario</span>
          </div>
          <form method="POST">
            <?= campoCSRF() ?>
            <input type="hidden" name="accion_form" value="cambiar_rol">
            <input type="hidden" name="usuario_id"  value="<?= $uid ?>">
            <div class="mb-3">
              <select name="rol_id" class="form-select form-select-sm">
                <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= (int)$usuario['rol_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                  <?= ucfirst(limpiar($r['nombre'])) ?> — <?= limpiar($r['descripcion'] ?? '') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn-verde btn btn-sm w-100"
                    onclick="return confirm('¿Confirmas el cambio de rol?')">
              <i class="bi bi-check-lg me-1"></i>Guardar rol
            </button>
          </form>
        </div>

        <!-- Puntos -->
        <div class="admin-card">
          <div class="admin-card-header">
            <span class="admin-card-title"><i class="bi bi-star-fill text-warning me-2"></i>Puntos</span>
            <span class="fw-800" style="font-size:1.1rem;color:var(--verde-dark)">
              <?= number_format((int)$usuario['puntos']) ?>
            </span>
          </div>

          <form method="POST">
            <?= campoCSRF() ?>
            <input type="hidden" name="accion_form" value="ajustar_puntos">
            <input type="hidden" name="usuario_id"  value="<?= $uid ?>">
            <div class="row g-2 mb-2">
              <div class="col-6">
                <select name="tipo_ajuste" class="form-select form-select-sm">
                  <option value="sumar">+ Sumar</option>
                  <option value="restar">– Restar</option>
                </select>
              </div>
              <div class="col-6">
                <input type="number" name="puntos_cantidad" class="form-control form-control-sm"
                       placeholder="Cantidad" min="1" required>
              </div>
            </div>
            <input type="text" name="nota_ajuste" class="form-control form-control-sm mb-2"
                   placeholder="Nota (opcional)" maxlength="200">
            <button type="submit" class="btn btn-sm btn-warning w-100 fw-600"
                    style="border-radius:8px">
              <i class="bi bi-star me-1"></i>Ajustar puntos
            </button>
          </form>
        </div>

      </div><!-- /col izquierda -->

      <!-- ── COLUMNA DERECHA ─────────────────────────────── -->
      <div class="col-lg-8">

      <?php if ($usuario['rol'] === 'delivery' && $statsDelivery): ?>

        <!-- ── STATS REPARTIDOR ──────────────────────────── -->
        <div class="row g-3 mb-3">
          <?php
          $sdStats = [
            [(int)$statsDelivery['completadas'],                      'Entregas completadas', 'bi-house-check-fill',  '#22c55e', 'rgba(34,197,94,.1)'],
            [(int)$statsDelivery['este_mes'],                         'Este mes',             'bi-calendar-check',   '#6366F1', 'rgba(99,102,241,.1)'],
            [(int)$statsDelivery['en_curso'],                         'En curso ahora',       'bi-truck',            '#0EA5E9', 'rgba(14,165,233,.1)'],
            ['Bs. '.number_format((float)$statsDelivery['bs_transportados'],0), 'Total transportado','bi-cash-stack','var(--verde-dark)','rgba(26,107,58,.1)'],
          ];
          foreach ($sdStats as [$val, $lbl, $ico, $col, $bg]): ?>
          <div class="col-6">
            <div class="admin-card p-3">
              <div class="d-flex align-items-center gap-3">
                <div style="width:42px;height:42px;border-radius:10px;background:<?= $bg ?>;
                            color:<?= $col ?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
                  <i class="bi <?= $ico ?>"></i>
                </div>
                <div>
                  <div style="font-size:1.4rem;font-weight:800;color:<?= $col ?>;line-height:1"><?= $val ?></div>
                  <div style="font-size:.72rem;color:#6B7280;margin-top:.15rem"><?= $lbl ?></div>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- ── ENTREGA ACTIVA ────────────────────────────── -->
        <?php if ($entregaActual): ?>
        <div class="admin-card mb-3" style="border-left:4px solid #0EA5E9">
          <div class="admin-card-header">
            <span class="admin-card-title">
              <i class="bi bi-truck me-2" style="color:#0EA5E9"></i>En camino ahora
            </span>
            <span class="badge" style="background:rgba(14,165,233,.15);color:#0284C7;border-radius:50px;font-size:.72rem">
              <i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i>Activo
            </span>
          </div>
          <div class="p-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="fw-700" style="font-size:.95rem;color:var(--verde-dark)">
                  <code><?= limpiar($entregaActual['codigo']) ?></code>
                </div>
                <div style="font-size:.82rem;color:#374151;margin-top:.3rem">
                  <i class="bi bi-person me-1"></i>
                  <?= limpiar($entregaActual['nombre'] . ' ' . $entregaActual['apellido']) ?>
                  <?php if ($entregaActual['telefono']): ?>
                  · <a href="https://wa.me/<?= preg_replace('/\D/', '', $entregaActual['telefono']) ?>"
                       target="_blank" style="color:#25D366">
                      <i class="bi bi-whatsapp"></i> <?= limpiar($entregaActual['telefono']) ?>
                    </a>
                  <?php endif; ?>
                </div>
                <?php if ($entregaActual['zona_nombre'] || $entregaActual['direccion_entrega']): ?>
                <div style="font-size:.82rem;color:#374151;margin-top:.2rem">
                  <i class="bi bi-geo-alt me-1"></i>
                  <?= limpiar($entregaActual['zona_nombre'] ?? '') ?>
                  <?= $entregaActual['direccion_entrega'] ? '— ' . limpiar(truncar($entregaActual['direccion_entrega'], 50)) : '' ?>
                </div>
                <?php endif; ?>
                <?php if ($entregaActual['referencia']): ?>
                <div style="font-size:.78rem;color:#6B7280;margin-top:.15rem">
                  <i class="bi bi-signpost me-1"></i><?= limpiar($entregaActual['referencia']) ?>
                </div>
                <?php endif; ?>
              </div>
              <div class="text-end">
                <div class="fw-800" style="font-size:1.1rem;color:var(--verde-dark)"><?= precio((float)$entregaActual['total']) ?></div>
                <div style="font-size:.72rem;color:#6B7280">
                  Asignado <?= tiempoRelativo($entregaActual['asignado_en']) ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="admin-card mb-3 text-center py-3" style="border:1.5px dashed #dee2e6">
          <i class="bi bi-truck" style="font-size:1.8rem;color:#dee2e6"></i>
          <div style="font-size:.83rem;color:#9CA3AF;margin-top:.4rem">Sin entrega activa en este momento</div>
        </div>
        <?php endif; ?>

        <!-- ── HISTORIAL DE ENTREGAS ─────────────────────── -->
        <div class="admin-card">
          <div class="admin-card-header">
            <span class="admin-card-title">
              <i class="bi bi-clock-history me-2"></i>Historial de entregas
            </span>
            <span class="badge bg-light text-dark border"><?= (int)$statsDelivery['total_entregas'] ?></span>
          </div>

          <?php if (empty($histDelivery)): ?>
          <div class="text-center py-4 text-muted">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3"></i>
            <div class="mt-2" style="font-size:.85rem">Sin entregas registradas</div>
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Zona</th>
                <th class="text-end">Total</th>
                <th>Estado</th>
                <th>Fecha</th>
              </tr></thead>
              <tbody>
              <?php foreach ($histDelivery as $hd):
                $bdgDel = match($hd['estado_asig']) {
                    'entregado' => ['success',   'Entregado'],
                    'recogido'  => ['info',      'Recogido'],
                    'cancelado' => ['danger',    'Cancelado'],
                    default     => ['warning',   'Asignado'],
                };
              ?>
              <tr>
                <td><code style="font-size:.78rem"><?= limpiar($hd['codigo']) ?></code></td>
                <td style="font-size:.82rem">
                  <?= limpiar($hd['nombre'] . ' ' . $hd['apellido']) ?>
                  <?php if ($hd['telefono']): ?>
                  <a href="https://wa.me/<?= preg_replace('/\D/', '', $hd['telefono']) ?>"
                     target="_blank" style="color:#25D366;margin-left:.3rem">
                    <i class="bi bi-whatsapp"></i>
                  </a>
                  <?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#6B7280"><?= limpiar($hd['zona_nombre'] ?? '—') ?></td>
                <td class="text-end fw-600" style="font-size:.83rem"><?= precio((float)$hd['total']) ?></td>
                <td>
                  <span class="badge bg-<?= $bdgDel[0] ?>" style="font-size:.68rem;border-radius:50px">
                    <?= $bdgDel[1] ?>
                  </span>
                </td>
                <td style="font-size:.75rem;color:#6B7280;white-space:nowrap">
                  <?= date('d/m/Y', strtotime($hd['asignado_en'])) ?>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

      <?php else: ?>

        <!-- Pedidos -->
        <div class="admin-card mb-3">
          <div class="admin-card-header">
            <span class="admin-card-title">
              <i class="bi bi-bag-check me-2"></i>Pedidos
            </span>
            <span class="badge bg-light text-dark border"><?= count($pedidosU) ?></span>
          </div>

          <?php if (empty($pedidosU)): ?>
          <div class="text-center py-4 text-muted">
            <i class="bi bi-bag-x" style="font-size:2rem;opacity:.3"></i>
            <div class="mt-2" style="font-size:.85rem">Sin pedidos todavía</div>
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr>
                <th>Código</th>
                <th>Total</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th></th>
              </tr></thead>
              <tbody>
              <?php foreach ($pedidosU as $p):
                $badgeEstado = match($p['estado']) {
                    'pendiente'       => 'warning',
                    'confirmado'      => 'info',
                    'en_preparacion'  => 'primary',
                    'en_camino'       => 'info',
                    'entregado'       => 'success',
                    'cancelado'       => 'danger',
                    default           => 'secondary',
                };
              ?>
              <tr>
                <td><code style="font-size:.8rem"><?= limpiar($p['codigo']) ?></code></td>
                <td class="fw-600"><?= precio((float)$p['total']) ?></td>
                <td>
                  <span class="badge bg-<?= $badgeEstado ?>" style="font-size:.72rem;border-radius:50px">
                    <?= str_replace('_', ' ', ucfirst($p['estado'])) ?>
                  </span>
                </td>
                <td style="font-size:.8rem;color:#6B7280">
                  <?= date('d/m/Y', strtotime($p['creado_en'])) ?>
                </td>
                <td>
                  <a href="<?= APP_URL ?>/admin/pedidos.php?accion=detalle&id=<?= (int)$p['id'] ?>"
                     class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:.2rem .5rem;border-radius:6px">
                    Ver <i class="bi bi-arrow-right"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Historial de puntos -->
        <div class="admin-card">
          <div class="admin-card-header">
            <span class="admin-card-title">
              <i class="bi bi-clock-history me-2"></i>Historial de puntos
            </span>
          </div>

          <?php if (empty($histPuntos)): ?>
          <div class="text-center py-4 text-muted">
            <i class="bi bi-star" style="font-size:2rem;opacity:.3"></i>
            <div class="mt-2" style="font-size:.85rem">Sin movimientos de puntos</div>
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr>
                <th>Tipo</th>
                <th>Puntos</th>
                <th>Saldo</th>
                <th>Descripción</th>
                <th>Fecha</th>
              </tr></thead>
              <tbody>
              <?php foreach ($histPuntos as $mv): ?>
              <tr>
                <td>
                  <?php $bTipo = match($mv['tipo']) {
                      'ganado'     => ['success', 'plus-circle-fill'],
                      'canjeado'   => ['danger',  'dash-circle-fill'],
                      'ajuste'     => ['warning',  'pencil-fill'],
                      'vencimiento'=> ['secondary','x-circle-fill'],
                      default      => ['secondary','circle'],
                  }; ?>
                  <span class="badge bg-<?= $bTipo[0] ?>" style="font-size:.7rem;border-radius:50px">
                    <i class="bi bi-<?= $bTipo[1] ?> me-1"></i><?= ucfirst($mv['tipo']) ?>
                  </span>
                </td>
                <td class="fw-700 <?= $mv['cantidad'] >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= ($mv['cantidad'] >= 0 ? '+' : '') . number_format($mv['cantidad']) ?> pts
                </td>
                <td style="font-size:.8rem;color:#6B7280">
                  → <?= number_format($mv['saldo_despues']) ?> pts
                </td>
                <td style="font-size:.8rem"><?= limpiar(truncar($mv['descripcion'] ?? '', 45)) ?></td>
                <td style="font-size:.78rem;color:#6B7280">
                  <?= date('d/m/Y H:i', strtotime($mv['creado_en'])) ?>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

      <?php endif; ?><!-- /delivery else -->

      </div><!-- /col derecha -->
    </div>

    <?php
    require_once __DIR__ . '/includes/admin_footer.php';
    exit;
}

// ── LISTADO DE USUARIOS ────────────────────────────────────
$q       = trim($_GET['q']   ?? '');
$rol     = trim($_GET['rol'] ?? '');
$estado  = $_GET['estado']   ?? '';
$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$porPag  = 20;
$offset  = ($pagina - 1) * $porPag;

$where  = '1=1';
$params = [];

if ($q) {
    $where .= " AND (u.nombre LIKE :q OR u.apellido LIKE :q2 OR u.email LIKE :q3 OR u.telefono LIKE :q4)";
    $like  = "%$q%";
    $params[':q'] = $like; $params[':q2'] = $like;
    $params[':q3'] = $like; $params[':q4'] = $like;
}
if ($rol) {
    $where .= " AND r.nombre = :rol";
    $params[':rol'] = $rol;
}
if ($estado !== '') {
    $where .= " AND u.activo = :activo";
    $params[':activo'] = (int)$estado;
}

$countSql = "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE $where";
$stmtC    = $db->prepare($countSql);
$stmtC->execute($params);
$totalRegs = (int)$stmtC->fetchColumn();
$totalPags = (int)ceil($totalRegs / $porPag);

$listaSql = "SELECT u.*, r.nombre AS rol
             FROM   usuarios u JOIN roles r ON r.id = u.rol_id
             WHERE  $where
             ORDER  BY u.creado_en DESC
             LIMIT  :lim OFFSET :off";
$stmtL = $db->prepare($listaSql);
foreach ($params as $k => $v) $stmtL->bindValue($k, $v);
$stmtL->bindValue(':lim', $porPag, PDO::PARAM_INT);
$stmtL->bindValue(':off', $offset, PDO::PARAM_INT);
$stmtL->execute();
$usuarios = $stmtL->fetchAll();

// Stats rápidas
$statsRoles = $db->query(
    "SELECT r.nombre, COUNT(u.id) AS total
     FROM   roles r LEFT JOIN usuarios u ON u.rol_id = r.id
     GROUP  BY r.id"
)->fetchAll(PDO::FETCH_KEY_PAIR);

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- ── HEADER PÁGINA ─────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h1 class="fw-800 mb-0" style="font-size:1.4rem;color:var(--verde-dark)">
      <i class="bi bi-people me-2"></i>Usuarios
    </h1>
    <div style="font-size:.82rem;color:#6B7280">
      <?= number_format($totalRegs) ?> usuario<?= $totalRegs !== 1 ? 's' : '' ?> en total
    </div>
  </div>
  <button type="button" class="btn-verde btn btn-sm"
          data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
    <i class="bi bi-person-plus me-1"></i>Nuevo usuario
  </button>
</div>

<!-- ── STATS ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $statData = [
      ['cliente',   'Clientes',    'bi-person-heart',     'rgba(26,107,58,.1)',  'var(--verde)'],
      ['admin',     'Admins',      'bi-shield-fill-check','rgba(99,102,241,.1)', '#6366F1'],
      ['delivery',  'Repartidores','bi-truck',            'rgba(14,165,233,.1)', '#0EA5E9'],
  ];
  foreach ($statData as [$key, $label, $icon, $bg, $color]):
    $val = $statsRoles[$key] ?? 0;
  ?>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
          <i class="bi <?= $icon ?>"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= number_format((int)$val) ?></div>
          <div class="stat-lbl"><?= $label ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── FILTROS ────────────────────────────────────────────── -->
<div class="admin-card mb-3">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-12 col-md-5">
      <label class="form-label mb-1" style="font-size:.78rem">Buscar</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control"
               placeholder="Nombre, apellido, email o teléfono…"
               value="<?= limpiar($q) ?>">
      </div>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label mb-1" style="font-size:.78rem">Rol</label>
      <select name="rol" class="form-select form-select-sm">
        <option value="">Todos</option>
        <option value="cliente"  <?= $rol === 'cliente'  ? 'selected' : '' ?>>Clientes</option>
        <option value="admin"    <?= $rol === 'admin'    ? 'selected' : '' ?>>Admins</option>
        <option value="delivery" <?= $rol === 'delivery' ? 'selected' : '' ?>>Repartidores</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label mb-1" style="font-size:.78rem">Estado</label>
      <select name="estado" class="form-select form-select-sm">
        <option value="">Todos</option>
        <option value="1" <?= $estado === '1' ? 'selected' : '' ?>>Activos</option>
        <option value="0" <?= $estado === '0' ? 'selected' : '' ?>>Inactivos</option>
      </select>
    </div>
    <div class="col-12 col-md-3 d-flex gap-2">
      <button type="submit" class="btn-verde btn btn-sm flex-grow-1">
        <i class="bi bi-search me-1"></i>Filtrar
      </button>
      <a href="<?= APP_URL ?>/admin/usuarios.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x-lg"></i>
      </a>
    </div>
  </form>
</div>

<!-- ── TABLA ──────────────────────────────────────────────── -->
<div class="admin-table">
  <?php if (empty($usuarios)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-people" style="font-size:3rem;opacity:.2"></i>
    <h5 class="mt-3">No se encontraron usuarios</h5>
    <p style="font-size:.85rem">Prueba ajustando los filtros de búsqueda.</p>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr>
        <th>Usuario</th>
        <th>Teléfono</th>
        <th>Rol</th>
        <th class="text-end">Puntos</th>
        <th>Estado</th>
        <th>Registrado</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): ?>
      <tr>
        <td>
          <div class="d-flex align-items-center gap-2">
            <div style="width:36px;height:36px;border-radius:50%;background:var(--verde);
                        color:white;display:flex;align-items:center;justify-content:center;
                        font-weight:700;font-size:.9rem;flex-shrink:0">
              <?= strtoupper(substr($u['nombre'], 0, 1)) ?>
            </div>
            <div>
              <div class="fw-600" style="font-size:.88rem">
                <?= limpiar($u['nombre'] . ' ' . $u['apellido']) ?>
              </div>
              <div style="font-size:.75rem;color:#6B7280"><?= limpiar($u['email']) ?></div>
            </div>
          </div>
        </td>
        <td style="font-size:.83rem">
          <?= $u['telefono'] ? limpiar($u['telefono']) : '<span class="text-muted">—</span>' ?>
        </td>
        <td>
          <?php $badgeRol = match($u['rol']) {
              'admin'    => ['rgba(99,102,241,.15)',  '#4338CA'],
              'delivery' => ['rgba(14,165,233,.15)', '#0284C7'],
              default    => ['rgba(26,107,58,.12)',   'var(--verde-dark)'],
          }; ?>
          <span class="badge px-2 py-1"
                style="border-radius:50px;font-size:.7rem;
                       background:<?= $badgeRol[0] ?>;color:<?= $badgeRol[1] ?>">
            <?= ucfirst($u['rol']) ?>
          </span>
        </td>
        <td class="text-end fw-600" style="font-size:.85rem;color:var(--verde-dark)">
          <i class="bi bi-star-fill text-warning" style="font-size:.7rem"></i>
          <?= number_format((int)$u['puntos']) ?>
        </td>
        <td>
          <span class="badge <?= $u['activo'] ? 'bg-success' : 'bg-danger' ?>"
                style="font-size:.7rem;border-radius:50px">
            <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
          </span>
        </td>
        <td style="font-size:.8rem;color:#6B7280">
          <?= date('d/m/Y', strtotime($u['creado_en'])) ?>
        </td>
        <td>
          <a href="<?= APP_URL ?>/admin/usuarios.php?accion=detalle&id=<?= (int)$u['id'] ?>"
             class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem">
            Ver <i class="bi bi-arrow-right"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <?php if ($totalPags > 1): ?>
  <div class="d-flex justify-content-center py-3">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php
      $urlBase = APP_URL . '/admin/usuarios.php?' . http_build_query(array_filter(['q' => $q, 'rol' => $rol, 'estado' => $estado]));
      for ($i = 1; $i <= $totalPags; $i++): ?>
      <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
        <a class="page-link" href="<?= $urlBase ?>&pagina=<?= $i ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL — Nuevo usuario
     ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalNuevoUsuario" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
    <div class="modal-content" style="border-radius:14px;border:none">

      <div class="modal-header" style="background:var(--verde-dark);color:white;border-radius:14px 14px 0 0;padding:1.1rem 1.5rem">
        <h5 class="modal-title fw-700 mb-0">
          <i class="bi bi-person-plus me-2"></i>Crear nuevo usuario
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <?= campoCSRF() ?>
        <input type="hidden" name="accion_form" value="crear_usuario">

        <div class="modal-body p-4">

          <!-- Nombre + Apellido -->
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-600" style="font-size:.82rem">Nombre <span class="text-danger">*</span></label>
              <input type="text" name="nombre" class="form-control form-control-sm"
                     placeholder="Juan" required maxlength="80">
            </div>
            <div class="col-6">
              <label class="form-label fw-600" style="font-size:.82rem">Apellido <span class="text-danger">*</span></label>
              <input type="text" name="apellido" class="form-control form-control-sm"
                     placeholder="Mamani" required maxlength="80">
            </div>
          </div>

          <!-- Email -->
          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.82rem">Email <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" name="email" class="form-control"
                     placeholder="correo@ejemplo.com" required>
            </div>
          </div>

          <!-- Teléfono + CI -->
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-600" style="font-size:.82rem">Teléfono</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                <input type="tel" name="telefono" class="form-control"
                       placeholder="7XXXXXXX" maxlength="20">
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-600" style="font-size:.82rem">Carnet (CI)</label>
              <input type="text" name="ci" class="form-control form-control-sm"
                     placeholder="12345678" maxlength="20">
            </div>
          </div>

          <!-- Contraseña -->
          <div class="mb-3">
            <label class="form-label fw-600" style="font-size:.82rem">Contraseña <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="password" id="pwdNuevoUser" class="form-control"
                     placeholder="Mínimo 6 caracteres" required minlength="6">
              <button type="button" class="input-group-text bg-white"
                      onclick="const i=document.getElementById('pwdNuevoUser');i.type=i.type==='password'?'text':'password'">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>

          <!-- Rol -->
          <div class="mb-1">
            <label class="form-label fw-600" style="font-size:.82rem">Rol <span class="text-danger">*</span></label>
            <div class="d-flex gap-2">

              <label class="rol-option flex-fill" style="cursor:pointer">
                <input type="radio" name="rol_id" value="2" checked class="d-none">
                <div class="rol-card" data-rol="2">
                  <i class="bi bi-person-heart" style="font-size:1.4rem;color:var(--verde)"></i>
                  <div class="fw-700" style="font-size:.82rem;margin-top:.3rem">Cliente</div>
                  <div style="font-size:.7rem;color:#6B7280">Compras online</div>
                </div>
              </label>

              <label class="rol-option flex-fill" style="cursor:pointer">
                <input type="radio" name="rol_id" value="3" class="d-none">
                <div class="rol-card" data-rol="3">
                  <i class="bi bi-truck" style="font-size:1.4rem;color:#0EA5E9"></i>
                  <div class="fw-700" style="font-size:.82rem;margin-top:.3rem">Repartidor</div>
                  <div style="font-size:.7rem;color:#6B7280">Gestión de delivery</div>
                </div>
              </label>

              <label class="rol-option flex-fill" style="cursor:pointer">
                <input type="radio" name="rol_id" value="1" class="d-none">
                <div class="rol-card" data-rol="1">
                  <i class="bi bi-shield-fill-check" style="font-size:1.4rem;color:#6366F1"></i>
                  <div class="fw-700" style="font-size:.82rem;margin-top:.3rem">Admin</div>
                  <div style="font-size:.7rem;color:#6B7280">Acceso total</div>
                </div>
              </label>

            </div>
          </div>

        </div><!-- /modal-body -->

        <div class="modal-footer" style="border-top:1px solid #F0F2F5;padding:1rem 1.5rem">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
            Cancelar
          </button>
          <button type="submit" class="btn-verde btn btn-sm px-4">
            <i class="bi bi-person-check me-1"></i>Crear usuario
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<style>
.rol-card {
  border: 2px solid #dee2e6;
  border-radius: 10px;
  padding: .75rem .5rem;
  text-align: center;
  transition: all .18s;
  background: white;
}
.rol-card:hover { border-color: var(--verde); }
input[type="radio"]:checked + .rol-card {
  border-color: var(--verde-dark);
  background: rgba(26,107,58,.06);
}
</style>

<script>
// Sincronizar selección visual de rol
document.querySelectorAll('.rol-option input[type="radio"]').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.rol-card').forEach(c => c.style.borderColor = '');
  });
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
