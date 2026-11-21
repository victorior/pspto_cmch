<?php

declare(strict_types=1);

/** Helpers compartidos para leer cna14865_budgetflow.sql */

/** @return list<string> */
function bud_sql_extract_insert_tuples(string $dump, string $table): array
{
    $pattern = '/INSERT INTO `' . preg_quote($table, '/') . '`\s*\([^)]+\)\s*VALUES\s*(.*?);/s';
    if (!preg_match($pattern, $dump, $m)) {
        return [];
    }
    $body = $m[1];
    $rows = [];
    $len = strlen($body);
    $i = 0;
    while ($i < $len) {
        while ($i < $len && in_array($body[$i], [' ', "\n", "\r", "\t", ','], true)) {
            $i++;
        }
        if ($i >= $len || $body[$i] !== '(') {
            break;
        }
        $depth = 0;
        $start = $i;
        $inString = false;
        $escape = false;
        for (; $i < $len; $i++) {
            $c = $body[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($c === "'") {
                $inString = true;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $rows[] = substr($body, $start, $i - $start + 1);
                    $i++;
                    break;
                }
            }
        }
    }

    return $rows;
}

/** @return list<string|null> */
function bud_sql_parse_tuple(string $tuple): array
{
    $tuple = trim($tuple);
    if (str_starts_with($tuple, '(')) {
        $tuple = substr($tuple, 1, -1);
    }
    $fields = [];
    $buf = '';
    $len = strlen($tuple);
    $inString = false;
    $escape = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $tuple[$i];
        if ($inString) {
            if ($escape) {
                $buf .= $c;
                $escape = false;
            } elseif ($c === '\\') {
                $escape = true;
            } elseif ($c === "'") {
                $inString = false;
            } else {
                $buf .= $c;
            }
            continue;
        }
        if ($c === "'") {
            $inString = true;
            continue;
        }
        if ($c === ',') {
            $fields[] = bud_sql_trim_field($buf);
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $fields[] = bud_sql_trim_field($buf);

    return $fields;
}

function bud_sql_trim_field(string $v): ?string
{
    $v = trim($v);
    if (strtoupper($v) === 'NULL') {
        return null;
    }

    return $v;
}

/** Lee dump SQL legacy preservando UTF-8 (latin1 mal exportado incluido). */
function bud_sql_load_dump(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('No se pudo leer: ' . $path);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($converted !== false) {
            $raw = $converted;
        }
    }

    return $raw;
}

