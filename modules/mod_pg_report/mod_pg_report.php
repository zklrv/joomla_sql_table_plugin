<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_pg_report
 */

defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;

require_once __DIR__ . '/helper.php';

$initialState = ModPgReportHelper::getInitialState($module, $params);

require ModuleHelper::getLayoutPath('mod_pg_report', $params->get('layout', 'default'));
