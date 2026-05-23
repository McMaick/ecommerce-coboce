<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$titulo      = 'Inicio';
$descripcion = 'Distribuidora oficial de Cerámica COBOCE en Cobija, Bolivia. Pisos, porcelánato, revestimientos y más.';

require_once __DIR__ . '/models/Producto.php';

$modeloProd    = new Producto();
$productosDest = $modeloProd->listarDestacados(8);
$categorias    = $modeloProd->listarCategorias();
$iconosCat     = Producto::ICONOS_CAT;

require_once __DIR__ . '/includes/header.php';
?>

<!-- ── HERO BANNER ─────────────────────────────────────────── -->
<section class="hero-section">
  <div class="hero-pattern"></div>
  <div class="container position-relative">
    <div class="row align-items-center g-4">

      <!-- Copy -->
      <div class="col-12 col-lg-7">
        <div class="hero-badge">
          <i class="bi bi-patch-check-fill me-1"></i>Distribuidor oficial COBOCE · Cobija, Bolivia
        </div>
        <h1 class="hero-title">
          Transforma tu hogar con la mejor <span>cerámica</span>
        </h1>
        <p class="hero-subtitle">
          Más de 500 modelos en pisos, porcelánato y revestimientos.
          Delivery a domicilio en toda la ciudad de Cobija.
        </p>
        <div class="d-flex flex-wrap gap-3 mb-5">
          <a href="<?= APP_URL ?>/views/catalogo.php" class="btn-hero-primary">
            <i class="bi bi-grid me-2"></i>Ver catálogo
          </a>
          <a href="<?= APP_URL ?>/views/catalogo.php?oferta=1" class="btn-hero-secondary">
            <i class="bi bi-tag me-2"></i>Ver ofertas
          </a>
        </div>

        <!-- Stats -->
        <div class="row g-3">
          <div class="col-4">
            <div class="hero-stat">
              <span class="hero-stat-num">500+</span>
              <span class="hero-stat-lbl">Modelos</span>
            </div>
          </div>
          <div class="col-4">
            <div class="hero-stat">
              <span class="hero-stat-num">5★</span>
              <span class="hero-stat-lbl">Calidad COBOCE</span>
            </div>
          </div>
          <div class="col-4">
            <div class="hero-stat">
              <span class="hero-stat-num">24h</span>
              <span class="hero-stat-lbl">Delivery rápido</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Visual derecha -->
      <div class="col-lg-5 d-none d-lg-flex justify-content-center align-items-center">
        <div class="hero-visual">
          <div class="hero-visual-icon">
            <i class="bi bi-house-heart"></i>
          </div>
          <div class="hero-visual-title">Catálogo Digital</div>
          <div class="hero-visual-sub">Explora y compra desde donde estés</div>
          <div class="hero-visual-grid">
            <?php
            $colores = ['#C9A84C','#1A6B3A','rgba(201,168,76,.25)','rgba(255,255,255,.12)',
                        'rgba(26,107,58,.4)','#C9A84C','rgba(255,255,255,.12)','#1A6B3A'];
            foreach ($colores as $c): ?>
            <div style="background:<?= $c ?>"></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ── CATEGORÍAS ─────────────────────────────────────────── -->
<section class="py-5">
  <div class="container">
    <div class="d-flex justify-content-between align-items-end mb-4">
      <h2 class="section-title mb-0">Categorías</h2>
      <a href="<?= APP_URL ?>/views/catalogo.php" class="link-verde" style="font-size:.88rem">
        Ver todas <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>

    <div class="row g-3">
      <?php foreach ($categorias as $cat):
        $icon = $iconosCat[$cat['nombre']] ?? 'bi-tag';
      ?>
      <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=<?= (int)$cat['id'] ?>"
           class="cat-card d-block">
          <div class="cat-card-icon">
            <i class="bi <?= $icon ?>"></i>
          </div>
          <div class="cat-card-body">
            <div class="cat-card-name"><?= limpiar($cat['nombre']) ?></div>
            <div class="cat-card-count"><?= (int)$cat['total'] ?> producto<?= (int)$cat['total'] !== 1 ? 's' : '' ?></div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>

      <!-- Ver todo -->
      <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="<?= APP_URL ?>/views/catalogo.php" class="cat-card d-block h-100"
           style="border-style:dashed;background:transparent">
          <div class="cat-card-icon" style="background:transparent;height:110px">
            <i class="bi bi-plus-circle" style="color:var(--texto-suave)"></i>
          </div>
          <div class="cat-card-body">
            <div class="cat-card-name" style="color:var(--texto-suave)">Ver todo</div>
          </div>
        </a>
      </div>
    </div>
  </div>
</section>

<!-- ── BANNER PUNTOS ──────────────────────────────────────── -->
<?php if (!estaLogueado()): ?>
<section class="py-2">
  <div class="container">
    <div class="promo-banner">
      <div class="row align-items-center g-3">
        <div class="col-12 col-md-8">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-star-fill" style="font-size:2.5rem;opacity:.8"></i>
            <div>
              <h4 class="fw-700 mb-1" style="font-size:1.2rem">¡Gana puntos con cada compra!</h4>
              <p class="mb-0" style="font-size:.88rem;opacity:.85">
                Regístrate gratis y obtén <strong>50 puntos de bienvenida</strong>.
                Gana 1 punto por cada Bs. comprado y canjéalos en tu próxima compra.
              </p>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-4 text-md-end">
          <a href="<?= APP_URL ?>/views/registro.php"
             class="btn fw-700 px-4 py-2"
             style="background:var(--verde-dark);color:white;border-radius:50px;font-size:.9rem">
            <i class="bi bi-person-plus me-2"></i>Crear cuenta gratis
          </a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── PRODUCTOS DESTACADOS ───────────────────────────────── -->
