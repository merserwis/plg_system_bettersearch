<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_bettersearch
 */

namespace Merserwis\Component\BetterSearch\Administrator\View\Cpanel;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Merserwis\Component\BetterSearch\Administrator\Helper\Catalog;
use Merserwis\Component\BetterSearch\Administrator\Helper\Settings;

/**
 * The control panel: the tools as tiles in sections (with a dot that says whether each is on), the
 * configuration as a section of its own, the quick setup offered first on a fresh site, and on the side
 * the state of BetterSearch and the buttons used most.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, list<array>> */
    protected array $tiles = [];

    protected ?object $plugin = null;

    protected bool $isNew = false;

    protected bool $gridbox = false;

    protected bool $store = false;

    protected int $on = 0;

    protected int $total = 0;

    protected string $version = '';

    protected bool $canEdit = false;

    protected bool $canClear = false;

    public function display($tpl = null): void
    {
        // also when reached by another task than display (page.display, setup.display): the settings are the plugin's
        if (!$this->getCurrentUser()->authorise('core.manage', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        Settings::loadLanguage();
        $this->plugin  = Settings::plugin();
        $params        = Settings::params();
        $defaults      = Settings::defaults();
        $this->store   = Settings::hasStore();
        $this->gridbox = Settings::hasGridbox();
        $this->tiles   = Catalog::tiles($params, $defaults, $this->store);
        $this->isNew   = Settings::isNew($params);
        foreach ($this->tiles as $tiles) {
            foreach ($tiles as $tile) {
                if ($tile['state'] !== '') {
                    $this->total++;
                    $this->on += $tile['state'] === 'on' ? 1 : 0;
                }
            }
        }
        $this->version  = (string) (Settings::manifest()?->version ?? '');
        $user           = $this->getCurrentUser();
        $this->canEdit  = $user->authorise('core.edit.state', 'com_plugins');
        // the index figures and the button "clear the caches" are answered by the plugin: not offered while it is off
        $this->canClear = $user->authorise('core.manage', 'com_plugins') && Settings::pluginReady();

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_bettersearch');
        $wa->useStyle('com_bettersearch.admin');
        if ($this->canClear) {
            $texts = [];
            foreach (['INDEX_ITEMS', 'INDEX_PENDING', 'INDEX_PENDING_NONE', 'INDEX_ERROR', 'CACHE_CLEARED'] as $key) {
                $texts[$key] = Text::_('COM_BETTERSEARCH_' . $key);
            }
            $this->getDocument()->addScriptOptions('com_bettersearch.cpanel', [
                'ajax'  => Uri::base(true) . '/index.php?option=com_ajax&plugin=bettersearch&group=system&format=json&' . Session::getFormToken() . '=1',
                'texts' => $texts,
            ]);
            $wa->useScript('com_bettersearch.cpanel');
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_BETTERSEARCH') . ': ' . Text::_('COM_BETTERSEARCH_MENU_CPANEL'), 'tools');
        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('wand', 'COM_BETTERSEARCH_MENU_SETUP')->url('index.php?option=com_bettersearch&view=setup');
        $toolbar->linkButton('cog', 'COM_BETTERSEARCH_MENU_CONFIG')->url('index.php?option=com_bettersearch&view=page&page=config');
    }
}
