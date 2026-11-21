<?php

declare(strict_types=1);

/**
 * Reglas al crear actividades y visibilidad en dashboard (arquitectura PSPTO / BudgetFlow).
 *
 * MODELO DUAL DE CATALOGO (ver tambien DepartmentCatalog y CostCenterCatalog)
 * =============================================================================
 * El tenant tiene DOS tablas de catalogo sin FK entre si:
 *
 * 1) departments — unidades organizativas (permisos, selector dashboard, created_by)
 *    - type estandar     = Division. Crea actividades imputando a cost_centers.
 *    - type coordinacion = Coordinacion / C.C. organico. Imputa a una division;
 *                          budget_headers.cost_center_id queda NULL.
 *    - parent_id         = sub-area bajo una division (estandar) o jerarquia interna.
 *
 * 2) cost_centers — catalogo plano de centros de costo contables (Docencia, Administracioncc…)
 *    - Referenciado solo por budget_headers.cost_center_id cuando el creador es division.
 *    - NO hay FK hacia departments; la relacion es por reglas de negocio y coincidencia de nombre.
 *
 * budget_headers (resumen)
 * ------------------------
 * - created_by      → departments.id del encargado creador (division o coordinacion).
 * - division_id     → departments type estandar (division destino / creadora).
 * - department_id   → coordinacion creadora o sub-area estandar bajo la division.
 * - cost_center_id  → cost_centers.id (solo actividades cargadas por division).
 *
 * Centros “solo recepcion” (columna 3 del dashboard)
 * --------------------------------------------------
 * Nombres en catalogReceiveCostCenterNames() existen en cost_centers y, en paralelo, como
 * departments type coordinacion (p. ej. Administracioncc ≠ division Administracion).
 * Esas coordinaciones no crean actividades; solo reciben imputaciones OC.
 *
 * Creacion de actividades
 * -----------------------
 * - Division (estandar): obligatorio cost_center_id; division_id = creador.
 * - Coordinacion: obligatorio division_id destino; department_id = creador; sin cost_center_id.
 */
final class BudgetActivityRules
{
    /** @var array<int, int|null> */
    private static array $estadioDivisionMemo = [];

    /** @return list<string> */
    private static function gestionEstadioNameVariants(): array
    {
        global $CONFIG;
        $raw = is_array($CONFIG ?? null)
            ? (string) ($CONFIG['budget_gestion_estadio_names'] ?? 'Gestion Estadio,Gesti&oacute;n Estadio')
            : 'Gestion Estadio';
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim(html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($p !== '') {
                $out[] = mb_strtolower($p);
            }
        }

        return $out !== [] ? $out : ['gestion estadio'];
    }

