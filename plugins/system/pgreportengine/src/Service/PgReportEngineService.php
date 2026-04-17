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
    /** @var resource|null */
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
        $searchColumns = $this->filterColumns($columns, $options['search_columns'] ?? []);
        $groupCascade = $this->filterColumns($columns, $options['group_key_cascade'] ?? ['department_name', 'maindepartament', 'dept_code', 'dept_id']);

        $sort = (string) ($options['sort'] ?? '');
        $sort = in_array($sort, $columns, true) ? $sort : (string) ($columns[0] ?? '');
        $dir = strtolower((string) ($options['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $maxPerPage = max(1, (int) ($options['max_per_page'] ?? 200));
        $perPage = min($maxPerPage, max(1, (int) ($options['per_page'] ?? 25)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $warnings = [];

        if (!preg_match('/\boe\.id\s+as\s+employee_id\b/i', $sql)) {
            $warnings[] = Text::_('PLG_SYSTEM_PGREPORTENGINE_WARNING_EMPLOYEE_ID_ALIAS');
        }

        $whereSql = '';
        $searchParams = [];

        if ($search !== '' && !empty($searchColumns)) {
            $parts = [];
            $searchValue = '%' . $search . '%';

            foreach ($searchColumns as $column) {
                $searchParams[] = $searchValue;
                $parts[] = 'CAST(t.' . $this->quoteIdentifier($column) . ' AS TEXT) ILIKE $' . count($searchParams);
            }

            $whereSql = ' WHERE (' . implode(' OR ', $parts) . ')';
        }

        $groupExpr = $this->buildGroupExpression($groupCascade);

        $countSql = 'SELECT COUNT(*) AS total_rows FROM (' . $sql . ') t' . $whereSql;
        $totalRows = (int) $this->fetchOne($countSql, $searchParams, 'total_rows');

        $dataSql = 'SELECT t.*, ' . $groupExpr . ' AS __group_key '
            . 'FROM (' . $sql . ') t'
            . $whereSql
            . ' ORDER BY __group_key ASC, t.' . $this->quoteIdentifier($sort) . ' ' . $dir
            . ' LIMIT $' . (count($searchParams) + 1)
            . ' OFFSET $' . (count($searchParams) + 2);

        $dataParams = $searchParams;
        $dataParams[] = $perPage;
        $dataParams[] = $offset;

        $rows = $this->fetchRows($dataSql, $dataParams);

        $hasEmployeeId = in_array('employee_id', $columns, true);
        $hasOesId = in_array('oes_id', $columns, true);

        $employeesExpr = $hasEmployeeId ? 'COUNT(DISTINCT t.' . $this->quoteIdentifier('employee_id') . ')' : 'COUNT(*)';
        $positionsExpr = $hasOesId ? 'COUNT(DISTINCT t.' . $this->quoteIdentifier('oes_id') . ')' : 'COUNT(*)';

        $groupTotalsSql = 'SELECT '
            . $groupExpr . ' AS group_key, '
            . $employeesExpr . ' AS employees_cnt, '
            . $positionsExpr . ' AS positions_cnt, '
            . 'COUNT(*) AS rows_cnt '
            . 'FROM (' . $sql . ') t'
            . $whereSql
            . ' GROUP BY group_key'
            . ' ORDER BY group_key ASC';

        $groupTotals = $this->fetchRows($groupTotalsSql, $searchParams);
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

        $totalPages = max(1, (int) ceil($totalRows / $perPage));

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
            $sql = preg_replace('/\s+ORDER\s+BY\s+[\s\S]*$/i', '', $sql) ?: $sql;
            $sql = trim($sql);
        }

        return $sql;
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

    private function connect(array $db): mixed
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

        if ($sslMode !== '') {
            $parts[] = 'sslmode=' . $this->quoteConnValue($sslMode);
        }

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

    private function fetchOne(string $sql, array $params, string $column): mixed
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
