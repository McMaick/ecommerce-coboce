<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$tituloAdmin = 'Gestión de Categorías';
$paginaAdmin = 'categorias.php';
$db          = Database::getConnection();
$accion      = $_GET['accion'] ?? 'listar';

// Iconos disponibles para asignar a categorías
const ICONOS_DISPONIBLES = [
    'bi-square-fill'                 => 'Cuadrado (Pisos)',
    'bi-layout-text-window-reverse'  => 'Ventana (Revestimientos)',
    'bi-gem'                         => 'Gema (Porcelánato)',
    'bi-grid-3x3-gap-fill'           => 'Mosaico',
    'bi-tools'                       => 'Herramientas (Accesorios)',
    'bi-house-fill'                  => 'Casa',
    'bi-bricks'                      => 'Ladrillos',
    'bi-star-fill'                   => 'Estrella',
    'bi-tag-fill'                    => 'Etiqueta',
    'bi-box-seam'                    => 'Caja',
];

// ══════════════════════════════════════════════════════════════
// POST — crear / editar
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido.');
        redirigir(APP_URL . '/admin/categorias.php');
    }

    $accionPost = $_POST['accion_form'] ?? '';
    $idEdit     = limpiarInt($_POST['id'] ?? 0);
    $nombre     = trim($_POST['nombre']      ?? '');
    $descripcion= trim($_POST['descripcion'] ?? '');

    $errores = [];
    if (!$nombre) $errores[] = 'El nombre de la categoría es obligatorio.';
    if (mb_strlen($nombre) > 80) $errores[] = 'El nombre no puede superar 80 caracteres.';

    // Verificar nombre duplicado
    if (!$errores) {
        $stmtDup = $db->prepare(
            "SELECT id FROM categorias WHERE nombre = :n AND id != :id LIMIT 1"
        );
        $stmtDup->execute([':n' => $nombre, ':id' => $idEdit]);
        if ($stmtDup->fetchColumn()) {
            $errores[] = 'Ya existe una categoría con ese nombre.';
        }
    }

    if (empty($errores)) {
        if ($accionPost === 'crear') {
            $db->prepare(
                "INSERT INTO categorias (nombre, descripcion, activo) VALUES (:n, :d, 1)"
            )->execute([':n' => $nombre, ':d' => $descripcion ?: null]);
            flash('exito', 'Categoría «' . $nombre . '» creada correctamente.');
        } elseif ($accionPost === 'editar' && $idEdit) {
            $db->prepare(
                "UPDATE categorias SET nombre = :n, descripcion = :d WHERE id = :id"
            )->execute([':n' => $nombre, ':d' => $descripcion ?: null, ':id' => $idEdit]);
            flash('exito', 'Categoría actualizada correctamente.');
        }
    } else {
        flash('error', implode('<br>', $errores));
    }

    redirigir(APP_URL . '/admin/categorias.php');
}