    /** @return list<string> */
    private static function estadioDivisionNameVariants(): array
    {
        global $CONFIG;
        $raw = is_array($CONFIG ?? null)
            ? (string) ($CONFIG['budget_estadio_division_names'] ?? 'Estadio')
            : 'Estadio';
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = mb_strtolower($p);
            }
        }

        return $out !== [] ? $out : ['estadio'];
    }

    private static function nameMatchesAny(string $name, array $lowercasedVariants): bool
    {
        $n = mb_strtolower(trim($name));
        foreach ($lowercasedVariants as $v) {
            if ($n === $v) {
                return true;
            }
        }

        return false;
    }

    /** @param array{id: int, name: string, type: string, parent_id: int|null} $departmentRow */
    public static function isGestionEstadioCoordination(array $departmentRow): bool
    {
        return ($departmentRow['type'] ?? '') === 'coordinacion'
            && self::nameMatchesAny((string) ($departmentRow['name'] ?? ''), self::gestionEstadioNameVariants());
    }

    public static function findEstadioDivisionId(PDO $pdo, int $tenantId): ?int
    {
        if (array_key_exists($tenantId, self::$estadioDivisionMemo)) {
            return self::$estadioDivisionMemo[$tenantId];
        }
        $found = null;
        foreach (DepartmentCatalog::listByTenant($pdo, $tenantId) as $d) {
            if (($d['type'] ?? '') !== 'estandar') {
                continue;
            }
            if (self::nameMatchesAny((string) ($d['name'] ?? ''), self::estadioDivisionNameVariants())) {
                $found = (int) $d['id'];
                break;
            }
        }
        self::$estadioDivisionMemo[$tenantId] = $found;

        return $found;
    }

    public static function normalizeCoordinationNamePolicy(string $name): string
    {
        $n = mb_strtolower(trim($name), 'UTF-8');
        $n = (string) preg_replace('/\s+/u', ' ', $n);
        $search = [
            "\u{00e1}", "\u{00e9}", "\u{00ed}", "\u{00f3}", "\u{00fa}", "\u{00fc}", "\u{00f1}",
        ];
        $replace = ['a', 'e', 'i', 'o', 'u', 'u', 'n'];

        return str_replace($search, $replace, $n);
    }

    private static function receiveOnlyNameKey(string $name): string
    {
        return str_replace(' ', '', self::normalizeCoordinationNamePolicy($name));
    }

    /** Centros de costo de catalogo: solo reciben imputaciones, no crean actividades. */
    /** @return list<string> */
    public static function catalogReceiveCostCenterNames(): array
    {
        return [
            'Docencia',
            'Administracióncc',
            'Direccióncc',
            'Laboratorios',
            'Pre universitario',
        ];
    }

    /** @return array<string, true> */
    private static function catalogReceiveCostCenterNameKeySet(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }
        $set = [];
        foreach (self::catalogReceiveCostCenterNames() as $label) {
            $k = self::receiveOnlyNameKey($label);
            if ($k !== '') {
                $set[$k] = true;
            }
        }

        return $set;
    }

    public static function isCatalogReceiveCostCenterName(string $name): bool
    {
        $key = self::receiveOnlyNameKey($name);

        return $key !== '' && isset(self::catalogReceiveCostCenterNameKeySet()[$key]);
    }

    /**
     * @param list<array{id: int, name: string, type: string, parent_id: int|null}> $departments
     * @return list<array{id: int, name: string, type: string, parent_id: int|null}>
     */
    public static function sortCatalogReceiveDepartments(array $departments): array
    {
        $order = [];
        foreach (self::catalogReceiveCostCenterNames() as $i => $label) {
            $order[self::receiveOnlyNameKey($label)] = $i;
        }
        usort(
            $departments,
            static function (array $a, array $b) use ($order): int {
                $ka = self::receiveOnlyNameKey((string) ($a['name'] ?? ''));
                $kb = self::receiveOnlyNameKey((string) ($b['name'] ?? ''));
                $ia = $order[$ka] ?? 999;
                $ib = $order[$kb] ?? 999;
                if ($ia !== $ib) {
                    return $ia <=> $ib;
                }

                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }
        );

        return $departments;
    }

    /** @return array<string, true> */
    private static function receiveOnlyBlockedNameKeySet(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }
        global $CONFIG;
        $raw = is_array($CONFIG ?? null)
            ? (string) ($CONFIG['budget_coord_receive_only_names'] ?? 'Docencia,Direccioncc,Administracioncc,Laboratorios,Pre universitario')
            : 'Docencia,Direccioncc,Administracioncc,Laboratorios,Pre universitario';
        $set = [];
        foreach (explode(',', $raw) as $p) {
            $k = self::receiveOnlyNameKey(trim($p));
            if ($k !== '') {
                $set[$k] = true;
            }
        }
        foreach (self::catalogReceiveCostCenterNameKeySet() as $k => $_) {
            $set[$k] = true;
        }

        return $set;
    }

    /** @param array{id: int, name: string, type: string, parent_id: int|null}|null $departmentRow */
    public static function isCoordinationReceiveOnlyDepartment(?array $departmentRow): bool
    {
        if ($departmentRow === null || ($departmentRow['type'] ?? '') !== 'coordinacion') {
            return false;
        }
        $key = self::receiveOnlyNameKey((string) ($departmentRow['name'] ?? ''));

        return $key !== '' && isset(self::receiveOnlyBlockedNameKeySet()[$key]);
    }

    public static function creatorCoordinationMayCreateBudget(PDO $pdo, int $tenantId, ?int $creatorDeptId): bool
    {
        if ($creatorDeptId === null || $creatorDeptId <= 0) {
            return true;
        }

        return !self::isCoordinationReceiveOnlyDepartment(DepartmentCatalog::getById($pdo, $creatorDeptId, $tenantId));
    }

    /**
     * @param array{month: int, cost_center_id?: int|null, activity_id: int, division_id?: int|null, department_id?: int|null, observations?: string|null} $header
     * @return array{ok: true, header: array}|array{ok: false, error: string}
     */
    public static function validateAndNormalizeHeader(PDO $pdo, int $tenantId, array $header, ?int $creatorDeptId): array
    {
        if ($creatorDeptId === null || $creatorDeptId <= 0) {
            return ['ok' => false, 'error' => 'no_creator'];
        }
        $creator = DepartmentCatalog::getById($pdo, $creatorDeptId, $tenantId);
        if ($creator === null) {
            return ['ok' => false, 'error' => 'no_creator'];
        }
        $creatorType = (string) ($creator['type'] ?? 'estandar');

        $actId = (int) ($header['activity_id'] ?? 0);
        if ($actId <= 0) {
            return ['ok' => false, 'error' => 'activity_required'];
        }

        $month = (int) ($header['month'] ?? 0);
        if ($month < 1 || $month > 12) {
            return ['ok' => false, 'error' => 'invalid_month'];
        }

        if ($creatorType === 'estandar') {
            $ccId = (int) ($header['cost_center_id'] ?? 0);
            if ($ccId <= 0) {
                return ['ok' => false, 'error' => 'cost_center_required'];
            }
            if (CostCenterCatalog::getById($pdo, $ccId, $tenantId) === null) {
                return ['ok' => false, 'error' => 'invalid_cost_center'];
            }
            $divId = (int) ($header['division_id'] ?? 0);
            if ($divId <= 0 || $divId !== $creatorDeptId) {
                return ['ok' => false, 'error' => 'division_must_match_creator'];
            }
            $deptOpt = isset($header['department_id']) ? (int) $header['department_id'] : 0;
            if ($deptOpt > 0) {
                $sub = DepartmentCatalog::getById($pdo, $deptOpt, $tenantId);
                if ($sub === null || ($sub['type'] ?? '') !== 'estandar') {
                    return ['ok' => false, 'error' => 'invalid_sub_department'];
                }
                $parent = (int) ($sub['parent_id'] ?? 0);
                if ($parent !== $creatorDeptId) {
                    return ['ok' => false, 'error' => 'sub_department_not_under_division'];
                }
            }
            $header['division_id'] = $creatorDeptId;
            $header['department_id'] = $deptOpt > 0 ? $deptOpt : null;
            $header['cost_center_id'] = $ccId;
            $header['month'] = $month;
            $header['activity_id'] = $actId;

            return ['ok' => true, 'header' => $header];
        }

        if ($creatorType === 'coordinacion') {
            $targetDiv = (int) ($header['division_id'] ?? 0);
            if ($targetDiv <= 0) {
                return ['ok' => false, 'error' => 'division_target_required'];
            }
            $divRow = DepartmentCatalog::getById($pdo, $targetDiv, $tenantId);
            if ($divRow === null || ($divRow['type'] ?? '') !== 'estandar') {
                return ['ok' => false, 'error' => 'invalid_target_division'];
            }
            if (self::isGestionEstadioCoordination($creator)) {
                $estadioId = self::findEstadioDivisionId($pdo, $tenantId);
                if ($estadioId !== null && $targetDiv !== $estadioId) {
                    return ['ok' => false, 'error' => 'gestion_estadio_only_estadio'];
                }
            }
            $header['division_id'] = $targetDiv;
            $header['department_id'] = $creatorDeptId;
            $header['cost_center_id'] = null;
            $header['month'] = $month;
            $header['activity_id'] = $actId;

            return ['ok' => true, 'header' => $header];
        }

        return ['ok' => false, 'error' => 'unknown_creator_type'];
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'no_creator' => 'No se pudo determinar la division o coordinacion creadora.',
            'cost_center_required' => 'Debe seleccionar un centro de costo del catalogo.',
            'invalid_cost_center' => 'El centro de costo no es valido para este tenant.',
            'activity_required' => 'Debe seleccionar una actividad.',
            'invalid_month' => 'Mes no valido.',
            'division_must_match_creator' => 'La division debe ser la misma division que crea el registro.',
            'invalid_sub_department' => 'El area / centro organico seleccionado no es valido.',
            'sub_department_not_under_division' => 'El area seleccionada no pertenece a su division.',
            'division_target_required' => 'Debe seleccionar la division a la que se imputa la actividad.',
            'invalid_target_division' => 'La division destino no es valida.',
            'gestion_estadio_only_estadio' => 'Gestion Estadio solo puede imputar a la division Estadio.',
            'coord_receive_only' => 'Esta coordinacion solo recibe imputaciones; no puede crear actividades.',
            'unknown_creator_type' => 'Tipo de departamento creador no soportado.',
            default => 'No se pudo validar la cabecera del presupuesto.',
        };
    }
}
