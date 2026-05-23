</main><!-- /main-content -->

<!-- ── FOOTER ─────────────────────────────────────────────── -->
<footer class="site-footer" id="contacto">
  <div class="container">
    <div class="row g-4">

      <!-- Marca -->
      <div class="col-12 col-md-6 col-lg-4">
        <div class="footer-logo d-flex align-items-center gap-2 mb-3">
          <div class="logo-icon">C</div>
          <div>
            <div class="logo-brand">COBOCE</div>
            <div class="logo-sub">Cerámica &amp; Porcelánato</div>
          </div>
        </div>
        <p class="footer-desc">
          Distribuidora oficial de Cerámica COBOCE en Cobija, Bolivia.
          Más de 500 modelos en pisos, porcelánato, revestimientos y accesorios
          para tu hogar o proyecto.
        </p>
        <div class="d-flex gap-2 mt-3">
          <a href="https://www.facebook.com/share/1b4zHWPNFh/" target="_blank" class="social-btn" title="Facebook"><i class="bi bi-facebook"></i></a>
          <a href="https://wa.me/59173943006" target="_blank" class="social-btn" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>
          <a href="https://wa.me/59178879418" target="_blank" class="social-btn" title="WhatsApp 2"><i class="bi bi-whatsapp"></i></a>
        </div>
      </div>

      <!-- Links rápidos -->
      <div class="col-6 col-md-3 col-lg-2">
        <h6 class="footer-title">Tienda</h6>
        <a href="<?= APP_URL ?>/views/catalogo.php" class="footer-link">
          <i class="bi bi-chevron-right"></i>Catálogo
        </a>
        <a href="<?= APP_URL ?>/views/catalogo.php?oferta=1" class="footer-link">
          <i class="bi bi-chevron-right"></i>Ofertas
        </a>
        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=1" class="footer-link">
          <i class="bi bi-chevron-right"></i>Pisos
        </a>
        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=3" class="footer-link">
          <i class="bi bi-chevron-right"></i>Porcelánato
        </a>
        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=2" class="footer-link">
          <i class="bi bi-chevron-right"></i>Revestimientos
        </a>
        <a href="<?= APP_URL ?>/views/catalogo.php?categoria=5" class="footer-link">
          <i class="bi bi-chevron-right"></i>Accesorios
        </a>
      </div>

      <!-- Mi cuenta -->
      <div class="col-6 col-md-3 col-lg-2">
        <h6 class="footer-title">Mi Cuenta</h6>
        <a href="<?= APP_URL ?>/views/login.php" class="footer-link">
          <i class="bi bi-chevron-right"></i>Iniciar sesión
        </a>
        <a href="<?= APP_URL ?>/views/registro.php" class="footer-link">
          <i class="bi bi-chevron-right"></i>Registrarse
        </a>
        <a href="<?= APP_URL ?>/views/mis-pedidos.php" class="footer-link">
          <i class="bi bi-chevron-right"></i>Mis pedidos
        </a>
        <a href="<?= APP_URL ?>/views/mi-cuenta.php#puntos" class="footer-link">
          <i class="bi bi-chevron-right"></i>Mis puntos
        </a>
        <a href="<?= APP_URL ?>/views/carrito.php" class="footer-link">
          <i class="bi bi-chevron-right"></i>Mi carrito
        </a>
      </div>

      <!-- Contacto -->
      <div class="col-12 col-lg-4" id="delivery">
        <h6 class="footer-title">Contacto &amp; Delivery</h6>
        <p class="footer-contact">
          <i class="bi bi-geo-alt-fill"></i>
          Av. Pando a lado de Centro de Salud Santa Clara, <?= CIUDAD ?>
        </p>
        <p class="footer-contact">
          <i class="bi bi-telephone-fill"></i>
          +591 73943006
        </p>
        <p class="footer-contact">
          <a href="https://wa.me/59173943006" target="_blank" style="color:inherit;text-decoration:none">
            <i class="bi bi-whatsapp"></i>
            +591 73943006
          </a>
        </p>
        <p class="footer-contact">
          <a href="https://wa.me/59178879418" target="_blank" style="color:inherit;text-decoration:none">
            <i class="bi bi-whatsapp"></i>
            +591 78879418
          </a>
        </p>
        <p class="footer-contact">
          <a href="https://wa.me/59169561576" target="_blank" style="color:inherit;text-decoration:none">
            <i class="bi bi-whatsapp"></i>
            +591 69561576
          </a>
        </p>
        <p class="footer-contact">
          <i class="bi bi-clock-fill"></i>
          Lun–Sáb: 8:00 – 18:00
        </p>
        <!-- Puntos de fidelidad -->
        <div class="mt-3 p-3 rounded" style="background:rgba(201,168,76,.12);border:1px solid rgba(201,168,76,.3)" id="puntos">
          <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-star-fill text-warning"></i>
            <strong style="color:var(--dorado-light);font-size:.85rem">Sistema de Puntos COBOCE</strong>
          </div>
          <small style="opacity:.75;font-size:.78rem">
            Gana 1 punto por cada Bs. comprado. ¡Canjéalos en tu próxima compra!
          </small>
        </div>
      </div>

    </div>
  </div>

  <!-- Footer bottom -->
  <div class="footer-bottom mt-4">
    <div class="container">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <span>&copy; <?= date('Y') ?> <?= APP_NAME ?>. Todos los derechos reservados.</span>
        <span class="opacity-60">Desarrollado con PHP 8 &amp; Bootstrap 5</span>
      </div>
    </div>
  </div>
</footer>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Main JS -->
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<?php if (isset($scriptsExtra)) echo $scriptsExtra; ?>

</body>
</html>
