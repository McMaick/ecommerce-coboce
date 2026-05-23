<?php
declare(strict_types=1);

class Usuario {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public const PUNTOS_BIENVENIDA = 50;

    public function crear(array $datos): int|false {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO usuarios
                     (rol_id, nombre, apellido, email, telefono, ci, password_hash, direccion, puntos)
                 VALUES (2, :nombre, :apellido, :email, :telefono, :ci, :hash, :direccion, :puntos)"
            )->execute([
                ':nombre'   => trim($datos['nombre']),
                ':apellido' => trim($datos['apellido']),
                ':email'    => strtolower(trim($datos['email'])),
                ':telefono' => $datos['telefono'] ?? null,
                ':ci'       => $datos['ci']       ?? null,
                ':hash'     => password_hash($datos['password'], PASSWORD_DEFAULT),
                ':direccion'=> $datos['direccion'] ?? null,
                ':puntos'   => self::PUNTOS_BIENVENIDA,
            ]);

            $id = (int) $this->db->lastInsertId();

            $this->db->prepare(
                "INSERT INTO movimientos_puntos
                     (usuario_id, pedido_id, tipo, cantidad, saldo_antes, saldo_despues, descripcion)
                 VALUES (:uid, NULL, 'ganado', :cantidad, 0, :saldo_despues, 'Puntos de bienvenida')"
            )->execute([
                ':uid'          => $id,
                ':cantidad'     => self::PUNTOS_BIENVENIDA,
                ':saldo_despues'=> self::PUNTOS_BIENVENIDA,
            ]);

            $this->db->commit();
            return $id;
        } catch (Throwable) {
            $this->db->rollBack();
            return false;
        }
    }

    public function buscarPorEmail(string $email): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, r.nombre AS rol
             FROM   usuarios u
             JOIN   roles r ON r.id = u.rol_id
             WHERE  u.email = :email AND u.activo = 1
             LIMIT  1"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, r.nombre AS rol
             FROM   usuarios u
             JOIN   roles r ON r.id = u.rol_id
             WHERE  u.id = :id
             LIMIT  1"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function emailExiste(string $email): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuarios WHERE email = :email LIMIT 1"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    }

    public function actualizarPuntos(int $id, int $puntos): void {
        $this->db->prepare("UPDATE usuarios SET puntos = :p WHERE id = :id")
                 ->execute([':p' => $puntos, ':id' => $id]);
    }

    public function actualizarPerfil(int $id, array $datos): bool {
        $stmt = $this->db->prepare(
            "UPDATE usuarios
             SET nombre = :nombre, apellido = :apellido,
                 telefono = :telefono, ci = :ci, direccion = :direccion
             WHERE id = :id"
        );
        return $stmt->execute([
            ':nombre'   => trim($datos['nombre']),
            ':apellido' => trim($datos['apellido']),
            ':telefono' => $datos['telefono'] ?? null,
            ':ci'       => $datos['ci']       ?? null,
            ':direccion'=> $datos['direccion'] ?? null,
            ':id'       => $id,
        ]);
    }

    public function cambiarPassword(int $id, string $nuevaPassword): bool {
        $stmt = $this->db->prepare(
            "UPDATE usuarios SET password_hash = :h WHERE id = :id"
        );
        return $stmt->execute([
            ':h'  => password_hash($nuevaPassword, PASSWORD_DEFAULT),
            ':id' => $id,
        ]);
    }

    public function listarTodos(int $limite = 100, int $offset = 0): array {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.nombre, u.apellido, u.email, u.telefono,
                    u.puntos, u.activo, u.creado_en, r.nombre AS rol
             FROM   usuarios u JOIN roles r ON r.id = u.rol_id
             ORDER BY u.creado_en DESC
             LIMIT :limite OFFSET :offset"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
