<?php

declare(strict_types=1);

/** Importa usuarios desde CSV en el panel admin (por tenant). */
final class AdminUserCsvImportService
{
    private const MAX_BYTES = 2_097_152;

    /**
     * @return array{created: int, updated: int, roles_added: int, skipped: int, errors: list<string>}
     */
    public static function importFromUploadedFile(
        PDO $pdo,
        int $tenantId,
        array $file,
        string $defaultPassword = '',
    ): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se recibio un archivo CSV valido.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Archivo de carga invalido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('El archivo CSV esta vacio.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('El archivo supera el limite de 2 MB.');
        }

        $csv = utf8_file_contents($tmp);

        return self::importFromCsvString($pdo, $tenantId, $csv, $defaultPassword);
    }

    /**
     * @return array{created: int, updated: int, roles_added: int, skipped: int, errors: list<string>}
     */
    public static function importFromCsvString(
        PDO $pdo,
        int $tenantId,
        string $csv,
        string $defaultPassword = '',
    ): array {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant invalido.');
        }

        $defaultPassword = trim($defaultPassword);
        $rows = self::parseCsv($csv);
        if ($rows === []) {
            throw new RuntimeException('El CSV no tiene filas de datos.');
        }

        $stats = [
            'created' => 0,
            'updated' => 0,
            'roles_added' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $emailToId = self::loadUsersByEmail($pdo);

        foreach ($rows as $lineNo => $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['skipped']++;
                $stats['errors'][] = "Fila {$lineNo}: email invalido o vacio.";
                continue;
            }

            $name = trim((string) ($row['full_name'] ?? ''));
            $pass = trim((string) ($row['password'] ?? ''));
            if ($pass === '') {
                $pass = $defaultPassword;
            }

            $app = self::normalizeApp((string) ($row['app'] ?? ''));
            if ($app === null) {
                $stats['skipped']++;
                $stats['errors'][] = "Fila {$lineNo} ({$email}): portal invalido (use budget u oc).";
                continue;
            }

            $role = self::normalizeRole($app, (string) ($row['role'] ?? ''));

            try {
                if (!isset($emailToId[$email])) {
                    if ($pass === '' || strlen($pass) < 8) {
                        $stats['skipped']++;
                        $stats['errors'][] = "Fila {$lineNo} ({$email}): contrasena obligatoria (min 8) para usuario nuevo.";
                        continue;
                    }
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $pdo->prepare(
                        'INSERT INTO users (email, password_hash, full_name, active) VALUES (?, ?, ?, 1)'
                    )->execute([$email, $hash, $name !== '' ? $name : $email]);
                    $uid = (int) $pdo->lastInsertId();
                    $emailToId[$email] = $uid;
                    $stats['created']++;
                } else {
                    $uid = $emailToId[$email];
                    $updates = [];
                    $params = [];
                    if ($name !== '') {
                        $updates[] = 'full_name = ?';
                        $params[] = $name;
                    }
                    if ($pass !== '') {
                        if (strlen($pass) < 8) {
                            $stats['skipped']++;
                            $stats['errors'][] = "Fila {$lineNo} ({$email}): contrasena debe tener al menos 8 caracteres.";
                            continue;
                        }
                        $updates[] = 'password_hash = ?';
                        $params[] = password_hash($pass, PASSWORD_DEFAULT);
                    }
                    if ($updates !== []) {
                        $params[] = $uid;
                        $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')
                            ->execute($params);
                        $stats['updated']++;
                    }
                }

                $stRole = $pdo->prepare(
                    'SELECT id FROM user_tenant_roles WHERE user_id = ? AND tenant_id = ? AND app = ? LIMIT 1'
                );
                $stRole->execute([$uid, $tenantId, $app]);
                if ($stRole->fetchColumn()) {
                    continue;
                }

                $pdo->prepare(
                    'INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, ?, ?)'
                )->execute([$uid, $tenantId, $app, $role]);
                $stats['roles_added']++;
            } catch (Throwable $e) {
                $stats['skipped']++;
                $msg = str_contains($e->getMessage(), 'Duplicate')
                    ? 'email duplicado en la importacion'
                    : $e->getMessage();
                $stats['errors'][] = "Fila {$lineNo} ({$email}): {$msg}";
            }
        }

        return $stats;
    }

    public static function sampleCsvContent(): string
    {
        return "email;nombre;contrasena;portal;rol\n"
            . "encargado@colegio.cl;Maria Gonzalez;Cambiar123;budget;encargado\n"
            . "compras@colegio.cl;Pedro Ruiz;Cambiar123;oc;\n"
            . "admin@colegio.cl;Admin Colegio;Cambiar123;budget;tenant_admin\n";
    }

    /**
     * @return list<array{email?: string, full_name?: string, password?: string, app?: string, role?: string}>
     */
    private static function parseCsv(string $csv): array
    {
        $fh = fopen('php://memory', 'rb+');
        if ($fh === false) {
            throw new RuntimeException('No se pudo procesar el CSV.');
        }
        fwrite($fh, $csv);
        rewind($fh);

        $header = null;
        $delimiter = ';';
        $out = [];
        $lineNo = 0;

        while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
            $lineNo++;
            if ($data === [null] || $data === []) {
                continue;
            }
            if (count($data) === 1 && str_contains((string) $data[0], ',')) {
                $delimiter = ',';
                rewind($fh);
                $header = null;
                $out = [];
                $lineNo = 0;
                continue;
            }
            if ($header === null) {
                $header = self::normalizeHeaderRow($data);
                if (!isset($header['email'])) {
                    fclose($fh);
                    throw new RuntimeException('El CSV debe incluir columna email (o correo).');
                }
                continue;
            }
            $row = [];
            foreach ($header as $key => $idx) {
                $row[$key] = utf8_normalize(trim((string) ($data[$idx] ?? '')));
            }
            if (implode('', $row) === '') {
                continue;
            }
            $out[$lineNo] = $row;
        }
        fclose($fh);

        return $out;
    }

    /**
     * @param list<string|null> $cells
     * @return array<string, int>
     */
    private static function normalizeHeaderRow(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $key = self::headerKey((string) $cell);
            if ($key !== '') {
                $map[$key] = $i;
            }
        }

        return $map;
    }

    private static function headerKey(string $label): string
    {
        $n = mb_strtolower(trim($label), 'UTF-8');
        $n = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $n);
        return match ($n) {
            'email', 'correo', 'e-mail', 'mail' => 'email',
            'nombre', 'full_name', 'name', 'fullname' => 'full_name',
            'contrasena', 'password', 'clave', 'pass' => 'password',
            'portal', 'app', 'modulo', 'modulo_acceso' => 'app',
            'rol', 'role', 'budget_role', 'rol_presupuesto' => 'role',
            default => '',
        };
    }

    private static function normalizeApp(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw), 'UTF-8');
        if (in_array($v, ['budget', 'presupuesto', 'bf'], true)) {
            return 'budget';
        }
        if (in_array($v, ['oc', 'orden', 'ordenes', 'compra', 'compras'], true)) {
            return 'oc';
        }

        return null;
    }

    private static function normalizeRole(string $app, string $raw): string
    {
        if ($app === 'oc') {
            return 'operator';
        }
        $v = mb_strtolower(trim($raw), 'UTF-8');
        if (in_array($v, ['tenant_admin', 'admin', 'admin colegio', 'administrador'], true)) {
            return 'tenant_admin';
        }

        return 'encargado';
    }

    /** @return array<string, int> */
    private static function loadUsersByEmail(PDO $pdo): array
    {
        $map = [];
        $st = $pdo->query('SELECT id, email FROM users');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $map[strtolower((string) $row['email'])] = (int) $row['id'];
        }

        return $map;
    }
}
