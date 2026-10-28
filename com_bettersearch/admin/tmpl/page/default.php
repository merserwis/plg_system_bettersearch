<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_bettersearch
 *
 * A page of settings: the fieldsets of the page as tabs (vertical for the configuration), or the one
 * fieldset alone.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Merserwis\Component\BetterSearch\Administrator\Helper\Catalog;

/** @var \Merserwis\Component\BetterSearch\Administrator\View\Page\HtmlView $this */

$page   = $this->page;
$names  = array_keys($this->fieldsets);
$tabsId = 'bsc-tabs-' . $page;
// the tab where a tool is switched on and set (the cog of the menu opens it): the first one after the overview
$settings = (string) (Catalog::page($page)['settings'] ?? '');
if ($settings === '' || !\in_array($settings, $names, true)) {
    $settings = $names[0] ?? '';
}
?>
<form action="<?php echo Route::_('index.php?option=com_bettersearch&view=page&page=' . $page); ?>" method="post" name="adminForm" id="adminForm"
    class="form-validate bsc-page bsc-page-<?php echo $this->escape($page); ?>" data-cancel-task="page.cancel" aria-label="<?php echo $this->escape(Text::_('COM_BETTERSEARCH_PAGE_' . strtoupper($page))); ?>">

    <?php if (!$this->plugin || !(int) $this->plugin->enabled) : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_BETTERSEARCH_PLUGIN_OFF'); ?>
            <a href="<?php echo Route::_('index.php?option=com_bettersearch'); ?>"><?php echo Text::_('COM_BETTERSEARCH_MENU_CPANEL'); ?></a>
        </div>
    <?php endif; ?>

    <p class="bsc-page-desc"><?php echo Text::_('COM_BETTERSEARCH_PAGE_' . strtoupper($page) . '_DESC'); ?></p>

    <?php if ($this->state === 'off') : ?>
        <div class="alert alert-info">
            <?php echo Text::_('COM_BETTERSEARCH_TOOL_OFF'); ?>
            <?php if ($settings !== '' && ($this->active ?: ($names[0] ?? '')) !== $settings) : ?>
                <a href="<?php echo Route::_('index.php?option=com_bettersearch&view=page&page=' . $page . '&tab=' . $settings); ?>"><?php echo Text::_('COM_BETTERSEARCH_TOOL_OFF_LINK'); ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$this->form) : ?>
        <div class="alert alert-danger"><?php echo Text::_('COM_BETTERSEARCH_ERROR_PAGE'); ?></div>
    <?php elseif (\count($names) === 1) : ?>
        <?php // the switch between the basic and the advanced settings (a field of the plugin) is on every page ?>
        <?php echo $this->form->getFieldset('_mode') ? $this->form->renderFieldset('_mode') : ''; ?>
        <div class="card"><div class="card-body"><?php echo $this->form->renderFieldset($names[0]); ?></div></div>
    <?php else : ?>
        <?php
        $options = ['active' => $this->active ?: $names[0], 'breakpoint' => 768, 'orientation' => $page === 'config' ? 'vertical' : 'horizontal'];
        if ($this->active === '') {
            // the tab last open on this page comes back, unless the address names one
            $options['recall'] = true;
        }
        echo $this->form->getFieldset('_mode') ? $this->form->renderFieldset('_mode') : '';
        echo HTMLHelper::_('uitab.startTabSet', $tabsId, $options);
        foreach ($this->fieldsets as $name => $label) {
            echo HTMLHelper::_('uitab.addTab', $tabsId, $name, Text::_($label));
            echo $this->form->renderFieldset($name);
            echo HTMLHelper::_('uitab.endTab');
        }
        echo HTMLHelper::_('uitab.endTabSet');
        ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="page" value="<?php echo $this->escape($page); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
