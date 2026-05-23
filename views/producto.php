<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$id     = limpiarInt($_GET['id'] ?? 0);
$modelo = new Producto();
$prod   = $modelo->buscarPorId($id);

if (!$prod) {
    flash('error', 'El producto no existe o no está disponible.');
    redirigir(APP_URL . '/views/catalogo.php');
}

$imagenes    = $modelo->imagenes((int)$prod['id']);
$relacionados= $modelo->relacionados((int)$prod['id'], (int)$prod['categoria_id'], 4);
$iconosCat   = Producto::ICONOS_CAT;
$iconProd    = $iconosCat[$prod['categoria']] ?? 'bi-image';

$precioFinal = $prod['precio_oferta'] ? (float)$prod['precio_oferta'] : (float)$prod['precio'];
$descuento   = $prod['precio_oferta']
    ? round((1 - $prod['precio_oferta'] / $prod['precio']) * 100)
    : 0;
$enStock     = (int)$prod['stock'] > 0;
$stockBajo   = $enStock && (int)$prod['stock'] <= (int)$prod['stock_minimo'];

// Step del input según unidad
$step = in_array($prod['unidad'], ['m²', 'm2']) ? '0.25' : '1';

$titulo      = $prod['nombre'];
$descripcion = truncar($prod['descripcion'] ?? $prod['nombre'], 160);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- ── BREADCRUMB ─────────────────────────────────────────── -->
<div style="background:white;border-bottom:1px solid var(--gris-borde)">
  <div class="container py-2">
    <nav><ol class="breadcrumb mb-0" style="font-size:.82rem">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php" class="text-verde">Inicio</a></li>
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/catalogo.php" class="text-verde">Catálogo</a></li>
      <li class="breadcrumb-item">
        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=<?= (int)$prod['categoria_id'] ?>" class="text-verde">
          <?= limpiar($prod['categoria']) ?>
        </a>
      </li>
      <li class="breadcrumb-item active"><?= limpiar(truncar($prod['nombre'], 50)) ?></li>
    </ol></nav>
  </div>
</div>

<div class="container py-4">

