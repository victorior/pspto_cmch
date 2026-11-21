<?php

declare(strict_types=1);

/**
 * Valida que config apunte solo a la base budjetoc (CLI y includes).
 *
 * @param array<string, mixed> $config
 */
function require_budjetoc_database(array $config): void
{
    $env = (string) ($config['env'] ?? 'local');
    $name = (string) ($config['db']['name'] ?? '');
    if ($env === 'local' && $name !== 'budjetoc') {
        fwrite(
            STDERR,
            "ERROR: en local config debe usar db.name = 'budjetoc' (actual: '{$name}').\n"
        );
        exit(1);
    }
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fwrite(STDERR, "ERROR: db.name invalido o vacio.\n");
        exit(1);
    }
}
