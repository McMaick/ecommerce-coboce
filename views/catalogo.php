<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$modelo = new Producto();

// ── Parámetros GET ─────────────────────────────────────────
$filtros = [
    'q'          => trim($_GET['q']          ?? ''),
    'categoria'  => limpiarInt($_GET['categoria']  ?? 0),
    'precio_min' => $_GET['precio_min'] ?? '',
    'precio_max' => $_GET['precio_max'] ?? '',
    'solo_oferta'=> !empty($_GET['oferta']),
    'en_stock'   => !empty($_GET['stock']),
    'orden'      => $_GET['orden'] ?? 'reciente',
];
$pagina    = max(1, limpiarInt($_GET['pagina'] ?? 1));
$porPagina = 12;

// ── Datos ──────────────────────────────────────────────────
$productos   = $modelo->listar($filtros, $pagina, $porPagina);
$total       = $modelo->contar($filtros);
$totalPag    = (int) ceil($total / $porPagina);
$categorias  = $modelo->listarCategorias();
$rango       = $modelo->rangoPrecios();

// Categoría activa (para breadcrumb)
$catActiva = null;
if ($filtros['categoria']) {
    foreach ($categorias as $c) {
        if ((int)$c['id'] === $filtros['categoria']) { $catActiva = $c; break; }
    }
}

// IDs de productos en wishlist del usuario actual
$wishlistIds = [];
if (!empty($_SESSION['usuario_id'])) {
    $db = Database::getConnection();
    $stmtW = $db->prepare("SELECT producto_id FROM wishlist WHERE usuario_id = :u");
    $stmtW->execute([':u' => (int)$_SESSION['usuario_id']]);
    $wishlistIds = array_column($stmtW->fetchAll(PDO::FETCH_ASSOC), 'producto_id');
}

// URL base para paginación (preserva todos los filtros menos pagina)
$qPag = http_build_query(array_filter([
    'q'         => $filtros['q'],
    'categoria' => $filtros['categoria'] ?: null,
    'precio_min'=> $filtros['precio_min'],
    'precio_max'=> $filtros['precio_max'],
    'oferta'    => $filtros['solo_oferta'] ? 1 : null,
    'stock'     => $filtros['en_stock']   ? 1 : null,
    'orden'     => $filtros['orden'] !== 'reciente' ? $filtros['orden'] : null,
]));

// Conteo de filtros activos (para badge)
$nFiltros = (int)(bool)$filtros['q']
          + (int)(bool)$filtros['categoria']
          + (int)($filtros['precio_min'] !== '')
          + (int)($filtros['precio_max'] !== '')
          + (int)$filtros['solo_oferta']
          + (int)$filtros['en_stock'];

$titulo = $catActiva ? limpiar($catActiva['nombre']) : ($filtros['q'] ? 'Búsqueda: ' . limpiar($filtros['q']) : 'Catálogo');

$iconosCat = Producto::ICONOS_CAT;

require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- ── BREADCRUMB ─────────────────────────────────────────── -->
<div style="background:white;border-bottom:1px solid var(--gris-borde)">
  <div class="container py-2">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0" style="font-size:.83rem">
        <li class="breadcrumb-item">
          <a href="<?= APP_URL ?>/index.php" class="text-verde">Inicio</a>
        </li>
        <li class="breadcrumb-item <?= !$catActiva ? 'active' : '' ?>">
          <?php if ($catActiva): ?>
            <a href="<?= APP_URL ?>/views/catalogo.php" class="text-verde">Catálogo</a>
          <?php else: ?>
            Catálogo
          <?php endif; ?>
        </li>
        <?php if ($catActiva): ?>
        <li class="breadcrumb-item active"><?= limpiar($catActiva['nombre']) ?></li>
        <?php endif; ?>
      </ol>
    </nav>
  </div>
</div>

<div class="container py-4">
<div class="row g-4">

<!-- ════════════════════════════════════════════════════════
     SIDEBAR FILTROS
     ════════════════════════════════════════════════════════ -->
