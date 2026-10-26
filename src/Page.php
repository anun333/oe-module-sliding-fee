<?php

/**
 * The language and writing direction each page declares on its html element: the signed-in user's
 * OpenEMR language, through openhealthcare-common 1.4.0. A site that copies modules in by hand can
 * load another module's older copy of the library first, one without pageLang(); the first copy
 * loaded serves every module, so the pages then say English, as they did before, rather than stop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee;

use OpenHealthcare\Common\OpenEmr;

final class Page
{
    /**
     * A BCP 47 tag such as en, es or zh-Hant.
     *
     * @param class-string $library the shared library's OpenEmr class; a test passes another
     */
    public static function lang(string $library = OpenEmr::class): string
    {
        return method_exists($library, 'pageLang') ? (string)$library::pageLang() : 'en';
    }

    /**
     * ltr or rtl.
     *
     * @param class-string $library the shared library's OpenEmr class; a test passes another
     */
    public static function dir(string $library = OpenEmr::class): string
    {
        return method_exists($library, 'pageDir') ? (string)$library::pageDir() : 'ltr';
    }
}
