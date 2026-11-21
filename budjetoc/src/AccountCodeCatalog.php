<?php

declare(strict_types=1);

/** Resuelve Nº Cta (plan_cuenta.id) desde account_codes o valores guardados en OC. */
final class AccountCodeCatalog
{
    /** @var array<string, int>|null */
    private static ?array $planByNameKey = null;

    public static function normalizeNameKey(string $name): string
    {
        $n = mb_strtoupper(trim($name));
        $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
        foreach ([' DE ', ' DEL ', ' LA ', ' EL ', ' LOS ', ' LAS '] as $token) {
            $n = str_replace($token, ' ', $n);
        }

        return trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
    }

    /** @return array<string, int> */
    private static function planByNameKey(PDO $pdo): array
    {
        if (self::$planByNameKey !== null) {
            return self::$planByNameKey;
        }

        self::$planByNameKey = [];
        try {
            $rows = $pdo->query('SELECT id, name FROM plan_cuenta ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return self::$planByNameKey;
        }

        foreach ($rows as $row) {
            $key = self::normalizeNameKey((string) ($row['name'] ?? ''));
            if ($key !== '' && !isset(self::$planByNameKey[$key])) {
                self::$planByNameKey[$key] = (int) $row['id'];
            }
        }

        return self::$planByNameKey;
    }

    public static function planCuentaIdByName(PDO $pdo, string $name): ?int
    {
        $key = self::normalizeNameKey($name);
        if ($key === '') {
            return null;
        }

        return self::planByNameKey($pdo)[$key] ?? null;
    }

    /**
     * Numero de cuenta contable para mostrar / guardar en OC (plan_cuenta.id).
     *
     * @param array{id?: int|string, account_id?: int|string, name?: string}|null $accountRow
     */
    public static function displayAccountId(PDO $pdo, int $tenantId, int $stored, ?array $accountRow = null): ?int
    {
        if ($stored <= 0 && $accountRow === null) {
            return null;
        }

        if ($accountRow === null) {
            $st = $pdo->prepare(
                'SELECT id, account_id, name FROM account_codes
                 WHERE tenant_id = ? AND (id = ? OR account_id = ?)
                 ORDER BY (id = ?) DESC, id ASC LIMIT 1'
            );
            $st->execute([$tenantId, $stored, $stored, $stored]);
            $accountRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (is_array($accountRow)) {
            return self::displayAccountIdFromRow($pdo, $accountRow);
        }

        try {
            $stPlan = $pdo->prepare('SELECT id FROM plan_cuenta WHERE id = ? LIMIT 1');
            $stPlan->execute([$stored]);
            if ($stPlan->fetchColumn()) {
                return $stored;
            }
        } catch (Throwable) {
        }

        return $stored > 0 ? $stored : null;
    }

    /** @param array{id?: int|string, account_id?: int|string, name?: string} $row */
    public static function displayAccountIdFromRow(PDO $pdo, array $row): int
    {
        $pk = (int) ($row['id'] ?? 0);
        $accountId = (int) ($row['account_id'] ?? 0);
        $name = (string) ($row['name'] ?? '');

        if ($accountId >= 100000 || ($accountId > 0 && $accountId !== $pk)) {
            return $accountId;
        }

        $fromPlan = self::planCuentaIdByName($pdo, $name);
        if ($fromPlan !== null) {
            return $fromPlan;
        }

        if ($accountId > 0) {
            return $accountId;
        }

        return $pk;
    }

    /** @param array<string, mixed> $po */
    public static function poCtaLabel(PDO $pdo, int $tenantId, array $po, string $emptyLabel): string
    {
        $parts = [];
        foreach (['account_code_1', 'account_code_2', 'account_code_3', 'account_code_4'] as $key) {
            $display = self::displayAccountId($pdo, $tenantId, (int) ($po[$key] ?? 0));
            if ($display !== null && $display > 0) {
                $parts[] = (string) $display;
            }
        }

        return $parts !== [] ? implode(' - ', $parts) : $emptyLabel;
    }
}
