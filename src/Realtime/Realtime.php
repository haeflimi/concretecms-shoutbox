<?php

namespace Shoutbox\Realtime;

use Concrete\Core\Application\Application;
use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\Notification\Events\MercureService;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder as TokenBuilder;
use Shoutbox\Event\ShoutboxEvent;
use Shoutbox\Event\ShoutboxTopic;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The core's Mercure integration, seen from the shoutbox: publishing on the shoutbox topic and giving browsers the
 * right to subscribe. Same-origin hub: the core's subscriber cookie. Hub on another origin (the dev site talking to
 * the live hub): the subscriber JWT travels as the "authorization" query parameter of the EventSource instead.
 */
class Realtime
{
    public const AUTH_COOKIE = 'cookie';
    public const AUTH_QUERY = 'query';

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
        return $this->service()->isEnabled() && $this->hubUrl() !== '';
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
     * Cookie when the hub shares the site's origin, else the query parameter.
     */
    public function authMode(): string
    {
        $hub = parse_url($this->hubUrl());
        $site = parse_url((string) $this->app->make('site')->getSite()->getSiteCanonicalURL());
        $origin = static fn (array $u): string => strtolower(($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '') . ':' . ($u['port'] ?? (($u['scheme'] ?? 'http') === 'https' ? 443 : 80)));
        if (!is_array($hub) || !is_array($site) || $origin($hub) === $origin($site)) {
            return self::AUTH_COOKIE;
        }
        // a RSA key pair is only wired up for the cookie in the core; no query token for it
        return $this->config->get('concrete.notification.mercure.default.connection_method') === 'rsa_dual' ? self::AUTH_COOKIE : self::AUTH_QUERY;
    }

    public function publish(ShoutboxEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $this->service()->publish($event);
        } catch (\Throwable $e) {
            $this->app->make(LoggerFactory::class)->createLogger('shoutbox')->notice(t('Publishing to the Mercure hub failed: %s', $e->getMessage()));
        }
    }

    /**
     * What the browser needs to subscribe; sets the cookie or mints the token on the way.
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
        if ($mode === self::AUTH_QUERY) {
            $jwt = $this->subscriberJwt();
            if ($jwt === null) {
                return ['enabled' => false];
            }
            $sse['jwt'] = $jwt;
        } else {
            $this->refreshCookie();
        }

        return $sse;
    }

    public function refreshCookie(): void
    {
        $subscriber = $this->service()->getSubscriber();
        $subscriber->addTopic($this->topic());
        $subscriber->refreshAuthorizationCookie();
    }

    /**
     * A subscriber token for the shoutbox topic, signed like the core's cookie (single secret key).
     */
    public function subscriberJwt(): ?string
    {
        $key = (string) $this->app->make('config/database')->get('concrete.notification.mercure.default.jwt_key');
        if ($key === '') {
            return null;
        }
        $builder = new TokenBuilder(new JoseEncoder(), ChainedFormatter::default());

        return $builder
            ->withClaim('mercure', ['subscribe' => [$this->topic()]])
            ->expiresAt($this->expiresAt())
            ->getToken(new Sha256(), InMemory::plainText($key))
            ->toString();
    }

    public function expiresAt(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) ($this->config->get('concrete.notification.mercure.jwt.subscriber.expires_at') ?: '+30 minutes'));
    }

    protected function service(): MercureService
    {
        return $this->app->make(MercureService::class);
    }
}
