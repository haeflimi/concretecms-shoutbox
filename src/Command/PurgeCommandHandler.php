<?php

namespace Shoutbox\Command;

use Concrete\Core\Command\Task\Output\OutputAwareInterface;
use Concrete\Core\Command\Task\Output\OutputAwareTrait;
use Shoutbox\Shout\Bans;
use Shoutbox\Shout\Settings;
use Shoutbox\Shout\ShoutService;

defined('C5_EXECUTE') or die('Access Denied.');

class PurgeCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    /** @var ShoutService */
    protected $shouts;

    /** @var Bans */
    protected $bans;

    /** @var Settings */
    protected $settings;

    public function __construct(ShoutService $shouts, Bans $bans, Settings $settings)
    {
        $this->shouts = $shouts;
        $this->bans = $bans;
        $this->settings = $settings;
    }

    public function __invoke(PurgeCommand $command)
    {
        $days = $this->settings->getRetentionDays();
        $this->output->write(t('Deleted %1$s shouts older than %2$s days.', $this->shouts->purge($days), $days));
        $this->output->write(t('Deleted %s expired bans.', $this->bans->purgeExpired()));
    }
}