<div class="col-lg-3 d-none d-lg-block">
  <div class="sidebar-filters">

    <!-- Header sidebar -->
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="fw-700 mb-0" style="color:var(--verde-dark)">
        <i class="bi bi-funnel me-2"></i>Filtros
      </h6>
      <?php if ($nFiltros > 0): ?>
      <a href="<?= APP_URL ?>/views/catalogo.php" class="text-danger" style="font-size:.78rem">
        <i class="bi bi-x-circle me-1"></i>Limpiar (<?= $nFiltros ?>)
      </a>
      <?php endif; ?>
    </div>

    <form method="GET" action="" id="filtrosForm">

      <!-- Buscador -->
      <div class="filter-block">
        <label class="filter-title">Buscar</label>
        <div class="input-group input-group-sm">
          <input type="text" name="q" class="form-control" placeholder="Nombre, código…"
                 value="<?= limpiar($filtros['q']) ?>">
          <button class="btn btn-sm" style="background:var(--verde);color:white" type="submit">
            <i class="bi bi-search"></i>
          </button>
        </div>
      </div>

      <!-- Categorías -->
      <div class="filter-block">
        <label class="filter-title">Categoría</label>
        <div class="d-flex flex-column gap-1">
          <label class="filter-check <?= !$filtros['categoria'] ? 'active' : '' ?>">
            <input type="radio" name="categoria" value=""
                   <?= !$filtros['categoria'] ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            <span>Todas las categorías</span>
            <span class="ms-auto badge" style="background:var(--gris-borde);color:var(--texto)">
              <?= array_sum(array_column($categorias, 'total')) ?>
            </span>
          </label>
          <?php foreach ($categorias as $cat):
            $icon = $iconosCat[$cat['nombre']] ?? 'bi-tag';
          ?>
          <label class="filter-check <?= (int)$cat['id'] === $filtros['categoria'] ? 'active' : '' ?>">
            <input type="radio" name="categoria" value="<?= (int)$cat['id'] ?>"
                   <?= (int)$cat['id'] === $filtros['categoria'] ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            <i class="bi <?= $icon ?> me-1" style="color:var(--verde)"></i>
            <span><?= limpiar($cat['nombre']) ?></span>
            <span class="ms-auto badge" style="background:var(--gris-borde);color:var(--texto)">
              <?= (int)$cat['total'] ?>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Rango de precios -->
      <div class="filter-block">
        <label class="filter-title">Precio (Bs.)</label>
        <div class="row g-2">
          <div class="col-6">
            <input type="number" name="precio_min" class="form-control form-control-sm"
                   placeholder="Mín" min="0" step="1"
                   value="<?= limpiar($filtros['precio_min']) ?>">
          </div>
          <div class="col-6">
            <input type="number" name="precio_max" class="form-control form-control-sm"
                   placeholder="Máx" min="0" step="1"
                   value="<?= limpiar($filtros['precio_max']) ?>">
          </div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:.72rem;color:var(--texto-suave)">
          <span>Desde Bs. <?= $rango['min'] ?></span>
          <span>Hasta Bs. <?= $rango['max'] ?></span>
        </div>
      </div>

      <!-- Filtros extra -->
      <div class="filter-block">
        <label class="filter-title">Opciones</label>
        <div class="d-flex flex-column gap-2">
          <label class="filter-check">
            <input type="checkbox" name="oferta" value="1"
                   <?= $filtros['solo_oferta'] ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            <i class="bi bi-tag-fill me-1 text-danger"></i>
            <span>Solo ofertas</span>
          </label>
          <label class="filter-check">
            <input type="checkbox" name="stock" value="1"
                   <?= $filtros['en_stock'] ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            <i class="bi bi-box-seam me-1 text-success"></i>
            <span>En stock</span>
          </label>
        </div>
      </div>

      <!-- Ordenamiento -->
      <div class="filter-block">
        <label class="filter-title">Ordenar por</label>
        <div class="d-flex flex-column gap-1">
          <?php
          $ordenes = [
            'reciente'    => ['Más recientes',    'bi-clock'],
            'precio_asc'  => ['Menor precio',     'bi-sort-numeric-down'],
            'precio_desc' => ['Mayor precio',     'bi-sort-numeric-up'],
            'nombre_asc'  => ['Nombre A–Z',       'bi-sort-alpha-down'],
            'oferta'      => ['Ofertas primero',  'bi-tag'],
          ];
          foreach ($ordenes as $val => [$label, $icon]):
          ?>
          <label class="filter-check <?= $filtros['orden'] === $val ? 'active' : '' ?>">
            <input type="radio" name="orden" value="<?= $val ?>"
                   <?= $filtros['orden'] === $val ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            <i class="bi <?= $icon ?> me-1"></i>
            <span><?= $label ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Botón aplicar (precio_min/max requiere submit manual) -->
      <button type="submit" class="btn w-100 fw-600" style="background:var(--verde);color:white;border-radius:8px;padding:.5rem">
        <i class="bi bi-check-lg me-1"></i>Aplicar filtros
      </button>

    </form>
  </div>
