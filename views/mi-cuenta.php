<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

requiereLogin(APP_URL . '/views/mi-cuenta.php');

$db  = Database::getConnection();
$uid = (int) $_SESSION['usuario_id'];

// ── POST: actualizar perfil ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarToken();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'perfil') {
        $nombre   = trim($_POST['nombre']   ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $ci       = trim($_POST['ci']       ?? '');
        $direccion= trim($_POST['direccion']?? '');

        if (!$nombre || !$apellido) {
            guardarFlash('error', 'Nombre y apellido son obligatorios.');
        } else {
            $db->prepare(
                "UPDATE usuarios SET nombre=:n, apellido=:a, telefono=:t, ci=:c, direccion=:d
                 WHERE id=:id"
            )->execute([':n'=>$nombre,':a'=>$apellido,':t'=>$telefono?:null,
                        ':c'=>$ci?:null,':d'=>$direccion?:null,':id'=>$uid]);

            // Actualizar sesión
            $_SESSION['usuario_nombre']   = $nombre;
            $_SESSION['usuario_apellido'] = $apellido;
            guardarFlash('exito', 'Perfil actualizado correctamente.');
        }
        redirigir(APP_URL . '/views/mi-cuenta.php?tab=perfil');
    }

    if ($accion === 'password') {
        $actual   = $_POST['password_actual']   ?? '';
        $nueva    = $_POST['password_nueva']    ?? '';
        $confirma = $_POST['password_confirma'] ?? '';

        $usuario = $db->prepare("SELECT password_hash FROM usuarios WHERE id=:id");
        $usuario->execute([':id' => $uid]);
        $hash = $usuario->fetchColumn();

        if (!password_verify($actual, $hash)) {
            guardarFlash('error', 'La contraseña actual es incorrecta.');
        } elseif (strlen($nueva) < 8) {
            guardarFlash('error', 'La nueva contraseña debe tener al menos 8 caracteres.');
        } elseif ($nueva !== $confirma) {
            guardarFlash('error', 'Las contraseñas no coinciden.');
        } else {
            $db->prepare("UPDATE usuarios SET password_hash=:h WHERE id=:id")
               ->execute([':h' => password_hash($nueva, PASSWORD_DEFAULT), ':id' => $uid]);
            guardarFlash('exito', 'Contraseña cambiada correctamente.');
        }
        redirigir(APP_URL . '/views/mi-cuenta.php?tab=seguridad');
    }
}

// ── Cargar datos del usuario ──────────────────────────────────
$stmt = $db->prepare(
    "SELECT u.*, r.nombre AS rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=:id"
);
$stmt->execute([':id' => $uid]);
$usuario = $stmt->fetch();

if (!$usuario) {
    guardarFlash('error', 'Error de sesión.');
    redirigir(APP_URL . '/views/login.php');
}

// ── Puntos: historial ────────────────────────────────────────
$movimientos = $db->prepare(
    "SELECT mp.*, p.codigo AS pedido_codigo
     FROM movimientos_puntos mp
     LEFT JOIN pedidos p ON p.id = mp.pedido_id
     WHERE mp.usuario_id = :uid
     ORDER BY mp.creado_en DESC
     LIMIT 20"
);
$movimientos->execute([':uid' => $uid]);
$historialPuntos = $movimientos->fetchAll();

// ── Puntos: progreso hacia el siguiente tier ──────────────────
$puntosActuales = (int) $usuario['puntos'];
$tiers = [150, 300, 600, 900];
$siguienteTier  = null;
$tierAnterior   = 0;
foreach ($tiers as $t) {
    if ($puntosActuales < $t) { $siguienteTier = $t; break; }
    $tierAnterior = $t;
}
$pctTier = $siguienteTier
    ? min(100, round(($puntosActuales - $tierAnterior) / ($siguienteTier - $tierAnterior) * 100))
    : 100;

