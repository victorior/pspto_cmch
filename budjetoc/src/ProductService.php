<?php

declare(strict_types=1);

final class ProductService
{
    /** @return list<array<string, mixed>> */
    public static function list(
        PDO $pdo,
        int $tenantId,
        string $q = '',
        int $limit = 50,
        int $offset = 0,
    ): array {
        $sql = 'SELECT id, code, name, unit_price, active
                FROM products WHERE tenant_id = ?';
        $params = [$tenantId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (code LIKE ? OR name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY name ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function count(PDO $pdo, int $tenantId, string $q = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE tenant_id = ?';
        $params = [$tenantId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (code LIKE ? OR name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public static function get(PDO $pdo, int $tenantId, int $id): ?array
    {
        $st = $pdo->prepare(
            'SELECT id, code, name, unit_price, active FROM products WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $st->execute([$id, $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public static function create(
        PDO $pdo,
        int $tenantId,
        string $code,
        string $name,
        float $unitPrice,
        bool $active = true,
    ): int {
        $code = mb_substr(trim($code), 0, 32);
        $name = mb_substr(trim($name), 0, 255);
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('Codigo y nombre son obligatorios.');
        }
        if ($unitPrice < 0) {
            throw new InvalidArgumentException('Precio invalido.');
        }
        $pdo->prepare(
            'INSERT INTO products (tenant_id, code, name, unit_price, active) VALUES (?, ?, ?, ?, ?)'
        )->execute([$tenantId, $code, $name, round($unitPrice, 4), $active ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    public static function update(
        PDO $pdo,
        int $tenantId,
        int $id,
        string $code,
        string $name,
        float $unitPrice,
        bool $active,
    ): void {
        $code = mb_substr(trim($code), 0, 32);
        $name = mb_substr(trim($name), 0, 255);
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('Codigo y nombre son obligatorios.');
        }
        if ($unitPrice < 0) {
            throw new InvalidArgumentException('Precio invalido.');
        }
        $st = $pdo->prepare(
            'UPDATE products SET code = ?, name = ?, unit_price = ?, active = ?
             WHERE id = ? AND tenant_id = ?'
        );
        $st->execute([$code, $name, round($unitPrice, 4), $active ? 1 : 0, $id, $tenantId]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Producto no encontrado.');
        }
    }

    public static function delete(PDO $pdo, int $tenantId, int $id): void
    {
        $st = $pdo->prepare('DELETE FROM products WHERE id = ? AND tenant_id = ?');
        $st->execute([$id, $tenantId]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Producto no encontrado.');
        }
    }

    public static function parsePrice(string $raw): float
    {
        $norm = str_replace(',', '.', str_replace(' ', '', trim($raw)));
        if ($norm === '' || !preg_match('/^\d+(\.\d+)?$/', $norm)) {
            throw new InvalidArgumentException('Precio invalido.');
        }

        return (float) $norm;
    }
}
