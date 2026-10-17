<?php

namespace Shoutbox\Command;

use Concrete\Core\Foundation\Command\Command;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Delete old shouts and expired bans, see Shoutbox\Shout\ShoutService::purge().
 */
class PurgeCommand extends Command
{
}
