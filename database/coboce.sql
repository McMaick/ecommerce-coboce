-- ============================================================
-- BASE DE DATOS: ecommerce_coboce
-- Distribuidora Cerámica COBOCE - Cobija, Bolivia
-- Versión: 1.1 (incluye wishlist y categorías actualizadas)
-- ============================================================

CREATE DATABASE IF NOT EXISTS ecommerce_coboce
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ecommerce_coboce;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- TABLA: roles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(30)  NOT NULL UNIQUE,
    descripcion VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (nombre, descripcion) VALUES
    ('admin',    'Administrador del sistema'),
    ('cliente',  'Cliente registrado'),
    ('delivery', 'Repartidor');

-- ------------------------------------------------------------
-- TABLA: usuarios
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    rol_id        TINYINT UNSIGNED NOT NULL DEFAULT 2,
    nombre        VARCHAR(80)      NOT NULL,
    apellido      VARCHAR(80)      NOT NULL,
    email         VARCHAR(120)     NOT NULL UNIQUE,
    telefono      VARCHAR(20),
    password_hash VARCHAR(255)     NOT NULL,
    ci            VARCHAR(20),
    direccion     TEXT,
    ciudad        VARCHAR(60)      DEFAULT 'Cobija',
    puntos        INT UNSIGNED     DEFAULT 0,
    activo        TINYINT(1)       DEFAULT 1,
    creado_en     DATETIME         DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: categorias
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorias (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80) NOT NULL,
    descripcion TEXT,
    imagen      VARCHAR(255),
    activo      TINYINT(1)  DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categorias (id, nombre) VALUES
    (1, 'Ceramicas'),
    (2, 'Porcelánato'),
    (3, 'Mosaicos'),
    (4, 'Accesorios');