</div><!-- /sidebar -->


<!-- ════════════════════════════════════════════════════════
     ÁREA DE PRODUCTOS
     ════════════════════════════════════════════════════════ -->
<div class="col-lg-9">

  <!-- ── Barra superior ────────────────────────────────── -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">

    <!-- Título + resultado -->
    <div>
      <h1 class="mb-0" style="font-size:1.3rem;font-weight:700;color:var(--verde-dark)">
        <?= $catActiva ? limpiar($catActiva['nombre']) : ($filtros['q'] ? 'Resultados para "' . limpiar($filtros['q']) . '"' : 'Catálogo completo') ?>
      </h1>
      <small class="text-muted">
        <?= number_format($total) ?> producto<?= $total !== 1 ? 's' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?>
        <?php if ($pagina > 1): ?> · Página <?= $pagina ?> de <?= $totalPag ?><?php endif; ?>
      </small>
    </div>

    <div class="d-flex align-items-center gap-2">
      <!-- Filtros móvil -->
      <button class="btn btn-sm d-lg-none fw-600"
              style="background:var(--verde);color:white;border-radius:8px"
              data-bs-toggle="offcanvas" data-bs-target="#filtrosOffcanvas">
        <i class="bi bi-funnel me-1"></i>Filtros
        <?php if ($nFiltros > 0): ?>
        <span class="badge rounded-pill bg-danger ms-1"><?= $nFiltros ?></span>
        <?php endif; ?>
      </button>

      <!-- Ordenar (móvil/tablet) -->
      <div class="d-lg-none">
        <select class="form-select form-select-sm" style="border-color:var(--verde);font-size:.82rem"
                onchange="location.href='?<?= $qPag ?>&orden='+this.value">
          <?php foreach ($ordenes as $val => [$label, ]): ?>
          <option value="<?= $val ?>" <?= $filtros['orden'] === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Vista grid/lista (decorativo) -->
      <div class="btn-group btn-group-sm d-none d-sm-flex" role="group">
        <button type="button" class="btn active" id="btnGrid"
                style="background:var(--verde);color:white;border:none">
          <i class="bi bi-grid-3x3-gap"></i>
        </button>
        <button type="button" class="btn" id="btnList"
                style="border:1px solid var(--gris-borde);color:var(--texto-suave)">
          <i class="bi bi-list-ul"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- ── Filtros activos (chips) ────────────────────────── -->
  <?php if ($nFiltros > 0): ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($filtros['q']): ?>
    <span class="filter-chip">
      <i class="bi bi-search me-1"></i><?= limpiar($filtros['q']) ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['q'=>'', 'pagina'=>1])) ?>" class="filter-chip-x">×</a>
    </span>
    <?php endif; ?>
    <?php if ($catActiva): ?>
    <span class="filter-chip">
      <i class="bi bi-tag me-1"></i><?= limpiar($catActiva['nombre']) ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['categoria'=>'', 'pagina'=>1])) ?>" class="filter-chip-x">×</a>
    </span>
    <?php endif; ?>
    <?php if ($filtros['precio_min'] !== '' || $filtros['precio_max'] !== ''): ?>
    <span class="filter-chip">
      <i class="bi bi-cash me-1"></i>
      Bs. <?= $filtros['precio_min'] ?: '0' ?> – <?= $filtros['precio_max'] ?: '∞' ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['precio_min'=>'','precio_max'=>'','pagina'=>1])) ?>" class="filter-chip-x">×</a>
    </span>
    <?php endif; ?>
    <?php if ($filtros['solo_oferta']): ?>
    <span class="filter-chip text-danger">
      <i class="bi bi-tag-fill me-1"></i>Solo ofertas
      <a href="?<?= http_build_query(array_merge($_GET, ['oferta'=>'', 'pagina'=>1])) ?>" class="filter-chip-x">×</a>
    </span>
    <?php endif; ?>
    <?php if ($filtros['en_stock']): ?>
    <span class="filter-chip text-success">
      <i class="bi bi-box-seam me-1"></i>En stock
      <a href="?<?= http_build_query(array_merge($_GET, ['stock'=>'', 'pagina'=>1])) ?>" class="filter-chip-x">×</a>
    </span>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/views/catalogo.php" class="filter-chip" style="color:var(--texto-suave)">
      <i class="bi bi-x-circle me-1"></i>Limpiar todo
    </a>
  </div>
  <?php endif; ?>

  <!-- ── Grid de productos ──────────────────────────────── -->
  <?php if (empty($productos)): ?>
  <div class="text-center py-5">
    <i class="bi bi-search" style="font-size:4rem;color:var(--gris-borde)"></i>
    <h4 class="mt-3 fw-600" style="color:var(--texto-suave)">Sin resultados</h4>
    <p class="text-muted mb-4">No encontramos productos con los filtros seleccionados.</p>
    <a href="<?= APP_URL ?>/views/catalogo.php" class="btn fw-600 px-4"
       style="background:var(--verde);color:white;border-radius:8px">
      <i class="bi bi-arrow-left me-2"></i>Ver todos los productos
    </a>
  </div>

  <?php else: ?>
  <div class="row g-3" id="productoGrid">
    <?php foreach ($productos as $p):
      $precioFinal = $p['precio_oferta'] ?? $p['precio'];
      $descuento   = $p['precio_oferta']
          ? round((1 - $p['precio_oferta'] / $p['precio']) * 100)
          : 0;
      $stockBajo   = (int)$p['stock'] > 0 && (int)$p['stock'] <= (int)$p['stock_minimo'];
    ?>
    <div class="col-6 col-sm-4 col-md-4 col-xl-3 product-col">
      <div class="product-card h-100">

        <!-- Imagen -->
        <div class="product-img-wrap">
          <?php if ($p['imagen'] && file_exists(UPLOADS_PATH . '/' . $p['imagen'])): ?>
            <img src="<?= UPLOADS_URL . '/' . limpiar($p['imagen']) ?>"
                 alt="<?= limpiar($p['nombre']) ?>"
                 class="product-img" loading="lazy">
          <?php else: ?>
            <div class="product-img-placeholder">
              <i class="bi <?= $iconosCat[$p['categoria']] ?? 'bi-image' ?>"></i>
            </div>
          <?php endif; ?>

          <?php if ($descuento >= 5): ?>
          <span class="badge-oferta">-<?= $descuento ?>%</span>
          <?php endif; ?>

          <?php if ($stockBajo): ?>
          <span style="position:absolute;top:10px;right:10px;
                       background:var(--dorado);color:var(--verde-dark);
                       padding:.15rem .55rem;border-radius:50px;
                       font-size:.68rem;font-weight:700">
            ¡Últimas!
          </span>
          <?php elseif ((int)$p['stock'] === 0): ?>
          <div style="position:absolute;inset:0;background:rgba(0,0,0,.45);
                      display:flex;align-items:center;justify-content:center;border-radius:var(--radio) var(--radio) 0 0">
            <span class="badge bg-secondary px-3 py-2">Sin stock</span>
          </div>
          <?php endif; ?>

          <!-- Acciones hover -->
          <div class="product-hover-actions">
            <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$p['id'] ?>"
               class="product-action-btn" title="Ver detalle">
              <i class="bi bi-eye"></i>
            </a>
            <?php $enWish = in_array((string)$p['id'], $wishlistIds) || in_array((int)$p['id'], $wishlistIds); ?>
            <button class="product-action-btn btn-wish <?= $enWish ? 'en-wish' : '' ?>"
                    data-pid="<?= (int)$p['id'] ?>" title="Favoritos">
              <i class="bi <?= $enWish ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            </button>
          </div>
        </div>

        <!-- Body -->
        <div class="product-body">
          <div class="product-cat"><?= limpiar($p['categoria']) ?></div>
          <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$p['id'] ?>"
             class="product-name d-block text-decoration-none text-dark">
            <?= limpiar(truncar($p['nombre'], 65)) ?>
          </a>

          <?php if ($p['codigo']): ?>
          <div style="font-size:.7rem;color:var(--texto-suave)">Cód: <?= limpiar($p['codigo']) ?></div>
          <?php endif; ?>

          <!-- Precio -->
          <div class="d-flex align-items-baseline gap-2 mt-auto pt-2">
            <?php if ($p['precio_oferta']): ?>
              <span class="product-price product-price-oferta">
                <?= precio((float)$p['precio_oferta']) ?>
              </span>
              <span class="product-price-old"><?= precio((float)$p['precio']) ?></span>
            <?php else: ?>
              <span class="product-price"><?= precio((float)$p['precio']) ?></span>
            <?php endif; ?>
            <span class="product-unit">/ <?= limpiar($p['unidad']) ?></span>
          </div>

          <?php if ($p['puntos_genera'] > 0): ?>
          <div style="font-size:.72rem;color:var(--dorado-dark);margin-top:.2rem">
            <i class="bi bi-star-fill me-1"></i>Gana <?= (int)$p['puntos_genera'] ?> pts por <?= limpiar($p['unidad']) ?>
          </div>
          <?php endif; ?>

          <!-- Botón carrito -->
          <?php if ((int)$p['stock'] > 0): ?>
          <a href="<?= APP_URL ?>/controllers/CarritoController.php?accion=agregar&id=<?= (int)$p['id'] ?>"
             class="btn-add-cart mt-2">
            <i class="bi bi-cart-plus"></i> Agregar al carrito
          </a>
          <?php else: ?>
          <button class="btn-add-cart mt-2" disabled style="opacity:.5;cursor:not-allowed">
            <i class="bi bi-x-circle"></i> Sin stock
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div><!-- /row -->

  <!-- ── Paginación ─────────────────────────────────────── -->
  <?php if ($totalPag > 1): ?>
  <nav class="mt-4" aria-label="Paginación">
    <ul class="pagination justify-content-center">

      <!-- Anterior -->
      <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="?<?= $qPag ?>&pagina=<?= $pagina - 1 ?>">
          <i class="bi bi-chevron-left"></i>
        </a>
      </li>

      <?php
      $inicio = max(1, $pagina - 2);
      $fin    = min($totalPag, $pagina + 2);
      if ($inicio > 1): ?>
        <li class="page-item">
          <a class="page-link" href="?<?= $qPag ?>&pagina=1">1</a>
        </li>
        <?php if ($inicio > 2): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif;
      endif;

      for ($i = $inicio; $i <= $fin; $i++): ?>
      <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
        <a class="page-link" href="?<?= $qPag ?>&pagina=<?= $i ?>"><?= $i ?></a>
      </li>
      <?php endfor;

      if ($fin < $totalPag): ?>
        <?php if ($fin < $totalPag - 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item">
          <a class="page-link" href="?<?= $qPag ?>&pagina=<?= $totalPag ?>"><?= $totalPag ?></a>
        </li>
      <?php endif; ?>

      <!-- Siguiente -->
      <li class="page-item <?= $pagina >= $totalPag ? 'disabled' : '' ?>">
        <a class="page-link" href="?<?= $qPag ?>&pagina=<?= $pagina + 1 ?>">
          <i class="bi bi-chevron-right"></i>
        </a>
      </li>

    </ul>
    <p class="text-center text-muted mt-1" style="font-size:.8rem">
      Mostrando <?= (($pagina-1)*$porPagina)+1 ?>–<?= min($pagina*$porPagina,$total) ?> de <?= $total ?> productos
    </p>
  </nav>
  <?php endif; ?>

  <?php endif; // /productos ?>

</div><!-- /col productos -->
</div><!-- /row -->
</div><!-- /container -->


<!-- ════════════════════════════════════════════════════════
     OFFCANVAS FILTROS (MÓVIL)
     ════════════════════════════════════════════════════════ -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="filtrosOffcanvas" style="width:300px">
  <div class="offcanvas-header" style="background:var(--verde);color:white">
    <h5 class="offcanvas-title fw-700">
      <i class="bi bi-funnel me-2"></i>Filtros
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-3">
    <form method="GET" action="">

      <div class="filter-block">
        <label class="filter-title">Buscar</label>
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Nombre, código…" value="<?= limpiar($filtros['q']) ?>">
      </div>

      <div class="filter-block">
        <label class="filter-title">Categoría</label>
        <select name="categoria" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach ($categorias as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>"
                  <?= (int)$cat['id'] === $filtros['categoria'] ? 'selected' : '' ?>>
            <?= limpiar($cat['nombre']) ?> (<?= (int)$cat['total'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-block">
        <label class="filter-title">Precio (Bs.)</label>
        <div class="row g-2">
          <div class="col-6">
            <input type="number" name="precio_min" class="form-control form-control-sm"
                   placeholder="Mín" value="<?= limpiar($filtros['precio_min']) ?>">
          </div>
          <div class="col-6">
            <input type="number" name="precio_max" class="form-control form-control-sm"
                   placeholder="Máx" value="<?= limpiar($filtros['precio_max']) ?>">
          </div>
        </div>
      </div>

      <div class="filter-block">
        <label class="filter-title">Opciones</label>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" name="oferta" value="1" id="mOferta"
                 <?= $filtros['solo_oferta'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="mOferta" style="font-size:.88rem">Solo ofertas</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="stock" value="1" id="mStock"
                 <?= $filtros['en_stock'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="mStock" style="font-size:.88rem">En stock</label>
        </div>
      </div>

      <div class="filter-block">
        <label class="filter-title">Ordenar por</label>
        <select name="orden" class="form-select form-select-sm">
          <?php foreach ($ordenes as $val => [$label, ]): ?>
          <option value="<?= $val ?>" <?= $filtros['orden'] === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="d-grid gap-2">
        <button type="submit" class="btn fw-600"
                style="background:var(--verde);color:white;border-radius:8px">
          <i class="bi bi-check-lg me-1"></i>Aplicar filtros
        </button>
        <a href="<?= APP_URL ?>/views/catalogo.php" class="btn btn-outline-secondary btn-sm">
          Limpiar filtros
        </a>
      </div>

    </form>
  </div>
</div>

<script>
(function() {
  const appUrl  = '<?= APP_URL ?>';
  const loggedIn = <?= empty($_SESSION['usuario_id']) ? 'false' : 'true' ?>;

  document.querySelectorAll('.btn-wish').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      if (!loggedIn) {
        window.location.href = appUrl + '/views/login.php?redirect_after_login=' +
          encodeURIComponent('/views/catalogo.php');
        return;
      }
      const pid  = this.dataset.pid;
      const icon = this.querySelector('i');
      const inWish = this.classList.contains('en-wish');

      fetch(appUrl + '/controllers/WishlistController.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'producto_id=' + pid
      })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) return;
        if (data.enWishlist) {
          this.classList.add('en-wish');
          icon.className = 'bi bi-heart-fill';
          this.style.color = '#EF4444';
        } else {
          this.classList.remove('en-wish');
          icon.className = 'bi bi-heart';
          this.style.color = '';
        }
        // Actualizar badge del header
        const badge = document.querySelector('.wish-count');
        if (badge) badge.textContent = data.total > 0 ? data.total : '';
      });
    });
  });
})();
</script>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
?>

