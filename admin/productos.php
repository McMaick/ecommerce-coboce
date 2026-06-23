<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$tituloAdmin = 'Gestión de Productos';
$paginaAdmin = 'productos.php';
$modelo      = new Producto();
$db          = Database::getConnection();
$accion      = $_GET['accion'] ?? 'listar';

// ── PROCESAR FORMULARIO POST ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido.');
        redirigir(APP_URL . '/admin/productos.php');
    }

    $accionPost = $_POST['accion_form'] ?? '';
    $idEdit     = limpiarInt($_POST['id'] ?? 0);

    $datos = [
        'categoria_id'  => limpiarInt($_POST['categoria_id'] ?? 0),
        'codigo'        => trim($_POST['codigo'] ?? '') ?: null,
        'nombre'        => trim($_POST['nombre']        ?? ''),
        'descripcion'   => trim($_POST['descripcion']   ?? ''),
        'precio'        => limpiarFloat($_POST['precio']        ?? 0),
        'precio_oferta' => trim($_POST['precio_oferta'] ?? '') ?: null,
        'unidad'        => trim($_POST['unidad']        ?? 'm²'),
        'stock'         => limpiarInt($_POST['stock']   ?? 0),
        'stock_minimo'  => limpiarInt($_POST['stock_minimo'] ?? 5),
        'puntos_genera' => limpiarInt($_POST['puntos_genera'] ?? 0),
    ];

    // Validaciones básicas
    $errores = [];
    if (!$datos['nombre'])      $errores[] = 'El nombre es obligatorio.';
    if (!$datos['categoria_id'])$errores[] = 'Selecciona una categoría.';
    if ($datos['precio'] <= 0)  $errores[] = 'El precio debe ser mayor a 0.';

    // Subida de imagen
    if (!empty($_FILES['imagen']['name']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $ext      = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
        $permitidos = ['jpg','jpeg','png','webp'];
        $maxSize    = 3 * 1024 * 1024; // 3 MB

        if (!in_array($ext, $permitidos)) {
            $errores[] = 'Formato de imagen no permitido. Usa JPG, PNG o WEBP.';
        } elseif ($_FILES['imagen']['size'] > $maxSize) {
            $errores[] = 'La imagen supera el tamaño máximo de 3 MB.';
        } else {
            $dirDest = UPLOADS_PATH . '/productos/';
            if (!is_dir($dirDest)) mkdir($dirDest, 0755, true);

            $nombreArchivo = 'prod_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['imagen']['tmp_name'], $dirDest . $nombreArchivo)) {
                // Eliminar imagen anterior si es edición
                if ($idEdit) {
                    $stmtImg = $db->prepare("SELECT imagen FROM productos WHERE id=:id");
                    $stmtImg->execute([':id' => $idEdit]);
                    $imgVieja = $stmtImg->fetchColumn();
                    if ($imgVieja && file_exists(UPLOADS_PATH . '/' . $imgVieja)) {
                        @unlink(UPLOADS_PATH . '/' . $imgVieja);
                    }
                }
                $datos['imagen'] = 'productos/' . $nombreArchivo;
            } else {
                $errores[] = 'Error al guardar la imagen. Verifica permisos de uploads/.';
            }
        }
    }

    if (empty($errores)) {
        if ($accionPost === 'crear') {
            $nuevoId = $modelo->crear($datos);
            if ($nuevoId) {
                flash('exito', 'Producto «' . $datos['nombre'] . '» creado correctamente.');
            } else {
                flash('error', 'Error al crear el producto.');
            }
        } elseif ($accionPost === 'editar' && $idEdit) {
            $ok = $modelo->actualizar($idEdit, $datos);
            flash($ok ? 'exito' : 'error', $ok ? 'Producto actualizado correctamente.' : 'Error al actualizar.');
        }
    } else {
        flash('error', implode('<br>', $errores));
    }

    redirigir(APP_URL . '/admin/productos.php');
}

// ── ELIMINAR ───────────────────────────────────────────────
if ($accion === 'eliminar' && $id = limpiarInt($_GET['id'] ?? 0)) {
    $modelo->eliminar($id);
    flash('info', 'Producto desactivado.');
    redirigir(APP_URL . '/admin/productos.php');
}

// ── RESTAURAR ──────────────────────────────────────────────
if ($accion === 'restaurar' && $id = limpiarInt($_GET['id'] ?? 0)) {
    $db->prepare("UPDATE productos SET activo=1 WHERE id=:id")->execute([':id'=>$id]);
    flash('exito', 'Producto restaurado.');
    redirigir(APP_URL . '/admin/productos.php');
}