// ── Resumen de pedidos ────────────────────────────────────────
$resumen = $db->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN estado='entregado' THEN 1 ELSE 0 END) AS entregados,
            SUM(CASE WHEN estado NOT IN ('cancelado','entregado') THEN 1 ELSE 0 END) AS activos,
            COALESCE(SUM(CASE WHEN estado='entregado' THEN total ELSE 0 END),0) AS gastado
     FROM pedidos WHERE usuario_id=:uid"
);
$resumen->execute([':uid' => $uid]);
$stats = $resumen->fetch();

$tab = $_GET['tab'] ?? 'perfil';
if (!in_array($tab, ['perfil','puntos','seguridad'])) $tab = 'perfil';
// El fragmento #puntos del header también abre la tab correcta
if (isset($_GET['puntos'])) $tab = 'puntos';

$titulo = 'Mi cuenta';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
  .page-bar { background:white; border-bottom:1px solid var(--gris-borde,#dee2e6); }
  .page-bar .breadcrumb { font-size:.82rem; margin:0; }
  .page-bar .breadcrumb-item a { color:var(--verde,#1A6B3A); text-decoration:none; }

  /* Tabs */
  .cuenta-tab {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.55rem 1.2rem; border-radius:8px; font-size:.85rem; font-weight:600;
    border:1.5px solid #dee2e6; background:white; color:#6B7280;
    text-decoration:none; transition:all .18s;
  }
  .cuenta-tab:hover { border-color:var(--verde,#1A6B3A); color:var(--verde-dark,#145730); }
  .cuenta-tab.active { background:var(--verde,#1A6B3A); border-color:var(--verde,#1A6B3A); color:white; }

  /* Avatar */
  .cuenta-avatar {
    width:80px; height:80px; border-radius:50%;
    background:var(--verde,#1A6B3A); color:white;
    display:flex; align-items:center; justify-content:center;
    font-size:2.2rem; font-weight:800;
  }

  /* Stat cards de cuenta */
  .ct-stat {
    background:white; border:1px solid #dee2e6; border-radius:10px;
    padding:1rem 1.25rem; text-align:center;
  }
  .ct-stat-num { font-size:1.6rem; font-weight:800; }
  .ct-stat-lbl { font-size:.75rem; color:#6B7280; margin-top:.15rem; }

  /* Sección de formulario */
  .cuenta-card {
    background:white; border:1px solid #dee2e6; border-radius:12px;
    padding:1.5rem;
  }
  .cuenta-card-title {
    font-size:.82rem; font-weight:700; color:#6B7280; text-transform:uppercase;
    letter-spacing:.6px; margin-bottom:1.25rem; padding-bottom:.75rem;
    border-bottom:1px solid #F0F2F5;
  }
  .form-label { font-weight:500; font-size:.83rem; color:#374151; }
  .form-control, .form-select {
    font-size:.88rem; border:1.5px solid #dee2e6; border-radius:8px; padding:.55rem .85rem;
  }
  .form-control:focus {
    border-color:var(--verde,#1A6B3A);
    box-shadow:0 0 0 .18rem rgba(26,107,58,.15);
  }

  /* Puntos */
  .pts-big {
    font-size:3.5rem; font-weight:900; color:var(--verde-dark,#145730); line-height:1;
  }
  .pts-label { font-size:.88rem; color:#6B7280; margin-top:.3rem; }

  .tier-bar-wrap { height:10px; background:#F0F2F5; border-radius:5px; overflow:hidden; margin:.6rem 0; }
  .tier-bar-fill { height:100%; background:linear-gradient(90deg,var(--verde,#1A6B3A),#22c55e); border-radius:5px; transition:width .6s ease; }

  .tier-card {
    border:1.5px solid #dee2e6; border-radius:10px; padding:.75rem 1rem;
    text-align:center; font-size:.78rem; transition:all .2s;
  }
  .tier-card.activo { border-color:#22c55e; background:#f0fdf4; }
  .tier-card.siguiente { border-color:var(--dorado,#C9A84C); background:#fefce8; }
  .tier-card .tier-pts { font-size:1.1rem; font-weight:800; }
  .tier-card .tier-val { font-size:.82rem; color:#6B7280; }

  /* Historial puntos */
  .pts-row { display:flex; align-items:center; gap:.75rem; padding:.7rem 0; border-bottom:1px solid #F0F2F5; }
  .pts-row:last-child { border-bottom:none; }
  .pts-row-icon { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
  .pts-row-cantidad { font-size:1rem; font-weight:800; min-width:60px; text-align:right; }

  /* Seguridad */
  .pass-strength { height:5px; border-radius:3px; background:#F0F2F5; margin-top:.4rem; overflow:hidden; }
  .pass-strength-fill { height:100%; border-radius:3px; transition:all .3s; }
</style>

<!-- Breadcrumb -->
<div class="page-bar">
  <div class="container py-2">
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Inicio</a></li>
      <li class="breadcrumb-item active">Mi cuenta</li>
    </ol></nav>
  </div>
</div>

<div class="container py-4">

  <!-- Cabecera perfil -->
  <div class="cuenta-card mb-4">
    <div class="d-flex align-items-center gap-4 flex-wrap">
      <div class="cuenta-avatar"><?= strtoupper(mb_substr($usuario['nombre'], 0, 1)) ?></div>
      <div class="flex-grow-1">
        <h2 style="font-size:1.4rem;font-weight:800;color:var(--verde-dark,#145730);margin-bottom:.2rem">
          <?= limpiar($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
        </h2>
        <div class="text-muted" style="font-size:.85rem"><?= limpiar($usuario['email']) ?></div>
        <div class="mt-1">
          <span style="background:#fefce8;border:1px solid #fde68a;color:#92400e;border-radius:20px;padding:.2rem .7rem;font-size:.76rem;font-weight:700">
            <i class="bi bi-star-fill text-warning me-1"></i><?= number_format($puntosActuales) ?> puntos
          </span>
          <span class="ms-2 text-muted" style="font-size:.78rem">
            Miembro desde <?= date('F Y', strtotime($usuario['creado_en'])) ?>
          </span>
        </div>
      </div>
      <a href="<?= APP_URL ?>/views/mis-pedidos.php"
         style="font-size:.82rem;font-weight:600;color:var(--verde,#1A6B3A);text-decoration:none">
        <i class="bi bi-bag-check me-1"></i>Mis pedidos
      </a>
    </div>
  </div>

  <!-- Stats resumen -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-sm-3">
      <div class="ct-stat">
        <div class="ct-stat-num" style="color:var(--verde-dark,#145730)"><?= (int)$stats['total'] ?></div>
        <div class="ct-stat-lbl">Pedidos totales</div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="ct-stat">
        <div class="ct-stat-num" style="color:#22c55e"><?= (int)$stats['entregados'] ?></div>
        <div class="ct-stat-lbl">Entregados</div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="ct-stat">
        <div class="ct-stat-num" style="color:#6366f1"><?= (int)$stats['activos'] ?></div>
        <div class="ct-stat-lbl">En proceso</div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="ct-stat">
        <div class="ct-stat-num" style="color:var(--dorado-dark,#A8882E);font-size:1.3rem">
          <?= number_format((float)$stats['gastado'], 0) ?>
        </div>
        <div class="ct-stat-lbl">Bs. invertidos</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="d-flex gap-2 flex-wrap mb-4">
    <a href="?tab=perfil"    class="cuenta-tab <?= $tab==='perfil'   ?'active':'' ?>" id="tab-puntos-anchor">
      <i class="bi bi-person"></i> Mi perfil
    </a>
    <a href="?tab=puntos"    class="cuenta-tab <?= $tab==='puntos'   ?'active':'' ?>">
      <i class="bi bi-star"></i> Mis puntos
    </a>
    <a href="?tab=seguridad" class="cuenta-tab <?= $tab==='seguridad'?'active':'' ?>">
      <i class="bi bi-shield-lock"></i> Seguridad
    </a>
  </div>

  <?php if ($tab === 'perfil'): ?>
  <!-- ══════════════════════════════════════════════
       TAB: PERFIL
  ══════════════════════════════════════════════ -->
  <div class="cuenta-card" style="max-width:640px">
    <div class="cuenta-card-title"><i class="bi bi-person me-2"></i>Información personal</div>
    <form method="post">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion" value="perfil">
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label">Nombre <span class="text-danger">*</span></label>
          <input type="text" name="nombre" class="form-control" required maxlength="80"
                 value="<?= limpiar($usuario['nombre']) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label">Apellido <span class="text-danger">*</span></label>
          <input type="text" name="apellido" class="form-control" required maxlength="80"
                 value="<?= limpiar($usuario['apellido']) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label">Teléfono</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
            <input type="tel" name="telefono" class="form-control" maxlength="20"
                   value="<?= limpiar($usuario['telefono'] ?? '') ?>">
          </div>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Carnet de identidad (CI)</label>
          <input type="text" name="ci" class="form-control" maxlength="20"
                 value="<?= limpiar($usuario['ci'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Dirección</label>
          <input type="text" name="direccion" class="form-control" maxlength="255"
                 placeholder="Av. / Calle, número, barrio…"
                 value="<?= limpiar($usuario['direccion'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Ciudad</label>
          <input type="text" class="form-control" value="<?= limpiar($usuario['ciudad'] ?? 'Cobija') ?>" disabled>
        </div>
        <div class="col-12 pt-1">
          <button type="submit"
                  style="background:var(--verde,#1A6B3A);color:white;border:none;border-radius:8px;padding:.6rem 1.5rem;font-weight:700;font-family:'Poppins',sans-serif;cursor:pointer;font-size:.9rem">
            <i class="bi bi-check-lg me-1"></i>Guardar cambios
          </button>
        </div>
      </div>
    </form>
  </div>

  <?php elseif ($tab === 'puntos'): ?>
  <!-- ══════════════════════════════════════════════
       TAB: PUNTOS
  ══════════════════════════════════════════════ -->
  <div class="row g-3" id="puntos">

    <!-- Balance y progreso -->
    <div class="col-12 col-lg-5">
      <div class="cuenta-card h-100">
        <div class="cuenta-card-title"><i class="bi bi-star me-2"></i>Tu saldo</div>
        <div class="text-center py-2">
          <div class="pts-big"><?= number_format($puntosActuales) ?></div>
          <div class="pts-label">puntos disponibles</div>
          <div class="mt-2" style="font-size:.85rem;color:var(--verde,#1A6B3A);font-weight:600">
            Equivalen a Bs. <?= number_format($puntosActuales * VALOR_PUNTO_BS, 2) ?> en descuento
          </div>
        </div>

        <!-- Progreso al siguiente tier -->
        <div class="mt-3 pt-3" style="border-top:1px solid #F0F2F5">
          <?php if ($siguienteTier): ?>
          <div class="d-flex justify-content-between" style="font-size:.78rem;color:#6B7280;margin-bottom:.3rem">
            <span><?= $puntosActuales ?> pts</span>
            <span><?= $siguienteTier ?> pts</span>
          </div>
          <div class="tier-bar-wrap">
            <div class="tier-bar-fill" style="width:<?= $pctTier ?>%"></div>
          </div>
          <div style="font-size:.78rem;color:#6B7280;text-align:center">
            Te faltan <strong><?= $siguienteTier - $puntosActuales ?> pts</strong> para el próximo canje de
            <strong>Bs. <?= number_format($siguienteTier * VALOR_PUNTO_BS, 0) ?></strong>
          </div>
          <?php else: ?>
          <div class="text-center" style="font-size:.82rem;color:#22c55e;font-weight:600">
            <i class="bi bi-trophy-fill me-1"></i>¡Alcanzaste todos los tiers de canje!
          </div>
          <?php endif; ?>
        </div>

        <!-- Tiers -->
        <div class="row g-2 mt-3">
          <?php
          $canjesInfo = [[150,5],[300,10],[600,20],[900,30]];
          foreach ($canjesInfo as [$pts, $desc]):
            $activo   = $puntosActuales >= $pts;
            $siguiente = $pts === $siguienteTier;
            $cls = $activo ? 'activo' : ($siguiente ? 'siguiente' : '');
          ?>
          <div class="col-6">
            <div class="tier-card <?= $cls ?>">
              <div class="tier-pts" style="color:<?= $activo?'#22c55e':($siguiente?'var(--dorado-dark,#A8882E)':'#9CA3AF') ?>"><?= $pts ?> pts</div>
              <div class="tier-val">= Bs. <?= $desc ?></div>
              <?php if ($activo): ?>
              <div style="font-size:.7rem;color:#22c55e;margin-top:.2rem"><i class="bi bi-check-circle-fill"></i> Disponible</div>
              <?php elseif ($siguiente): ?>
              <div style="font-size:.7rem;color:var(--dorado-dark,#A8882E);margin-top:.2rem"><i class="bi bi-arrow-up-circle"></i> Próximo</div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-3 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.78rem;color:#166534">
          <i class="bi bi-info-circle me-1"></i>
          Canjeas tus puntos en el paso 2 del checkout. Máximo 30% del total del pedido.
        </div>
      </div>
    </div>

    <!-- Historial -->
    <div class="col-12 col-lg-7">
      <div class="cuenta-card">
        <div class="cuenta-card-title"><i class="bi bi-clock-history me-2"></i>Historial de puntos</div>
        <?php if (empty($historialPuntos)): ?>
        <div class="text-center py-4 text-muted">
          <i class="bi bi-star" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
          Aún no tienes movimientos de puntos.
        </div>
        <?php else: ?>
        <div>
          <?php foreach ($historialPuntos as $mov):
            $ganado = $mov['cantidad'] > 0;
            $iconoMov = match($mov['tipo']) {
              'ganado'    => 'bi-star-fill',
              'canjeado'  => 'bi-gift',
              'ajuste'    => 'bi-sliders',
              'vencimiento'=> 'bi-clock',
              default     => 'bi-circle',
            };
            $colorMov = $ganado ? '#22c55e' : '#ef4444';
            $bgMov    = $ganado ? '#dcfce7' : '#fee2e2';
          ?>
          <div class="pts-row">
            <div class="pts-row-icon" style="background:<?= $bgMov ?>;color:<?= $colorMov ?>">
              <i class="bi <?= $iconoMov ?>"></i>
            </div>
            <div class="flex-grow-1">
              <div style="font-size:.84rem;font-weight:600;color:#374151">
                <?= limpiar($mov['descripcion'] ?: ucfirst($mov['tipo'])) ?>
              </div>
              <div style="font-size:.74rem;color:#9CA3AF">
                <?= date('d/m/Y H:i', strtotime($mov['creado_en'])) ?>
                <?php if ($mov['pedido_codigo']): ?>
                · <a href="<?= APP_URL ?>/views/mis-pedidos.php?id=<?= (int)$mov['pedido_id'] ?>"
                     style="color:var(--verde,#1A6B3A);text-decoration:none">
                    <?= limpiar($mov['pedido_codigo']) ?>
                  </a>
                <?php endif; ?>
              </div>
            </div>
            <div class="pts-row-cantidad" style="color:<?= $colorMov ?>">
              <?= $ganado ? '+' : '' ?><?= number_format($mov['cantidad']) ?>
            </div>
            <div style="font-size:.74rem;color:#9CA3AF;min-width:50px;text-align:right">
              → <?= number_format($mov['saldo_despues']) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /row puntos -->

  <?php elseif ($tab === 'seguridad'): ?>
  <!-- ══════════════════════════════════════════════
       TAB: SEGURIDAD
  ══════════════════════════════════════════════ -->
  <div class="cuenta-card" style="max-width:480px">
    <div class="cuenta-card-title"><i class="bi bi-shield-lock me-2"></i>Cambiar contraseña</div>
    <form method="post" id="formPass">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion" value="password">
      <div class="d-flex flex-column gap-3">
        <div>
          <label class="form-label">Contraseña actual <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="password_actual" id="passActual" class="form-control" required autocomplete="current-password">
            <button type="button" class="input-group-text bg-white" onclick="togglePass('passActual',this)">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
        <div>
          <label class="form-label">Nueva contraseña <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="password_nueva" id="passNueva" class="form-control" required
                   minlength="8" autocomplete="new-password" oninput="checkStrength(this.value)">
            <button type="button" class="input-group-text bg-white" onclick="togglePass('passNueva',this)">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="pass-strength mt-1">
            <div class="pass-strength-fill" id="strengthBar" style="width:0%;background:#ef4444"></div>
          </div>
          <div id="strengthTxt" style="font-size:.74rem;color:#9CA3AF;margin-top:.2rem">Mínimo 8 caracteres</div>
        </div>
        <div>
          <label class="form-label">Confirmar nueva contraseña <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="password_confirma" id="passConf" class="form-control" required
                   autocomplete="new-password">
            <button type="button" class="input-group-text bg-white" onclick="togglePass('passConf',this)">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div id="matchTxt" style="font-size:.74rem;margin-top:.2rem;display:none"></div>
        </div>
        <div>
          <button type="submit"
                  style="background:var(--verde,#1A6B3A);color:white;border:none;border-radius:8px;padding:.6rem 1.5rem;font-weight:700;font-family:'Poppins',sans-serif;cursor:pointer;font-size:.9rem">
            <i class="bi bi-shield-check me-1"></i>Cambiar contraseña
          </button>
        </div>
      </div>
    </form>

    <div class="mt-4 pt-3" style="border-top:1px solid #F0F2F5">
      <div style="font-size:.78rem;color:#9CA3AF">
        <i class="bi bi-info-circle me-1"></i>
        Si olvidaste tu contraseña actual, cierra sesión y usa la opción de recuperación en el login.
      </div>
    </div>
  </div>

  <?php endif; ?>

</div><!-- /container -->

<script>
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.querySelector('i').className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function checkStrength(val) {
  const bar = document.getElementById('strengthBar');
  const txt = document.getElementById('strengthTxt');
  let score = 0;
  if (val.length >= 8)  score++;
  if (val.length >= 12) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;

  const pct    = (score / 5) * 100;
  const colors = ['#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
  const labels = ['Muy débil','Débil','Regular','Fuerte','Muy fuerte'];
  bar.style.width   = pct + '%';
  bar.style.background = colors[score - 1] || '#ef4444';
  txt.textContent   = score > 0 ? labels[score - 1] : 'Mínimo 8 caracteres';
  txt.style.color   = colors[score - 1] || '#9CA3AF';

  // Verificar coincidencia
  const conf = document.getElementById('passConf');
  if (conf.value) checkMatch(val, conf.value);
}

function checkMatch(nueva, confirma) {
  const el = document.getElementById('matchTxt');
  el.style.display = '';
  if (nueva === confirma) {
    el.textContent  = '✓ Las contraseñas coinciden';
    el.style.color  = '#22c55e';
  } else {
    el.textContent  = '✗ Las contraseñas no coinciden';
    el.style.color  = '#ef4444';
  }
}

document.getElementById('passConf')?.addEventListener('input', function() {
  const nueva = document.getElementById('passNueva').value;
  checkMatch(nueva, this.value);
});

// Abrir tab puntos si viene del anchor #puntos en la URL
if (window.location.hash === '#puntos') {
  window.location.href = '?tab=puntos';
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
