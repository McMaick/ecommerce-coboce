<?php
declare(strict_types=1);
/**
 * COBOCE — Instalador del sistema
 * Accede a: http://localhost/ecommerce-coboce/install.php
 * IMPORTANTE: Elimina o bloquea este archivo después de instalar.
 */

// ── Configuración de conexión ──────────────────────────────
const DB_HOST_I = 'localhost';
const DB_USER_I = 'root';
const DB_PASS_I = '';
const DB_NAME_I = 'ecommerce_coboce';

const ADMIN_EMAIL    = 'admin@coboce.com';
const ADMIN_PASSWORD = 'Admin123!';
const ADMIN_NOMBRE   = 'Administrador';
const ADMIN_APELLIDO = 'COBOCE';

const SQL_FILE = __DIR__ . '/database/coboce.sql';

// ── Helpers de UI ──────────────────────────────────────────
$pasos   = [];
$errores = [];

function paso(string $icono, string $msg, bool $ok = true): void {
    global $pasos;
    $pasos[] = ['icono' => $icono, 'msg' => $msg, 'ok' => $ok];
}

function errorf(string $msg): void {
    global $errores;
    $errores[] = $msg;
}

// ── Proceso de instalación ─────────────────────────────────
$instalado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['instalar'] ?? '') === '1') {

    // 1. Verificar archivo SQL
    if (!file_exists(SQL_FILE)) {
        errorf('No se encontró el archivo database/coboce.sql');
    } else {
        paso('📄', 'Archivo SQL encontrado: <code>database/coboce.sql</code>');
    }

    // 2. Conectar sin BD para crearla
    if (empty($errores)) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST_I . ';charset=utf8mb4',
                DB_USER_I, DB_PASS_I,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            paso('🔌', 'Conexión a MySQL establecida correctamente');
        } catch (PDOException $e) {
            errorf('No se pudo conectar a MySQL: ' . $e->getMessage());
        }
    }

    // 3. Crear base de datos si no existe
    if (empty($errores)) {
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME_I . "`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `" . DB_NAME_I . "`");
            paso('🗄️', 'Base de datos <strong>' . DB_NAME_I . '</strong> creada/verificada');
        } catch (PDOException $e) {
            errorf('Error al crear la base de datos: ' . $e->getMessage());
        }
    }

    // 4. Ejecutar el SQL línea a línea
    if (empty($errores)) {
        try {
            $sql = file_get_contents(SQL_FILE);

            // Quitar la sentencia CREATE DATABASE y USE del SQL (ya la manejamos)
            $sql = preg_replace('/CREATE\s+DATABASE[^;]+;/i', '', $sql);
            $sql = preg_replace('/USE\s+[^;]+;/i', '', $sql);

            // Dividir en sentencias individuales (respeta DELIMITER implícito)
            $sentencias = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => $s !== ''
            );

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $total = 0;
            foreach ($sentencias as $stmt) {
                $pdo->exec($stmt);
                $total++;
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            paso('⚡', "SQL ejecutado correctamente — <strong>{$total}</strong> sentencias procesadas");
        } catch (PDOException $e) {
            errorf('Error al ejecutar el SQL: ' . $e->getMessage());
        }
    }

    // 5. Crear usuario admin
    if (empty($errores)) {
        try {
            // Verificar si ya existe
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $check->execute([ADMIN_EMAIL]);

            if ($check->fetchColumn()) {
                // Actualizar contraseña si ya existe
                $pdo->prepare("UPDATE usuarios SET password_hash = ?, rol_id = 1 WHERE email = ?")
                    ->execute([password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT), ADMIN_EMAIL]);
                paso('👤', 'Usuario admin ya existía — contraseña actualizada');
            } else {
                $pdo->prepare(
                    "INSERT INTO usuarios (rol_id, nombre, apellido, email, password_hash, activo)
                     VALUES (1, ?, ?, ?, ?, 1)"
                )->execute([
                    ADMIN_NOMBRE,
                    ADMIN_APELLIDO,
                    ADMIN_EMAIL,
                    password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT),
                ]);
                paso('👤', 'Usuario administrador creado exitosamente');
            }

            paso('🔑', 'Credenciales admin: <code>' . ADMIN_EMAIL . '</code> / <code>' . ADMIN_PASSWORD . '</code>');
        } catch (PDOException $e) {
            errorf('Error al crear el usuario admin: ' . $e->getMessage());
        }
    }

    // 6. Verificar carpeta uploads
    if (empty($errores)) {
        $uploads = __DIR__ . '/uploads';
        if (!is_dir($uploads)) {
            mkdir($uploads, 0755, true);
        }
        is_writable($uploads)
            ? paso('📁', 'Carpeta <code>uploads/</code> lista y con permisos de escritura')
            : paso('⚠️', 'Carpeta <code>uploads/</code> existe pero sin permisos de escritura — verifica manualmente', false);
    }

    // 7. Verificar versión PHP
    if (PHP_MAJOR_VERSION < 8) {
        errorf('Se requiere PHP 8.0 o superior. Versión actual: ' . PHP_VERSION);
    } else {
        paso('🐘', 'PHP ' . PHP_VERSION . ' ✓');
    }

    $instalado = empty($errores);
}