// ── DATOS PARA LISTADO ─────────────────────────────────────
$q         = trim($_GET['q']  ?? '');
$pagina    = max(1, limpiarInt($_GET['pagina'] ?? 1));
$porPagina = 15;
$productos = $modelo->listarAdmin($q, $pagina, $porPagina);
$total     = $modelo->contarAdmin($q);
$totalPag  = (int) ceil($total / $porPagina);
$categorias= $modelo->listarCategorias();

// Producto a editar
$prodEdit = null;
if ($accion === 'editar' && $idE = limpiarInt($_GET['id'] ?? 0)) {
    $prodEdit = $db->prepare("SELECT * FROM productos WHERE id=:id")->execute([':id'=>$idE]) ? null : null;
    $stmt = $db->prepare("SELECT * FROM productos WHERE id=:id");
    $stmt->execute([':id'=>$idE]);
    $prodEdit = $stmt->fetch();
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="fw-700 mb-0" style="color:var(--verde-dark)">Productos</h5>
    <small class="text-muted"><?= number_format($total) ?> en total</small>
  </div>
  <button class="btn-verde btn" data-bs-toggle="modal" data-bs-target="#modalProducto"
          onclick="abrirModalNuevo()">
    <i class="bi bi-plus-lg me-1"></i>Nuevo producto
  </button>
</div>

<!-- ── BUSCADOR ────────────────────────────────────────────── -->
<div class="admin-card mb-3">
  <form method="GET" class="d-flex gap-2">
    <div class="input-group">
      <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
      <input type="text" name="q" class="form-control" placeholder="Buscar por nombre o código…"
             value="<?= limpiar($q) ?>" autofocus>
    </div>
    <button type="submit" class="btn-verde btn px-4">Buscar</button>
    <?php if ($q): ?>
    <a href="<?= APP_URL ?>/admin/productos.php" class="btn btn-outline-secondary px-3">✕</a>
    <?php endif; ?>
  </form>
</div>

<!-- ── TABLA ────────────────────────────────────────────────── -->
<div class="admin-card">
  <?php if (empty($productos)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-box-seam" style="font-size:3rem;opacity:.3;display:block;margin-bottom:.75rem"></i>
    <?= $q ? 'No se encontraron resultados para "' . limpiar($q) . '".' : 'No hay productos. ¡Crea el primero!' ?>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table admin-table mb-0">
      <thead><tr>
        <th style="width:60px">Img</th>
        <th>Nombre</th>
        <th>Categoría</th>
        <th>Precio</th>
        <th>Stock</th>
        <th>Estado</th>
        <th style="width:130px">Acciones</th>
      </tr></thead>
      <tbody>
        <?php foreach ($productos as $p):
          $precioFinal = $p['precio_oferta'] ? (float)$p['precio_oferta'] : (float)$p['precio'];
          $stockClass  = (int)$p['stock'] === 0 ? 'stock-out' : ((int)$p['stock'] <= (int)$p['stock_minimo'] ? 'stock-low' : 'stock-ok');
          $iconProd    = Producto::ICONOS_CAT[$p['categoria']] ?? 'bi-image';
        ?>
        <tr>
          <!-- Imagen -->
          <td>
            <?php if ($p['imagen'] && file_exists(UPLOADS_PATH . '/' . $p['imagen'])): ?>
              <img src="<?= UPLOADS_URL . '/' . limpiar($p['imagen']) ?>"
                   style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6"
                   alt="">
            <?php else: ?>
              <div style="width:48px;height:48px;border-radius:8px;background:#f0f4f0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#adb5bd">
                <i class="bi <?= $iconProd ?>"></i>
              </div>
            <?php endif; ?>
          </td>
          <!-- Nombre -->
          <td>
            <div class="fw-600" style="font-size:.88rem"><?= limpiar(truncar($p['nombre'], 45)) ?></div>
            <?php if ($p['codigo']): ?>
            <code style="font-size:.72rem;color:#888"><?= limpiar($p['codigo']) ?></code>
            <?php endif; ?>
          </td>
          <!-- Categoría -->
          <td style="font-size:.85rem"><?= limpiar($p['categoria']) ?></td>
          <!-- Precio -->
          <td>
            <?php if ($p['precio_oferta']): ?>
              <div class="text-danger fw-700" style="font-size:.9rem">Bs. <?= number_format((float)$p['precio_oferta'],2) ?></div>
              <div class="text-muted text-decoration-line-through" style="font-size:.75rem">Bs. <?= number_format((float)$p['precio'],2) ?></div>
            <?php else: ?>
              <div class="fw-600" style="font-size:.88rem">Bs. <?= number_format((float)$p['precio'],2) ?></div>
            <?php endif; ?>
            <div style="font-size:.72rem;color:#888">/ <?= limpiar($p['unidad']) ?></div>
          </td>
          <!-- Stock -->
          <td>
            <span class="<?= $stockClass ?>">
              <?= (int)$p['stock'] ?> <?= limpiar($p['unidad']) ?>
            </span>
            <div style="font-size:.72rem;color:#aaa">mín. <?= (int)$p['stock_minimo'] ?></div>
          </td>
          <!-- Estado -->
          <td>
            <?php if ($p['activo']): ?>
              <span class="badge bg-success">Activo</span>
            <?php else: ?>
              <span class="badge bg-secondary">Inactivo</span>
            <?php endif; ?>
          </td>
          <!-- Acciones -->
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" style="border-radius:6px"
                      onclick="abrirModalEditar(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)"
                      title="Editar">
                <i class="bi bi-pencil"></i>
              </button>
              <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$p['id'] ?>"
                 target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius:6px" title="Ver en tienda">
                <i class="bi bi-eye"></i>
              </a>
              <?php if ($p['activo']): ?>
              <a href="?accion=eliminar&id=<?= (int)$p['id'] ?>"
                 class="btn btn-sm btn-outline-danger" style="border-radius:6px"
                 data-confirm="¿Desactivar «<?= limpiar($p['nombre']) ?>»?" title="Desactivar">
                <i class="bi bi-trash"></i>
              </a>
              <?php else: ?>
              <a href="?accion=restaurar&id=<?= (int)$p['id'] ?>"
                 class="btn btn-sm btn-outline-success" style="border-radius:6px" title="Restaurar">
                <i class="bi bi-arrow-counterclockwise"></i>
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <?php if ($totalPag > 1): ?>
  <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top" style="font-size:.82rem">
    <span class="text-muted">Página <?= $pagina ?> de <?= $totalPag ?></span>
    <div class="d-flex gap-1">
      <?php for ($i = 1; $i <= $totalPag; $i++): ?>
      <a href="?q=<?= urlencode($q) ?>&pagina=<?= $i ?>"
         class="btn btn-sm <?= $i===$pagina ? 'btn-verde btn' : 'btn-outline-secondary' ?>"
         style="border-radius:6px;min-width:32px"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; // /productos ?>
</div>


<!-- ════════ MODAL CREAR / EDITAR PRODUCTO ════════ -->
<div class="modal fade" id="modalProducto" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:12px;border:none">

      <div class="modal-header" style="background:var(--verde-dark);color:white;border-radius:12px 12px 0 0">
        <h5 class="modal-title fw-700" id="modalTitle">Nuevo producto</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" enctype="multipart/form-data" data-loading>
        <?= campoCSRF() ?>
        <input type="hidden" name="accion_form" id="accionForm" value="crear">
        <input type="hidden" name="id"          id="prodId"     value="">

        <div class="modal-body p-4">
          <div class="row g-3">

            <!-- Columna imagen -->
            <div class="col-12 col-md-3">
              <label class="form-label">Imagen del producto</label>
              <div class="img-preview-wrap" id="imgPreviewWrap" onclick="document.getElementById('inputImagen').click()">
                <div class="img-preview-placeholder" id="imgPlaceholder">
                  <i class="bi bi-cloud-upload"></i>
                  <span>Click para subir imagen</span>
                  <small class="d-block mt-1 text-muted" style="font-size:.7rem">JPG, PNG, WEBP · Máx 3MB</small>
                </div>
                <img id="imgPreview" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:8px">
              </div>
              <input type="file" name="imagen" id="inputImagen" accept=".jpg,.jpeg,.png,.webp" class="d-none"
                     onchange="previewImagen(this)">
              <div class="text-center mt-2">
                <small class="text-muted" style="font-size:.72rem">La imagen anterior se reemplazará</small>
              </div>
            </div>

            <!-- Columna datos -->
            <div class="col-12 col-md-9">
              <div class="row g-3">

                <div class="col-12">
                  <label class="form-label">Nombre del producto <span class="text-danger">*</span></label>
                  <input type="text" name="nombre" id="fNombre" class="form-control"
                         placeholder="Ej: Piso Porcelánato 60x60 Negro Pulido" required maxlength="150">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Categoría <span class="text-danger">*</span></label>
                  <select name="categoria_id" id="fCategoria" class="form-select" required>
                    <option value="">Seleccionar…</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"><?= limpiar($cat['nombre']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Código / SKU</label>
                  <input type="text" name="codigo" id="fCodigo" class="form-control"
                         placeholder="COB-001" maxlength="30">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Precio (Bs.) <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text">Bs.</span>
                    <input type="number" name="precio" id="fPrecio" class="form-control"
                           placeholder="0.00" min="0.01" step="0.01" required>
                  </div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Precio de oferta (Bs.)</label>
                  <div class="input-group">
                    <span class="input-group-text">Bs.</span>
                    <input type="number" name="precio_oferta" id="fOferta" class="form-control"
                           placeholder="Dejar vacío si no aplica" min="0.01" step="0.01">
                  </div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Unidad de venta</label>
                  <select name="unidad" id="fUnidad" class="form-select">
                    <option value="m²">m² (metro cuadrado)</option>
                    <option value="caja">Caja</option>
                    <option value="pieza">Pieza</option>
                    <option value="ml">ml (metro lineal)</option>
                    <option value="unidad">Unidad</option>
                    <option value="bolsa">Bolsa</option>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Stock actual</label>
                  <input type="number" name="stock" id="fStock" class="form-control"
                         placeholder="0" min="0" step="1" value="0">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Stock mínimo (alerta)</label>
                  <input type="number" name="stock_minimo" id="fStockMin" class="form-control"
                         placeholder="5" min="0" step="1" value="5">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Puntos que genera</label>
                  <div class="input-group">
                    <input type="number" name="puntos_genera" id="fPuntos" class="form-control"
                           placeholder="0" min="0" step="1" value="0">
                    <span class="input-group-text">pts / <?= '' ?><span id="unitLabel">unidad</span></span>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Descripción</label>
                  <textarea name="descripcion" id="fDesc" class="form-control" rows="3"
                            placeholder="Características, material, dimensiones, acabado…"></textarea>
                </div>

              </div><!-- /row inner -->
            </div><!-- /col-md-9 -->

          </div><!-- /row outer -->
        </div><!-- /modal-body -->

        <div class="modal-footer" style="background:#F8F9FA;border-radius:0 0 12px 12px">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-verde btn px-4" id="btnSubmit">
            <i class="bi bi-check-lg me-1"></i>Guardar producto
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<?php
$appUrl     = APP_URL;
$jsAutoOpen = ($accion === 'nuevo') ? 'true' : 'false';
$scriptsAdmin = <<<JS
<script>
function previewImagen(input) {
  if (!input.files?.[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('imgPreview').src = e.target.result;
    document.getElementById('imgPreview').style.display = 'block';
    document.getElementById('imgPlaceholder').style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}

function abrirModalNuevo() {
  document.getElementById('modalTitle').textContent = 'Nuevo producto';
  document.getElementById('accionForm').value = 'crear';
  document.getElementById('prodId').value = '';
  document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-plus-lg me-1"></i>Crear producto';
  ['fNombre','fCodigo','fPrecio','fOferta','fDesc'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.getElementById('fCategoria').value  = '';
  document.getElementById('fUnidad').value     = 'm²';
  document.getElementById('fStock').value      = '0';
  document.getElementById('fStockMin').value   = '5';
  document.getElementById('fPuntos').value     = '0';
  document.getElementById('imgPreview').style.display    = 'none';
  document.getElementById('imgPlaceholder').style.display = 'block';
  document.getElementById('inputImagen').value = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProducto')).show();
}

function abrirModalEditar(p) {
  document.getElementById('modalTitle').textContent = 'Editar producto';
  document.getElementById('accionForm').value = 'editar';
  document.getElementById('prodId').value     = p.id;
  document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check-lg me-1"></i>Actualizar producto';
  document.getElementById('fNombre').value    = p.nombre        || '';
  document.getElementById('fCodigo').value    = p.codigo        || '';
  document.getElementById('fCategoria').value = p.categoria_id  || '';
  document.getElementById('fPrecio').value    = p.precio        || '';
  document.getElementById('fOferta').value    = p.precio_oferta || '';
  document.getElementById('fUnidad').value    = p.unidad        || 'm²';
  document.getElementById('fStock').value     = p.stock         ?? 0;
  document.getElementById('fStockMin').value  = p.stock_minimo  ?? 5;
  document.getElementById('fPuntos').value    = p.puntos_genera ?? 0;
  document.getElementById('fDesc').value      = p.descripcion   || '';
  const prev   = document.getElementById('imgPreview');
  const holder = document.getElementById('imgPlaceholder');
  if (p.imagen) {
    prev.src = '$appUrl/uploads/' + p.imagen;
    prev.style.display   = 'block';
    holder.style.display = 'none';
  } else {
    prev.style.display   = 'none';
    holder.style.display = 'block';
  }
  document.getElementById('inputImagen').value = '';
  document.getElementById('unitLabel').textContent = p.unidad || 'unidad';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProducto')).show();
}

document.getElementById('fUnidad')?.addEventListener('change', function() {
  document.getElementById('unitLabel').textContent = this.value;
});

if ($jsAutoOpen) {
  window.addEventListener('load', () => abrirModalNuevo());
}
</script>
JS;

require_once __DIR__ . '/includes/admin_footer.php';
?>
