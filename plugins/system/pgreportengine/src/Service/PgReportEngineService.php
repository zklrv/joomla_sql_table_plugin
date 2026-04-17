<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.pgreportengine
 */

namespace Joomla\Plugin\System\Pgreportengine\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class PgReportEngineService
{
    /** @var \PgSql\Connection|resource|null */
    private $connection;

    public function run(array $options): array
    {
        $this->assertAccess($options['access'] ?? []);

        $sql = $this->normalizeSql((string) ($options['sql'] ?? ''), !empty($options['strip_order_by']));
        $this->validateSqlSafety($sql);

        $dbConfig = $options['db'] ?? [];
        $this->connection = $this->connect($dbConfig);

        $columns = $this->getColumns($sql);

        if (empty($columns)) {
            return [
                'columns' => [],
                'groups' => [],
                'rows' => [],
                'page' => 1,
                'per_page' => 1,
                'total_rows' => 0,
                'total_pages' => 1,
                'warnings' => [Text::_('PLG_SYSTEM_PGREPORTENGINE_WARNING_NO_COLUMNS')],
            ];
        }

        $search = trim((string) ($options['search'] ?? ''));
        $searchMode = strtolower((string) ($options['search_mode'] ?? 'standard')) === 'pointer' ? 'pointer' : 'standard';
        $searchColumns = $this->filterColumns($columns, $options['search_columns'] ?? []);
        $pointerMatchColumns = $this->filterColumns($columns, $options['pointer_match_columns'] ?? ['fullfio', 'email', 'mobile_phone', 'ip_phone']);
        $pointerMatchColumns = !empty($pointerMatchColumns) ? $pointerMatchColumns : $searchColumns;
        $groupCascade = $this->filterColumns($columns, $options['group_key_cascade'] ?? ['department_name', 'maindepartament', 'dept_code', 'dept_id']);
        $sortColumns = $this->filterColumns($columns, $options['sort_columns'] ?? []);
        $sortColumns = !empty($sortColumns) ? $sortColumns : $columns;
        $exportAll = !empty($options['export_all']);

        $sort = (string) ($options['sort'] ?? '');
        $sort = in_array($sort, $sortColumns, true) ? $sort : (string) ($sortColumns[0] ?? '');
        $dir = strtolower((string) ($options['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $maxPerPage = max(1, (int) ($options['max_per_page'] ?? 200));
        $perPage = min($maxPerPage, max(1, (int) ($options['per_page'] ?? 25)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $warnings = [];

        if (!preg_match('/\boe\.id\s+as\s+employee_id\b/i', $sql)) {
            $warnings[] = Text::_('PLG_SYSTEM_PGREPORTENGINE_WARNING_EMPLOYEE_ID_ALIAS');
        }

        $groupExpr = $this->buildGroupExpression($groupCascade);
        $isPointerSearch = $searchMode === 'pointer' && $search !== '' && !empty($pointerMatchColumns);

        $countParams = [];
        $countWhereSql = '';

        if ($isPointerSearch) {
            $index = 1;
            $pointerCondition = $this->buildSearchCondition($pointerMatchColumns, $search, 't', $index, $countParams);
            $matchedGroupKeysSql = $this->buildMatchedGroupKeysSql($groupExpr, $sql, $pointerCondition);
            $countWhereSql = ' WHERE ' . $groupExpr . ' IN (' . $matchedGroupKeysSql . ')';
        } elseif ($search !== '' && !empty($searchColumns)) {
            $index = 1;
            $searchCondition = $this->buildSearchCondition($searchColumns, $search, 't', $index, $countParams);
            $countWhereSql = ' WHERE (' . $searchCondition . ')';
        }

        $countSql = 'SELECT COUNT(*) AS total_rows FROM (' . $sql . ') t' . $countWhereSql;
        $totalRows = (int) $this->fetchOne($countSql, $countParams, 'total_rows');

        $dataParams = [];
        $dataWhereSql = '';
        $matchSelect = '0 AS __match, ';

        if ($isPointerSearch) {
            $index = 1;
            $rowMatchCondition = $this->buildSearchCondition($pointerMatchColumns, $search, 't', $index, $dataParams);
            $matchSelect = 'CASE WHEN (' . $rowMatchCondition . ') THEN 1 ELSE 0 END AS __match, ';
            $matchedGroupKeysSql = $this->buildMatchedGroupKeysSql($groupExpr, $sql, $rowMatchCondition);
            $dataWhereSql = ' WHERE ' . $groupExpr . ' IN (' . $matchedGroupKeysSql . ')';
        } elseif ($search !== '' && !empty($searchColumns)) {
            $index = 1;
            $searchCondition = $this->buildSearchCondition($searchColumns, $search, 't', $index, $dataParams);
            $dataWhereSql = ' WHERE (' . $searchCondition . ')';
        }

        $dataSql = 'SELECT t.*, ' . $matchSelect . $groupExpr . ' AS __group_key '
            . 'FROM (' . $sql . ') t'
            . $dataWhereSql
            . ' ORDER BY __group_key ASC, t.' . $this->quoteIdentifier($sort) . ' ' . $dir;

        if (!$exportAll) {
            $dataSql .= ' LIMIT $' . (count($dataParams) + 1)
                . ' OFFSET $' . (count($dataParams) + 2);
            $dataParams[] = $perPage;
            $dataParams[] = $offset;
        }

        $rows = $this->fetchRows($dataSql, $dataParams);

        $hasEmployeeId = in_array('employee_id', $columns, true);
        $hasOesId = in_array('oes_id', $columns, true);

        $employeesExpr = $hasEmployeeId ? 'COUNT(DISTINCT t.' . $this->quoteIdentifier('employee_id') . ')' : 'COUNT(*)';
        $positionsExpr = $hasOesId ? 'COUNT(DISTINCT t.' . $this->quoteIdentifier('oes_id') . ')' : 'COUNT(*)';

        $groupTotalsParams = [];
        $groupTotalsWhereSql = '';

        if ($isPointerSearch) {
            $index = 1;
            $pointerCondition = $this->buildSearchCondition($pointerMatchColumns, $search, 't', $index, $groupTotalsParams);
            $matchedGroupKeysSql = $this->buildMatchedGroupKeysSql($groupExpr, $sql, $pointerCondition);
            $groupTotalsWhereSql = ' WHERE ' . $groupExpr . ' IN (' . $matchedGroupKeysSql . ')';
        } elseif ($search !== '' && !empty($searchColumns)) {
            $index = 1;
            $searchCondition = $this->buildSearchCondition($searchColumns, $search, 't', $index, $groupTotalsParams);
            $groupTotalsWhereSql = ' WHERE (' . $searchCondition . ')';
        }

        $groupTotalsSql = 'SELECT '
            . $groupExpr . ' AS group_key, '
            . $employeesExpr . ' AS employees_cnt, '
            . $positionsExpr . ' AS positions_cnt, '
            . 'COUNT(*) AS rows_cnt '
            . 'FROM (' . $sql . ') t'
            . $groupTotalsWhereSql
            . ' GROUP BY group_key'
            . ' ORDER BY group_key ASC';

        $groupTotals = $this->fetchRows($groupTotalsSql, $groupTotalsParams);
        $groupTotalsMap = [];

        foreach ($groupTotals as $totalsRow) {
            $groupTotalsMap[(string) $totalsRow['group_key']] = [
                'employees_cnt' => (int) ($totalsRow['employees_cnt'] ?? 0),
                'positions_cnt' => (int) ($totalsRow['positions_cnt'] ?? 0),
                'rows_cnt' => (int) ($totalsRow['rows_cnt'] ?? 0),
            ];
        }

        $groups = [];

        foreach ($rows as $row) {
            $groupKey = (string) ($row['__group_key'] ?? Text::_('PLG_SYSTEM_PGREPORTENGINE_GROUP_EMPTY'));

            if (!array_key_exists($groupKey, $groups)) {
                $groups[$groupKey] = [
                    'label' => $groupKey,
                    'employees_cnt' => (int) ($groupTotalsMap[$groupKey]['employees_cnt'] ?? 0),
                    'positions_cnt' => (int) ($groupTotalsMap[$groupKey]['positions_cnt'] ?? 0),
                    'rows_cnt' => (int) ($groupTotalsMap[$groupKey]['rows_cnt'] ?? 0),
                    'rows' => [],
                ];
            }

            unset($row['__group_key']);
            $groups[$groupKey]['rows'][] = $row;
        }

        if ($exportAll) {
            $page = 1;
            $perPage = max(1, $totalRows);
            $totalPages = 1;
        } else {
            $totalPages = max(1, (int) ceil($totalRows / $perPage));
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'groups' => array_values($groups),
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'warnings' => $warnings,
        ];
    }

    private function assertAccess(array $access): void
    {
        $user = Factory::getApplication()->getIdentity();
        $allowGuests = !empty($access['allow_guests']);

        if ($user->guest && !$allowGuests) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_ACCESS_DENIED'));
        }

        $groups = array_map('intval', (array) ($access['groups'] ?? []));

        if (empty($groups)) {
            return;
        }

        $userGroups = array_map('intval', (array) $user->getAuthorisedGroups());
        $hasIntersection = count(array_intersect($groups, $userGroups)) > 0;
        $mode = strtolower((string) ($access['mode'] ?? 'allow'));

        if ($mode === 'deny' && $hasIntersection) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_ACCESS_DENIED'));
        }

        if ($mode !== 'deny' && !$hasIntersection) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_ACCESS_DENIED'));
        }
    }

    private function normalizeSql(string $sql, bool $stripOrderBy): string
    {
        $sql = trim($sql);

        if ($stripOrderBy) {
            $sql = $this->stripTrailingOrderBy($sql);
            $sql = trim($sql);
        }

        return $sql;
    }

    private function stripTrailingOrderBy(string $sql): string
    {
        $length = strlen($sql);
        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $orderByPos = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inSingleQuote) {
                if ($char === "'" && $next === "'") {
                    $i++;
                    continue;
                }

                if ($char === "'") {
                    $inSingleQuote = false;
                }

                continue;
            }

            if ($inDoubleQuote) {
                if ($char === '"' && $next === '"') {
                    $i++;
                    continue;
                }

                if ($char === '"') {
                    $inDoubleQuote = false;
                }

                continue;
            }

            if ($char === "'") {
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if (stripos(substr($sql, $i), 'order by') === 0) {
                $prev = $i > 0 ? $sql[$i - 1] : ' ';
                $after = $i + 8 < $length ? $sql[$i + 8] : ' ';

                if (!preg_match('/[A-Za-z0-9_]/', $prev) && !preg_match('/[A-Za-z0-9_]/', $after)) {
                    $orderByPos = $i;
                }
            }
        }

        if ($orderByPos === null) {
            return $sql;
        }

        return rtrim(substr($sql, 0, $orderByPos));
    }

    private function validateSqlSafety(string $sql): void
    {
        if ($sql === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_EMPTY_SQL'));
        }

        if (strpos($sql, ';') !== false) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_SQL_SEMICOLON'));
        }

        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_SQL_SELECT_ONLY'));
        }