<!-- ════════════════ PRODUCTO PRINCIPAL ════════════════ -->
<div class="row g-4 mb-5">

  <!-- ── GALERÍA ──────────────────────────────────────── -->
  <div class="col-12 col-lg-5">
    <div style="position:sticky;top:90px">

      <!-- Imagen principal -->
      <div class="prod-main-img-wrap mb-3">
        <?php
        $imgPrincipal = $prod['imagen'] && file_exists(UPLOADS_PATH . '/' . $prod['imagen'])
            ? UPLOADS_URL . '/' . $prod['imagen'] : null;
        ?>
        <?php if ($imgPrincipal): ?>
          <img src="<?= $imgPrincipal ?>" alt="<?= limpiar($prod['nombre']) ?>"
               id="mainImg" class="prod-main-img">
        <?php else: ?>
          <div class="prod-main-img-placeholder">
            <i class="bi <?= $iconProd ?>"></i>
          </div>
        <?php endif; ?>

        <?php if ($descuento >= 5): ?>
        <span class="badge-oferta" style="font-size:.85rem;padding:.3rem .8rem">
          -<?= $descuento ?>%
        </span>
        <?php endif; ?>

        <?php if (!$enStock): ?>
        <div style="position:absolute;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;border-radius:12px">
          <span class="badge bg-secondary fs-6 px-4 py-2">Sin stock</span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Thumbnails galería -->
      <?php if (!empty($imagenes)): ?>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($imgPrincipal): ?>
        <img src="<?= $imgPrincipal ?>" class="prod-thumb active" onclick="cambiarImg(this.src)" alt="">
        <?php endif; ?>
        <?php foreach ($imagenes as $img):
          $url = UPLOADS_URL . '/' . $img['ruta'];
        ?>
        <img src="<?= $url ?>" class="prod-thumb" onclick="cambiarImg(this.src)"
             alt="<?= limpiar($prod['nombre']) ?>">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- ── INFO PRODUCTO ────────────────────────────────── -->
  <div class="col-12 col-lg-7">

    <!-- Categoría + badge stock -->
    <div class="d-flex align-items-center gap-2 mb-2">
      <a href="<?= APP_URL ?>/views/catalogo.php?categoria=<?= (int)$prod['categoria_id'] ?>"
         class="text-verde fw-600" style="font-size:.82rem;text-transform:uppercase;letter-spacing:.5px">
        <i class="bi <?= $iconProd ?> me-1"></i><?= limpiar($prod['categoria']) ?>
      </a>
      <?php if ($enStock): ?>
        <?php if ($stockBajo): ?>
        <span class="badge" style="background:var(--dorado);color:var(--verde-dark)">
          ¡Últimas <?= (int)$prod['stock'] ?> unidades!
        </span>
        <?php else: ?>
        <span class="badge bg-success">En stock</span>
        <?php endif; ?>
      <?php else: ?>
        <span class="badge bg-secondary">Sin stock</span>
      <?php endif; ?>
    </div>

    <!-- Nombre -->
    <h1 style="font-size:1.6rem;font-weight:700;color:var(--verde-dark);line-height:1.3" class="mb-2">
      <?= limpiar($prod['nombre']) ?>
    </h1>

    <!-- Código -->
    <?php if ($prod['codigo']): ?>
    <p class="text-muted mb-3" style="font-size:.82rem">
      <i class="bi bi-upc me-1"></i>Código: <strong><?= limpiar($prod['codigo']) ?></strong>
    </p>
    <?php endif; ?>

    <!-- Precio -->
    <div class="prod-precio-box mb-4">
      <?php if ($prod['precio_oferta']): ?>
      <div class="d-flex align-items-baseline gap-3">
        <span class="prod-precio-oferta"><?= precio($precioFinal) ?></span>
        <span class="prod-precio-tachado"><?= precio((float)$prod['precio']) ?></span>
        <span class="badge bg-danger">-<?= $descuento ?>% OFF</span>
      </div>
      <div style="font-size:.8rem;color:#dc3545;margin-top:.2rem">
        Ahorras <?= precio((float)$prod['precio'] - $precioFinal) ?> por <?= limpiar($prod['unidad']) ?>
      </div>
      <?php else: ?>
      <span class="prod-precio"><?= precio((float)$prod['precio']) ?></span>
      <?php endif; ?>
      <span class="prod-unidad">por <?= limpiar($prod['unidad']) ?></span>
    </div>

    <!-- Puntos -->
    <?php if ($prod['puntos_genera'] > 0): ?>
    <div class="prod-puntos-info mb-4">
      <i class="bi bi-star-fill me-2" style="color:var(--dorado)"></i>
      Gana <strong><?= (int)$prod['puntos_genera'] ?> puntos</strong>
      por cada <?= limpiar($prod['unidad']) ?> comprada
    </div>
    <?php endif; ?>

    <!-- Separador -->
    <hr style="border-color:var(--gris-borde)">

    <!-- Formulario agregar al carrito -->
    <form action="<?= APP_URL ?>/controllers/CarritoController.php" method="POST" class="mb-4">
      <input type="hidden" name="accion"   value="agregar">
      <input type="hidden" name="id"       value="<?= (int)$prod['id'] ?>">

      <!-- Cantidad -->
      <div class="mb-3">
        <label class="form-label fw-600" style="font-size:.88rem">
          Cantidad (<?= limpiar($prod['unidad']) ?>)
        </label>
        <div class="d-flex align-items-center gap-2">
          <div class="qty-control">
            <button type="button" class="qty-btn" data-qty-minus="qtyInput">−</button>
            <input type="number" id="qtyInput" name="cantidad"
                   class="qty-input form-control"
                   value="<?= $step === '0.25' ? '1.00' : '1' ?>"
                   min="<?= $step ?>" max="<?= (int)$prod['stock'] ?>"
                   step="<?= $step ?>">
            <button type="button" class="qty-btn" data-qty-plus="qtyInput">+</button>
          </div>
          <span class="text-muted" style="font-size:.82rem">
            Stock disponible: <strong><?= (int)$prod['stock'] ?></strong>
          </span>
        </div>
      </div>

      <!-- Botones -->
      <div class="d-flex flex-wrap gap-2">
        <?php if ($enStock): ?>
        <button type="submit" class="btn-coboce flex-grow-1"
                style="max-width:280px;border-radius:50px;padding:.75rem 1.5rem">
          <i class="bi bi-cart-plus me-2"></i>Agregar al carrito
        </button>
        <?php else: ?>
        <button type="button" class="btn-coboce flex-grow-1" disabled
                style="max-width:280px;border-radius:50px;opacity:.5;cursor:not-allowed">
          <i class="bi bi-x-circle me-2"></i>Sin stock disponible
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/views/carrito.php" class="btn px-4"
           style="border:2px solid var(--verde);color:var(--verde);border-radius:50px;font-weight:600">
          <i class="bi bi-cart3 me-1"></i>Ver carrito
        </a>
      </div>
    </form>

    <!-- Descripción -->
    <?php if ($prod['descripcion']): ?>
    <div class="prod-descripcion">
      <h6 class="fw-700 mb-2" style="color:var(--verde-dark)">Descripción</h6>
      <p class="text-muted" style="font-size:.92rem;line-height:1.8">
        <?= nl2br(limpiar($prod['descripcion'])) ?>
      </p>
    </div>
    <?php endif; ?>

    <!-- Info extra -->
    <div class="row g-3 mt-3">
      <div class="col-6">
        <div class="prod-info-badge">
          <i class="bi bi-truck text-verde fs-5"></i>
          <div>
            <div class="fw-600" style="font-size:.85rem">Delivery disponible</div>
            <small class="text-muted">En toda Cobija</small>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="prod-info-badge">
          <i class="bi bi-patch-check text-verde fs-5"></i>
          <div>
            <div class="fw-600" style="font-size:.85rem">Garantía COBOCE</div>
            <small class="text-muted">Calidad certificada</small>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="prod-info-badge">
          <i class="bi bi-arrow-return-left text-verde fs-5"></i>
          <div>
            <div class="fw-600" style="font-size:.85rem">Devoluciones</div>
            <small class="text-muted">Consulta condiciones</small>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="prod-info-badge">
          <i class="bi bi-whatsapp text-verde fs-5"></i>
          <div>
            <div class="fw-600" style="font-size:.85rem">Asesoramiento</div>
            <small class="text-muted">+591 7XX-XXXXX</small>
          </div>
        </div>
      </div>
    </div>

  </div>
