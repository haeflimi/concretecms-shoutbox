<?php

namespace Shoutbox\Shout;

use Concrete\Core\Error\UserMessageException;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * A shout was refused because of a ban (manual or just imposed automatically); carries the ban for the browser.
 */
class BannedException extends UserMessageException
{
    /** @var array<string, mixed> */
    protected $ban;

    /**
     * @param array<string, mixed> $ban Bans::active() shape
     */
    public function __construct(string $message, array $ban)
    {
        parent::__construct($message);
        $this->ban = $ban;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBan(): array
    {
        return $this->ban;
    }
}
