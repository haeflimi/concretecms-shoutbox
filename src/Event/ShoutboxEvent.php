<?php

namespace Shoutbox\Event;

use Concrete\Core\Notification\Events\ServerEvent\AbstractEvent;
use Concrete\Core\Notification\Events\Topic\TopicInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What the hub sends to the browsers: a new shout or announcement, a hidden shout, an unpinned announcement or a
 * ban. Private: only subscribers with the cookie/token of the shoutbox topic receive it.
 */
class ShoutboxEvent extends AbstractEvent
{
    /** @var array<string, mixed> */
    protected $data;

    /**
     * @param array<string, mixed> $data
     */
    protected function __construct(string $type, array $data)
    {
        $this->data = ['type' => $type] + $data;
    }

    /**
     * @param array<string, mixed> $message ShoutService::render()
     */
    public static function message(array $message): self
    {
        return new self('message', ['message' => $message]);
    }

    public static function hidden(int $id): self
    {
        return new self('hidden', ['id' => $id]);
    }

    public static function unpinned(int $id): self
    {
        return new self('unpinned', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $ban Bans::active() shape
     */
    public static function banned(int $uID, array $ban): self
    {
        return new self('banned', ['uID' => $uID, 'ban' => $ban]);
    }

    protected function isPrivate()
    {
        return true;
    }

    protected function getEventData()
    {
        return $this->data;
    }

    public function createTopic(): TopicInterface
    {
        return new ShoutboxTopic();
    }

    /**
     * The topic as a string: the Symfony Mercure client gets exactly what the browser subscribes to.
     */
    public function getTopics(): array
    {
        return [(string) $this->createTopic()];
    }
}
