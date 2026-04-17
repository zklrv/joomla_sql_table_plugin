<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_pg_report
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$result = $result ?? [];
$state = $state ?? [];
$availableColumns = $result['columns'] ?? [];
$groups = $result['groups'] ?? [];
$totalRows = (int) ($result['total_rows'] ?? 0);
$totalPages = max(1, (int) ($result['total_pages'] ?? 1));
$page = max(1, (int) ($result['page'] ?? 1));
$sort = (string) ($state['sort'] ?? '');
$dir = (string) ($state['dir'] ?? 'asc');
$collapsible = !empty($state['collapsibleGroups']);
$collapsedByDefault = $collapsible && !empty($state['collapsedByDefault']);
$search = trim((string) ($state['search'] ?? ''));
$isPointerSearchMode = (string) ($state['searchMode'] ?? 'standard') === 'pointer';
$autoExpandOnSearch = !empty($state['autoExpandOnSearch']);
$requestedVisibleColumns = (array) ($state['visibleColumns'] ?? []);
$columnLabels = (array) ($state['columnLabels'] ?? []);

if (empty($requestedVisibleColumns)) {
    $columns = $availableColumns;
} else {
    $columns = [];

    foreach ($requestedVisibleColumns as $column) {
        if (in_array($column, $availableColumns, true)) {
            $columns[] = $column;
        }
    }

    $columns = array_values(array_unique($columns));

    if (empty($columns)) {
        $columns = $availableColumns;
    }
}

$escape = static function ($value): string {
    if ($value === null) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="pg-report__summary">
    <?php echo Text::sprintf('MOD_PG_REPORT_TOTAL_ROWS', $totalRows); ?>
</div>

<?php if (empty($columns)) : ?>
    <div class="pg-report__empty"><?php echo Text::_('MOD_PG_REPORT_NO_DATA'); ?></div>
<?php else : ?>
    <div class="pg-report__table-wrap">
        <table class="pg-report__table table table-striped table-hover">
            <thead>
            <tr>
                <?php foreach ($columns as $column) :
                    $isCurrent = $sort === $column;
                    $nextDir = ($isCurrent && $dir === 'asc') ? 'desc' : 'asc';
                    $columnLabel = $columnLabels[$column] ?? $column;
                    ?>
                    <th scope="col" data-sort="<?php echo $escape($column); ?>" data-dir="<?php echo $nextDir; ?>">
                        <?php echo $escape($columnLabel); ?>
                        <?php if ($isCurrent) : ?>
                            <span class="pg-report__sort-mark"><?php echo $dir === 'asc' ? '▲' : '▼'; ?></span>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $groupIndex => $group) : ?>
                <?php
                $groupRows = $group['rows'] ?? [];
                $groupHasMatch = false;

                foreach ($groupRows as $groupRow) {
                    if (!empty($groupRow['__match'])) {
                        $groupHasMatch = true;
                        break;
                    }
                }

                $isCollapsed = $collapsedByDefault;

                if ($isPointerSearchMode && $autoExpandOnSearch && $search !== '') {
                    $isCollapsed = !$groupHasMatch;
                }
                ?>
                <tr class="pg-report__group-row" data-has-match="<?php echo $groupHasMatch ? '1' : '0'; ?>">
                    <td colspan="<?php echo count($columns); ?>">
                        <?php if ($collapsible) : ?>
                            <button
                                class="pg-report__toggle btn btn-sm btn-outline-primary me-2"
                                data-group="<?php echo $groupIndex; ?>"
                                data-has-match="<?php echo $groupHasMatch ? '1' : '0'; ?>"
                                data-label-expand="<?php echo $escape(Text::_('MOD_PG_REPORT_EXPAND_GROUP')); ?>"
                                data-label-collapse="<?php echo $escape(Text::_('MOD_PG_REPORT_COLLAPSE_GROUP')); ?>"
                                type="button"
                                aria-expanded="<?php echo $isCollapsed ? 'false' : 'true'; ?>"
                                title="<?php echo $escape(Text::_('MOD_PG_REPORT_TOGGLE_GROUP')); ?>"
                            >
                                <span class="visually-hidden pg-report__toggle-text"><?php echo $isCollapsed ? $escape(Text::_('MOD_PG_REPORT_EXPAND_GROUP')) : $escape(Text::_('MOD_PG_REPORT_COLLAPSE_GROUP')); ?></span>
                                <span class="pg-report__toggle-icon" aria-hidden="true"><?php echo $isCollapsed ? '+' : '−'; ?></span>
                            </button>
                        <?php endif; ?>
                        <strong><?php echo $escape($group['label'] ?? Text::_('MOD_PG_REPORT_GROUP_EMPTY')); ?></strong>
                        <span class="pg-report__totals ms-3">
                            <?php echo Text::sprintf(
                                'MOD_PG_REPORT_GROUP_TOTALS',
                                (int) ($group['employees_cnt'] ?? 0),
                                (int) ($group['positions_cnt'] ?? 0),
                                (int) ($group['rows_cnt'] ?? 0)
                            ); ?>
                        </span>
                    </td>
                </tr>
                <?php foreach ($groupRows as $row) : ?>
                    <tr class="pg-report__data-row<?php echo !empty($row['__match']) ? ' pg-report__row--match' : ''; ?><?php echo $isCollapsed ? ' pg-report__row--hidden' : ''; ?>" data-group="<?php echo $groupIndex; ?>">
                        <?php foreach ($columns as $column) : ?>
                            <td><?php echo $escape($row[$column] ?? null); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pg-report__pagination">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-page="<?php echo max(1, $page - 1); ?>" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
            <?php echo Text::_('JPREVIOUS'); ?>
        </button>
        <span><?php echo Text::sprintf('MOD_PG_REPORT_PAGE_OF', $page, $totalPages); ?></span>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-page="<?php echo min($totalPages, $page + 1); ?>" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
            <?php echo Text::_('JNEXT'); ?>
        </button>
    </div>
<?php endif; ?>