<style>
/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar-filters {
  background: white;
  border-radius: var(--radio);
  padding: 1.25rem;
  box-shadow: var(--sombra);
  border: 1px solid var(--gris-borde);
  position: sticky; top: 80px;
}
.filter-block { margin-bottom: 1.3rem; padding-bottom: 1.3rem; border-bottom: 1px solid var(--gris-borde); }
.filter-block:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.filter-title { display: block; font-size: .78rem; font-weight: 700; color: var(--texto-suave); text-transform: uppercase; letter-spacing: .8px; margin-bottom: .6rem; }
.filter-check {
  display: flex; align-items: center; gap: .5rem;
  padding: .4rem .5rem; border-radius: 6px;
  cursor: pointer; font-size: .86rem; transition: var(--trans);
}
.filter-check:hover { background: var(--gris-bg); }
.filter-check.active { background: rgba(26,107,58,.08); color: var(--verde); font-weight: 600; }
.filter-check input { width: 15px; height: 15px; flex-shrink: 0; accent-color: var(--verde); }

/* ── Chips filtros activos ────────────────────────────────── */
.filter-chip {
  display: inline-flex; align-items: center; gap: .3rem;
  background: rgba(26,107,58,.08);
  border: 1px solid rgba(26,107,58,.2);
  color: var(--verde-dark);
  padding: .2rem .7rem; border-radius: 50px;
  font-size: .78rem; font-weight: 500;
}
.filter-chip-x {
  color: var(--verde-dark); font-size: 1rem; line-height: 1;
  font-weight: 700; text-decoration: none; margin-left: .1rem;
}
.filter-chip-x:hover { color: #dc3545; }

/* ── Product hover actions ────────────────────────────────── */
.product-img-wrap { position: relative; overflow: hidden; }
.product-hover-actions {
  position: absolute; top: 8px; right: 8px;
  display: flex; flex-direction: column; gap: 6px;
  opacity: 0; transition: opacity .25s;
}
.product-card:hover .product-hover-actions { opacity: 1; }
.product-action-btn {
  width: 34px; height: 34px;
  background: white; border: none; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  color: var(--verde-dark); font-size: 1rem;
  box-shadow: 0 2px 8px rgba(0,0,0,.15); cursor: pointer;
  transition: var(--trans); text-decoration: none;
}
.product-action-btn:hover { background: var(--verde); color: white; }

/* ── Paginación ───────────────────────────────────────────── */
.page-link { color: var(--verde); border-color: var(--gris-borde); }
.page-item.active .page-link { background: var(--verde); border-color: var(--verde); color: white; }
.page-link:hover { color: var(--verde-dark); }

/* ── Vista lista (toggle) ─────────────────────────────────── */
#productoGrid.list-view { display: block !important; }
#productoGrid.list-view .product-col { width: 100%; max-width: 100%; }
#productoGrid.list-view .product-card { flex-direction: row; max-height: 150px; }
#productoGrid.list-view .product-img-wrap { width: 150px; flex-shrink: 0; }
#productoGrid.list-view .product-img,
#productoGrid.list-view .product-img-placeholder { height: 150px; border-radius: var(--radio) 0 0 var(--radio); }
</style>

<script>
// Vista grid/lista
const btnGrid = document.getElementById('btnGrid');
const btnList = document.getElementById('btnList');
const grid    = document.getElementById('productoGrid');

btnGrid?.addEventListener('click', () => {
  grid.classList.remove('list-view');
  btnGrid.style.background = 'var(--verde)';
  btnGrid.style.color = 'white';
  btnList.style.background = '';
  btnList.style.color = 'var(--texto-suave)';
});
btnList?.addEventListener('click', () => {
  grid.classList.add('list-view');
  btnList.style.background = 'var(--verde)';
  btnList.style.color = 'white';
  btnGrid.style.background = '';
  btnGrid.style.color = 'var(--texto-suave)';
});
</script>
