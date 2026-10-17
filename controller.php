<?php

namespace Concrete\Package\Shoutbox;

use Concrete\Core\Asset\Asset;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Backup\ContentImporter;
use Concrete\Core\Command\Task\Manager as TaskManager;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Routing\Router;
use Concrete\Core\User\User;
use Shoutbox\Command\Task\PurgeController;
use Shoutbox\Install\Installer;
use Shoutbox\Shout\Access;
use Shoutbox\Shout\Frontend;
use Shoutbox\Shout\Settings;
use Shoutbox\Shout\ShoutService;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Shoutbox: one chat room for the signed-in members, pulled up from a button on the pages that have the "Shoutbox"
 * attribute, with channels, pinned announcements of the moderators, timed bans and a big-screen block. Real-time
 * through the core's Mercure integration.
 */
class Controller extends Package
{
    protected $pkgHandle = 'shoutbox';
    protected $appVersionRequired = '9.5.0';
    protected $pkgVersion = '0.1.0';
    protected $pkgAutoloaderRegistries = [
        'src' => 'Shoutbox',
    ];

    public function getPackageName()
    {
        return t('Shoutbox');
    }

    public function getPackageDescription()
    {
        return t('A chat room for the members: shouts in channels, pinned announcements, timed bans and a big-screen block, live through Mercure.');
    }

    public function on_start()
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(Access::class);
        $this->app->singleton(ShoutService::class);
        $this->app->singleton(Frontend::class);

        $router = $this->app->make(Router::class);
        $api = 'Shoutbox\Controller\Api::';
        $router->get('/ccm/shoutbox/boot', $api . 'boot');
        $router->get('/ccm/shoutbox/subscribe', $api . 'subscribe');
        foreach (['shout', 'announce', 'unpin', 'hide', 'ban', 'unban'] as $action) {
            $router->post('/ccm/shoutbox/' . $action, $api . $action);
        }

        $this->app->make(TaskManager::class)->extend('shoutbox_purge', function () {
            return $this->app->make(PurgeController::class);
        });

        $assets = AssetList::getInstance();
        $assets->register('javascript', 'shoutbox/ui', 'js/shoutbox.js', ['version' => $this->pkgVersion, 'position' => Asset::ASSET_POSITION_FOOTER, 'minify' => false, 'combine' => false], $this);
        $assets->register('css', 'shoutbox/ui', 'css/shoutbox.css', ['version' => $this->pkgVersion, 'minify' => false, 'combine' => false], $this);

        // the floating panel on the pages that ask for it: the page config in the footer, the built-in UI's
        // assets unless a theme renders the shoutbox itself (see README, "Theme integration")
        $this->app->make('director')->addListener('on_before_render', function ($event) {
            $view = $event->getArgument('view');
            if (!$view instanceof PageView) {
                return;
            }
            $page = $view->getPageObject();
            if (!$page || $page->isError() || $page->isAdminArea() || $page->isEditMode() || !$page->getAttribute(Installer::ATTRIBUTE)) {
                return;
            }
            $user = $this->app->make(User::class);
            if (!$this->app->make(Access::class)->mayRead($user)) {
                return;
            }
            $frontend = $this->app->make(Frontend::class);
            $view->addFooterItem('<script>window.ShoutboxConfig = ' . json_encode($frontend->config($user), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';</script>');
            if (!$frontend->usesThemeUi()) {
                $view->requireAsset('css', 'font-awesome');
                $view->requireAsset('javascript', 'shoutbox/ui');
                $view->requireAsset('css', 'shoutbox/ui');
            }
        });
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installContent();
        $this->app->make(Installer::class)->install($pkg);

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installContent();
        $this->app->make(Installer::class)->install($this->getPackageEntity());
    }

    protected function installContent(): void
    {
        $importer = new ContentImporter();
        $importer->importContentFile($this->getPackagePath() . '/install.xml');
    }
}