/** Corrige mojibake y ? por tildes/ñ en textos importados desde dumps legacy. */
function bud_sql_fix_utf8_text(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $s = str_replace("\r\n", "\n", $value);
    $map = [
        "\u{00C3}\u{00A1}" => "\u{00E1}",
        "\u{00C3}\u{00A9}" => "\u{00E9}",
        "\u{00C3}\u{00AD}" => "\u{00ED}",
        "\u{00C3}\u{00B3}" => "\u{00F3}",
        "\u{00C3}\u{00BA}" => "\u{00FA}",
        "\u{00C3}\u{00B1}" => "\u{00F1}",
        "\u{00C3}\u{2018}" => "\u{00D1}",
        "\u{00C3}\u{2030}" => "\u{00C9}",
        "\u{00C3}\u{201C}" => "\u{00D3}",
        "\u{00C3}\u{0161}" => "\u{00DA}",
        "\u{00E2}\u{20AC}\u{2122}" => "'",
        "\u{00C2}\u{00B0}" => "\u{00B0}",
        "\u{00C2}\u{00BA}" => "\u{00BA}",
    ];
    $s = strtr($s, $map);
    $replacements = [
        '/SEG\?{2,6}N/i' => 'SEGÚN',
        '/subterr\?{2,6}neo/i' => 'subterráneo',
        '/alt\?{2,6}simos/i' => 'altísimos',
        '/alt\?{2,6}simo/i' => 'altísimo',
        '/Drag\?{2,6}n/i' => 'Dragón',
        '/ni\?{2,6}os/i' => 'niños',
        '/ni\?{2,6}a/i' => 'niña',
        '/ni\?{2,6}as/i' => 'niñas',
        '/Ferreter\?{2,6}a/i' => 'Ferretería',
        '/B\?{2,6}SICO/i' => 'BÁSICO',
        '/B\?{2,6}SICA/i' => 'BÁSICA',
        '/(\d)\?{2,6}\s/' => '$1° ',
        '/N\?{2,6}/' => 'Nº',
        '/BOT\?{2,8}N/i' => 'BOTÓN',
        '/Asesor\?{2,6}as/i' => 'Asesorías',
        '/Reposici\?{2,6}n/i' => 'Reposición',
        '/Revisi\?{2,6}n/i' => 'Revisión',
        '/ba\?{2,6}o/i' => 'baño',
        '/el\?{2,6}ctrico/i' => 'eléctrico',
        '/a\?{2,6}o/i' => 'año',
        '/per\?{2,6}odicamente/i' => 'periódicamente',
        '/TERCER BOT\?{2,8}N/i' => 'TERCER BOTÓN',
        '/Inscripci\?{2,6}n/i' => 'Inscripción',
        '/Econ\?{2,6}mica/i' => 'Económica',
        '/informaci\?{2,6}n/i' => 'información',
        '/operaci\?{2,6}n/i' => 'operación',
        '/instalaci\?{2,6}n/i' => 'instalación',
        '/VERSI\?{2,8}N/i' => 'VERSIÓN',
        '/CALIGRAF\?{2,8}A/i' => 'CALIGRAFÍA',
        '/TRAVES\?{2,8}AS/i' => 'TRAVESÍAS',
        '/J\?{2,8}VENES/i' => 'JÓVENES',
        '/MATEM\?{2,8}TICA/i' => 'MATEMÁTICA',
        '/PLUM\?{2,8}N/i' => 'PLUMÓN',
        '/PL\?{2,8}STICA/i' => 'PLÁSTICA',
        '/GEOM\?{2,8}TRICOS/i' => 'GEOMÉTRICOS',
        '/ACR\?{2,8}LICOS/i' => 'ACRÍLICOS',
        '/\bah\?{2,8}\b/i' => 'ahí',
        '/D\?{2,8}A\b/i' => 'DÍA',
        '/(\d)\?{2,8}BASICO/i' => '$1° BASICO',
        '/(\d)\?{2,8}BÁSICO/i' => '$1° BÁSICO',
    ];
    foreach ($replacements as $pattern => $replacement) {
        $s = (string) preg_replace($pattern, $replacement, $s);
    }
    $s = (string) preg_replace('/N\s*\xC2\xB0/i', 'N°', $s);

    return $s;
}

/** @return list<string> rutas candidatas al dump OC legacy */
function bud_legacy_oc_dump_paths(string $projectRoot): array
{
    $paths = [
        $projectRoot . '/cna14865_oc_multitenant.sql',
        dirname($projectRoot) . '/pspto/cna14865_oc_multitenant.sql',
        dirname($projectRoot, 2) . '/pspto/cna14865_oc_multitenant.sql',
    ];

    return array_values(array_filter($paths, 'is_file'));
}

/** @return array<int, array{name: string, slug: string}> */
function bud_legacy_source_tenants(): array
{
    return [
        1 => ['name' => 'Colegio CMCH', 'slug' => 'cmch'],
        2 => ['name' => 'IAE', 'slug' => 'iae'],
    ];
}

/**
 * @return array<int, int> legacy tenant_id => budjetoc tenant_id
 */
function bud_resolve_tenant_map(PDO $pdo): array
{
    $map = [];
    foreach (bud_legacy_source_tenants() as $oldId => $meta) {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
        $st->execute([$meta['slug']]);
        $newId = $st->fetchColumn();
        if ($newId === false) {
            $pdo->prepare(
                'INSERT INTO tenants (name, slug, budget_enabled, oc_enabled, active) VALUES (?, ?, 1, 1, 1)'
            )->execute([$meta['name'], $meta['slug']]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO tenant_settings (tenant_id, company_name) VALUES (?, ?)')
                ->execute([(int) $newId, $meta['name']]);
        } else {
            $newId = (int) $newId;
        }
        $map[$oldId] = $newId;
    }

    return $map;
}