</div><!-- /row producto -->

<!-- ════════════════ PRODUCTOS RELACIONADOS ════════════════ -->
<?php if (!empty($relacionados)): ?>
<section class="py-4 border-top">
  <h3 class="section-title mb-4">Productos relacionados</h3>
  <div class="row g-3">
    <?php foreach ($relacionados as $rel):
      $relDesc = $rel['precio_oferta']
          ? round((1 - $rel['precio_oferta'] / $rel['precio']) * 100) : 0;
    ?>
    <div class="col-6 col-sm-4 col-md-3">
      <div class="product-card h-100">
        <div class="product-img-wrap">
          <?php if ($rel['imagen'] && file_exists(UPLOADS_PATH . '/' . $rel['imagen'])): ?>
            <img src="<?= UPLOADS_URL . '/' . limpiar($rel['imagen']) ?>"
                 class="product-img" alt="<?= limpiar($rel['nombre']) ?>" loading="lazy">
          <?php else: ?>
            <div class="product-img-placeholder">
              <i class="bi <?= $iconosCat[$rel['categoria']] ?? 'bi-image' ?>"></i>
            </div>
          <?php endif; ?>
          <?php if ($relDesc >= 5): ?>
          <span class="badge-oferta">-<?= $relDesc ?>%</span>
          <?php endif; ?>
        </div>
        <div class="product-body">
          <div class="product-cat"><?= limpiar($rel['categoria']) ?></div>
          <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$rel['id'] ?>"
             class="product-name d-block text-decoration-none text-dark">
            <?= limpiar(truncar($rel['nombre'], 60)) ?>
          </a>
          <div class="d-flex align-items-baseline gap-2 mt-auto pt-1">
            <?php if ($rel['precio_oferta']): ?>
              <span class="product-price product-price-oferta"><?= precio((float)$rel['precio_oferta']) ?></span>
              <span class="product-price-old"><?= precio((float)$rel['precio']) ?></span>
            <?php else: ?>
              <span class="product-price"><?= precio((float)$rel['precio']) ?></span>
            <?php endif; ?>
            <span class="product-unit">/ <?= limpiar($rel['unidad']) ?></span>
          </div>
          <a href="<?= APP_URL ?>/controllers/CarritoController.php?accion=agregar&id=<?= (int)$rel['id'] ?>"
             class="btn-add-cart mt-2">
            <i class="bi bi-cart-plus"></i> Agregar
          </a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

