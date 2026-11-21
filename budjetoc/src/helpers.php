<?php

declare(strict_types=1);

function h(string|int|float|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Texto de formulario, CSV o archivo legacy → UTF-8 válido (evita ? al guardar tildes). */
function utf8_normalize(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (mb_check_encoding($value, 'UTF-8')) {
        // Reparar mojibake típico (UTF-8 leído como Windows-1252).
        if (!preg_match('/(?:Ã.|â€|ï¿½)/u', $value)) {
            return $value;
        }
        $repaired = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        if ($repaired !== false && mb_check_encoding($repaired, 'UTF-8')) {
            return $repaired;
        }

        return $value;
    }
    $from = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
    if ($from === false || $from === 'UTF-8') {
        $from = 'Windows-1252';
    }

    return mb_convert_encoding($value, 'UTF-8', $from);
}

/** Lee archivo de texto (CSV/SQL export) con BOM y codificación Windows. */
function utf8_file_contents(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('No se pudo leer: ' . $path);
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    return $raw;
}

/** Quita URLs del texto (pie de impresión OC, notas). */
function oc_strip_http_urls(string $s): string
{
    $t = $s;
    $prev = null;
    while ($prev !== $t) {
        $prev = $t;
        $t = preg_replace('#https?:/*[^\s<>"\']+#iu', '', $t);
        if (!is_string($t)) {
            return trim($s);
        }
        $t = preg_replace('/\s{2,}/u', ' ', $t);
        $t = trim($t);
    }

    return $t;
}

/** Texto UI en español (UTF-8 seguro aunque el .php no esté en UTF-8). */
function t_es(string $key): string
{
    static $labels = [
        'catalogo' => "Cat\u{00E1}logo",
        'catalogo_productos' => "Cat\u{00E1}logo de productos",
        'catalogo_proveedores' => "Cat\u{00E1}logo de proveedores",
        'razon_social' => "Raz\u{00F3}n social",
        'telefono' => "Tel\u{00E9}fono",
        'codigo' => "C\u{00F3}digo",
        'descripcion' => "Descripci\u{00F3}n",
        'credito' => "Cr\u{00E9}dito",
        'imputacion' => "Imputaci\u{00F3}n",
        'observacion' => "Observaci\u{00F3}n",
        'linea' => "L\u{00ED}nea",
        'saldo_linea' => "Saldo l\u{00ED}nea",
        'ano' => "a\u{00F1}o",
        'dias' => "d\u{00ED}as",
        'division' => "Divisi\u{00F3}n",
        'coordinacion' => "coordinaci\u{00F3}n",
        'aqui' => "aqu\u{00ED}",
        'n_ordinal' => "N\u{00BA}",
        'n_ordinal_colon' => "N\u{00BA}:",
        'n_cta' => "N\u{00BA} Cta:",
        'n_actv_monto' => "N\u{00BA} Actv, Monto:",
        'orden_compra' => "ORDEN DE COMPRA",
        'factura' => "Factura",
        'sin_valor' => "\u{2014}",
        'em_dash' => "\u{2014}",
        'n_actividad_ej' => "(N\u{00BA} actividad)",
        'n_empresa' => "N\u{00BA} empresa",
        'nro_req' => "Nro. Req.",
        'nro_req_ph' => "Nro. de Requerimiento",
        'pago_15_dias' => "Pago a 15 d\u{00ED}as",
        'pago_7_dias' => "Pago a 7 d\u{00ED}as",
        'pago_30_dias' => "Pago a 30 d\u{00ED}as",
        'uso_interno' => "Uso interno \u{2014} Adquisiciones",
        'nombre_comercial' => "Nombre comercial",
        'descripcion_col' => "DESCRIPCI\u{00D3}N",
        'print_reception_intro' => "Los productos ser\u{00E1}n recepcionados en el siguiente horario:",
        'print_reception_por' => "Recepci\u{00F3}n por",
        'facturar_a' => "Facturar a",
        'encargado' => "Encargado",
        'pagina' => "P\u{00E1}gina",
        'copyright_iae' => "\u{00A9} IAE",
        'sep_middle' => "\u{00B7}",
        'sep_title' => "\u{2014}",
        'mes' => "Mes",
        'total' => "Total",
        'utilizado' => "Utilizado",
        'quedan' => "Quedan",
        'guardar_cambios' => "Guardar cambios",
        'montos_guardados' => "Montos imputados guardados.",
        'no_actividad' => "No se encontr\u{00F3} actividad",
        'nueva_oc' => "Nueva OC",
        'editar_oc' => "Editar OC",
        'guardar_oc' => "Guardar OC",
        'editar' => "Editar",
        'perfil_tributario' => "Perfil tributario",
        'guardar_perfil_tributario' => "Guardar perfil tributario",
        'oc_perfil_actualizado' => "Perfil tributario actualizado.",
        'quitar' => "Quitar",
        'oc_actualizada' => "OC actualizada.",
        'ordenes_compra' => "\u{00D3}rdenes de compra",
        'ano_folio' => "A\u{00F1}o folio",
        'plantilla_impresion' => "Plantilla impresi\u{00F3}n",
        'sin_oc_en_ano' => "Sin OC en este a\u{00F1}o.",
        'range_sep' => "\u{2013}",
        'pie_pagina' => "Pie de p\u{00E1}gina",
        'direccion' => "Direcci\u{00F3}n",
        'print_linea_2_horario' => "L\u{00ED}nea 2 \u{2014} Horario (solo el horario, sin el texto \u{00AB}Los productos...\u{00BB})",
        'print_linea_3_direccion' => "L\u{00ED}nea 3 \u{2014} Direcci\u{00F3}n (solo calle; en la OC se antepone \u{00AB}",
        'print_footer_placeholder' => "\u{00A9} IAE 2026",
    ];

    return $labels[$key] ?? $key;
}

/** Respuesta JSON con tildes y eñes legibles (sin \uXXXX). */
function json_utf8(mixed $data, int $flags = 0): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | $flags
    );
}

