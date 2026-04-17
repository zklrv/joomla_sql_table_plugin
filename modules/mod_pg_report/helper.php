<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_pg_report
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Joomla\Plugin\System\Pgreportengine\Service\PgReportEngineService;

class ModPgReportHelper
{
    public static function getInitialState($module, Registry $params): array
    {
        $collapsibleGroups = (bool) $params->get('collapsible_groups', 1);
        $searchMode = self::normalizeSearchMode((string) $params->get('search_mode', 'standard'));

        return [
            'moduleId' => (int) $module->id,
            'endpoint' => Route::_('index.php?option=com_ajax&module=pg_report&method=query&format=json'),
            'token' => Session::getFormToken(),
            'defaultPerPage' => (int) $params->get('per_page_default', 25),
            'defaultSortBy' => (string) $params->get('default_sort_by', ''),
            'defaultSortDir' => strtolower((string) $params->get('default_sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc',
            'collapsibleGroups' => $collapsibleGroups,
            'collapsedByDefault' => $collapsibleGroups && (bool) $params->get('collapsed_by_default', 0),
            'searchMode' => $searchMode,
            'autoExpandOnSearch' => (bool) $params->get('auto_expand_on_search', 1),
        ];
    }

    public static function queryAjax(): array
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $moduleId = $input->getInt('module_id', 0);

        if ($moduleId <= 0) {
            return [
                'success' => false,
                'error' => Text::_('MOD_PG_REPORT_ERROR_MODULE_ID_REQUIRED'),
            ];
        }

        $module = self::loadModuleById($moduleId);

        if (!$module) {
            return [
                'success' => false,
                'error' => Text::_('MOD_PG_REPORT_ERROR_MODULE_NOT_FOUND'),
            ];
        }

        $params = new Registry($module->params);

        $pluginServicePath = JPATH_PLUGINS . '/system/pgreportengine/src/Service/PgReportEngineService.php';

        if (!is_file($pluginServicePath)) {
            return [
                'success' => false,
                'error' => Text::_('MOD_PG_REPORT_ERROR_ENGINE_NOT_INSTALLED'),
            ];
        }

        require_once $pluginServicePath;

        try {
            $service = new PgReportEngineService();
            $result = $service->run(self::buildServiceOptions($params, $input));

            $collapsibleGroups = (bool) $params->get('collapsible_groups', 1);
            $collapsedByDefault = $collapsibleGroups && (bool) $params->get('collapsed_by_default', 0);

            $renderState = [
                'sort' => $input->getCmd('sort', (string) $params->get('default_sort_by', '')),
                'dir' => strtolower($input->getCmd('dir', (string) $params->get('default_sort_dir', 'asc'))) === 'desc' ? 'desc' : 'asc',
                'page' => max(1, $input->getInt('page', 1)),
                'perPage' => max(1, $input->getInt('per_page', (int) $params->get('per_page_default', 25))),
                'search' => trim((string) $input->getString('search', '')),
                'collapsibleGroups' => $collapsibleGroups,
                'collapsedByDefault' => $collapsedByDefault,
                'autoExpandOnSearch' => (bool) $params->get('auto_expand_on_search', 1),
                'searchMode' => self::normalizeSearchMode((string) $params->get('search_mode', 'standard')),
                'visibleColumns' => self::parseCsv((string) $params->get('visible_columns', '')),
                'columnLabels' => self::parseColumnLabels((string) $params->get('column_labels', '')),
            ];

            return [
                'success' => true,
                'html' => self::renderTable($result, $renderState),
                'meta' => [
                    'total_rows' => (int) ($result['total_rows'] ?? 0),
                    'total_pages' => (int) ($result['total_pages'] ?? 1),
                    'page' => (int) ($result['page'] ?? 1),
                    'per_page' => (int) ($result['per_page'] ?? 25),
                ],
                'warnings' => $result['warnings'] ?? [],
                'access_denied' => !empty($result['access_denied']),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => Text::sprintf('MOD_PG_REPORT_ERROR_RUNTIME', $e->getMessage()),
            ];
        }
    }

    private static function buildServiceOptions(Registry $params, $input): array
    {
        return [
            'sql' => (string) $params->get('base_sql', ''),
            'strip_order_by' => (bool) $params->get('strip_order_by', 1),
            'search' => trim((string) $input->getString('search', '')),
            'search_mode' => self::normalizeSearchMode((string) $params->get('search_mode', 'standard')),
            'search_columns' => self::parseCsv($params->get('search_columns', 'dept_code,maindepartament,department_name,staff_unit_name,fullfio,email,mobile_phone,ip_phone')),
            'pointer_match_columns' => self::parseCsv((string) $params->get('pointer_match_columns', 'fullfio,email,mobile_phone,ip_phone')),
            'sort' => $input->getCmd('sort', (string) $params->get('default_sort_by', '')),
            'dir' => strtolower($input->getCmd('dir', (string) $params->get('default_sort_dir', 'asc'))) === 'desc' ? 'desc' : 'asc',
            'page' => max(1, $input->getInt('page', 1)),
            'per_page' => max(1, $input->getInt('per_page', (int) $params->get('per_page_default', 25))),
            'max_per_page' => max(1, (int) $params->get('max_per_page', 200)),
            'group_key_cascade' => self::parseCsv($params->get('group_key_cascade', 'department_name,maindepartament,dept_code,dept_id')),
            'db' => [
                'host' => (string) $params->get('db_host', ''),
                'port' => (string) $params->get('db_port', '5432'),
                'dbname' => (string) $params->get('db_name', ''),
                'user' => (string) $params->get('db_user', ''),
                'password' => (string) $params->get('db_password', ''),
                'schema' => (string) $params->get('db_schema', ''),
                'sslmode' => (string) $params->get('db_sslmode', 'disable'),
                'sslrootcert' => (string) $params->get('db_sslrootcert', ''),
            ],
            'access' => [
                'allow_guests' => (bool) $params->get('allow_guests', 0),
                'mode' => (string) $params->get('acl_mode', 'allow'),
                'groups' => self::normalizeGroupIds($params->get('acl_groups', [])),
            ],
        ];
    }

    private static function renderTable(array $result, array $state): string
    {
        ob_start();
        include __DIR__ . '/tmpl/table.php';

        return (string) ob_get_clean();
    }

    private static function parseCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $items = array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== '');

        return array_values(array_unique($items));
    }

    private static function parseColumnLabels(string $value): array
    {
        $lines = preg_split('/\R/u', $value) ?: [];
        $map = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }

            [$column, $label] = explode('=', $line, 2);
            $column = trim($column);
            $label = trim($label);

            if ($column === '' || $label === '') {
                continue;
            }

            $map[$column] = $label;
        }

        return $map;
    }

    private static function normalizeSearchMode(string $value): string
    {
        return strtolower($value) === 'pointer' ? 'pointer' : 'standard';
    }

    private static function normalizeGroupIds($value): array
    {
        $raw = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($raw as $item) {
            $parts = explode(',', (string) $item);

            foreach ($parts as $part) {
                $id = (int) trim($part);

                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    private static function loadModuleById(int $moduleId): ?object
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'module', 'params', 'published']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_pg_report'))
            ->bind(':id', $moduleId);

        $db->setQuery($query);

        $module = $db->loadObject();

        return $module ?: null;
    }
}
