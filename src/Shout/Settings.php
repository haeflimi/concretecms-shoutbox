<?php

namespace Shoutbox\Shout;

use Concrete\Core\Application\Application;
use Concrete\Core\Config\Repository\Repository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Site settings of the shoutbox (Dashboard › Shoutbox), stored in the database config with the file config
 * application/config/shoutbox.php as fallback, and the texts the browser side shows.
 */
class Settings
{
    public const SHOUT = 'shout';
    public const PIN = 'pin';

    /** colour keys a channel can take: Bootstrap context names, the theme maps them to its own hues */
    public const COLORS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info'];

    /** icon keys a channel can take: Font Awesome class for the built-in UI, Lucide name for a theme */
    public const ICONS = [
        'users' => ['fa fa-users', 'users'],
        'pizza' => ['fa fa-pizza-slice', 'pizza'],
        'utensils' => ['fa fa-utensils', 'utensils'],
        'beer' => ['fa fa-beer', 'beer'],
        'coffee' => ['fa fa-coffee', 'coffee'],
        'trophy' => ['fa fa-trophy', 'trophy'],
        'gamepad' => ['fa fa-gamepad', 'gamepad-2'],
        'network' => ['fa fa-network-wired', 'network'],
        'wifi' => ['fa fa-wifi', 'wifi'],
        'monitor' => ['fa fa-desktop', 'monitor'],
        'zap' => ['fa fa-bolt', 'zap'],
        'star' => ['fa fa-star', 'star'],
        'tag' => ['fa fa-tag', 'tag'],
        'ticket' => ['fa fa-ticket-alt', 'ticket'],
        'gift' => ['fa fa-gift', 'gift'],
        'shield' => ['fa fa-shield-alt', 'shield-check'],
        'radio' => ['fa fa-broadcast-tower', 'radio'],
        'info' => ['fa fa-info-circle', 'info'],
        'alert' => ['fa fa-exclamation-triangle', 'triangle-alert'],
    ];

    /** @var Repository */
    protected $config;

    /** @var Repository */
    protected $store;

    public function __construct(Repository $config, Application $app)
    {
        $this->config = $config;
        $this->store = $app->make('config/database');
    }

    /**
     * Saved value (dashboard), else the file config (application/config/shoutbox.php), else the default.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    protected function value(string $key, $default)
    {
        $saved = $this->store->get('shoutbox.' . $key);

        return $saved !== null ? $saved : $this->config->get('shoutbox.' . $key, $default);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->value('enabled', true);
    }

    /** @return int[] */
    public function getReadGroupIDs(): array
    {
        return $this->ids($this->value('read_groups', (string) REGISTERED_GROUP_ID));
    }

    /** @return int[] */
    public function getShoutGroupIDs(): array
    {
        return $this->ids($this->value('shout_groups', (string) REGISTERED_GROUP_ID));
    }

    /** @return int[] administrators always moderate, these groups too */
    public function getModeratorGroupIDs(): array
    {
        return $this->ids($this->value('moderator_groups', ''));
    }

    /**
     * @return array<int, array{handle: string, name: string, color: string, icon: string}>
     */
    public function getChannels(): array
    {
        $channels = [];
        foreach ((array) $this->value('channels', self::defaultChannels()) as $channel) {
            $channel = self::normalizeChannel((array) $channel);
            if ($channel !== null) {
                $channels[$channel['handle']] = $channel;
            }
        }

        return array_values($channels);
    }

    /**
     * @return array{handle: string, name: string, color: string, icon: string}|null
     */
    public function getChannel(string $handle): ?array
    {
        foreach ($this->getChannels() as $channel) {
            if ($channel['handle'] === $handle) {
                return $channel;
            }
        }

        return null;
    }

    public function getDefaultChannel(): string
    {
        $channels = $this->getChannels();
        $wanted = (string) $this->value('default_channel', 'allgemein');
        foreach ($channels as $channel) {
            if ($channel['handle'] === $wanted) {
                return $wanted;
            }
        }

        return $channels[0]['handle'] ?? '';
    }

    public function getMaxLength(): int
    {
        return max(20, min(1000, (int) $this->value('max_length', 200)));
    }

    public function getHistory(): int
    {
        return max(10, min(500, (int) $this->value('history', 100)));
    }

    public function getRateCount(): int
    {
        return max(1, (int) $this->value('rate_count', 3));
    }

    /** seconds */
    public function getRateWindow(): int
    {
        return max(1, (int) $this->value('rate_window', 10));
    }

    /** seconds */
    public function getRateBan(): int
    {
        return max(10, (int) $this->value('rate_ban', 120));
    }

    public function getRetentionDays(): int
    {
        return max(1, (int) $this->value('retention_days', 30));
    }

    public function getToastSeconds(): int
    {
        return max(2, (int) $this->value('toast_seconds', 8));
    }

    public function getPollSeconds(): int
    {
        return max(5, (int) $this->value('poll_seconds', 15));
    }

    /** @return int[] minutes a pin stays, 0 = until it is unpinned */
    public function getPinDurations(): array
    {
        $durations = array_values(array_unique(array_map('intval', (array) $this->value('pin_durations', [5, 15, 60, 0]))));
        sort($durations);

        return $durations ?: [0];
    }