// ── TOGGLE ACTIVO ──────────────────────────────────────────
if ($accion === 'toggle' && $id = limpiarInt($_GET['id'] ?? 0)) {
    $stmt = $db->prepare("SELECT activo FROM categorias WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $actual = (int)$stmt->fetchColumn();
    $nuevo  = $actual ? 0 : 1;

    // No permitir desactivar si tiene productos activos
    if (!$nuevo) {
        $stmtP = $db->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id = :id AND activo = 1");
        $stmtP->execute([':id' => $id]);
        $nProds = (int)$stmtP->fetchColumn();

        if ($nProds > 0) {
            flash('error', "No puedes desactivar esta categoría porque tiene $nProds producto(s) activo(s). Desactívalos primero.");
            redirigir(APP_URL . '/admin/categorias.php');
        }
    }

    $db->prepare("UPDATE categorias SET activo = :a WHERE id = :id")
       ->execute([':a' => $nuevo, ':id' => $id]);
    flash($nuevo ? 'exito' : 'advertencia', 'Categoría ' . ($nuevo ? 'activada' : 'desactivada') . '.');
    redirigir(APP_URL . '/admin/categorias.php');
}

// ── DATOS ──────────────────────────────────────────────────
$categorias = $db->query(
    "SELECT c.*, COUNT(p.id) AS total_productos
     FROM   categorias c
     LEFT JOIN productos p ON p.categoria_id = c.id AND p.activo = 1
     GROUP  BY c.id
     ORDER  BY c.activo DESC, c.nombre ASC"
)->fetchAll();

$totalActivas   = count(array_filter($categorias, fn($c) => $c['activo']));
$totalInactivas = count($categorias) - $totalActivas;
$totalProductos = array_sum(array_column($categorias, 'total_productos'));

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h1 class="fw-800 mb-0" style="font-size:1.4rem;color:var(--verde-dark)">
      <i class="bi bi-tags me-2"></i>Categorías
    </h1>
    <div style="font-size:.82rem;color:#6B7280">
      <?= $totalActivas ?> activa<?= $totalActivas !== 1 ? 's' : '' ?>,
      <?= $totalProductos ?> producto<?= $totalProductos !== 1 ? 's' : '' ?> en total
    </div>
  </div>
  <button class="btn-verde btn" data-bs-toggle="modal" data-bs-target="#modalCategoria"
          onclick="abrirNueva()">
    <i class="bi bi-plus-lg me-1"></i>Nueva categoría
  </button>
</div>

<!-- ── STATS ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(26,107,58,.1);color:var(--verde)">
          <i class="bi bi-tags-fill"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= count($categorias) ?></div>
          <div class="stat-lbl">Categorías totales</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(25,135,84,.1);color:#198754">
          <i class="bi bi-check-circle-fill"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= $totalActivas ?></div>
          <div class="stat-lbl">Activas</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="d-flex align-items-center gap-3">
        <div class="stat-icon" style="background:rgba(99,102,241,.1);color:#6366F1">
          <i class="bi bi-box-seam"></i>
        </div>
        <div>
          <div class="stat-num" style="font-size:1.5rem"><?= number_format($totalProductos) ?></div>
          <div class="stat-lbl">Productos activos</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── GRID DE CATEGORÍAS ─────────────────────────────────── -->
<?php if (empty($categorias)): ?>
<div class="admin-card text-center py-5 text-muted">
  <i class="bi bi-tags" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
  <h5>No hay categorías todavía</h5>
  <p style="font-size:.85rem">Crea la primera categoría para organizar tus productos.</p>
  <button class="btn-verde btn mt-2" data-bs-toggle="modal" data-bs-target="#modalCategoria" onclick="abrirNueva()">
    <i class="bi bi-plus-lg me-1"></i>Crear categoría
  </button>
</div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($categorias as $cat):
    $iconos   = \Producto::ICONOS_CAT ?? [];
    $icono    = $iconos[$cat['nombre']] ?? 'bi-tag';
    $nProds   = (int)$cat['total_productos'];
    $activo   = (bool)$cat['activo'];
  ?>
  <div class="col-12 col-sm-6 col-lg-4">
    <div class="cat-admin-card <?= !$activo ? 'inactiva' : '' ?>">

      <!-- Cabecera con icono -->
      <div class="cat-admin-head">
        <div class="cat-admin-icon">
          <i class="bi <?= $icono ?>"></i>
        </div>
        <div class="flex-grow-1 min-w-0">
          <div class="cat-admin-nombre"><?= limpiar($cat['nombre']) ?></div>
          <div class="cat-admin-sub">
            <?= $nProds ?> producto<?= $nProds !== 1 ? 's' : '' ?> activo<?= $nProds !== 1 ? 's' : '' ?>
          </div>
        </div>
        <span class="badge <?= $activo ? 'bg-success' : 'bg-secondary' ?>"
              style="border-radius:50px;font-size:.68rem;flex-shrink:0">
          <?= $activo ? 'Activa' : 'Inactiva' ?>
        </span>
      </div>

      <!-- Descripción -->
      <div class="cat-admin-desc">
        <?= $cat['descripcion'] ? limpiar(truncar($cat['descripcion'], 90)) : '<span class="text-muted" style="font-size:.8rem">Sin descripción</span>' ?>
      </div>

      <!-- Barra de productos -->
      <?php
      $pct = $totalProductos > 0 ? min(100, round($nProds / $totalProductos * 100)) : 0;
      ?>
      <div class="cat-admin-bar-wrap">
        <div class="d-flex justify-content-between mb-1" style="font-size:.72rem;color:#6B7280">
          <span><?= $pct ?>% del catálogo</span>
          <span><?= $nProds ?> / <?= $totalProductos ?></span>
        </div>
        <div class="cat-admin-bar">
          <div class="cat-admin-bar-fill" style="width:<?= $pct ?>%"></div>
        </div>
      </div>

      <!-- Acciones -->
      <div class="cat-admin-actions">
        <button class="btn btn-sm btn-outline-primary flex-grow-1"
                style="border-radius:7px;font-size:.8rem"
                onclick="abrirEditar(<?= htmlspecialchars(json_encode([
                    'id'          => (int)$cat['id'],
                    'nombre'      => $cat['nombre'],
                    'descripcion' => $cat['descripcion'] ?? '',
                ]), ENT_QUOTES) ?>)">
          <i class="bi bi-pencil me-1"></i>Editar
        </button>

        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=<?= (int)$cat['id'] ?>"
           target="_blank"
           class="btn btn-sm btn-outline-secondary"
           style="border-radius:7px" title="Ver en tienda">
          <i class="bi bi-eye"></i>
        </a>

        <a href="<?= APP_URL ?>/admin/productos.php?categoria=<?= (int)$cat['id'] ?>"
           class="btn btn-sm btn-outline-secondary"
           style="border-radius:7px" title="Ver productos">
          <i class="bi bi-box-seam"></i>
        </a>

        <a href="?accion=toggle&id=<?= (int)$cat['id'] ?>"
           class="btn btn-sm <?= $activo ? 'btn-outline-danger' : 'btn-outline-success' ?>"
           style="border-radius:7px"
           title="<?= $activo ? 'Desactivar' : 'Activar' ?>"
           onclick="return confirm('<?= $activo
               ? '¿Desactivar la categoría «' . $cat['nombre'] . '»?'
               : '¿Activar la categoría «' . $cat['nombre'] . '»?' ?>')">
          <i class="bi bi-<?= $activo ? 'slash-circle' : 'check-circle' ?>"></i>
        </a>
      </div>

    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>


