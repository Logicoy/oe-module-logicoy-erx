<?php

/**
 * Module entry point: events, menus, globals.
 *
 * The prescribing entry point sits alongside OpenEMR's native Rx screen rather
 * than displacing it, so a practice can always fall back to core prescribing.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx;

use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\ProviderProfileForm;
use LogiCoy\ERx\Prescription\NotificationStore;
use LogiCoy\ERx\Queue\BackgroundSync;
use LogiCoy\ERx\Smart\SmartLaunchHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\PatientDemographics\RenderEvent;
use OpenEMR\Events\User\UserCreatedEvent;
use OpenEMR\Events\User\UserEditRenderEvent;
use OpenEMR\Events\User\UserUpdatedEvent;
use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

class Bootstrap
{
    public const MODULE_NAME = 'oe-module-logicoy-erx';
    public const MODULE_TITLE = 'LogiCoy eRx';

    /** Prescribing surfaces check the same ACL as core Rx. */
    public const ACL_SECTION = 'patients';
    public const ACL_SUBSECTION = 'rx';

    private EventDispatcherInterface $eventDispatcher;
    private GlobalConfig $globalConfig;
    private SystemLogger $logger;
    private string $moduleDirectoryName;
    private ?ProviderProfileForm $providerProfileForm = null;

    public function __construct(EventDispatcherInterface $eventDispatcher, ?SystemLogger $logger = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger ?? new SystemLogger();
        $this->moduleDirectoryName = basename(dirname(__DIR__));
        $this->globalConfig = new GlobalConfig($GLOBALS);
    }

    public function subscribeToEvents(): void
    {
        // Globals are registered unconditionally — an admin has to be able to
        // reach the settings in order to turn the module on in the first place.
        $this->eventDispatcher->addListener(
            GlobalsInitializedEvent::EVENT_HANDLE,
            [$this, 'addGlobalSettings']
        );

        if (!$this->globalConfig->isModuleEnabled()) {
            return;
        }

        $this->eventDispatcher->addListener(
            RenderEvent::EVENT_SECTION_LIST_RENDER_AFTER,
            [$this, 'renderPrescribingCard']
        );

        // OpenEMR's own prescription buttons open the LogiCoy screen. The
        // event is what Header::setupHeader() offers modules for adding a
        // script to a page; absent on an OpenEMR too old to have it.
        if (class_exists(ScriptFilterEvent::class)) {
            $this->eventDispatcher->addListener(
                ScriptFilterEvent::EVENT_NAME,
                [$this, 'addRxEntryScript']
            );
        }

        $this->eventDispatcher->addListener(
            MenuEvent::MENU_UPDATE,
            [$this, 'addMenuItem']
        );

        // The eRx fields on the OpenEMR user screens. Core sets aside a full
        // width cell inside its own form for module content and fires these
        // three events around it, so nothing in core has to change.
        $this->eventDispatcher->addListener(
            UserEditRenderEvent::EVENT_USER_EDIT_RENDER_AFTER,
            [$this, 'renderProviderProfileFields']
        );

        $this->eventDispatcher->addListener(
            UserCreatedEvent::EVENT_HANDLE,
            [$this, 'saveProviderProfileOnCreate']
        );

        $this->eventDispatcher->addListener(
            UserUpdatedEvent::EVENT_HANDLE,
            [$this, 'saveProviderProfileOnUpdate']
        );
    }

    /**
     * Add-user and edit-user screens. The event carries no user id on add.
     */
    public function renderProviderProfileFields(UserEditRenderEvent $event): void
    {
        $userId = $event->getUserId();

        $this->getProviderProfileForm()->render($userId !== null ? (int)$userId : null);
    }

    /**
     * Fires after core has inserted the user, and hands back the POST it was
     * given rather than the new row, so the username is the handle we have.
     */
    public function saveProviderProfileOnCreate(UserCreatedEvent $event): void
    {
        $form = $this->getProviderProfileForm();
        $data = $event->getUserData();

        $form->saveFromPost(
            $form->resolveUserIdByUsername($event->getUsername()),
            is_array($data) ? $data : []
        );
    }

    public function saveProviderProfileOnUpdate(UserUpdatedEvent $event): void
    {
        $userId = (int)($event->getUserId() ?? 0);

        $this->getProviderProfileForm()->saveFromPost($userId ?: null, $event->getNewUserData());
    }

    private function getProviderProfileForm(): ProviderProfileForm
    {
        return $this->providerProfileForm ??= new ProviderProfileForm(null, $this->logger);
    }

    public function addGlobalSettings(GlobalsInitializedEvent $event): void
    {
        $service = $event->getGlobalsService();
        $service->createSection(GlobalConfig::CONFIG_SECTION, 'Connectors');

        foreach (GlobalConfig::buildGlobalSettings() as $key => $setting) {
            $service->appendToSection(GlobalConfig::CONFIG_SECTION, $key, $setting);
        }
    }

    /**
     * Pages whose editScripts() opens core's prescription list: the patient
     * dashboard (Prescriptions card) and the Eye Exam form (Impression/Plan
     * eRx button). Inside an encounter a form is served through
     * view_form.php / load_form.php, which is the script name the header
     * event sees; view.php is the form opened on its own. The script only
     * acts where editScripts() exists.
     */
    public const RX_ENTRY_PAGES = ['demographics.php', 'view_form.php', 'load_form.php', 'view.php'];

    /**
     * Add public/assets/erx-entry.js to the pages above, so their prescription
     * buttons open the LogiCoy screen. Nothing here may break the page header:
     * every failure is logged and the page renders without the script.
     */
    public function addRxEntryScript(ScriptFilterEvent $event): void
    {
        try {
            if (
                !in_array($event->getPageName(), self::RX_ENTRY_PAGES, true)
                || !$this->globalConfig->isModuleEnabled()
                || !$this->globalConfig->shouldReplaceRxButtons()
                || !AclMain::aclCheckCore(self::ACL_SECTION, self::ACL_SUBSECTION)
            ) {
                return;
            }
            $script = $this->getPublicPath() . 'assets/erx-entry.js';
            // setupHeader() runs more than once on the dashboard; add it once.
            if (!in_array($script, $event->getScripts(), true)) {
                $event->setScripts(array_merge($event->getScripts(), [$script]));
            }
        } catch (Throwable $e) {
            $this->logger->debug('LogiCoy eRx entry script not added', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Render the prescribing entry point on Patient Summary / Demographics.
     */
    public function renderPrescribingCard(RenderEvent $event): void
    {
        if (!AclMain::aclCheckCore(self::ACL_SECTION, self::ACL_SUBSECTION)) {
            return;
        }

        $pid = $event->getPid();
        if (empty($pid)) {
            return;
        }

        $launchUrl = $this->getPublicPath() . 'launch.php?pid=' . urlencode((string)$pid);

        // Rendered inline rather than through Twig: the demographics event fires
        // mid-page and OpenEMR's Twig environment is not guaranteed to be built.
        echo '<section class="logicoy-erx-card">';
        echo '<div class="card mb-2">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">' . text(xl('LogiCoy eRx')) . '</h5>';
        echo '<p class="card-text text-muted">'
            . text(xl('Electronic prescribing through the LogiCoy network.'))
            . '</p>';
        $awaiting = $this->countAwaitingRequests((int)$pid);
        if ($awaiting > 0) {
            echo '<p class="card-text"><span class="badge badge-warning">'
                . text((string)$awaiting) . '</span> '
                . text(xl('pharmacy request(s) awaiting a response'))
                . '</p>';
        }
        echo '<a class="btn btn-primary btn-sm" href="' . attr($launchUrl) . '" '
            . 'onclick="top.restoreSession()">'
            . text(xl('Open prescribing'))
            . '</a>';
        echo '</div></div></section>';
    }

    /**
     * Requests on this chart the prescriber still owes a pharmacy an answer on.
     *
     * The demographics page must render whatever happens here: before the
     * 1.0.7 upgrade has run, the table does not exist yet. Check for it first
     * rather than relying on the catch — when a bound query fails to prepare,
     * ADOdb's mysqli driver echoes the SQL into the page before QueryUtils
     * throws. existsTable() runs unbound, which takes the quiet path.
     */
    private function countAwaitingRequests(int $pid): int
    {
        try {
            if (!QueryUtils::existsTable(NotificationStore::TABLE)) {
                $this->logger->debug('LogiCoy eRx notification table missing; disable and re-enable the module');
                return 0;
            }
            return (new NotificationStore())->countAwaitingForPatient($pid);
        } catch (Throwable $e) {
            $this->logger->debug('LogiCoy eRx request count unavailable', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * One entry, "LogiCoy eRx", opening public/index.php, which holds every
     * screen as a tab. Prescribers reach it through the Rx ACL; an
     * administrator without prescribing rights reaches it too, for the
     * Settings tab, which that page offers to administrators only.
     *
     * It sits under Miscellaneous rather than Modules: core gates the whole
     * Modules section on the menus/modle ACL, which clinical roles do not
     * hold, so an entry there is invisible to the prescribers who use it.
     */
    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        // The menu is built once per sign-in, which makes it the place to
        // check that the background service is there to send the queue.
        try {
            BackgroundSync::ensureRegistered();
        } catch (Throwable $e) {
            $this->logger->error('LogiCoy eRx background sync not registered', ['error' => $e->getMessage()]);
        }

        $menu = $event->getMenu();

        foreach ($menu as $section) {
            if (($section->menu_id ?? '') !== 'misimg') {
                continue;
            }

            $item = new \stdClass();
            $item->requirement = 0;
            $item->target = 'mod';
            $item->menu_id = 'logicoy_erx';
            $item->label = xlt('LogiCoy eRx');
            $item->url = $this->getPublicPath() . 'index.php';
            $item->children = [];
            // A list of lists is an OR in core's MenuRole.
            $item->acl_req = [[self::ACL_SECTION, self::ACL_SUBSECTION], ['admin', 'super']];
            $item->global_req = [GlobalConfig::ENABLE_MODULE];
            $section->children[] = $item;
            break;
        }

        $event->setMenu($menu);

        return $event;
    }

    /**
     * A file under public/assets, relative to the screens in public/, stamped
     * with its modification time. Without the stamp a browser keeps the
     * stylesheet and scripts of the previous release after an upgrade.
     */
    public static function assetUrl(string $file): string
    {
        $path = dirname(__DIR__) . '/public/assets/' . $file;

        return 'assets/' . $file . (is_file($path) ? '?v=' . filemtime($path) : '');
    }

    public function getPublicPath(): string
    {
        return $this->getModulePath() . 'public/';
    }

    /** The module's own directory, as a path from the site root. */
    public function getModulePath(): string
    {
        return rtrim($GLOBALS['webroot'] ?? '', '/')
            . '/interface/modules/custom_modules/' . $this->moduleDirectoryName . '/';
    }

    public function getGlobalConfig(): GlobalConfig
    {
        return $this->globalConfig;
    }
}
