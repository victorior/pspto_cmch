<?php

declare(strict_types=1);

final class SupplierService
{
    /** @return list<array<string, mixed>> */
    public static function list(
        PDO $pdo,
        int $tenantId,
        string $q = '',
        int $limit = 50,
        int $offset = 0,
    ): array {
        $sql = 'SELECT id, legal_name, trade_name, phone, email
                FROM suppliers WHERE tenant_id = ?';
        $params = [$tenantId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (legal_name LIKE ? OR trade_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        $sql .= ' ORDER BY legal_name ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function count(PDO $pdo, int $tenantId, string $q = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM suppliers WHERE tenant_id = ?';
        $params = [$tenantId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (legal_name LIKE ? OR trade_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public static function get(PDO $pdo, int $tenantId, int $id): ?array
    {
        $st = $pdo->prepare(
            'SELECT id, legal_name, trade_name, phone, email
             FROM suppliers WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $st->execute([$id, $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public static function displayName(array $row): string
    {
        $legal = trim((string) ($row['legal_name'] ?? ''));
        $trade = trim((string) ($row['trade_name'] ?? ''));

        return $trade !== '' ? $legal . ' / ' . $trade : $legal;
    }

    public static function create(
        PDO $pdo,
        int $tenantId,
        string $legalName,
        string $tradeName,
        string $phone,
        string $email,
    ): int {
        $legalName = mb_substr(utf8_normalize(trim($legalName)), 0, 255);
        if ($legalName === '') {
            throw new InvalidArgumentException('Razon social es obligatoria.');
        }
        $pdo->prepare(
            'INSERT INTO suppliers (tenant_id, legal_name, trade_name, phone, email) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $tenantId,
            $legalName,
            mb_substr(utf8_normalize(trim($tradeName)), 0, 255),
            mb_substr(utf8_normalize(trim($phone)), 0, 64),
            mb_substr(utf8_normalize(trim($email)), 0, 255),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function update(
        PDO $pdo,
        int $tenantId,
        int $id,
        string $legalName,
        string $tradeName,
        string $phone,
        string $email,
    ): void {
        $legalName = mb_substr(utf8_normalize(trim($legalName)), 0, 255);
        if ($legalName === '') {
            throw new InvalidArgumentException('Razon social es obligatoria.');
        }
        $st = $pdo->prepare(
            'UPDATE suppliers SET legal_name = ?, trade_name = ?, phone = ?, email = ?
             WHERE id = ? AND tenant_id = ?'
        );
        $st->execute([
            $legalName,
            mb_substr(utf8_normalize(trim($tradeName)), 0, 255),
            mb_substr(utf8_normalize(trim($phone)), 0, 64),
            mb_substr(utf8_normalize(trim($email)), 0, 255),
            $id,
            $tenantId,
        ]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Proveedor no encontrado.');
        }
    }

    public static function delete(PDO $pdo, int $tenantId, int $id): void
    {
        $st = $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND tenant_id = ?');
        $st->execute([$id, $tenantId]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Proveedor no encontrado.');
        }
    }
}
