<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_pg_report
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

class JFormFieldColumnLabelsMap extends FormField
{
    protected $type = 'columnlabelsmap';

    protected function getInput(): string
    {
        $value = (string) $this->value;
        $map = $this->parseMap($value);
        $columns = $this->collectColumns($map);
        $id = $this->id;
        $hiddenValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $hiddenName = htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8');
        $rowsHtml = '';

        foreach ($columns as $column) {
            $columnEscaped = htmlspecialchars($column, ENT_QUOTES, 'UTF-8');
            $labelValue = htmlspecialchars((string) ($map[$column] ?? ''), ENT_QUOTES, 'UTF-8');
            $rowsHtml .= '<tr data-key="' . $columnEscaped . '">'
                . '<td><code>' . $columnEscaped . '</code></td>'
                . '<td><input type="text" class="form-control inputbox" value="' . $labelValue . '" placeholder="' . $columnEscaped . '"></td>'
                . '</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="2" class="text-muted">'
                . htmlspecialchars(Text::_('MOD_PG_REPORT_COLUMN_LABELS_EMPTY_HINT'), ENT_QUOTES, 'UTF-8')
                . '</td></tr>';
        }

        $hiddenIdJs = json_encode($id, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $tableIdJs = json_encode($id . '_table', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        Factory::getApplication()->getDocument()->addScriptDeclaration(
            "(function(){"
            . "var hidden=document.getElementById(" . $hiddenIdJs . ");"
            . "var table=document.getElementById(" . $tableIdJs . ");"
            . "if(!hidden||!table){return;}"
            . "var sync=function(){"
            . "var map={};"
            . "table.querySelectorAll('tr[data-key]').forEach(function(row){"
            . "var key=row.getAttribute('data-key')||'';"
            . "var input=row.querySelector('input');"
            . "var label=input&&typeof input.value==='string'?input.value.trim():'';"
            . "if(key!==''&&label!==''){map[key]=label;}"
            . "});"
            . "hidden.value=JSON.stringify(map);"
            . "};"
            . "table.addEventListener('input',function(event){if(event.target&&event.target.matches('input')){sync();}});"
            . "table.addEventListener('change',function(event){if(event.target&&event.target.matches('input')){sync();}});"
            . "var form=hidden.form||hidden.closest('form');"
            . "if(form){form.addEventListener('submit',sync);}"
            . "sync();"
            . "})();"
        );

        return '<input type="hidden" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . $hiddenName . '" value="' . $hiddenValue . '">'
            . '<table id="' . htmlspecialchars($id . '_table', ENT_QUOTES, 'UTF-8') . '" class="table table-sm table-striped">'
            . '<thead><tr><th>' . htmlspecialchars(Text::_('MOD_PG_REPORT_COLUMN_LABELS_SOURCE_COLUMN'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars(Text::_('MOD_PG_REPORT_COLUMN_LABELS_TARGET_LABEL'), ENT_QUOTES, 'UTF-8') . '</th></tr></thead>'
            . '<tbody>' . $rowsHtml . '</tbody>'
            . '</table>';
    }

    private function parseMap(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            $jsonMap = [];

            foreach ($decoded as $column => $label) {
                $column = trim((string) $column);
                $label = trim((string) $label);

                if ($column !== '' && $label !== '') {
                    $jsonMap[$column] = $label;
                }
            }

            if (!empty($jsonMap)) {
                return $jsonMap;
            }
        }

        $lines = preg_split('/\R/u', $value);

        if ($lines === false) {
            return [];
        }

        $map = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$column, $label] = array_map('trim', explode('=', $line, 2));

            if ($column !== '' && $label !== '') {
                $map[$column] = $label;
            }
        }

        return $map;
    }

    private function collectColumns(array $map): array
    {
        $keys = [];

        foreach (['visible_columns_ui', 'visible_columns', 'search_columns', 'pointer_match_columns', 'group_key_cascade'] as $field) {
            $raw = (string) $this->form->getValue($field, 'params', '');

            foreach (array_filter(array_map('trim', explode(',', $raw))) as $column) {
                $keys[$column] = $column;
            }
        }

        $defaultSort = trim((string) $this->form->getValue('default_sort_by', 'params', ''));

        if ($defaultSort !== '') {
            $keys[$defaultSort] = $defaultSort;
        }

        foreach (array_keys($map) as $column) {
            $keys[$column] = $column;
        }

        return array_values($keys);
    }
}
