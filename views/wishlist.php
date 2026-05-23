<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';

if (empty($_SESSION['usuario_id'])) {
    redirigir(APP_URL . '/views/login.php?redirect_after_login=' . urlencode('/views/wishlist.php'));
}

$titulo      = 'Mis Favoritos';
$db          = Database::getConnection();
$usuarioId   = (int)$_SESSION['usuario_id'];

$stmt = $db->prepare(
    "SELECT p.id, p.nombre, p.precio, p.precio_oferta, p.imagen, p.unidad,
            p.stock, p.puntos_genera, p.codigo,
            c.nombre AS categoria,
            w.creado_en AS guardado_en
     FROM wishlist w
     JOIN productos p  ON p.id = w.producto_id
     JOIN categorias c ON c.id = p.categoria_id
     WHERE w.usuario_id = :u AND p.activo = 1
     ORDER BY w.creado_en DESC"
);
$stmt->execute([':u' => $usuarioId]);
$favoritos = $stmt->fetchAll();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="container py-5">
  <div class="d-flex align-items-center gap-3 mb-4">
    <h1 style="font-size:1.6rem;font-weight:800;color:var(--verde-dark);margin:0">
      <i class="bi bi-heart-fill text-danger me-2"></i>Mis Favoritos
    </h1>
    <span class="badge bg-danger rounded-pill"><?= count($favoritos) ?></span>
  </div>

  <?php if (empty($favoritos)): ?>
  <div class="text-center py-5">
    <i class="bi bi-heart" style="font-size:4rem;color:#D1D5DB"></i>
    <h5 class="mt-3 fw-600" style="color:#6B7280">Aún no tienes productos favoritos</h5>
    <p class="text-muted mb-4">Explora el catálogo y guarda los productos que más te gustan.</p>
    <a href="<?= APP_URL ?>/views/catalogo.php" class="btn-verde btn px-4">
      <i class="bi bi-grid me-2"></i>Ver catálogo
    </a>
  </div>

  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($favoritos as $p):
      $precioFinal = $p['precio_oferta'] ? (float)$p['precio_oferta'] : (float)$p['precio'];
      $imgSrc = $p['imagen']
          ? APP_URL . '/uploads/' . $p['imagen']
          : APP_URL . '/assets/img/no-image.png';
    ?>
    <div class="col-12 col-sm-6 col-md-4 col-lg-3" id="wish-card-<?= (int)$p['id'] ?>">
      <div class="product-card h-100">

        <!-- Imagen -->
        <div class="product-img-wrap" style="position:relative">
          <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$p['id'] ?>">
            <img src="<?= limpiar($imgSrc) ?>" alt="<?= limpiar($p['nombre']) ?>"
                 class="product-img" loading="lazy">
          </a>
          <?php if ($p['precio_oferta']): ?>
          <span style="position:absolute;top:8px;left:8px;background:#EF4444;color:white;
                       padding:.2rem .55rem;border-radius:50px;font-size:.68rem;font-weight:700">
            Oferta
          </span>
          <?php endif; ?>
          <!-- Quitar de favoritos -->
          <button class="btn-quitar-wish" data-pid="<?= (int)$p['id'] ?>"
                  title="Quitar de favoritos">
            <i class="bi bi-heart-fill"></i>
          </button>
        </div>

        <!-- Body -->
        <div class="product-body">
          <div class="product-cat"><?= limpiar($p['categoria']) ?></div>
          <a href="<?= APP_URL ?>/views/producto.php?id=<?= (int)$p['id'] ?>"
             class="product-name d-block text-decoration-none text-dark">
            <?= limpiar(truncar($p['nombre'], 60)) ?>
          </a>
          <div class="d-flex align-items-baseline gap-2 mt-auto pt-2">
            <?php if ($p['precio_oferta']): ?>
              <span class="product-price product-price-oferta"><?= precio((float)$p['precio_oferta']) ?></span>
              <span class="product-price-old"><?= precio((float)$p['precio']) ?></span>
            <?php else: ?>
              <span class="product-price"><?= precio((float)$p['precio']) ?></span>
            <?php endif; ?>
            <span class="product-unit">/ <?= limpiar($p['unidad']) ?></span>
          </div>

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
  </div>
  <?php endif; ?>
</div>

<style>
.btn-quitar-wish {
  position: absolute; top: 8px; right: 8px;
  width: 34px; height: 34px;
  background: white; border: none; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: #EF4444; font-size: 1rem;
  box-shadow: 0 2px 8px rgba(0,0,0,.15);
  cursor: pointer; transition: transform .15s, box-shadow .15s;
}
.btn-quitar-wish:hover { transform: scale(1.12); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
</style>

<script>
document.querySelectorAll('.btn-quitar-wish').forEach(btn => {
  btn.addEventListener('click', function() {
    const pid = this.dataset.pid;
    const card = document.getElementById('wish-card-' + pid);
    fetch('<?= APP_URL ?>/controllers/WishlistController.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'producto_id=' + pid
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        card.style.transition = 'opacity .3s, transform .3s';
        card.style.opacity = '0';
        card.style.transform = 'scale(.9)';
        setTimeout(() => {
          card.remove();
          const badge = document.querySelector('.badge.bg-danger');
          if (badge) {
            const n = parseInt(badge.textContent) - 1;
            badge.textContent = n;
            if (n === 0) location.reload();
          }
        }, 300);
      }
    });
  });
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
