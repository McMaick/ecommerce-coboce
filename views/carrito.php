<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/models/Producto.php';

$titulo  = 'Mi carrito';
$carrito = $_SESSION['carrito'] ?? [];

// Calcular totales
$subtotal    = 0.0;
$totalPuntos = 0;
foreach ($carrito as $item) {
    $p           = $item['oferta'] ?? $item['precio'];
    $subtotal   += $p * $item['cantidad'];
    $totalPuntos+= (int)$item['puntos'] * (int)ceil($item['cantidad']);
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- Breadcrumb -->
<div style="background:white;border-bottom:1px solid var(--gris-borde)">
  <div class="container py-2">
    <nav><ol class="breadcrumb mb-0" style="font-size:.82rem">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php" class="text-verde">Inicio</a></li>
      <li class="breadcrumb-item active">Mi carrito</li>
    </ol></nav>
  </div>
</div>

<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-4">
    <h1 style="font-size:1.6rem;font-weight:700;color:var(--verde-dark)">
      <i class="bi bi-cart3 me-2"></i>Mi carrito
      <?php if ($carrito): ?>
      <span class="badge rounded-pill ms-1" style="background:var(--dorado);color:var(--verde-dark);font-size:.8rem">
        <?= contarCarrito() ?> ítem<?= contarCarrito() !== 1 ? 's' : '' ?>
      </span>
      <?php endif; ?>
    </h1>
    <?php if ($carrito): ?>
    <a href="<?= APP_URL ?>/controllers/CarritoController.php?accion=vaciar"
       class="btn btn-sm btn-outline-danger"
       onclick="return confirm('¿Vaciar todo el carrito?')">
      <i class="bi bi-trash me-1"></i>Vaciar carrito
    </a>
    <?php endif; ?>
  </div>

  <?php if (empty($carrito)): ?>
  <!-- ── CARRITO VACÍO ─────────────────────────────────── -->
  <div class="text-center py-5">
    <div style="width:120px;height:120px;background:var(--gris-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem">
      <i class="bi bi-cart-x" style="font-size:3.5rem;color:var(--gris-borde)"></i>
    </div>
    <h4 class="fw-700 mb-2" style="color:var(--texto-suave)">Tu carrito está vacío</h4>
    <p class="text-muted mb-4">Agrega productos del catálogo para comenzar tu compra.</p>
    <a href="<?= APP_URL ?>/views/catalogo.php" class="btn-coboce" style="display:inline-block;max-width:240px;border-radius:50px;padding:.75rem 2rem;text-decoration:none">
      <i class="bi bi-grid me-2"></i>Explorar catálogo
    </a>
  </div>

  <?php else: ?>
  <div class="row g-4">

    <!-- ── ITEMS DEL CARRITO ─────────────────────────────── -->
    <div class="col-lg-8">
      <form action="<?= APP_URL ?>/controllers/CarritoController.php" method="POST" id="formCarrito">
        <input type="hidden" name="accion" value="actualizar">

        <div class="cart-items-wrap">
          <?php foreach ($carrito as $id => $item):
            $precioUnit = $item['oferta'] ?? $item['precio'];
            $subtotalItem = $precioUnit * $item['cantidad'];
            $step = in_array($item['unidad'], ['m²','m2']) ? '0.25' : '1';
            $iconsCat = Producto::ICONOS_CAT;
            $iconProd = $iconsCat[$item['categoria'] ?? ''] ?? 'bi-image';
          ?>
          <div class="cart-item">

            <!-- Imagen -->
            <div class="cart-item-img">
              <?php if ($item['imagen'] && file_exists(UPLOADS_PATH . '/' . $item['imagen'])): ?>
                <img src="<?= UPLOADS_URL . '/' . limpiar($item['imagen']) ?>"
                     alt="<?= limpiar($item['nombre']) ?>">
              <?php else: ?>
                <div class="cart-item-img-ph">
                  <i class="bi <?= $iconProd ?>"></i>
                </div>
              <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="cart-item-info flex-grow-1">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <a href="<?= APP_URL ?>/views/producto.php?id=<?= $id ?>"
                     class="cart-item-name"><?= limpiar($item['nombre']) ?></a>
                  <div class="text-muted" style="font-size:.78rem;margin-top:.2rem">
                    <?= limpiar($item['categoria'] ?? '') ?>
                    <?php if ($item['oferta']): ?>
                    · <span class="text-danger fw-600">Precio de oferta</span>
                    <?php endif; ?>
                  </div>
                </div>
                <!-- Eliminar -->
                <a href="<?= APP_URL ?>/controllers/CarritoController.php?accion=eliminar&id=<?= $id ?>"
                   class="btn-remove-item" title="Eliminar">
                  <i class="bi bi-x-lg"></i>
                </a>
              </div>

              <div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
                <!-- Precio unitario -->
                <div>
                  <div style="font-size:.75rem;color:var(--texto-suave)">Precio unit.</div>
                  <div style="font-weight:600;color:var(--verde-dark)">
                    <?php if ($item['oferta']): ?>
                      <span class="text-danger"><?= precio($precioUnit) ?></span>
                      <small class="text-muted text-decoration-line-through ms-1">
                        <?= precio($item['precio']) ?>
                      </small>
                    <?php else: ?>
                      <?= precio($precioUnit) ?>
                    <?php endif; ?>
                    <small class="text-muted">/ <?= limpiar($item['unidad']) ?></small>
                  </div>
                </div>

                <!-- Control cantidad -->
                <div class="qty-control">
                  <button type="button" class="qty-btn" data-qty-minus="qty_<?= $id ?>">−</button>
                  <input type="number" id="qty_<?= $id ?>"
                         name="cantidad[<?= $id ?>]"
                         class="qty-input form-control"
                         value="<?= $step === '0.25' ? number_format($item['cantidad'],2) : (int)$item['cantidad'] ?>"
                         min="<?= $step ?>" max="<?= $item['stock'] ?>" step="<?= $step ?>"
                         onchange="actualizarSubtotal(<?= $id ?>, <?= $precioUnit ?>, this.value)">
                  <button type="button" class="qty-btn" data-qty-plus="qty_<?= $id ?>">+</button>
                </div>

                <!-- Subtotal ítem -->
                <div class="text-end">
                  <div style="font-size:.75rem;color:var(--texto-suave)">Subtotal</div>
                  <div class="fw-700" style="color:var(--verde-dark);font-size:1.05rem"
                       id="sub_<?= $id ?>"><?= precio($subtotalItem) ?></div>
                </div>
              </div>

              <!-- Puntos -->
              <?php if ($item['puntos'] > 0): ?>
              <div style="font-size:.72rem;color:var(--dorado-dark);margin-top:.4rem">
                <i class="bi bi-star-fill me-1"></i>
                Ganarás <?= (int)($item['puntos'] * ceil($item['cantidad'])) ?> puntos con este ítem
              </div>
              <?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>
        </div>

        <!-- Botones -->
        <div class="d-flex flex-wrap gap-2 mt-3">
          <button type="submit" class="btn fw-600 px-4"
                  style="background:var(--verde);color:white;border-radius:8px">
            <i class="bi bi-arrow-clockwise me-1"></i>Actualizar cantidades
          </button>
          <a href="<?= APP_URL ?>/views/catalogo.php" class="btn btn-outline-secondary px-4" style="border-radius:8px">
            <i class="bi bi-arrow-left me-1"></i>Seguir comprando
          </a>
        </div>
      </form>
    </div>

    <!-- ── RESUMEN PEDIDO ─────────────────────────────────── -->
    <div class="col-lg-4">
      <div class="order-summary">

        <h5 class="fw-700 mb-3" style="color:var(--verde-dark)">
          <i class="bi bi-receipt me-2"></i>Resumen del pedido
        </h5>

        <!-- Items resumen -->
        <div class="mb-3" style="border-bottom:1px solid var(--gris-borde);padding-bottom:1rem">
          <?php foreach ($carrito as $id => $item):
            $pu = $item['oferta'] ?? $item['precio'];
          ?>
          <div class="d-flex justify-content-between align-items-start mb-2" style="font-size:.85rem">
            <span class="text-muted" style="flex:1;padding-right:.5rem">
              <?= limpiar(truncar($item['nombre'], 30)) ?>
              <span class="text-muted">×<?= $item['cantidad'] ?></span>
            </span>
            <span class="fw-600"><?= precio($pu * $item['cantidad']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Subtotal -->
        <div class="d-flex justify-content-between mb-2" style="font-size:.92rem">
          <span class="text-muted">Subtotal</span>
          <span class="fw-600" id="totalGeneral"><?= precio($subtotal) ?></span>
        </div>

        <!-- Delivery -->
        <div class="d-flex justify-content-between mb-2" style="font-size:.92rem">
          <span class="text-muted">Delivery</span>
          <span class="text-muted">Se calcula en el checkout</span>
        </div>

        <!-- Puntos a ganar -->
        <?php if ($totalPuntos > 0): ?>
        <div class="d-flex justify-content-between mb-2" style="font-size:.85rem">
          <span style="color:var(--dorado-dark)"><i class="bi bi-star-fill me-1"></i>Puntos a ganar</span>
          <span style="color:var(--dorado-dark);font-weight:600">+<?= $totalPuntos ?> pts</span>
        </div>
        <?php endif; ?>

        <!-- Total -->
        <div class="d-flex justify-content-between mt-3 pt-3"
             style="border-top:2px solid var(--verde);font-size:1.1rem">
          <span class="fw-700">Total</span>
          <span class="fw-800" style="color:var(--verde-dark)" id="totalFinal"><?= precio($subtotal) ?></span>
        </div>

        <!-- Checkout -->
        <a href="<?= APP_URL ?>/views/checkout/paso1-entrega.php"
           class="btn-coboce mt-4 d-block text-center text-decoration-none"
           style="border-radius:50px;padding:.85rem">
          <i class="bi bi-credit-card me-2"></i>Proceder al checkout
        </a>

        <!-- Login reminder -->
        <?php if (!estaLogueado()): ?>
        <div class="mt-3 p-3 rounded text-center"
             style="background:rgba(201,168,76,.1);border:1px solid rgba(201,168,76,.3);font-size:.82rem">
          <i class="bi bi-info-circle me-1 text-warning"></i>
          <a href="<?= APP_URL ?>/views/login.php" class="link-verde fw-600">Inicia sesión</a>
          para usar tus puntos y ver el costo de delivery.
        </div>
        <?php elseif ($_SESSION['usuario_puntos'] > 0): ?>
        <div class="mt-3 p-3 rounded text-center"
             style="background:rgba(26,107,58,.07);border:1px solid rgba(26,107,58,.2);font-size:.82rem">
          <i class="bi bi-star-fill me-1" style="color:var(--dorado)"></i>
          Tienes <strong><?= number_format((int)$_SESSION['usuario_puntos']) ?> puntos</strong>
          disponibles. Podrás usarlos en el checkout.
        </div>
        <?php endif; ?>

        <!-- Métodos pago -->
        <div class="mt-3 text-center">
          <div style="font-size:.72rem;color:var(--texto-suave);margin-bottom:.4rem">Métodos de pago</div>
          <div class="d-flex justify-content-center gap-2 flex-wrap">
            <span class="badge bg-light text-dark border"><i class="bi bi-cash me-1"></i>Efectivo</span>
            <span class="badge bg-light text-dark border"><i class="bi bi-qr-code me-1"></i>QR Tigo</span>
            <span class="badge bg-light text-dark border"><i class="bi bi-bank me-1"></i>Bisa</span>
          </div>
        </div>

      </div>
    </div>

  </div><!-- /row -->
  <?php endif; // carrito no vacío ?>

</div><!-- /container -->

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

<style>
/* Items carrito */
.cart-items-wrap {
  background: white; border-radius: var(--radio);
  border: 1px solid var(--gris-borde); overflow: hidden;
  box-shadow: var(--sombra);
}
.cart-item {
  display: flex; align-items: flex-start; gap: 1rem;
  padding: 1.25rem; border-bottom: 1px solid var(--gris-borde);
}
.cart-item:last-child { border-bottom: none; }
.cart-item-img {
  width: 90px; height: 90px; flex-shrink: 0;
  border-radius: 8px; overflow: hidden;
  border: 1px solid var(--gris-borde);
}
.cart-item-img img { width: 100%; height: 100%; object-fit: cover; }
.cart-item-img-ph {
  width: 100%; height: 100%;
  background: var(--gris-bg);
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem; color: #ccc;
}
.cart-item-name {
  font-weight: 600; font-size: .92rem; color: var(--texto);
  text-decoration: none; display: block;
}
.cart-item-name:hover { color: var(--verde); }
.btn-remove-item {
  background: none; border: none; color: #adb5bd;
  font-size: 1rem; cursor: pointer; padding: .2rem;
  transition: color .2s; flex-shrink: 0;
}
.btn-remove-item:hover { color: #dc3545; }

/* Qty control (igual que producto.php pero inline) */
.qty-control { display: flex; align-items: center; }
.qty-btn {
  width: 32px; height: 32px;
  background: var(--gris-bg); border: 1.5px solid var(--gris-borde);
  font-size: 1.1rem; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: var(--trans); color: var(--texto); line-height: 1;
}
.qty-btn:first-child { border-radius: 6px 0 0 6px; }
.qty-btn:last-child  { border-radius: 0 6px 6px 0; }
.qty-btn:hover { background: var(--verde); color: white; border-color: var(--verde); }
.qty-input {
  width: 60px; border-radius: 0 !important;
  border-left: none; border-right: none;
  text-align: center; font-weight: 600; font-size: .88rem;
  border-color: var(--gris-borde) !important; box-shadow: none !important; height: 32px;
  padding: .2rem .4rem;
}

/* Resumen */
.order-summary {
  background: white; border-radius: var(--radio);
  padding: 1.5rem; border: 1px solid var(--gris-borde);
  box-shadow: var(--sombra); position: sticky; top: 90px;
}

/* Mobile */
@media (max-width: 576px) {
  .cart-item { flex-wrap: wrap; }
  .cart-item-img { width: 70px; height: 70px; }
}
</style>

<script>
const BS = '<?= MONEDA ?> ';

function fmt(n) {
  return BS + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function actualizarSubtotal(id, precio, qty) {
  const sub = precio * parseFloat(qty || 0);
  const el  = document.getElementById('sub_' + id);
  if (el) el.textContent = fmt(sub);
  recalcularTotal();
}

function recalcularTotal() {
  let total = 0;
  document.querySelectorAll('.qty-input').forEach(input => {
    const id    = input.name.match(/\[(\d+)\]/)?.[1];
    const subEl = document.getElementById('sub_' + id);
    if (subEl) {
      const num = parseFloat(subEl.textContent.replace(/[^0-9.]/g,'')) || 0;
      total += num;
    }
  });
  const t1 = document.getElementById('totalGeneral');
  const t2 = document.getElementById('totalFinal');
  if (t1) t1.textContent = fmt(total);
  if (t2) t2.textContent = fmt(total);
}
</script>
