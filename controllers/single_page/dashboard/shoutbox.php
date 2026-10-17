<?php

namespace Concrete\Package\Shoutbox\Controller\SinglePage\Dashboard;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Page\PageList;
use Concrete\Core\User\Group\GroupList;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\Error\UserMessageException;
use Shoutbox\Install\Installer;
use Shoutbox\Realtime\Realtime;
use Shoutbox\Shout\Bans;
use Shoutbox\Shout\Settings;
use Shoutbox\Shout\ShoutService;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dashboard › Shoutbox: who may read, shout and moderate, the channels, the limits, retention, the real-time status
 * and the active bans.
 */
class Shoutbox extends DashboardPageController
{
    public function view()
    {
        $settings = $this->app->make(Settings::class);
        $this->set('settings', $settings);
        $this->set('groups', $this->groups());
        $this->set('colors', Settings::COLORS);
        $this->set('icons', array_keys(Settings::ICONS));
        $this->set('bans', $this->app->make(Bans::class)->listActive());
        $realtime = $this->app->make(Realtime::class);
        $this->set('realtime', [
            'enabled' => $realtime->isEnabled(),
            'hubUrl' => $realtime->hubUrl(),
            'auth' => $realtime->isEnabled() ? $realtime->authMode() : '',
            'topic' => $realtime->topic(),
        ]);
        $this->set('pages', $this->pagesWithShoutbox());
        $this->set('attributeHandle', Installer::ATTRIBUTE);
    }

    public function save()
    {
        if (!$this->token->validate('shoutbox_save')) {
            $this->error->add($this->token->getErrorMessage());
        }
        if (!$this->error->has()) {
            $post = $this->request->request;
            $this->app->make(Settings::class)->save([
                'enabled' => $post->get('enabled'),
                'read_groups' => $post->all('read_groups'),
                'shout_groups' => $post->all('shout_groups'),
                'moderator_groups' => $post->all('moderator_groups'),
                'channels' => $post->all('channels'),
                'default_channel' => $post->get('default_channel'),
                'max_length' => $post->get('max_length'),
                'history' => $post->get('history'),
                'rate_count' => $post->get('rate_count'),
                'rate_window' => $post->get('rate_window'),
                'rate_ban' => $post->get('rate_ban'),
                'retention_days' => $post->get('retention_days'),
                'toast_seconds' => $post->get('toast_seconds'),
                'poll_seconds' => $post->get('poll_seconds'),
                'pin_durations' => $post->get('pin_durations'),
            ]);
            $this->flash('success', t('The shoutbox settings were saved.'));

            return $this->buildRedirect($this->action(''));
        }
        $this->view();
    }

    public function ban()
    {
        if (!$this->token->validate('shoutbox_ban')) {
            $this->error->add($this->token->getErrorMessage());
        }
        if (!$this->error->has()) {
            $post = $this->request->request;
            $name = trim((string) $post->get('username'));
            $repository = $this->app->make(UserInfoRepository::class);
            $info = $repository->getByName($name) ?: $repository->getByEmail($name);
            if (!$info) {
                $this->error->add(t('No member called "%s".', $name));
            } else {
                try {
                    $this->app->make(ShoutService::class)->ban($this->app->make(User::class), (int) $info->getUserID(), (int) $post->get('minutes', 10), (string) $post->get('reason'));
                    $this->flash('success', t('%s is banned.', $info->getUserDisplayName()));

                    return $this->buildRedirect($this->action(''));
                } catch (UserMessageException $e) {
                    $this->error->add($e->getMessage());
                }
            }
        }
        $this->view();
    }

    public function unban()
    {
        if (!$this->token->validate('shoutbox_unban')) {
            $this->error->add($this->token->getErrorMessage());
        }
        if (!$this->error->has()) {
            $this->app->make(Bans::class)->unban((int) $this->request->request->get('id'));
            $this->flash('success', t('The ban was lifted.'));

            return $this->buildRedirect($this->action(''));
        }
        $this->view();
    }

    /**
     * @return array<int, string> gID => name, Guest and Registered first
     */
    protected function groups(): array
    {
        $groups = [GUEST_GROUP_ID => t('Guests (everybody)'), REGISTERED_GROUP_ID => t('Signed-in members')];
        $list = new GroupList();
        $list->includeAllGroups();
        $others = [];
        foreach ($list->getResults() as $group) {
            $gID = (int) $group->getGroupID();
            if (!in_array($gID, [GUEST_GROUP_ID, REGISTERED_GROUP_ID], true)) {
                $others[$gID] = $group->getGroupDisplayName(false);
            }
        }
        asort($others);

        return $groups + $others;
    }

    /**
     * @return array<int, array{name: string, path: string, url: string}>
     */
    protected function pagesWithShoutbox(): array
    {
        $pages = [];
        try {
            $list = new PageList();
            $list->ignorePermissions();
            $list->filterByAttribute(Installer::ATTRIBUTE, true);
            $list->sortByName();
            foreach (array_slice($list->getResults(), 0, 50) as $page) {
                $pages[] = ['name' => (string) $page->getCollectionName(), 'path' => (string) $page->getCollectionPath(), 'url' => (string) $page->getCollectionLink()];
            }
        } catch (\Throwable $e) {
            // the attribute key may not exist yet (first install): nothing to list
        }

        return $pages;
    }
}
