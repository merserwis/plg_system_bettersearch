<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_bettersearch
 *
 * The settings of BetterSearch are, as they always were, the parameters of its system plugin
 * (#__extensions): the plugin reads them and nothing about that changes. This class reads them, builds
 * the form of a page from the fieldsets of the plugin's manifest, and saves through Joomla's own plugin
 * model, so everything that follows a save of the plugin in Joomla follows here too (the plugin's
 * onExtensionAfterSave empties the kept pages, Joomla cleans its cache of the plugins, the action log
 * notes who saved).
 */

namespace Merserwis\Component\BetterSearch\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Settings
{
    /** the row of the plugin (false: not read yet) */
    private static object|false|null $plugin = false;

    private static ?\SimpleXMLElement $manifest = null;

    private static ?array $defaults = null;

    /** the plugin's row: extension_id, enabled, params; null when it is not installed */
    public static function plugin(): ?object
    {
        if (self::$plugin === false) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName(['extension_id', 'enabled', 'params']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('bettersearch'));
            self::$plugin = $db->setQuery($query)->loadObject() ?: null;
        }

        return self::$plugin;
    }

    public static function params(): Registry
    {
        return new Registry((string) (self::plugin()->params ?? ''));
    }

    /** the texts of the settings are the plugin's: loaded where a page of BetterSearch shows them */
    public static function loadLanguage(): void
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_system_bettersearch', JPATH_ADMINISTRATOR)
            || $lang->load('plg_system_bettersearch', JPATH_PLUGINS . '/system/bettersearch');
    }

    /** the plugin's manifest, whose <config> holds every setting */
    public static function manifest(): ?\SimpleXMLElement
    {
        if (self::$manifest === null) {
            $file = JPATH_PLUGINS . '/system/bettersearch/bettersearch.xml';
            $xml  = is_file($file) ? simplexml_load_file($file) : false;
            if ($xml === false || !isset($xml->config->fields)) {
                return null;
            }
            self::$manifest = $xml;
        }

        return self::$manifest;
    }

    /**
     * The tabs of a page: fieldset name => its label (a language key), in the order of the manifest.
     *
     * @return array<string, string>
     */
    public static function fieldsets(string $page): array
    {
        $out = [];
        foreach (self::manifest()?->config->fields->fieldset ?? [] as $fieldset) {
            if ((string) $fieldset['page'] === $page) {
                $out[(string) $fieldset['name']] = (string) $fieldset['label'];
            }
        }

        return $out;
    }

    /**
     * The default value of every setting, from the manifest (the plugin uses the same ones when a
     * setting was never saved).
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        if (self::$defaults === null) {
            self::$defaults = [];
            foreach (self::manifest()?->config->fields->fieldset ?? [] as $fieldset) {
                foreach ($fieldset->field as $field) {
                    if (isset($field['default'])) {
                        self::$defaults[(string) $field['name']] = (string) $field['default'];
                    }
                }
            }
        }

        return self::$defaults;
    }

    /**
     * The form of a page (its fieldsets of the manifest under jform[params]), or null for a page without
     * settings. Every page carries the switch between the basic and the advanced settings (the plugin's
     * own field, moved above the tabs by its script), and none the "return to Gridbox" field of the
     * plugin's own form.
     */
    public static function form(string $page): ?Form
    {
        $fields = self::manifest()?->config->fields;
        if (!$fields) {
            return null;
        }
        $copies = [];
        $local  = [];
        $mode   = false;
        foreach ($fields->fieldset as $fieldset) {
            if ((string) $fieldset['page'] !== $page) {
                continue;
            }
            $copy = new \SimpleXMLElement($fieldset->asXML());
            foreach ($copy->xpath('field[@type="gbreturn"]') ?: [] as $field) {
                unset($field[0]);
            }
            $mode = $mode || (bool) $copy->xpath('field[@name="settings_mode"]');
            foreach ($copy->field as $field) {
                $local[(string) $field['name']] = true;
            }
            $copies[] = $copy;
        }
        if (!$copies) {
            return null;
        }
        $xml = '';
        foreach ($copies as $copy) {
            self::resolveShowon($copy, $local);
            // asXML() of a whole document starts with the XML declaration, which may not stand inside another document
            $xml .= preg_replace('/^<\?xml[^>]*\?>\s*/', '', (string) $copy->asXML());
        }
        if (!$mode) {
            $xml .= '<fieldset name="_mode"><field name="settings_mode" type="bsmode" default="basic" label="PLG_SYSTEM_BETTERSEARCH_SETTINGS_MODE_LABEL" hiddenLabel="true" /></fieldset>';
            $local['settings_mode'] = true;
        }
        // the settings of the other pages as hidden fields, never shown or saved: the plugin's own fields read some of them
        // (the searched apps for the order of the sections, the colours for the preview of the theme)
        $context = '';
        foreach ($fields->fieldset as $fieldset) {
            if ((string) $fieldset['page'] === $page) {
                continue;
            }
            foreach ($fieldset->field as $field) {
                $name = (string) $field['name'];
                if ($name === '' || isset($local[$name]) || \in_array((string) $field['type'], ['note', 'spacer', 'bstools', 'bspreview', 'bsdict', 'gbreturn'], true)) {
                    continue;
                }
                $local[$name] = true;
                $context     .= '<field name="' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" type="hidden" />';
            }
        }
        $xml .= '<fieldset name="_context">' . $context . '</fieldset>';
        $form = Factory::getContainer()->get(FormFactoryInterface::class)->createForm('com_bettersearch.page.' . $page, ['control' => 'jform']);
        $form->load('<form><fields name="params" addfieldprefix="' . htmlspecialchars((string) $fields['addfieldprefix'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
            . $xml . '</fields></form>');

        return $form;
    }

    /**
     * A field shown on another page of the settings than the fields it is on (showon="theme:default" where "theme" is
     * on the Appearance page): the form has no such field to look at, so the condition is answered with the saved value
     * now. True: the condition is dropped. False: the field is not in the form (it cannot be shown, and its saved value
     * stays). Only conditions joined with [AND] are answered; the others are left as they are.
     *
     * @param array<string, true> $local names of the fields of the page
     */
    private static function resolveShowon(\SimpleXMLElement $fieldset, array $local): void
    {
        $params   = self::params();
        $defaults = self::defaults();
        $remove   = [];
        foreach ($fieldset->field as $field) {
            $showon = (string) $field['showon'];
            if ($showon === '' || str_contains($showon, '[OR]')) {
                continue;
            }
            $keep = [];
            $show = true;
            foreach (explode('[AND]', $showon) as $condition) {
                if (!preg_match('/^([A-Za-z0-9_]+)(!?):(.*)$/', $condition, $m) || isset($local[$m[1]])) {
                    $keep[] = $condition;
                    continue;
                }
                $actual = $params->get($m[1], $defaults[$m[1]] ?? '');
                $actual = \is_array($actual) ? array_map('strval', $actual) : [(string) $actual];
                $found  = (bool) array_intersect($actual, explode(',', $m[3]));
                $show   = $show && ($m[2] === '!' ? !$found : $found);
            }
            if (!$show) {
                $remove[] = $field;
            } elseif ($keep) {
                $field['showon'] = implode('[AND]', $keep);
            } else {
                unset($field['showon']);
            }
        }
        foreach ($remove as $field) {
            unset($field[0]);
        }
    }

    /** the names of the settings of a form (not those inside a subform, not the hidden context of the other pages) */
    public static function fieldNames(Form $form): array
    {
        $context = [];
        foreach ($form->getFieldset('_context') as $field) {
            $context[(string) $field->fieldname] = true;
        }
        $out = [];
        foreach ($form->getGroup('params') as $field) {
            if (!isset($context[(string) $field->fieldname])) {
                $out[] = (string) $field->fieldname;
            }
        }

        return $out;
    }

    /**
     * Saves the settings of a page: the values sent are filtered and checked by the page's form; a
     * setting of the page that was not sent (a list of checkboxes with none ticked) goes back to its
     * default, as it did when the plugin's own form replaced all its settings; the settings of other
     * pages are kept as they are.
     *
     * @param array<string, mixed> $posted jform[params]
     *
     * @return list<string> the errors (none: saved)
     */
    public static function savePage(string $page, array $posted): array
    {
        $form = self::form($page);
        if (!$form) {
            return [Text::_('COM_BETTERSEARCH_ERROR_PAGE')];
        }
        $data  = $form->filter(['params' => $posted]);
        $valid = $form->validate($data);
        if ($valid !== true) {
            $errors = [];
            foreach ($form->getErrors() as $error) {
                $errors[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
            }

            return $errors ?: [Text::_('COM_BETTERSEARCH_ERROR_SAVE')];
        }
        $values = (array) ($data['params'] ?? []);

        return self::store($values, array_diff(self::fieldNames($form), array_keys($values)));
    }

    /**
     * Writes settings into the plugin's parameters through Joomla's plugin model.
     *
     * @param array<string, mixed> $set   setting => value
     * @param list<string>         $unset settings that go back to their default
     *
     * @return list<string> the errors (none: saved)
     */
    public static function store(array $set, array $unset = []): array
    {
        // read again just before writing: another administrator may have saved another page meanwhile
        self::$plugin = false;
        $plugin       = self::plugin();
        if (!$plugin) {
            return [Text::_('COM_BETTERSEARCH_ERROR_PLUGIN')];
        }
        $params = json_decode((string) $plugin->params, true);
        $params = \is_array($params) ? $params : [];
        foreach ($unset as $key) {
            unset($params[$key]);
        }
        $params = array_replace($params, $set);

        try {
            $model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            if (!$model || !$model->save(['extension_id' => (int) $plugin->extension_id, 'params' => $params])) {
                return [($model ? (string) $model->getError() : '') ?: Text::_('COM_BETTERSEARCH_ERROR_SAVE')];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        self::$plugin = false;

        return [];
    }

    /** turns the plugin on (Joomla's plugin model, so the change of state is seen by other extensions) */
    public static function enable(): array
    {
        $plugin = self::plugin();
        if (!$plugin) {
            return [Text::_('COM_BETTERSEARCH_ERROR_PLUGIN')];
        }
        try {
            $model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            $pks   = [(int) $plugin->extension_id];
            if (!$model || !$model->publish($pks, 1)) {
                return [($model ? (string) $model->getError() : '') ?: Text::_('COM_BETTERSEARCH_ERROR_SAVE')];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        self::$plugin = false;

        return [];
    }

    /** the plugin is installed and on: its field classes (the pages are made of them) are then loaded */
    public static function pluginReady(): bool
    {
        return (int) (self::plugin()->enabled ?? 0) === 1 && class_exists(\Merserwis\Plugin\System\BetterSearch\Field\BsmodeField::class);
    }

    /** the site has a Gridbox store (an app of products) */
    public static function hasStore(): bool
    {
        try {
            // a site without Gridbox has no such table: the query fails and that is "no store"
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select('1')
                ->from($db->quoteName('#__gridbox_app'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('products'));

            return (bool) $db->setQuery($query, 0, 1)->loadResult();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Gridbox is installed and on */
    public static function hasGridbox(): bool
    {
        return \Joomla\CMS\Component\ComponentHelper::isEnabled('com_gridbox');
    }

    /**
     * A fresh installation: the installer's script marks it (setup_pending) and the quick setup has not
     * been saved yet, so the control panel offers the quick setup first. A site updated from an older
     * version has its settings and is not asked (Joomla saves the defaults of a new install, so the
     * number of settings says nothing).
     */
    public static function isNew(Registry $params): bool
    {
        return (int) $params->get('setup_pending', 0) === 1 && !(int) $params->get('setup_done', 0);
    }
}
