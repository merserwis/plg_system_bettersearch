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

/** Gridbox apps (stores, blogs) whose pages are searched. */
class BsappsField extends ListField
{
    protected $type = 'Bsapps';

    protected function getOptions()
    {
        $options = parent::getOptions();
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select(['id', 'title', 'type'])
                ->from($db->quoteName('#__gridbox_app'))
                ->where($db->quoteName('type') . ' NOT IN (' . $db->quote('system_apps') . ', ' . $db->quote('single') . ')')
                ->order('order_list ASC, id ASC');
            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $app) {
                $options[] = (object) ['value' => (int) $app->id, 'text' => $app->title . ' (' . $app->type . ', #' . (int) $app->id . ')'];
            }
        } catch (\Throwable $e) {
        }

        return $options;
    }
}