/** Home global: selector Presupuesto / OC (misma URL que /budjetoc/public/). */
function portal_home_href(): string
{
    global $CONFIG;
    $base = rtrim((string) ($CONFIG['base_url'] ?? ''), '/');

    return $base !== '' ? $base . '/' : url_path('index.php');
}

/**
 * Enlace «Inicio» del menu superior dentro de un portal (no cierra la sesion del modulo).
 */
function portal_inicio_href(string $realm): string
{
    if ($realm === 'budget') {
        Auth::startRealm(Auth::REALM_BUDGET);
        $yCal = (int) date('Y');
        $tid = Auth::tenantId();
        $budgetYear = $yCal;
        if ($tid !== null && isset($_SESSION['budjetoc_budget_year'][(int) $tid])) {
            $budgetYear = (int) $_SESSION['budjetoc_budget_year'][(int) $tid];
        }
        if ($budgetYear < $yCal - 1 || $budgetYear > $yCal + 1) {
            $budgetYear = $yCal;
        }

        return 'index.php?budget_year=' . $budgetYear;
    }
    if ($realm === 'oc') {
        return 'index.php?year=' . (int) date('Y');
    }

    return portal_home_href();
}

function url_path(string $path): string
{
    global $CONFIG;
    $base = rtrim(str_replace('\\', '/', (string) ($CONFIG['base_url'] ?? '')), '/');
    if ($base === '' || preg_match('#^[A-Za-z]:#', $base) || !str_starts_with($base, '/')) {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim(dirname($script), '/');
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    while (str_starts_with($path, '../') || $path === '..') {
        $path = preg_replace('#^\.\./#', '', $path) ?? $path;
        if ($path === '..') {
            $path = '';
            break;
        }
    }

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    if ($base === '') {
        return '/' . $path;
    }

    return $base . '/' . $path;
}

/** URL absoluta para OAuth y enlaces externos (usa public_url o el host actual). */
function absolute_url(string $pathFromPublic): string
{
    global $CONFIG;
    $configured = trim((string) ($CONFIG['public_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim($pathFromPublic, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . url_path($pathFromPublic);
}

/**
 * Nombre de usuario y colegio (tenant) para la barra superior de portales.
 *
 * @return array{user_name: string, tenant_name: string}
 */
function layout_portal_context(PDO $pdo): array
{
    $uid = Auth::userId();
    $tid = Auth::tenantId();
    $userName = '';
    $tenantName = '';

    if ($uid !== null) {
        $st = $pdo->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int) $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $userName = trim((string) ($row['full_name'] ?? ''));
        if ($userName === '') {
            $userName = trim((string) ($row['email'] ?? ''));
        }
    }

    if ($tid !== null && (int) $tid > 0) {
        $st = $pdo->prepare('SELECT name FROM tenants WHERE id = ? LIMIT 1');
        $st->execute([(int) $tid]);
        $tenantName = trim((string) ($st->fetchColumn() ?: ''));
    }

    return [
        'user_name' => $userName,
        'tenant_name' => $tenantName,
    ];
}

/** Redireccion absoluta bajo public/ (evita rutas relativas rotas entre admin, budget y oc). */
function redirect_to(string $pathFromPublic): void
{
    header('Location: ' . url_path($pathFromPublic));
    exit;
}

/** Enlace absoluto a un script en public/admin/. */
function admin_href(string $script): string
{
    return url_path('admin/' . ltrim($script, '/'));
}

/** @param array{account_id?: int|string, name?: string} $row */
function account_code_label(array $row): string
{
    $name = trim((string) ($row['name'] ?? ''));
    $aid = $row['account_id'] ?? null;
    if ($aid !== null && $aid !== '' && (int) $aid > 0) {
        return (string) (int) $aid . ($name !== '' ? ' - ' . $name : '');
    }

    return $name !== '' ? $name : '';
}

/**
 * Etiqueta de cuenta para presupuesto/OC: Nº plan (plan_cuenta) + nombre del catálogo.
 *
 * @param array{id?: int|string, account_id?: int|string, name?: string, account_name?: string} $accountRow
 */
function budget_account_label(PDO $pdo, array $accountRow): string
{
    $planId = AccountCodeCatalog::displayAccountIdFromRow($pdo, [
        'id' => (int) ($accountRow['id'] ?? 0),
        'account_id' => (int) ($accountRow['account_id'] ?? 0),
        'name' => (string) ($accountRow['name'] ?? $accountRow['account_name'] ?? ''),
    ]);

    return account_code_label([
        'account_id' => $planId,
        'name' => (string) ($accountRow['name'] ?? $accountRow['account_name'] ?? ''),
    ]);
}

function require_post_csrf(): void
{
    $token = (string) ($_POST['_csrf'] ?? '');
    $sess = (string) ($_SESSION['_csrf'] ?? '');
    if ($token === '' || $sess === '' || !hash_equals($sess, $token)) {
        http_response_code(403);
        exit('CSRF invalido');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['_csrf'];
}

/** Cierra sesiones admin, presupuesto y OC (misma base_url). */
function logout_all_portals(): void
{
    foreach ([Auth::REALM_ADMIN, Auth::REALM_BUDGET, Auth::REALM_OC] as $realm) {
        Auth::startRealm($realm);
        Auth::logout();
    }
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

    return trim($s, '-') ?: 'tenant';
}

function money_fmt(float $n): string
{
    return number_format($n, 2, ',', '.');
}

/** Etiqueta folio OC (ano-numero), ej. 2026-1 */
function oc_folio_label(string|int $folioYear, string|int $numeroOc): string
{
    return trim((string) $folioYear) . '-' . (int) $numeroOc;
}

/** @param list<array{href?: string, label: string}> $items */
function breadcrumb_html(array $items): string
{
    if ($items === []) {
        return t_es('sin_valor');
    }
    $parts = [];
    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        $label = h($item['label']);
        if ($i < $last && !empty($item['href'])) {
            $parts[] = '<a href="' . h((string) $item['href']) . '">' . $label . '</a>';
        } else {
            $parts[] = '<span class="breadcrumb-current">' . $label . '</span>';
        }
    }

    return '<nav class="breadcrumb" aria-label="Ruta">'
        . implode('<span class="breadcrumb-sep" aria-hidden="true">&rsaquo;</span>', $parts)
        . '</nav>';
}

function realm_home_href(string $realm): string
{
    return match ($realm) {
        'admin', 'budget', 'oc' => 'index.php',
        default => 'index.php',
    };
}

/** Formato entero estilo PSPTO (miles con punto). */
function budget_int_fmt(float $n): string
{
    return number_format($n, 0, ',', '.');
}

/**
 * Etiqueta columna C.C. / coordinacion en listados de presupuesto.
 */
function budget_ccosto_coordinacion_header(): string
{
    return 'C.C./Coordinaci&oacute;n';
}

/**
 * Valor columna C.C. / coordinacion en listados de presupuesto.
 *
 * Prioridad: cost_center_name (catalogo cost_centers) → created_by_name (department creador)
 * → department_name (coordinacion imputante). Refleja el modelo dual de catalogo.
 *
 * @param array<string, mixed> $row
 */
function budget_ccosto_coordinacion_label(array $row): string
{
    $cc = trim((string) ($row['cost_center_name'] ?? ''));
    if ($cc !== '') {
        return $cc;
    }
    $creator = trim((string) ($row['created_by_name'] ?? ''));
    if ($creator !== '') {
        return $creator;
    }
    $dept = trim((string) ($row['department_name'] ?? ''));
    if ($dept !== '') {
        return $dept;
    }

    return t_es('sin_valor');
}

/**
 * @param array<string, int|string|null> $query
 */
function budget_year_from_activity_number(string $activityNumber): int
{
    if (preg_match('/-(\d{4})$/', $activityNumber, $m) === 1) {
        return (int) $m[1];
    }

    return (int) date('Y');
}

/** Parte numerica antes del guion (ej. 9314-2026 -> 9314). */
function activity_num_short(string $activityNumber): string
{
    $p = explode('-', trim($activityNumber), 2);

    return $p[0] !== '' ? $p[0] : trim($activityNumber);
}

function budget_dashboard_url(?int $departmentId, array $query = [], bool $showActivities = false): string
{
    if ($departmentId !== null && $departmentId > 0) {
        $query['dept'] = $departmentId;
    }
    if ($showActivities) {
        $query['actividades'] = '1';
    }

    return 'index.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

/** URL relativa segura para volver al listado de presupuesto (solo index.php). */
function budget_list_return_href(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '' || !preg_match('/^index\.php(\?[A-Za-z0-9_%.=&+-]*)?$/', $raw)) {
        return 'index.php';
    }

    return $raw;
}

/** Sufijo ?return=... para enlaces desde vista/edición de actividad. */
function budget_return_query_suffix(?string $raw): string
{
    $href = budget_list_return_href($raw);
    if ($href === 'index.php') {
        return '';
    }

    return '&return=' . rawurlencode($href);
}

function budget_month_label(int $month): string
{
    static $months = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    return $months[$month] ?? (string) $month;
}

/**
 * Contexto del listado (division/centro, mes, vista) a partir del parametro return.
 *
 * @return array{
 *   department_kind: string,
 *   department_name: string,
 *   budget_year: int|null,
 *   month: int|null,
 *   month_name: string|null,
 *   list_view_label: string|null
 * }|null
 */
function budget_list_context_from_return(PDO $pdo, int $tenantId, ?string $returnRaw): ?array
{
    $href = budget_list_return_href($returnRaw);
    $query = parse_url($href, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $params);
    $deptId = (int) ($params['dept'] ?? 0);
    if ($deptId <= 0) {
        return null;
    }
    $dept = DepartmentCatalog::getById($pdo, $deptId, $tenantId);
    if ($dept === null) {
        return null;
    }

    $budgetYear = isset($params['budget_year']) ? (int) $params['budget_year'] : null;
    if ($budgetYear !== null && ($budgetYear < 2000 || $budgetYear > 2100)) {
        $budgetYear = null;
    }
    $month = isset($params['month']) ? (int) $params['month'] : null;
    if ($month !== null && ($month < 1 || $month > 12)) {
        $month = null;
    }

    $viewMode = (string) ($params['admin_view'] ?? $params['encargado_view'] ?? '');
    $listViewLabel = match ($viewMode) {
        'by_centros_costo' => 'Actividades por Coordinaciones / C. C.',
        'by_division' => 'Actividades por Division',
        default => null,
    };

    return [
        'department_kind' => ($dept['type'] ?? '') === 'coordinacion' ? 'Centro de costo' : 'Division',
        'department_name' => (string) ($dept['name'] ?? ''),
        'budget_year' => $budgetYear,
        'month' => $month,
        'month_name' => $month !== null ? budget_month_label($month) : null,
        'list_view_label' => $listViewLabel,
    ];
}
