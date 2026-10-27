<?php

/**
 * Sliding Fee Discount module bootstrap, loaded by OpenEMR on every request while the module is enabled.
 *
 * @package   OpenHealthcare\SlidingFee
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\SlidingFee\Bootstrap;

// This module needs OpenEMR 8.1.0 or later: its pages use the session, service container and
// OEGlobalsBag getters 8.1.0 brought. OpenEMR loads this file on every request, the login page
// included, so on an older release (7.0.4 to 8.0.x have the bag without the getters, earlier ones
// no bag) the module stays switched off rather than stop every page.
if (!class_exists(OEGlobalsBag::class) || !method_exists(OEGlobalsBag::class, 'getKernel')) {
    return;
}

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\SlidingFee\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

// openhealthcare-common: in OpenEMR's vendor when Composer installed this module; in this module's own
// when it was copied in and `composer install --no-dev` run in its folder. Only its own folder is
// registered, before anything asks for the class (Composer remembers a class it once failed to find).
$common = __DIR__ . '/vendor/anun333/openhealthcare-common/src';
if (is_dir($common)) {
    $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\', $common);
    // The shared contracts (1.5.0) come from the first copy that has them, so they're there even where a module
    // copied in by hand registered an older copy first: Composer looks under the longer prefix before the shorter.
    if (is_dir($common . '/Contract')) {
        $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\Contract\\', $common . '/Contract');
    }
}
if (!class_exists(\OpenHealthcare\Common\OpenEmr::class)) {
    return;
}

(new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->subscribeToEvents();