<!-- ════ MODAL CREAR / EDITAR ════ -->
<div class="modal fade" id="modalCategoria" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content" style="border-radius:14px;border:none">

      <div class="modal-header" style="background:var(--verde-dark);color:white;border-radius:14px 14px 0 0">
        <h5 class="modal-title fw-700" id="modalTitulo">Nueva categoría</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <?= campoCSRF() ?>
        <input type="hidden" name="accion_form" id="accionForm" value="crear">
        <input type="hidden" name="id"          id="catId"     value="">

        <div class="modal-body p-4">

          <div class="mb-3">
            <label class="form-label">
              Nombre de la categoría <span class="text-danger">*</span>
            </label>
            <input type="text" name="nombre" id="fNombre" class="form-control"
                   placeholder="Ej: Porcelánato, Pisos, Accesorios…"
                   maxlength="80" required autofocus>
            <div class="form-text">Máximo 80 caracteres. No puede repetirse.</div>
          </div>

          <div class="mb-1">
            <label class="form-label">Descripción <small class="text-muted">(opcional)</small></label>
            <textarea name="descripcion" id="fDesc" class="form-control" rows="3"
                      placeholder="Describe brevemente los productos de esta categoría…"
                      maxlength="500"></textarea>
          </div>

        </div>

        <div class="modal-footer" style="background:#F8F9FA;border-radius:0 0 14px 14px">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-verde btn px-4" id="btnGuardar">
            <i class="bi bi-plus-lg me-1"></i>Crear categoría
          </button>
        </div>
      </form>

    </div>
  </div>
</div>


<style>
.cat-admin-card {
  background: white;
  border-radius: 12px;
  border: 1px solid #dee2e6;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: .85rem;
  transition: box-shadow .22s, transform .22s;
}
.cat-admin-card:hover {
  box-shadow: 0 6px 24px rgba(0,0,0,.1);
  transform: translateY(-2px);
}
.cat-admin-card.inactiva {
  opacity: .6;
  background: #f8f9fa;
}
.cat-admin-head {
  display: flex;
  align-items: center;
  gap: .85rem;
}
.cat-admin-icon {
  width: 48px; height: 48px;
  border-radius: 10px;
  background: rgba(26,107,58,.1);
  color: var(--verde);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.35rem;
  flex-shrink: 0;
}
.cat-admin-nombre { font-weight: 700; font-size: .95rem; color: #1f2937; }
.cat-admin-sub    { font-size: .75rem; color: #6B7280; margin-top: .1rem; }
.cat-admin-desc   { font-size: .82rem; color: #4B5563; line-height: 1.5; min-height: 2.5rem; }

.cat-admin-bar-wrap { }
.cat-admin-bar {
  height: 6px;
  background: #e9ecef;
  border-radius: 50px;
  overflow: hidden;
}
.cat-admin-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--verde), var(--verde-light));
  border-radius: 50px;
  transition: width .6s ease;
}

.cat-admin-actions {
  display: flex;
  gap: .4rem;
  padding-top: .6rem;
  border-top: 1px solid #f0f2f5;
}
</style>

<?php
$scriptsAdmin = <<<JS
<script>
function abrirNueva() {
  document.getElementById('modalTitulo').textContent = 'Nueva categoría';
  document.getElementById('accionForm').value = 'crear';
  document.getElementById('catId').value = '';
  document.getElementById('fNombre').value = '';
  document.getElementById('fDesc').value = '';
  document.getElementById('btnGuardar').innerHTML = '<i class="bi bi-plus-lg me-1"></i>Crear categoría';
}

function abrirEditar(cat) {
  document.getElementById('modalTitulo').textContent = 'Editar categoría';
  document.getElementById('accionForm').value = 'editar';
  document.getElementById('catId').value = cat.id;
  document.getElementById('fNombre').value = cat.nombre || '';
  document.getElementById('fDesc').value = cat.descripcion || '';
  document.getElementById('btnGuardar').innerHTML = '<i class="bi bi-check-lg me-1"></i>Guardar cambios';
  new bootstrap.Modal(document.getElementById('modalCategoria')).show();
}
</script>
JS;

require_once __DIR__ . '/includes/admin_footer.php';
?>
