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

/** Gridbox product fields whose values go into the index (text-like field types only). */
class BsfieldsField extends ListField
{
    protected $type = 'Bsfields';

    private const SKIP = ['product-slideshow', 'product-gallery', 'field-simple-gallery', 'field-slideshow', 'field-google-maps',
        'field-video', 'image-field', 'field-button', 'file', 'field-file'];

    protected function getOptions()
    {
        $options = parent::getOptions();
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select(['f.id', 'f.label', 'f.field_type', 'f.options', 'a.title AS app'])
                ->from($db->quoteName('#__gridbox_fields', 'f'))
                ->leftJoin($db->quoteName('#__gridbox_app', 'a') . ' ON a.id = f.app_id')
                ->order('a.order_list ASC, f.app_id ASC, f.order_list ASC, f.id ASC');
            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $field) {
                if (in_array($field->field_type, self::SKIP, true)) {
                    continue;
                }
                // the brand filter: fields with a list of values only
                if ((string) $this->element['selectonly'] === 'true' && !in_array($field->field_type, ['select', 'radio', 'checkbox'], true)) {
                    continue;
                }
                $options[] = (object) ['value' => (int) $field->id, 'text' => $field->app . ' › ' . self::name($field) . ' [' . $field->field_type . ']'];
            }
        } catch (\Throwable $e) {
        }

        return $options;
    }

    /**
     * The name of a field as in Gridbox's app settings (options.label). The label column is the text shown
     * before the value on the page: empty for text areas, or "." and "Uwaga:" for others.
     */
    public static function name(object $field): string
    {
        $options = json_decode((string) ($field->options ?? ''), true);
        foreach ([is_array($options) ? (string) ($options['label'] ?? '') : '', (string) ($field->label ?? '')] as $name) {
            $name = trim($name);
            if ($name !== '' && preg_match('/[\p{L}\p{N}]/u', $name)) {
                return $name;
            }
        }

        return '#' . (int) $field->id;
    }
}
