<?php

namespace Shoutbox\Realtime;

use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Publisher token for the core's Hub: a fresh hub 1.x token per publish, so a long-running worker (messenger
 * consumer) never sends an expired one.
 */
class CoreTokenProvider implements TokenProviderInterface
{
    /** @var Realtime */
    protected $realtime;

    public function __construct(Realtime $realtime)
    {
        $this->realtime = $realtime;
    }

    public function getJwt(): string
    {
        return $this->realtime->corePublisherJwt();
    }
}