</div><!-- /container -->

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

<style>
/* Galería */
.prod-main-img-wrap {
  position: relative; border-radius: 12px; overflow: hidden;
  background: white; border: 1px solid var(--gris-borde);
  aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
}
.prod-main-img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.prod-main-img:hover { transform: scale(1.05); }
.prod-main-img-placeholder {
  width: 100%; aspect-ratio: 1;
  display: flex; align-items: center; justify-content: center;
  font-size: 6rem; color: #ccc;
  background: linear-gradient(135deg, #f0f4f0, #e8ece8);
}
.prod-thumb {
  width: 70px; height: 70px; object-fit: cover;
  border-radius: 8px; cursor: pointer;
  border: 2px solid var(--gris-borde); transition: var(--trans);
}
.prod-thumb:hover, .prod-thumb.active { border-color: var(--dorado); }

/* Precios */
.prod-precio-box { padding: 1rem; background: var(--gris-bg); border-radius: 10px; }
.prod-precio       { font-size: 2rem; font-weight: 800; color: var(--verde-dark); }
.prod-precio-oferta{ font-size: 2rem; font-weight: 800; color: #dc3545; }
.prod-precio-tachado{ font-size: 1.1rem; color: var(--texto-suave); text-decoration: line-through; }
.prod-unidad       { display: block; font-size: .82rem; color: var(--texto-suave); margin-top: .25rem; }

/* Puntos */
.prod-puntos-info {
  background: rgba(201,168,76,.1); border: 1px solid rgba(201,168,76,.3);
  border-radius: 8px; padding: .6rem 1rem; font-size: .88rem; color: var(--verde-dark);
}

/* Qty control */
.qty-control { display: flex; align-items: center; gap: 0; }
.qty-btn {
  width: 36px; height: 36px;
  background: var(--gris-bg); border: 1.5px solid var(--gris-borde);
  color: var(--texto); font-size: 1.2rem; font-weight: 700;
  cursor: pointer; transition: var(--trans); line-height: 1;
  display: flex; align-items: center; justify-content: center;
}
.qty-btn:first-child { border-radius: 8px 0 0 8px; }
.qty-btn:last-child  { border-radius: 0 8px 8px 0; }
.qty-btn:hover { background: var(--verde); color: white; border-color: var(--verde); }
.qty-input {
  width: 70px; border-radius: 0 !important;
  border-left: none; border-right: none;
  text-align: center; font-weight: 600;
  border-color: var(--gris-borde) !important;
  box-shadow: none !important;
}

/* Info badges */
.prod-info-badge {
  display: flex; align-items: center; gap: .75rem;
  padding: .75rem; background: var(--gris-bg); border-radius: 8px;
  border: 1px solid var(--gris-borde);
}

/* Descripción */
.prod-descripcion {
  background: white; border-radius: 10px; padding: 1.2rem;
  border: 1px solid var(--gris-borde);
}
</style>

<script>
function cambiarImg(src) {
  document.getElementById('mainImg').src = src;
  document.querySelectorAll('.prod-thumb').forEach(t => t.classList.remove('active'));
  event.target.classList.add('active');
}
</script>