    /**
     * @param array<string, mixed> $values keys as in the dashboard form
     */
    public function save(array $values): void
    {
        $this->store->save('shoutbox.enabled', !empty($values['enabled']));
        foreach (['read_groups', 'shout_groups', 'moderator_groups'] as $key) {
            $this->store->save('shoutbox.' . $key, implode(',', array_filter(array_map('intval', (array) ($values[$key] ?? [])))));
        }
        $channels = [];
        foreach ((array) ($values['channels'] ?? []) as $channel) {
            $channel = self::normalizeChannel((array) $channel);
            if ($channel !== null) {
                $channels[$channel['handle']] = $channel;
            }
        }
        $this->store->save('shoutbox.channels', array_values($channels) ?: self::defaultChannels());
        $this->store->save('shoutbox.default_channel', (string) ($values['default_channel'] ?? ''));
        foreach (['max_length', 'history', 'rate_count', 'rate_window', 'rate_ban', 'retention_days', 'toast_seconds', 'poll_seconds'] as $key) {
            if (isset($values[$key])) {
                $this->store->save('shoutbox.' . $key, (int) $values[$key]);
            }
        }
        if (isset($values['pin_durations'])) {
            $durations = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $values['pin_durations'])), static fn ($m) => $m >= 0 && $m <= 10080);
            $this->store->save('shoutbox.pin_durations', array_values(array_unique($durations)) ?: [0]);
        }
    }

    /**
     * @return array<int, array{handle: string, name: string, color: string, icon: string}>
     */
    public static function defaultChannels(): array
    {
        return [
            ['handle' => 'allgemein', 'name' => t('General'), 'color' => 'primary', 'icon' => 'users'],
            ['handle' => 'catering', 'name' => t('Catering'), 'color' => 'warning', 'icon' => 'pizza'],
            ['handle' => 'turniere', 'name' => t('Tournaments'), 'color' => 'info', 'icon' => 'trophy'],
            ['handle' => 'technik', 'name' => t('Tech'), 'color' => 'success', 'icon' => 'network'],
        ];
    }

    /**
     * @return array{handle: string, name: string, color: string, icon: string}|null
     */
    public static function normalizeChannel(array $channel): ?array
    {
        $name = trim((string) ($channel['name'] ?? ''));
        $handle = self::handle((string) ($channel['handle'] ?? '') ?: $name);
        if ($name === '' || $handle === '') {
            return null;
        }
        $color = (string) ($channel['color'] ?? '');
        $icon = (string) ($channel['icon'] ?? '');

        return [
            'handle' => $handle,
            'name' => mb_substr($name, 0, 40),
            'color' => in_array($color, self::COLORS, true) ? $color : 'primary',
            'icon' => isset(self::ICONS[$icon]) ? $icon : 'users',
        ];
    }

    public static function handle(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? '';

        return mb_substr(trim($text, '_'), 0, 32);
    }

    /**
     * Texts of the browser side (built-in UI and themes), in the page's language.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'eyebrow' => t('Shoutbox'),
            'title' => t('From the hall'),
            'open' => t('Open the shoutbox'),
            'close' => t('Close'),
            'shouts' => t('%s shouts'),
            'channel' => t('Channel'),
            'all' => t('All'),
            'shoutIn' => t('Shout in %s'),
            'chars' => t('%1$s / %2$s characters'),
            'send' => t('Send'),
            'pin' => t('Pin'),
            'shout' => t('Shout'),
            'announcement' => t('Pinned announcement'),
            'titleField' => t('Title'),
            'text' => t('Text'),
            'pinHint' => t('Shows at the top of the channel until you unpin it.'),
            'pinFor' => t('Pinned for'),
            'untilUnpinned' => t('until unpinned'),
            'minutes' => t('%s minutes'),
            'pinnedBy' => t('Pinned · %1$s · %2$s'),
            'unpin' => t('Unpin'),
            'hide' => t('Hide'),
            'ban' => t('Ban'),
            'banFor' => t('Ban %s for how many minutes?'),
            'banReason' => t('Reason (optional)'),
            'emptyAll' => t('No shouts yet. Write the first one.'),
            'emptyChannel' => t('It is still quiet in %s.'),
            'loginPrompt' => t('Sign in to join the conversation.'),
            'login' => t('Sign in'),
            'live' => t('Live from the hall'),
            'writeWith' => t('Write with us:'),
            'banned' => t('You are banned until %s.'),
            'tooFast' => t('Too fast: take a %s minute break.'),
            'mute' => t('Mute announcements'),
            'unmute' => t('Show announcements'),
            'newShouts' => t('New shouts'),
            'loading' => t('Loading…'),
            'offline' => t('Offline, trying again…'),
            'confirmHide' => t('Hide this shout for everybody?'),
            'confirmUnpin' => t('Unpin this announcement?'),
            'seat' => t('Seat %s'),
            'you' => t('you'),
            'today' => t('today'),
        ];
    }

    /** @return int[] */
    protected function ids($csv): array
    {
        return array_values(array_filter(array_map('intval', is_array($csv) ? $csv : explode(',', (string) $csv))));
    }
}