-- ------------------------------------------------------------
-- TABLA: productos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
    id            INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    categoria_id  SMALLINT UNSIGNED NOT NULL,
    codigo        VARCHAR(30)       UNIQUE,
    nombre        VARCHAR(150)      NOT NULL,
    descripcion   TEXT,
    precio        DECIMAL(10,2)     NOT NULL,
    precio_oferta DECIMAL(10,2)     DEFAULT NULL,
    unidad        VARCHAR(20)       DEFAULT 'm²',
    imagen        VARCHAR(255),
    stock         INT UNSIGNED      DEFAULT 0,
    stock_minimo  INT UNSIGNED      DEFAULT 5,
    puntos_genera SMALLINT UNSIGNED DEFAULT 0,
    activo        TINYINT(1)        DEFAULT 1,
    creado_en     DATETIME          DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: imagenes_producto  (galería múltiple)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS imagenes_producto (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT UNSIGNED NOT NULL,
    ruta        VARCHAR(255) NOT NULL,
    orden       TINYINT UNSIGNED DEFAULT 0,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: zonas_delivery
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zonas_delivery (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80)  NOT NULL,
    descripcion VARCHAR(150),
    costo       DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    activo      TINYINT(1)   DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO zonas_delivery (nombre, costo) VALUES
    ('Zona Centro',     15.00),
    ('Zona Norte',      20.00),
    ('Zona Sur',        20.00),
    ('Zona Este',       25.00),
    ('Zona Oeste',      25.00),
    ('Fuera de Cobija', 50.00);

-- ------------------------------------------------------------
-- TABLA: metodos_pago
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metodos_pago (
    id     TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    activo TINYINT(1)  DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO metodos_pago (nombre) VALUES
    ('Efectivo contra entrega'),
    ('Transferencia bancaria'),
    ('QR - Tigo Money'),
    ('QR - Banco Bisa');

-- ------------------------------------------------------------
-- TABLA: pedidos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedidos (
    id               INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    usuario_id       INT UNSIGNED   NOT NULL,
    codigo           VARCHAR(20)    NOT NULL UNIQUE,
    estado           ENUM('pendiente','confirmado','en_preparacion','en_camino','entregado','cancelado')
                     DEFAULT 'pendiente',
    tipo_entrega     ENUM('delivery','retiro_tienda') DEFAULT 'delivery',
    zona_id          SMALLINT UNSIGNED,
    direccion_entrega TEXT,
    referencia       VARCHAR(200),
    fecha_entrega    DATE,
    puntos_usados    INT UNSIGNED   DEFAULT 0,
    descuento_puntos DECIMAL(8,2)   DEFAULT 0.00,
    metodo_pago_id   TINYINT UNSIGNED,
    subtotal         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    costo_delivery   DECIMAL(8,2)   NOT NULL DEFAULT 0.00,
    total            DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    comprobante      VARCHAR(255),
    notas            TEXT,
    puntos_ganados   INT UNSIGNED   DEFAULT 0,
    creado_en        DATETIME       DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id)     REFERENCES usuarios(id),
    FOREIGN KEY (zona_id)        REFERENCES zonas_delivery(id),
    FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: detalle_pedido
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS detalle_pedido (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    pedido_id   INT UNSIGNED  NOT NULL,
    producto_id INT UNSIGNED  NOT NULL,
    cantidad    DECIMAL(10,2) NOT NULL,
    precio_unit DECIMAL(10,2) NOT NULL,
    subtotal    DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (pedido_id)   REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: movimientos_inventario
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    producto_id   INT UNSIGNED  NOT NULL,
    tipo          ENUM('entrada','salida','ajuste') NOT NULL,
    cantidad      DECIMAL(10,2) NOT NULL,
    stock_antes   INT           NOT NULL,
    stock_despues INT           NOT NULL,
    referencia    VARCHAR(100),
    usuario_id    INT UNSIGNED,
    notas         VARCHAR(255),
    creado_en     DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: movimientos_puntos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movimientos_puntos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT UNSIGNED NOT NULL,
    pedido_id     INT UNSIGNED,
    tipo          ENUM('ganado','canjeado','ajuste','vencimiento') NOT NULL,
    cantidad      INT          NOT NULL,
    saldo_antes   INT          NOT NULL,
    saldo_despues INT          NOT NULL,
    descripcion   VARCHAR(200),
    creado_en     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (pedido_id)  REFERENCES pedidos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: config_puntos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS config_puntos (
    id             TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    puntos_por_bs  DECIMAL(5,2)     DEFAULT 1.00,
    valor_punto_bs DECIMAL(5,2)     DEFAULT 0.10,
    max_canje_pct  TINYINT UNSIGNED DEFAULT 30,
    activo         TINYINT(1)       DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO config_puntos (puntos_por_bs, valor_punto_bs, max_canje_pct)
VALUES (1.00, 0.10, 30);

-- ------------------------------------------------------------
-- TABLA: asignaciones_delivery
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asignaciones_delivery (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id     INT UNSIGNED NOT NULL UNIQUE,
    repartidor_id INT UNSIGNED NOT NULL,
    estado        ENUM('asignado','recogido','entregado') DEFAULT 'asignado',
    asignado_en   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    entregado_en  DATETIME,
    notas         VARCHAR(255),
    FOREIGN KEY (pedido_id)     REFERENCES pedidos(id),
    FOREIGN KEY (repartidor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: sesiones_carrito  (carrito persistente)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sesiones_carrito (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED,
    session_key VARCHAR(64)  NOT NULL UNIQUE,
    creado_en   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: carrito_items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carrito_items (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    carrito_id  INT UNSIGNED  NOT NULL,
    producto_id INT UNSIGNED  NOT NULL,
    cantidad    DECIMAL(10,2) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_carrito_producto (carrito_id, producto_id),
    FOREIGN KEY (carrito_id)  REFERENCES sesiones_carrito(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: wishlist  (lista de deseos / favoritos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wishlist (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    producto_id INT UNSIGNED NOT NULL,
    creado_en   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wish_usuario_producto (usuario_id, producto_id),
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABLA: opciones_canje  (cuadrantes del paso de puntos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS opciones_canje (
    id        INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    puntos    INT UNSIGNED  NOT NULL,
    descuento DECIMAL(8,2) NOT NULL,
    activo    TINYINT(1)   DEFAULT 1,
    creado_en DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_puntos (puntos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO opciones_canje (puntos, descuento) VALUES
    (150,  5.00),
    (300, 10.00),
    (600, 20.00),
    (900, 30.00);

-- ------------------------------------------------------------
-- TABLA: config_tienda  (datos del local físico)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS config_tienda (
    id           TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(100) NOT NULL DEFAULT 'Cerámica COBOCE',
    direccion    VARCHAR(200) NOT NULL DEFAULT '',
    referencia   VARCHAR(200)          DEFAULT NULL,
    ciudad       VARCHAR(80)           DEFAULT 'Cobija, Bolivia',
    telefono     VARCHAR(20)           DEFAULT NULL,
    whatsapp     VARCHAR(20)           DEFAULT NULL,
    horario_sem  VARCHAR(100)          DEFAULT 'Lun–Sáb: 8:00–18:00',
    horario_dom  VARCHAR(100)          DEFAULT 'Domingo: Cerrado',
    maps_url     VARCHAR(500)          DEFAULT NULL,
    activo       TINYINT(1)            DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO config_tienda
    (nombre, direccion, referencia, ciudad, telefono, whatsapp, horario_sem, horario_dom)
VALUES
    ('Cerámica COBOCE',
     'Av. Pando',
     'A lado de Centro de Salud Santa Clara',
     'Cobija, Bolivia',
     '73943006',
     '73943006',
     'Lun–Sáb: 8:00–18:00',
     'Domingo: Cerrado');

-- ------------------------------------------------------------
-- ÍNDICES adicionales para rendimiento
-- ------------------------------------------------------------
CREATE INDEX idx_productos_categoria  ON productos(categoria_id);
CREATE INDEX idx_pedidos_usuario      ON pedidos(usuario_id);
CREATE INDEX idx_pedidos_estado       ON pedidos(estado);
CREATE INDEX idx_movimientos_producto ON movimientos_inventario(producto_id);
CREATE INDEX idx_puntos_usuario       ON movimientos_puntos(usuario_id);
CREATE INDEX idx_wishlist_usuario     ON wishlist(usuario_id);

SET FOREIGN_KEY_CHECKS = 1;
