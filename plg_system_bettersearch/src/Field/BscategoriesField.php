<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.bettersearch
 */

namespace Merserwis\Plugin\System\BetterSearch\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\Database\DatabaseInterface;

/** Gridbox categories as a tree (app › parent › child). */
class BscategoriesField extends ListField
{
    protected $type = 'Bscategories';

    protected function getOptions()
    {
        $options = parent::getOptions();
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select(['c.id', 'c.title', 'c.parent', 'c.app_id', 'c.published', 'a.title AS app'])
                ->from($db->quoteName('#__gridbox_categories', 'c'))
                ->leftJoin($db->quoteName('#__gridbox_app', 'a') . ' ON a.id = c.app_id')
                ->order('a.order_list ASC, c.app_id ASC, c.order_list ASC, c.id ASC');
            $rows     = $db->setQuery($query)->loadObjectList() ?: [];
            $children = [];
            foreach ($rows as $row) {
                $children[(int) $row->app_id . ':' . (int) $row->parent][] = $row;
            }
            $apps = [];
            foreach ($rows as $row) {
                $apps[(int) $row->app_id] = (string) $row->app;
            }
            $walk = function (int $app, int $parent, int $depth) use (&$walk, &$options, $children): void {
                foreach ($children[$app . ':' . $parent] ?? [] as $cat) {
                    $options[] = (object) [
                        'value' => (int) $cat->id,
                        'text'  => str_repeat('— ', $depth) . $cat->title . ((int) $cat->published ? '' : ' (unpublished)') . ' #' . (int) $cat->id,
                    ];
                    if ($depth < 30) {
                        $walk($app, (int) $cat->id, $depth + 1);
                    }
                }
            };
            foreach ($apps as $appId => $title) {
                $options[] = (object) ['value' => '', 'text' => '[ ' . $title . ' ]', 'disable' => true];
                $walk($appId, 0, 0);
            }
        } catch (\Throwable $e) {
        }

        return $options;
    }
}
