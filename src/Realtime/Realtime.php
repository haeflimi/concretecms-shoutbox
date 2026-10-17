<?php

namespace Shoutbox\Realtime;

use Concrete\Core\Application\Application;
use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Cookie\ResponseCookieJar;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\Notification\Events\MercureService;
use DateTimeImmutable;
use Shoutbox\Event\ShoutboxEvent;
use Shoutbox\Event\ShoutboxTopic;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Mercure for the shoutbox: publishing on the shoutbox topic and giving browsers the right to subscribe.
 *
 * The core's switch and publish URL (/dashboard/system/notification/events) decide whether it is on; the tokens are
 * our own, because the hub (Mercure 1.x) wants RFC 9068 access tokens with "authorization_details" and separate
 * publisher/subscriber keys (application/config/mercure.php), which the core's legacy "mercure" claim cannot do.
 * Same-origin hub: the subscriber token goes into the hub's cookie. Hub on another origin (the dev site talking to
 * the live hub): the browser sends it as "Authorization" header (the hub takes no token in the address).
 */
class Realtime
{
    public const AUTH_COOKIE = 'cookie';
    public const AUTH_HEADER = 'header';

    /** the cookie name hub 1.x reads */
    public const COOKIE_NAME = '__Secure-mercure_access_token';

    /** @var Application */
    protected $app;

    /** @var Repository */
    protected $config;

    public function __construct(Application $app, Repository $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    public function isEnabled(): bool
    {
        return $this->service()->isEnabled() && $this->hubUrl() !== '' && $this->key('publisher') !== '' && $this->key('subscriber') !== '';
    }

    /** the hub URL the core publishes to; browsers use the same one (as the core's own scripts do) */
    public function hubUrl(): string
    {
        return trim((string) $this->service()->getPublisherUrl());
    }

    public function topic(): string
    {
        return (string) new ShoutboxTopic();
    }

    /**
     * Cookie when the hub shares the site's origin, else the header.
     */
    public function authMode(): string
    {
        $hub = parse_url($this->hubUrl());
        $site = parse_url((string) $this->app->make('site')->getSite()->getSiteCanonicalURL());
        $origin = static fn (array $u): string => strtolower(($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '') . ':' . ($u['port'] ?? (($u['scheme'] ?? 'http') === 'https' ? 443 : 80)));
        if (!is_array($hub) || !is_array($site) || $origin($hub) === $origin($site)) {
            return self::AUTH_COOKIE;
        }

        return self::AUTH_HEADER;
    }

    public function publish(ShoutboxEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $jwt = $this->token('publisher', ['publish'], '+5 minutes');
            (new Hub($this->hubUrl(), new StaticTokenProvider($jwt)))->publish($event->getUpdate());
        } catch (\Throwable $e) {
            $this->app->make(LoggerFactory::class)->createLogger('shoutbox')->notice(t('Publishing to the Mercure hub failed: %s', $e->getMessage()));
        }
    }

    /**
     * What the browser needs to subscribe; sets the cookie or hands out the token on the way.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        if (!$this->isEnabled()) {
            return ['enabled' => false];
        }
        $mode = $this->authMode();
        $sse = ['enabled' => true, 'hubUrl' => $this->hubUrl(), 'topic' => $this->topic(), 'auth' => $mode, 'expiresAt' => $this->expiresAt()->format(DATE_ATOM)];
        if ($mode === self::AUTH_HEADER) {
            $sse['jwt'] = $this->subscriberJwt();
        } else {
            $this->refreshCookie();
        }

        return $sse;
    }

    public function refreshCookie(): void
    {
        $path = (string) (parse_url($this->hubUrl(), PHP_URL_PATH) ?: '/');
        $this->app->make(ResponseCookieJar::class)->addCookie(self::COOKIE_NAME, $this->subscriberJwt(), 0, $path, null, true, true, false, 'Lax');
    }

    public function subscriberJwt(): string
    {
        return $this->token('subscriber', ['subscribe'], (string) ($this->config->get('concrete.notification.mercure.jwt.subscriber.expires_at') ?: '+30 minutes'));
    }

    public function expiresAt(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) ($this->config->get('concrete.notification.mercure.jwt.subscriber.expires_at') ?: '+30 minutes'));
    }

    /**
     * A Mercure 1.x access token (RFC 9068, HS256) for the shoutbox topic.
     *
     * @param string[] $actions
     */
    protected function token(string $which, array $actions, string $expires): string
    {
        $now = time();
        $claims = [
            'iss' => (string) $this->config->get('mercure.hub.issuer'),
            'aud' => (string) ($this->config->get('mercure.hub.audience') ?: $this->hubUrl()),
            'sub' => 'shoutbox',
            'client_id' => 'shoutbox',
            'iat' => $now,
            'exp' => (new DateTimeImmutable($expires))->getTimestamp(),
            'jti' => bin2hex(random_bytes(12)),
            'authorization_details' => [[
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => $actions,
                'topics' => [['match' => $this->topic()]],
            ]],
        ];
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64(json_encode(['alg' => 'HS256', 'typ' => 'at+jwt'])) . '.' . $b64(json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $unsigned . '.' . $b64(hash_hmac('sha256', $unsigned, $this->key($which), true));
    }

    protected function key(string $which): string
    {
        return (string) $this->config->get('mercure.hub.' . $which . '_key');
    }

    protected function service(): MercureService
    {
        return $this->app->make(MercureService::class);
    }
}
