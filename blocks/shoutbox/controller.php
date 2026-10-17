<?php

namespace Concrete\Package\Shoutbox\Block\Shoutbox;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Shoutbox\Shout\Access;
use Shoutbox\Shout\Frontend;
use Shoutbox\Shout\Settings;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The shoutbox on a page ("From the hall"): the feed with channels, pinned announcements and the composer. Templates:
 * Default (full), Compact (sidebar) and Display (big screen, no input). The page's permissions and the shoutbox's
 * read rule decide who sees it.
 */
class Controller extends BlockController
{
    protected $btTable = 'btShoutbox';
    protected $btInterfaceWidth = 520;
    protected $btInterfaceHeight = 520;
    protected $btDefaultSet = 'social';
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockRecord = true;

    /** @var string|null */
    public $eyebrow;
    /** @var string|null */
    public $title;
    /** @var string|null comma separated channel handles, empty = all */
    public $channels;
    /** @var string|null */
    public $defaultChannel;
    /** @var int */
    public $count;
    /** @var string|null */
    public $hint;

    public function getBlockTypeName()
    {
        return t('Shoutbox');
    }

    public function getBlockTypeDescription()
    {
        return t('The shoutbox on a page: shouts in channels, pinned announcements and the composer; a big-screen template without input.');
    }

    public function add()
    {
        $this->set('eyebrow', '');
        $this->set('title', '');
        $this->set('count', 30);
        $this->set('hint', '');
        $this->set('selectedChannels', []);
        $this->set('defaultChannel', '');
        $this->setFormData();
    }

    public function edit()
    {
        $this->set('selectedChannels', $this->selectedChannels());
        $this->setFormData();
    }

    public function save($args)
    {
        $handles = array_filter(array_map('strval', (array) ($args['channels'] ?? [])));
        parent::save([
            'eyebrow' => mb_substr(trim((string) ($args['eyebrow'] ?? '')), 0, 64),
            'title' => mb_substr(trim((string) ($args['title'] ?? '')), 0, 120),
            'channels' => implode(',', $handles),
            'defaultChannel' => mb_substr((string) ($args['defaultChannel'] ?? ''), 0, 32),
            'count' => max(5, min(200, (int) ($args['count'] ?? 30))),
            'hint' => mb_substr(trim((string) ($args['hint'] ?? '')), 0, 255),
        ]);
    }

    public function view()
    {
        $user = $this->app->make(User::class);
        $access = $this->app->make(Access::class);
        $settings = $this->app->make(Settings::class);
        $page = Page::getCurrentPage();
        $this->set('editMode', $page && !$page->isError() && $page->isEditMode());
        if (!$access->mayRead($user)) {
            $this->set('denied', true);

            return;
        }
        $this->set('denied', false);
        $frontend = $this->app->make(Frontend::class);
        $selected = $this->selectedChannels();
        $channels = array_values(array_filter($frontend->channels(), static fn ($channel) => $selected === [] || in_array($channel['handle'], $selected, true)));
        $default = $this->defaultChannel && in_array($this->defaultChannel, array_column($channels, 'handle'), true) ? $this->defaultChannel : ($channels[0]['handle'] ?? $settings->getDefaultChannel());
        $labels = Settings::labels();
        $config = $frontend->config($user);
        $boot = $frontend->boot($user, (int) $this->count);
        $props = [
            'config' => $config,
            'boot' => $boot,
            'block' => [
                'bID' => (int) $this->bID,
                'eyebrow' => $this->eyebrow !== null && $this->eyebrow !== '' ? $this->eyebrow : $labels['eyebrow'],
                'title' => $this->title !== null && $this->title !== '' ? $this->title : $labels['title'],
                'channels' => $channels,
                'defaultChannel' => $default,
                'count' => (int) $this->count,
                'hint' => (string) $this->hint,
            ],
        ];
        $this->set('props', $props);
        $this->set('labels', $labels);
        if (!$frontend->usesThemeUi()) {
            $this->requireAsset('css', 'font-awesome');
            $this->requireAsset('javascript', 'shoutbox/ui');
            $this->requireAsset('css', 'shoutbox/ui');
        }
    }

    /**
     * @return string[]
     */
    protected function selectedChannels(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->channels))));
    }

    protected function setFormData(): void
    {
        $this->set('allChannels', $this->app->make(Settings::class)->getChannels());
    }
}