<section class="py-5">
  <div class="container">
    <div class="d-flex justify-content-between align-items-end mb-4">
      <h2 class="section-title mb-0">Productos destacados</h2>
      <a href="<?= APP_URL ?>/views/catalogo.php" class="link-verde" style="font-size:.88rem">
        Ver todos <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>

    <?php if (!empty($productosDest)): ?>
    <div class="row g-3">
      <?php foreach ($productosDest as $prod):
        $descuento  = $prod['precio_oferta']
            ? round((1 - $prod['precio_oferta'] / $prod['precio']) * 100)
            : 0;
        $iconProd   = $iconosCat[$prod['categoria']] ?? 'bi-image';
      ?>
      <div class="col-6 col-sm-4 col-md-3">
        <div class="product-card">
          <div class="product-img-wrap">
            <?php if ($prod['imagen'] && file_exists(UPLOADS_PATH . '/' . $prod['imagen'])): ?>
              <img src="<?= UPLOADS_URL . '/' . limpiar($prod['imagen']) ?>"
                   alt="<?= limpiar($prod['nombre']) ?>"
                   class="product-img" loading="lazy">
            <?php else: ?>
              <div class="product-img-placeholder">
                <i class="bi <?= $iconProd ?>"></i>
              </div>
            <?php endif; ?>
            <?php if ($descuento >= 5): ?>
              <span class="badge-oferta">-<?= $descuento ?>%</span>
            <?php endif; ?>
          </div>
          <div class="product-body">
            <div class="product-cat"><?= limpiar($prod['categoria']) ?></div>
            <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$prod['id'] ?>"
               class="product-name d-block text-decoration-none text-dark">
              <?= limpiar(truncar($prod['nombre'], 60)) ?>
            </a>
            <div class="d-flex align-items-baseline gap-2 mt-auto pt-1">
              <?php if ($prod['precio_oferta']): ?>
                <span class="product-price product-price-oferta"><?= precio((float)$prod['precio_oferta']) ?></span>
                <span class="product-price-old"><?= precio((float)$prod['precio']) ?></span>
              <?php else: ?>
                <span class="product-price"><?= precio((float)$prod['precio']) ?></span>
              <?php endif; ?>
              <span class="product-unit">/ <?= limpiar($prod['unidad']) ?></span>
            </div>
            <a href="<?= APP_URL ?>/controllers/CarritoController.php?accion=agregar&id=<?= (int)$prod['id'] ?>"
               class="btn-add-cart mt-2">
              <i class="bi bi-cart-plus"></i> Agregar al carrito
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="text-center py-5">
      <i class="bi bi-box-seam" style="font-size:3.5rem;color:var(--gris-borde)"></i>
      <h5 class="mt-3 text-muted">Aún no hay productos cargados</h5>
      <p class="text-muted mb-3" style="font-size:.88rem">
        Accede al panel de administración para agregar productos al catálogo.
      </p>
      <?php if (esAdmin()): ?>
      <a href="<?= APP_URL ?>/admin/productos.php" class="btn fw-600 px-4"
         style="background:var(--verde);color:white;border-radius:8px">
        <i class="bi bi-plus-circle me-2"></i>Agregar productos
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</section>

<!-- ── POR QUÉ ELEGIRNOS ──────────────────────────────────── -->
<section class="py-5" style="background:white">
  <div class="container">
    <h2 class="section-title centered text-center">¿Por qué elegir COBOCE?</h2>
    <div class="row g-4 mt-2">

      <div class="col-12 col-sm-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-patch-check-fill"></i></div>
          <h5 class="fw-700 mb-2">Calidad garantizada</h5>
          <p class="text-muted mb-0" style="font-size:.88rem">
            Productos directos de fábrica COBOCE con certificación de calidad boliviana.
          </p>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-truck"></i></div>
          <h5 class="fw-700 mb-2">Delivery en Cobija</h5>
          <p class="text-muted mb-0" style="font-size:.88rem">
            Entregamos en todas las zonas de Cobija. Rápido, seguro y a tu puerta.
          </p>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-star-fill"></i></div>
          <h5 class="fw-700 mb-2">Programa de puntos</h5>
          <p class="text-muted mb-0" style="font-size:.88rem">
            Gana puntos en cada compra y canjéalos por descuentos en tu próximo pedido.
          </p>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-headset"></i></div>
          <h5 class="fw-700 mb-2">Atención personalizada</h5>
          <p class="text-muted mb-0" style="font-size:.88rem">
            Nuestro equipo te asesora en la elección del material ideal para tu proyecto.
          </p>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ── CTA FINAL ──────────────────────────────────────────── -->
<section class="py-5" style="background:var(--verde-dark)">
  <div class="container text-center" style="color:white">
    <i class="bi bi-whatsapp" style="font-size:2.5rem;color:#25D366"></i>
    <h3 class="mt-3 fw-700">¿Necesitas asesoramiento?</h3>
    <p class="mt-2 mb-4" style="opacity:.8;max-width:480px;margin-inline:auto">
      Nuestros especialistas te ayudan a elegir el mejor material para tu hogar o proyecto de construcción.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-3">
      <a href="https://wa.me/59173943006" target="_blank"
         class="btn fw-700 px-4 py-2"
         style="background:#25D366;color:white;border-radius:50px">
        <i class="bi bi-whatsapp me-2"></i>Escribir por WhatsApp
      </a>
      <a href="<?= APP_URL ?>/views/catalogo.php"
         class="btn fw-600 px-4 py-2"
         style="background:transparent;color:white;border:2px solid rgba(255,255,255,.4);border-radius:50px">
        <i class="bi bi-grid me-2"></i>Explorar catálogo
      </a>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
