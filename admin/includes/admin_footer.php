</main><!-- /admin-main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($scriptsAdmin)) echo $scriptsAdmin; ?>
<script>
// Cerrar sidebar al hacer click fuera (móvil)
document.addEventListener('click', function(e) {
  const sidebar = document.getElementById('adminSidebar');
  if (sidebar && sidebar.classList.contains('show') && !sidebar.contains(e.target)) {
    const toggleBtn = document.querySelector('.d-lg-none.topbar-btn');
    if (!toggleBtn?.contains(e.target)) sidebar.classList.remove('show');
  }
});

// Confirmar eliminaciones
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm)) e.preventDefault();
  });
});

// Auto-dismiss alertas de éxito
document.querySelectorAll('.alert-success').forEach(el => {
  setTimeout(() => bootstrap.Alert.getOrCreateInstance(el)?.close(), 4000);
});
</script>
</body>
</html>
