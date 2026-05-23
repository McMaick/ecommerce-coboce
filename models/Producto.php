<?php
declare(strict_types=1);

class Producto {
    private PDO $db;

    // Iconos por defecto para cada categoría
    public const ICONOS_CAT = [
        'Ceramicas'       => 'bi-square-fill',
        'Porcelánato'     => 'bi-gem',
        'Mosaicos'        => 'bi-grid-3x3-gap-fill',
        'Accesorios'      => 'bi-tools',
    ];

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── Categorías ────────────────────────────────────────────
    public function listarCategorias(): array {
        $stmt = $this->db->query(
            "SELECT c.*, COUNT(p.id) AS total
             FROM   categorias c
             LEFT JOIN productos p ON p.categoria_id = c.id AND p.activo = 1
             WHERE  c.activo = 1
             GROUP  BY c.id
             ORDER  BY c.nombre ASC"
        );
        return $stmt->fetchAll();
    }

    // ── Productos para el HOME ─────────────────────────────────
    public function listarDestacados(int $limite = 8): array {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  p.activo = 1 AND p.stock > 0
             ORDER  BY (p.precio_oferta IS NOT NULL) DESC, p.creado_en DESC
             LIMIT  :lim"
        );
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Catálogo con filtros ───────────────────────────────────
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 12): array {
        [$where, $params] = $this->buildWhere($filtros);
        $order  = $this->buildOrder($filtros['orden'] ?? '');
        $offset = ($pagina - 1) * $porPagina;

        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  p.activo = 1 $where
             ORDER  BY $order
             LIMIT  :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function contar(array $filtros = []): int {
        [$where, $params] = $this->buildWhere($filtros);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM productos p WHERE p.activo = 1 $where"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function buildWhere(array $f): array {
        $where  = '';
        $params = [];

        if (!empty($f['q'])) {
            $where .= ' AND (p.nombre LIKE :q OR p.descripcion LIKE :q2 OR p.codigo LIKE :q3)';
            $like   = '%' . $f['q'] . '%';
            $params[':q']  = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if (!empty($f['categoria'])) {
            $where .= ' AND p.categoria_id = :cat';
            $params[':cat'] = (int) $f['categoria'];
        }
        if (isset($f['precio_min']) && $f['precio_min'] !== '') {
            $where .= ' AND COALESCE(p.precio_oferta, p.precio) >= :pmin';
            $params[':pmin'] = (float) $f['precio_min'];
        }
        if (isset($f['precio_max']) && $f['precio_max'] !== '') {
            $where .= ' AND COALESCE(p.precio_oferta, p.precio) <= :pmax';
            $params[':pmax'] = (float) $f['precio_max'];
        }
        if (!empty($f['solo_oferta'])) {
            $where .= ' AND p.precio_oferta IS NOT NULL';
        }
        if (!empty($f['en_stock'])) {
            $where .= ' AND p.stock > 0';
        }

        return [$where, $params];
    }

    private function buildOrder(string $orden): string {
        return match($orden) {
            'precio_asc'  => 'COALESCE(p.precio_oferta, p.precio) ASC',
            'precio_desc' => 'COALESCE(p.precio_oferta, p.precio) DESC',
            'nombre_asc'  => 'p.nombre ASC',
            'nombre_desc' => 'p.nombre DESC',
            'oferta'      => '(p.precio_oferta IS NULL) ASC, p.creado_en DESC',
            default       => 'p.creado_en DESC',
        };
    }

    // ── Producto individual ────────────────────────────────────
    public function buscarPorId(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  p.id = :id AND p.activo = 1
             LIMIT  1"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorCodigo(string $codigo): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  p.codigo = :cod AND p.activo = 1 LIMIT 1"
        );
        $stmt->execute([':cod' => $codigo]);
        return $stmt->fetch() ?: null;
    }

    // Galería de imágenes adicionales
    public function imagenes(int $productoId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM imagenes_producto
             WHERE  producto_id = :id ORDER BY orden ASC"
        );
        $stmt->execute([':id' => $productoId]);
        return $stmt->fetchAll();
    }

    // Productos relacionados (misma categoría)
    public function relacionados(int $productoId, int $categoriaId, int $limite = 4): array {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  p.categoria_id = :cat AND p.id != :id AND p.activo = 1
             ORDER  BY RAND()
             LIMIT  :lim"
        );
        $stmt->bindValue(':cat', $categoriaId, PDO::PARAM_INT);
        $stmt->bindValue(':id',  $productoId,  PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limite,      PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Rango de precios (para filtro de precio)
    public function rangoPrecios(): array {
        $row = $this->db->query(
            "SELECT FLOOR(MIN(COALESCE(precio_oferta, precio))) AS minimo,
                    CEIL(MAX(precio)) AS maximo
             FROM   productos WHERE activo = 1"
        )->fetch();
        return [
            'min' => (int)($row['minimo'] ?? 0),
            'max' => (int)($row['maximo'] ?? 500),
        ];
    }

    // ── CRUD Admin ─────────────────────────────────────────────
    public function crear(array $d): int|false {
        $stmt = $this->db->prepare(
            "INSERT INTO productos
                (categoria_id, codigo, nombre, descripcion,
                 precio, precio_oferta, unidad, imagen,
                 stock, stock_minimo, puntos_genera)
             VALUES
                (:cat, :cod, :nom, :desc,
                 :precio, :oferta, :unidad, :img,
                 :stock, :smin, :puntos)"
        );
        $ok = $stmt->execute([
            ':cat'    => (int)   $d['categoria_id'],
            ':cod'    =>         $d['codigo']        ?? null,
            ':nom'    =>         $d['nombre'],
            ':desc'   =>         $d['descripcion']   ?? null,
            ':precio' => (float) $d['precio'],
            ':oferta' => isset($d['precio_oferta']) && $d['precio_oferta'] !== ''
                            ? (float) $d['precio_oferta'] : null,
            ':unidad' =>         $d['unidad']         ?? 'm²',
            ':img'    =>         $d['imagen']         ?? null,
            ':stock'  => (int)   ($d['stock']         ?? 0),
            ':smin'   => (int)   ($d['stock_minimo']  ?? 5),
            ':puntos' => (int)   ($d['puntos_genera'] ?? 0),
        ]);
        return $ok ? (int) $this->db->lastInsertId() : false;
    }

    public function actualizar(int $id, array $d): bool {
        $imgSql = isset($d['imagen']) ? ', imagen = :img' : '';
        $stmt   = $this->db->prepare(
            "UPDATE productos
             SET categoria_id   = :cat,
                 codigo         = :cod,
                 nombre         = :nom,
                 descripcion    = :desc,
                 precio         = :precio,
                 precio_oferta  = :oferta,
                 unidad         = :unidad,
                 stock          = :stock,
                 stock_minimo   = :smin,
                 puntos_genera  = :puntos
                 $imgSql
             WHERE id = :id"
        );
        $params = [
            ':cat'    => (int)   $d['categoria_id'],
            ':cod'    =>         $d['codigo']        ?? null,
            ':nom'    =>         $d['nombre'],
            ':desc'   =>         $d['descripcion']   ?? null,
            ':precio' => (float) $d['precio'],
            ':oferta' => isset($d['precio_oferta']) && $d['precio_oferta'] !== ''
                            ? (float) $d['precio_oferta'] : null,
            ':unidad' =>         $d['unidad']         ?? 'm²',
            ':stock'  => (int)   ($d['stock']         ?? 0),
            ':smin'   => (int)   ($d['stock_minimo']  ?? 5),
            ':puntos' => (int)   ($d['puntos_genera'] ?? 0),
            ':id'     => $id,
        ];
        if (isset($d['imagen'])) $params[':img'] = $d['imagen'];
        return $stmt->execute($params);
    }

    public function eliminar(int $id): bool {
        return $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id")
                        ->execute([':id' => $id]);
    }

    public function actualizarStock(int $id, int $delta, string $tipo, ?int $usuarioId = null, string $ref = ''): void {
        $row = $this->db->prepare("SELECT stock FROM productos WHERE id = :id FOR UPDATE");
        $row->execute([':id' => $id]);
        $stockActual = (int) $row->fetchColumn();
        $stockNuevo  = max(0, $stockActual + $delta);

        $this->db->prepare("UPDATE productos SET stock = :s WHERE id = :id")
                 ->execute([':s' => $stockNuevo, ':id' => $id]);

        $this->db->prepare(
            "INSERT INTO movimientos_inventario
                (producto_id, tipo, cantidad, stock_antes, stock_despues, referencia, usuario_id)
             VALUES (:pid, :tipo, :qty, :sa, :sd, :ref, :uid)"
        )->execute([
            ':pid'  => $id,
            ':tipo' => $tipo,
            ':qty'  => abs($delta),
            ':sa'   => $stockActual,
            ':sd'   => $stockNuevo,
            ':ref'  => $ref,
            ':uid'  => $usuarioId,
        ]);
    }

    // ── Admin: listar todos (incluso inactivos) ────────────────
    public function listarAdmin(string $q = '', int $pagina = 1, int $porPagina = 20): array {
        $where  = $q ? "AND (p.nombre LIKE :q OR p.codigo LIKE :q2)" : '';
        $offset = ($pagina - 1) * $porPagina;
        $stmt   = $this->db->prepare(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  1=1 $where
             ORDER  BY p.creado_en DESC
             LIMIT  :lim OFFSET :off"
        );
        if ($q) {
            $stmt->bindValue(':q',  "%$q%");
            $stmt->bindValue(':q2', "%$q%");
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function contarAdmin(string $q = ''): int {
        $where = $q ? "AND (p.nombre LIKE :q OR p.codigo LIKE :q2)" : '';
        $stmt  = $this->db->prepare(
            "SELECT COUNT(*) FROM productos p WHERE 1=1 $where"
        );
        if ($q) {
            $stmt->bindValue(':q',  "%$q%");
            $stmt->bindValue(':q2', "%$q%");
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function stockBajo(): array {
        $stmt = $this->db->query(
            "SELECT p.*, c.nombre AS categoria
             FROM   productos p
             JOIN   categorias c ON c.id = p.categoria_id
             WHERE  p.activo = 1 AND p.stock <= p.stock_minimo
             ORDER  BY p.stock ASC"
        );
        return $stmt->fetchAll();
    }
}
