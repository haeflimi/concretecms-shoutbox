<?php

namespace Shoutbox\Event;

use Concrete\Core\Notification\Events\Topic\AbstractTopic;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The one Mercure topic of the shoutbox: <canonical URL>/shoutbox. Dev and live sites therefore never share it.
 */
class ShoutboxTopic extends AbstractTopic
{
    public function __construct()
    {
        parent::__construct('shoutbox');
    }
}
