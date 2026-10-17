<?php

namespace Shoutbox\Controller;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\User;
use Shoutbox\Realtime\Realtime;
use Shoutbox\Shout\Access;
use Shoutbox\Shout\BannedException;
use Shoutbox\Shout\Bans;
use Shoutbox\Shout\Frontend;
use Shoutbox\Shout\ShoutService;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * JSON endpoints of the shoutbox (/ccm/shoutbox/…). Reads need the right to read, writes the "shoutbox" token
 * (POST field ccm_token) and the right to shout or moderate. Answers are {ok: bool, …}; errors carry "error".
 */
class Api extends Controller
{
    /** GET: the feed, the member and the real-time access; ?since=<id> for only the newer rows */
    public function boot(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->readable($user)) {
            return $error;
        }
        $query = $this->request->query;

        return $this->ok($this->frontend()->boot($user, (int) $query->get('limit', 0), (int) $query->get('since', 0)));
    }

    /** GET: a fresh subscriber cookie / token */
    public function subscribe(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->readable($user)) {
            return $error;
        }

        return $this->ok(['sse' => $this->app->make(Realtime::class)->describe()]);
    }

    public function shout(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->writable($user, false)) {
            return $error;
        }
        $post = $this->request->request;

        return $this->attempt(fn () => ['message' => $this->shouts()->shout($user, (string) $post->get('body', ''), (string) $post->get('channel', ''))]);
    }

    public function announce(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->writable($user, true)) {
            return $error;
        }
        $post = $this->request->request;

        return $this->attempt(fn () => ['message' => $this->shouts()->announce(
            $user,
            (string) $post->get('channel', ''),
            (string) $post->get('title', ''),
            (string) $post->get('body', ''),
            (int) $post->get('minutes', 0)
        )]);
    }

    public function unpin(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->writable($user, true)) {
            return $error;
        }
        if (!$this->shouts()->unpin((int) $this->request->request->get('id'))) {
            return $this->fail(t('Announcement not found.'), 404);
        }

        return $this->ok();
    }

    public function hide(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->writable($user, true)) {
            return $error;
        }
        if (!$this->shouts()->hide((int) $this->request->request->get('id'), (int) $user->getUserID())) {
            return $this->fail(t('Shout not found.'), 404);
        }

        return $this->ok();
    }

    public function ban(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->writable($user, true)) {
            return $error;
        }
        $post = $this->request->request;
        $reason = $post->get('reason');

        return $this->attempt(fn () => ['ban' => $this->shouts()->ban($user, (int) $post->get('uID'), (int) $post->get('minutes', 10), $reason !== null ? (string) $reason : null)]);
    }

    public function unban(): JsonResponse
    {
        $user = $this->user();
        if ($error = $this->writable($user, true)) {
            return $error;
        }
        if (!$this->app->make(Bans::class)->unban((int) $this->request->request->get('id'))) {
            return $this->fail(t('Ban not found.'), 404);
        }

        return $this->ok();
    }

    protected function readable(User $user): ?JsonResponse
    {
        if ($this->app->make(Access::class)->mayRead($user)) {
            return null;
        }

        return $user->isRegistered() ? $this->fail(t('You may not read the shoutbox.'), 403) : $this->fail(t('Please sign in first.'), 401);
    }

    protected function writable(User $user, bool $moderation): ?JsonResponse
    {
        if (!$this->app->make('token')->validate(Frontend::TOKEN, (string) $this->request->request->get('ccm_token'))) {
            return $this->fail(t('Your session expired. Please reload the page.'), 400);
        }
        if (!$user->isRegistered()) {
            return $this->fail(t('Please sign in first.'), 401);
        }
        $access = $this->app->make(Access::class);
        if ($moderation ? !$access->canModerate($user) : !$access->mayShout($user)) {
            return $this->fail($moderation ? t('Only moderators can do that.') : t('You may not shout here.'), 403);
        }

        return null;
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    protected function attempt(callable $action): JsonResponse
    {
        try {
            return $this->ok($action());
        } catch (BannedException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage(), 'ban' => $e->getBan()], 422);
        } catch (UserMessageException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function ok(array $data = []): JsonResponse
    {
        return new JsonResponse(['ok' => true] + $data);
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }

    protected function user(): User
    {
        return $this->app->make(User::class);
    }

    protected function frontend(): Frontend
    {
        return $this->app->make(Frontend::class);
    }

    protected function shouts(): ShoutService
    {
        return $this->app->make(ShoutService::class);
    }
}
