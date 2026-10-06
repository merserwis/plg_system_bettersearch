<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Merserwis\Plugin\System\BetterSearch\Render\Renderer;

/**
 * Order of the result sections: matching categories, each Gridbox app and the pages, put in order by
 * dragging (or with the arrow buttons). Stored as a list of keys: "cats", "app<id>", "pages".
 */
class BsorderField extends FormField
{
    protected $type = 'Bsorder';

    protected function getInput()
    {
        Admin::assets();
        $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $t    = fn (string $k) => Text::_('PLG_SYSTEM_BETTERSEARCH_' . $k);
        $form = fn (string $name, $default) => $this->form ? $this->form->getValue($name, 'params', $default) : $default;

        $apps = [];
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()->select(['id', 'title', 'type'])->from($db->quoteName('#__gridbox_app'))
                ->where($db->quoteName('type') . ' NOT IN (' . $db->quote('system_apps') . ', ' . $db->quote('single') . ')')
                ->order('order_list ASC, id ASC');
            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $app) {
                $apps[(int) $app->id] = $app;
            }
        } catch (\Throwable $e) {
        }

        // default order: categories, the searched apps as chosen, the other apps, the pages
        $chosen = $form('apps', []);
        $chosen = array_values(array_filter(array_map('intval', is_array($chosen) ? $chosen : explode(',', (string) $chosen))));
        if (!$chosen) {
            $chosen = array_keys(array_filter($apps, fn ($a) => $a->type === 'products'));
        }
        $labels = ['cats' => [$t('ORDER_CATS'), '']];
        foreach ($chosen as $id) {
            if (isset($apps[$id])) {
                $labels['app' . $id] = [$apps[$id]->title, $apps[$id]->type];
            }
        }
        foreach ($apps as $id => $app) {
            $labels['app' . $id] ??= [$app->title, $app->type, true];
        }
        $labels['pages'] = [$t('ORDER_PAGES'), ''];
        $default = array_keys($labels);
        $order   = Renderer::sectionOrder(new Registry(['results_order' => (string) $this->value]), $default);

        $items = '';
        foreach ($order as $key) {
            [$title, $type] = $labels[$key];
            $off    = !empty($labels[$key][2]) || ($key === 'pages' && !$form('index_pages', 0));
            $items .= '<li class="bs-order-item' . ($off ? ' is-off' : '') . '" draggable="true" data-key="' . $esc($key) . '">'
                . '<span class="bs-order-handle" aria-hidden="true">⋮⋮</span><span class="bs-order-pos"></span>'
                . '<span class="bs-order-title">' . $esc($title) . ($type !== '' ? ' <small class="text-muted">' . $esc($type) . '</small>' : '')
                . ($off ? ' <small class="bs-order-off">' . $esc($t('ORDER_NOT_SEARCHED')) . '</small>' : '') . '</span>'
                . '<button type="button" class="btn btn-link btn-sm" data-move="-1" aria-label="' . $esc($t('PICKER_UP')) . '">↑</button>'
                . '<button type="button" class="btn btn-link btn-sm" data-move="1" aria-label="' . $esc($t('PICKER_DOWN')) . '">↓</button></li>';
        }

        return '<div class="bs-order" data-default="' . $esc(implode(',', $default)) . '">'
            . '<input type="hidden" name="' . $esc($this->name) . '" id="' . $esc($this->id) . '" value="' . $esc((string) $this->value) . '">'
            . '<ol class="bs-order-list">' . $items . '</ol>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary bs-order-reset">↺ ' . $esc($t('ORDER_RESET')) . '</button></div>';
    }
}
