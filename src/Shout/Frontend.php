<?php

namespace Shoutbox\Shout;

use Concrete\Core\Application\Application;
use Concrete\Core\User\User;
use Shoutbox\Realtime\Realtime;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What the browser side gets: the page configuration (window.ShoutboxConfig) and the boot payload with the shouts,
 * the pins, the member and the real-time access. Used by the API, the page listener and the block.
 */
class Frontend
{
    public const TOKEN = 'shoutbox';

    /** container binding a theme sets to render the UI itself (see README, "Theme integration") */
    public const THEME_BINDING = 'shoutbox.ui';

    /** @var Application */
    protected $app;

    /** @var Settings */
    protected $settings;

    /** @var Access */
    protected $access;

    /** @var ShoutService */
    protected $shouts;

    /** @var Bans */
    protected $bans;

    /** @var Realtime */
    protected $realtime;

    public function __construct(Application $app, Settings $settings, Access $access, ShoutService $shouts, Bans $bans, Realtime $realtime)
    {
        $this->app = $app;
        $this->settings = $settings;
        $this->access = $access;
        $this->shouts = $shouts;
        $this->bans = $bans;
        $this->realtime = $realtime;
    }

    public function usesThemeUi(): bool
    {
        return $this->app->bound(self::THEME_BINDING);
    }

    /**
     * Static facts for a page: endpoints, token, texts, limits, rights. No messages (boot fetches them).
     *
     * @return array<string, mixed>
     */
    public function config(User $user): array
    {
        $urls = $this->app->make('url/manager');
        // path only, so the endpoints stay on the host the page was served from (session cookie)
        $endpoint = static fn (string $route): string => $urls->resolve([$route])->getPath()->getUriComponent();
        $endpoints = [];
        foreach (['boot', 'subscribe', 'shout', 'announce', 'unpin', 'hide', 'ban', 'unban'] as $name) {
            $endpoints[$name] = $endpoint('/ccm/shoutbox/' . $name);
        }

        return [
            'ui' => $this->usesThemeUi() ? 'theme' : 'builtin',
            'endpoints' => $endpoints,
            'loginUrl' => $endpoint('/login'),
            'token' => $this->app->make('token')->generate(self::TOKEN),
            'labels' => Settings::labels(),
            'maxLength' => $this->settings->getMaxLength(),
            'history' => $this->settings->getHistory(),
            'toastSeconds' => $this->settings->getToastSeconds(),
            'pollSeconds' => $this->settings->getPollSeconds(),
            'channels' => $this->channels(),
            'defaultChannel' => $this->settings->getDefaultChannel(),
            'pinDurations' => $this->settings->getPinDurations(),
            'canShout' => $this->access->mayShout($user),
            'canModerate' => $this->access->canModerate($user),
        ];
    }

    /**
     * The feed: shouts, pins, the member, rights and the real-time access (cookie set / token minted here).
     *
     * @return array<string, mixed>
     */
    public function boot(User $user, int $limit = 0, int $sinceId = 0): array
    {
        $limit = $limit > 0 ? $limit : $this->settings->getHistory();

        return [
            'me' => $this->me($user),
            'canShout' => $this->access->mayShout($user),
            'canModerate' => $this->access->canModerate($user),
            'channels' => $this->channels(),
            'defaultChannel' => $this->settings->getDefaultChannel(),
            'pinDurations' => $this->settings->getPinDurations(),
            'maxLength' => $this->settings->getMaxLength(),
            'shouts' => $this->shouts->recent($limit, $sinceId),
            'pins' => $this->shouts->pins($sinceId),
            'lastId' => $this->shouts->lastId(),
            'todayCount' => $this->shouts->todayCount(),
            'sse' => $this->realtime->describe(),
        ];
    }

    /**
     * @return array<string, mixed>|null null for guests
     */
    public function me(User $user): ?array
    {
        if (!$user->isRegistered()) {
            return null;
        }
        $uID = (int) $user->getUserID();

        return $this->shouts->person($uID) + ['seat' => $this->shouts->seatFor($uID), 'ban' => $this->bans->active($uID)];
    }

    /**
     * Channels with their icon classes/names resolved for the browser.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channels(): array
    {
        return array_map(static function (array $channel): array {
            $icons = Settings::ICONS[$channel['icon']] ?? Settings::ICONS['users'];

            return $channel + ['faIcon' => $icons[0], 'lucide' => $icons[1]];
        }, $this->settings->getChannels());
    }
}
