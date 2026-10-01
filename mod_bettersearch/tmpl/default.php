<?php

/**
 * @package     Merserwis.Module
 * @subpackage  mod_bettersearch
 */

\defined('_JEXEC') or die;

/** @var string $boxHtml the search field rendered by the Better Search plugin */
if ($boxHtml === '') {
    return;
}
?>
<div class="mod-bettersearch"><?php echo $boxHtml; ?></div>
