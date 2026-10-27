<?php

/**
 * Hooks the module into OpenEMR: a Fees menu entry, its Twig templates, and a card on
 * the patient dashboard.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Events\Core\TwigEnvironmentEvent;
use OpenEMR\Events\Patient\Summary\Card\SectionEvent;
use OpenEMR\Menu\MenuEvent;
use OpenHealthcare\Common\Contract\Figures\FiguresRequest;
use OpenHealthcare\SlidingFee\Card\SlidingFeeCard;
use OpenHealthcare\SlidingFee\Domain\NoticeTable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Loader\FilesystemLoader;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-sliding-fee';
    public const TWIG_NAMESPACE = 'slidingfee';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
        $this->eventDispatcher->addListener(TwigEnvironmentEvent::EVENT_CREATED, $this->addTemplates(...));
        $this->eventDispatcher->addListener(SectionEvent::EVENT_HANDLE, $this->addCard(...));
        // Clinic Forms asks before printing a document; its name, so this module needn't load that one's classes.
        $this->eventDispatcher->addListener('clinic_forms.document.fill', $this->fillNotice(...));
        // Another module's page asks for figures (openhealthcare-common's figures contract).
        $this->eventDispatcher->addListener('openhealthcare.figures.request', $this->answerFigures(...));
    }

    /**
     * The discounts posted over a period, for another module's page: for users who may run the evaluation (acct/rep_a,
     * the Collections report's check), and only with a schedule in force. Where an older copy of the shared library
     * loaded first there's no request to answer. A failure is logged and the figures left out, so the asking page still
     * opens.
     */
    public function answerFigures(object $event): void
    {
        if (!class_exists(FiguresRequest::class) || !$event instanceof FiguresRequest || !Figures::asked($event)
            || !AclMain::aclCheckCore('acct', 'rep_a')) {
            return;
        }
        try {
            $context = Context::load();
            if ($context->inForce !== []) {
                Figures::answer($event, $context->allReasons());
            }
        } catch (\Exception $e) {
            error_log('Sliding Fee Discount: figures not answered: ' . $e->getMessage());
        }
    }

    /**
     * Fills Clinic Forms' sliding fee notice with the discounts of the schedule in force. Without a
     * schedule, or its guidelines, the notice's table stays blank for the clinic to fill in.
     */
    public function fillNotice(object $event): void
    {
        if (!method_exists($event, 'fillTable') || ($event->documentId ?? null) !== NoticeTable::DOCUMENT
            || !in_array(NoticeTable::TABLE, (array)($event->tableIds ?? []), true)) {
            return;
        }
        $context = Context::load();
        $schedule = $context->schedule;
        $rows = $schedule === null ? null : NoticeTable::rows($schedule, $context->guidelines);
        if ($schedule !== null && $rows !== null) {
            $event->fillTable(NoticeTable::TABLE, $rows, NoticeTable::note($schedule, (string)($event->language ?? 'en')));
        }
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'sfd';
        $item->menu_id = 'sfd0';
        $item->label = xlt('Sliding Fee Discount');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = ['acct', 'bill'];
        $item->global_req = [];

        $menu = $event->getMenu();
        foreach ($menu as $entry) {
            if (($entry->menu_id ?? '') === 'feeimg' && is_array($entry->children ?? null)) {
                $entry->children[] = $item;
                $event->setMenu($menu);
                return $event;
            }
        }
        $menu[] = $item;
        $event->setMenu($menu);
        return $event;
    }

    /** The module's templates, under their own namespace (@slidingfee/...) so no core template is shadowed. */
    public function addTemplates(TwigEnvironmentEvent $event): void
    {
        $loader = $event->getTwigEnvironment()->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array(self::TWIG_NAMESPACE, $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__) . '/templates', self::TWIG_NAMESPACE);
        }
    }

    public function addCard(SectionEvent $event): void
    {
        if ($event->getSection() !== 'primary') {
            return;
        }
        $pid = (int)($GLOBALS['pid'] ?? 0);
        if ($pid > 0) {
            $event->addCard(new SlidingFeeCard($pid));
        }
    }
}
