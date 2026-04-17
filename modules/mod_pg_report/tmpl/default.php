<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_pg_report
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();

$wa->registerAndUseScript('mod_pg_report.main', 'media/mod_pg_report/js/report.js', [], ['defer' => true]);
$wa->registerAndUseStyle('mod_pg_report.main', 'media/mod_pg_report/css/report.css');
?>
<div
    class="pg-report"
    data-module-id="<?php echo (int) $initialState['moduleId']; ?>"
    data-endpoint="<?php echo htmlspecialchars($initialState['endpoint'], ENT_QUOTES, 'UTF-8'); ?>"
    data-token="<?php echo htmlspecialchars($initialState['token'], ENT_QUOTES, 'UTF-8'); ?>"
    data-default-sort="<?php echo htmlspecialchars($initialState['defaultSortBy'], ENT_QUOTES, 'UTF-8'); ?>"
    data-default-dir="<?php echo htmlspecialchars($initialState['defaultSortDir'], ENT_QUOTES, 'UTF-8'); ?>"
    data-default-per-page="<?php echo (int) $initialState['defaultPerPage']; ?>"
    data-collapsed-by-default="<?php echo !empty($initialState['collapsedByDefault']) ? '1' : '0'; ?>"
>
    <div class="pg-report__controls">
        <label>
            <?php echo Text::_('MOD_PG_REPORT_SEARCH_LABEL'); ?>
            <input type="search" class="pg-report__search" placeholder="<?php echo Text::_('MOD_PG_REPORT_SEARCH_PLACEHOLDER'); ?>">
        </label>

        <label>
            <?php echo Text::_('MOD_PG_REPORT_PER_PAGE_LABEL'); ?>
            <select class="pg-report__per-page">
                <?php foreach ([10, 25, 50, 100, 200] as $pageSize) : ?>
                    <option value="<?php echo $pageSize; ?>" <?php echo ((int) $initialState['defaultPerPage'] === $pageSize) ? 'selected' : ''; ?>>
                        <?php echo $pageSize; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <div class="pg-report__messages"></div>
    <div class="pg-report__content"><?php echo Text::_('MOD_PG_REPORT_LOADING'); ?></div>
</div>