        if (preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE|COPY|CREATE|GRANT|REVOKE)\b/i', $sql)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_SQL_FORBIDDEN_KEYWORDS'));
        }
    }

    /**
     * @return \PgSql\Connection|resource
     */
    private function connect(array $db)
    {
        $parts = [];

        foreach (['host', 'port', 'dbname', 'user', 'password'] as $key) {
            $value = trim((string) ($db[$key] ?? ''));

            if ($value === '') {
                throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_PGREPORTENGINE_ERROR_DB_PARAM_REQUIRED', $key));
            }

            $parts[] = $key . '=' . $this->quoteConnValue($value);
        }

        $sslMode = trim((string) ($db['sslmode'] ?? 'disable'));
        $allowedSslModes = ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'];

        if (!in_array($sslMode, $allowedSslModes, true)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_INVALID_SSLMODE'));
        }

        $parts[] = 'sslmode=' . $this->quoteConnValue($sslMode);

        $sslRootCert = trim((string) ($db['sslrootcert'] ?? ''));

        if ($sslRootCert !== '') {
            $parts[] = 'sslrootcert=' . $this->quoteConnValue($sslRootCert);
        }

        $connection = pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);

        if (!$connection) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_DB_CONNECT'));
        }

        $schema = trim((string) ($db['schema'] ?? ''));

        if ($schema !== '') {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_INVALID_SCHEMA'));
            }

            $setSchema = 'SET search_path TO "' . str_replace('"', '""', $schema) . '"';

            if (!pg_query($connection, $setSchema)) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_PGREPORTENGINE_ERROR_SET_SCHEMA'));
            }
        }

        return $connection;
    }

    private function getColumns(string $sql): array
    {
        $query = 'SELECT * FROM (' . $sql . ') t LIMIT 0';
        $result = @pg_query($this->connection, $query);

        if (!$result) {
            throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_PGREPORTENGINE_ERROR_SQL_EXECUTION', pg_last_error($this->connection)));
        }

        $columns = [];
        $count = pg_num_fields($result);

        for ($i = 0; $i < $count; $i++) {
            $columns[] = pg_field_name($result, $i);
        }

        return $columns;
    }

    private function filterColumns(array $availableColumns, array $requested): array
    {
        $requested = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $requested)));

        if (empty($requested)) {
            return [];
        }

        return array_values(array_filter($requested, static fn($column) => in_array($column, $availableColumns, true)));
    }

    private function buildSearchCondition(array $columns, string $search, string $alias, int &$index, array &$params): string
    {
        $parts = [];
        $searchValue = '%' . $search . '%';

        foreach ($columns as $column) {
            $params[] = $searchValue;
            $parts[] = 'CAST(' . $alias . '.' . $this->quoteIdentifier($column) . ' AS TEXT) ILIKE $' . $index;
            $index++;
        }

        return implode(' OR ', $parts);
    }

    private function buildMatchedGroupKeysSql(string $groupExpr, string $sql, string $matchCondition): string
    {
        return 'SELECT DISTINCT ' . $groupExpr . ' AS group_key FROM (' . $sql . ') t WHERE (' . $matchCondition . ')';
    }

    private function buildGroupExpression(array $groupCascade): string
    {
        $groupEmptyLabel = str_replace("'", "''", Text::_('PLG_SYSTEM_PGREPORTENGINE_GROUP_EMPTY'));

        if (empty($groupCascade)) {
            return "'" . $groupEmptyLabel . "'";
        }

        $parts = [];

        foreach ($groupCascade as $column) {
            $parts[] = 'NULLIF(CAST(t.' . $this->quoteIdentifier($column) . ' AS TEXT), \'\')';
        }

        return 'COALESCE(' . implode(', ', $parts) . ", '" . $groupEmptyLabel . "')";
    }

    /**
     * @return string|int|float|null
     */
    private function fetchOne(string $sql, array $params, string $column): string|int|float|null
    {
        $rows = $this->fetchRows($sql, $params);

        return $rows[0][$column] ?? null;
    }

    private function fetchRows(string $sql, array $params): array
    {
        $result = @pg_query_params($this->connection, $sql, $params);

        if (!$result) {
            throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_PGREPORTENGINE_ERROR_SQL_EXECUTION', pg_last_error($this->connection)));
        }

        $rows = pg_fetch_all($result);

        return $rows ?: [];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function quoteConnValue(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