// ── UI ─────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instalador — COBOCE E-Commerce</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --verde:#1A6B3A; --dorado:#C9A84C; }
    body  { font-family:'Poppins',sans-serif; background:#F4F6F4; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem; }
    .install-card { background:white; border-radius:16px; box-shadow:0 10px 40px rgba(0,0,0,.12); overflow:hidden; width:100%; max-width:640px; }
    .install-header { background:linear-gradient(135deg,#145730,#1A6B3A); color:white; padding:2rem 2.5rem; }
    .install-header .logo { width:52px;height:52px;background:var(--dorado);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;color:#145730; }
    .install-body { padding:2.5rem; }
    .step-item { display:flex;align-items:flex-start;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f0f0f0; }
    .step-item:last-child { border:none; }
    .step-ok   { color:#198754; font-weight:600; font-size:.88rem; }
    .step-warn { color:#fd7e14; font-weight:600; font-size:.88rem; }
    .btn-install { background:var(--verde);color:white;border:none;width:100%;padding:.85rem;border-radius:10px;font-weight:700;font-size:1rem;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .2s; }
    .btn-install:hover { background:#145730; }
    .btn-install:disabled { opacity:.6; cursor:not-allowed; }
    .alert-step { background:#f8fff9;border:1px solid #c3e6cb;border-radius:10px;padding:1rem 1.2rem; }
    code { background:#f0f4f0;padding:.1rem .4rem;border-radius:4px;font-size:.85rem;color:var(--verde); }
    .credential-box { background:#fffbf0;border:1px solid var(--dorado);border-radius:10px;padding:1rem 1.2rem; }
  </style>
</head>
<body>

<div class="install-card">

  <!-- Header -->
  <div class="install-header">
    <div class="d-flex align-items-center gap-3 mb-3">
      <div class="logo">C</div>
      <div>
        <h1 class="mb-0 fw-700" style="font-size:1.4rem">COBOCE E-Commerce</h1>
        <div style="opacity:.75;font-size:.85rem">Instalador del sistema</div>
      </div>
    </div>
    <div style="font-size:.82rem;opacity:.7;background:rgba(255,255,255,.1);border-radius:8px;padding:.6rem 1rem">
      ⚠️ <strong>Elimina este archivo</strong> después de completar la instalación.
    </div>
  </div>

  <div class="install-body">

    <?php if (!$instalado && empty($pasos)): ?>
    <!-- Estado inicial -->
    <h5 class="fw-700 mb-1" style="color:#1A6B3A">¿Qué hará este instalador?</h5>
    <p class="text-muted mb-4" style="font-size:.88rem">
      Al presionar el botón se ejecutarán los siguientes pasos automáticamente:
    </p>

    <div class="alert-step mb-4">
      <div class="step-item">
        <span>🗄️</span>
        <div>
          <div class="step-ok">Crear base de datos</div>
          <small class="text-muted">Crea <code>ecommerce_coboce</code> si no existe</small>
        </div>
      </div>
      <div class="step-item">
        <span>⚡</span>
        <div>
          <div class="step-ok">Importar estructura SQL</div>
          <small class="text-muted">Ejecuta <code>database/coboce.sql</code> con todas las tablas</small>
        </div>
      </div>
      <div class="step-item">
        <span>👤</span>
        <div>
          <div class="step-ok">Crear usuario administrador</div>
          <small class="text-muted">Email: <code><?= ADMIN_EMAIL ?></code> | Password: <code><?= ADMIN_PASSWORD ?></code></small>
        </div>
      </div>
      <div class="step-item">
        <span>📁</span>
        <div>
          <div class="step-ok">Verificar carpeta uploads/</div>
          <small class="text-muted">Comprueba permisos de escritura para imágenes</small>
        </div>
      </div>
    </div>

    <div class="mb-3 p-3 rounded" style="background:#f8f9fa;border:1px solid #dee2e6;font-size:.82rem">
      <strong>Conexión que se usará:</strong><br>
      Host: <code>localhost</code> &nbsp;|&nbsp;
      BD: <code><?= DB_NAME_I ?></code> &nbsp;|&nbsp;
      Usuario: <code><?= DB_USER_I ?></code> &nbsp;|&nbsp;
      Password: <code><?= DB_PASS_I ?: '(vacía)' ?></code>
    </div>

    <form method="POST">
      <input type="hidden" name="instalar" value="1">
      <button type="submit" class="btn-install">
        🚀 &nbsp;Instalar COBOCE E-Commerce
      </button>
    </form>

    <?php elseif ($instalado): ?>
    <!-- ✅ ÉXITO -->
    <div class="text-center mb-4">
      <div style="font-size:3.5rem">🎉</div>
      <h4 class="fw-700 mt-2" style="color:#1A6B3A">¡Instalación completada!</h4>
      <p class="text-muted" style="font-size:.88rem">
        El sistema COBOCE E-Commerce está listo para usarse.
      </p>
    </div>

    <!-- Pasos ejecutados -->
    <div class="alert-step mb-4">
      <?php foreach ($pasos as $p): ?>
      <div class="step-item">
        <span><?= $p['icono'] ?></span>
        <div class="<?= $p['ok'] ? 'step-ok' : 'step-warn' ?>">
          <?= $p['msg'] ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Credenciales -->
    <div class="credential-box mb-4">
      <h6 class="fw-700 mb-3" style="color:#A8882E">🔑 Credenciales de acceso</h6>
      <div class="row g-2" style="font-size:.88rem">
        <div class="col-5 text-muted">Email admin:</div>
        <div class="col-7"><code><?= ADMIN_EMAIL ?></code></div>
        <div class="col-5 text-muted">Contraseña:</div>
        <div class="col-7"><code><?= ADMIN_PASSWORD ?></code></div>
        <div class="col-5 text-muted">URL del sitio:</div>
        <div class="col-7"><code>http://localhost/ecommerce-coboce</code></div>
      </div>
    </div>

    <!-- Botones de acceso -->
    <div class="d-grid gap-2">
      <a href="http://localhost/ecommerce-coboce/index.php"
         class="btn fw-700 py-2" style="background:#1A6B3A;color:white;border-radius:10px">
        🏠 Ir a la tienda
      </a>
      <a href="http://localhost/ecommerce-coboce/views/login.php"
         class="btn fw-600 py-2" style="background:#f8f9fa;color:#1A6B3A;border:2px solid #1A6B3A;border-radius:10px">
        🔐 Ir al login de admin
      </a>
    </div>

    <div class="mt-4 p-3 rounded text-center" style="background:#fff3cd;border:1px solid #ffc107;font-size:.8rem">
      ⚠️ <strong>Seguridad:</strong> Elimina o bloquea <code>install.php</code> ahora que la instalación está completa.
    </div>

    <?php else: ?>
    <!-- ❌ ERRORES -->
    <div class="text-center mb-4">
      <div style="font-size:3rem">❌</div>
      <h4 class="fw-700 mt-2 text-danger">Instalación con errores</h4>
    </div>

    <?php if (!empty($pasos)): ?>
    <h6 class="fw-600 mb-2">Pasos completados:</h6>
    <div class="alert-step mb-3">
      <?php foreach ($pasos as $p): ?>
      <div class="step-item">
        <span><?= $p['icono'] ?></span>
        <div class="<?= $p['ok'] ? 'step-ok' : 'step-warn' ?>"><?= $p['msg'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h6 class="fw-600 mb-2 text-danger">Errores encontrados:</h6>
    <div class="mb-4">
      <?php foreach ($errores as $err): ?>
      <div class="alert alert-danger py-2 mb-2" style="font-size:.85rem">
        <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?>
      </div>
      <?php endforeach; ?>
    </div>

    <form method="POST">
      <input type="hidden" name="instalar" value="1">
      <button type="submit" class="btn-install" style="background:#dc3545">
        🔄 &nbsp;Reintentar instalación
      </button>
    </form>

    <div class="mt-3 p-3 rounded" style="background:#f8f9fa;font-size:.8rem;color:#666">
      <strong>Soluciones comunes:</strong><br>
      • XAMPP no está corriendo — inicia Apache y MySQL en el panel<br>
      • MySQL tiene contraseña — ajusta <code>DB_PASS_I</code> en install.php<br>
      • El archivo SQL tiene errores — verifica <code>database/coboce.sql</code>
    </div>
    <?php endif; ?>

  </div><!-- /install-body -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
