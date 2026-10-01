<?php

/**
 * @package     Merserwis.Module
 * @subpackage  mod_bettersearch
 */

namespace Merserwis\Module\BetterSearch\Site\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\Event\Event;

/**
 * A search field for places without a Gridbox search element. The Better Search system plugin
 * renders it and gives it its live results; the plugin must be enabled.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data  = parent::getLayoutData();
        $event = new Event('onBetterSearchModule', ['params' => $data['params'], 'html' => '']);
        $this->getApplication()->getDispatcher()->dispatch('onBetterSearchModule', $event);
        $data['boxHtml'] = (string) $event->getArgument('html', '');

        return $data;
    }
}
