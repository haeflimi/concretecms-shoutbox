# Shoutbox for Concrete CMS

One chat room for the signed-in members of a community site. Members *shout* short messages into channels
(General, Catering, Tournaments, …), moderators pin announcements to the top of a channel, abuse is handled with
timed bans — by hand or automatically when somebody shouts too fast — and a big-screen template shows the stream
read-only in the hall. Everything updates live through [Mercure](https://mercure.rocks), using the Mercure
integration that ships with Concrete CMS 9.5; without a hub the shoutbox polls instead.

Requires Concrete CMS 9.5 and PHP 8.1.

## Using the shoutbox

**The floating panel** appears on every page that has the page attribute **Shoutbox** (`has_shoutbox`) ticked —
the pages of a running LAN, the home page during the event, whatever you like. A button in the bottom right corner
opens the panel; it shows the unread count while closed, and pinned announcements pop up as a small toast
(members can mute those). The panel is the compact feed described below.

**The block "Shoutbox"** places the feed on a page. Its templates:

* **Default** — the full card: channel chips, pinned announcements, the shouts with avatar, nick, seat, channel and
  time, and the composer.
* **Compact** — the same for a sidebar: smaller rows, the list scrolls after a few shouts.
* **Display** — for a big screen: the announcements as large cards above a three-column grid of the newest shouts,
  no input, never a toast. The block option "Hint on the big screen" prints "Write with us: …" next to the title.

Block options: eyebrow and title (defaults "Shoutbox" / "From the hall"), the channels shown (none ticked = all),
the default channel for new shouts, the number of shouts and the big-screen hint.

Shouts are plain text (links are made clickable, HTML is stripped), at most 200 characters by default. Everyone who
may shout picks a channel by filtering the feed: the shout goes to the filtered channel, or to the default channel
when "All" is active. Moderators switch the composer to **Pinned announcement**: channel, title, text and how long
it stays pinned (5, 15, 60 minutes or until unpinned). They also hide shouts and ban members right from the feed.

When the [Community Event Manager](https://github.com/haeflimi/concretecms-community_event_manager) is installed
and an event is running, each shout shows the member's seat.

## Settings (Dashboard › Shoutbox)

* **Access** — the shoutbox on/off; which groups may read, which may shout, which moderate. Administrators always
  read and moderate; guests never shout. The first moderator group a member belongs to becomes the sender of their
  announcements ("Catering", "Tournament desk").
* **Channels** — name, handle, colour and icon of each channel. Handles never change once shouts exist; rename
  instead of re-creating. Default channels: General, Catering, Tournaments, Tech.
* **Limits** — characters per shout, shouts loaded, the rate limit (default: more than 3 shouts within 10 seconds →
  a 2-minute ban; moderators are exempt), the pin durations, the toast and polling intervals.
* **Retention** — how long shouts are kept. The task **Purge Shoutbox** (Dashboard › System › Automation, console
  `concrete:task:run shoutbox-purge`) deletes older shouts and expired bans; schedule it with the other tasks.
* **Real-time** — shows whether the core's Mercure integration is on and how browsers authenticate.
* **Bans** — the active bans with their reason and who imposed them, a button to lift each, and a form to ban a
  member by username or email.

Values are stored in the database config (`shoutbox.*`); `application/config/shoutbox.php` can preset them.

## Real-time with Mercure

Concrete CMS 9.5 ships the Mercure client. Set it up once under **Dashboard › System › Notification › Events**:
enable server-sent events, enter the hub's publish URL and the single secret key the hub was started with (the site
needs a canonical URL). The shoutbox then publishes every shout, announcement, hide, unpin and ban to the private
topic `<canonical URL>/shoutbox`, and the browsers subscribe to it:

* hub on the **same origin** as the site (the usual `/.well-known/mercure` behind the web server): the core's
  `mercureAuthorization` cookie authenticates the EventSource;
* hub on **another origin** (a development site using the production hub, a hub on its own hostname): the
  subscriber token travels as the `authorization` query parameter instead. The hub must then allow the site's
  origin in `cors_origins`. Only the single-secret-key method is supported in this mode.

Tokens run out after 30 minutes (core setting `concrete.notification.mercure.jwt.subscriber.expires_at`); the
browser renews them in time and fills any gap after a reconnect. Without Mercure the feed polls every 15 seconds.

### Server setup

The hub is one static binary. A minimal production setup on a Linux host:

```
# /etc/mercure/Caddyfile
{ auto_https off }
:3000 {
    mercure {
        publisher_jwt {env.MERCURE_JWT_KEY}
        subscriber_jwt {env.MERCURE_JWT_KEY}
        cors_origins https://www.example.ch
    }
    respond /healthz 200
    respond "Not Found" 404
}
```

```
# /etc/systemd/system/mercure.service
[Unit]
Description=Mercure hub
After=network.target

[Service]
User=mercure
EnvironmentFile=/etc/mercure/mercure.env     # MERCURE_JWT_KEY=…  XDG_DATA_HOME=/var/lib/mercure
ExecStart=/usr/local/bin/mercure run --config /etc/mercure/Caddyfile
WorkingDirectory=/var/lib/mercure
Restart=always

[Install]
WantedBy=multi-user.target
```

```
# nginx: the hub under the site's origin
location /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Connection '';
    proxy_set_header Host $host;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 24h;
}
```

Then, in the dashboard: publish URL `https://www.example.ch/.well-known/mercure`, single secret key = the same
`MERCURE_JWT_KEY`, cookie domain empty.

**Using one hub for several sites** (a development site next to production): give every site the same publish
URL and key; the topics differ because they carry each site's canonical URL. Add every site origin to the hub's
`cors_origins`.

## Theme integration

The package ships a complete UI in plain JavaScript with Bootstrap 5 markup (`js/shoutbox.js`, `css/shoutbox.css`),
so the shoutbox works with any theme. A theme that wants to render the shoutbox itself claims the UI in its package
controller:

```php
public function on_start()
{
    if (class_exists(\Shoutbox\Shout\Frontend::class)) {
        $this->app->instance(\Shoutbox\Shout\Frontend::THEME_BINDING, 'my_theme');
    }
}
```

The package then prints only `window.ShoutboxConfig` (endpoints, token, texts, limits, channels, rights) on the
pages with the attribute and loads none of its assets; the theme mounts its own panel from that object and
overrides the block views (`blocks/shoutbox/view.php`, `templates/compact/view.php`, `templates/display/view.php`),
which receive `$props` with `config`, `boot` (the feed) and `block` (the options). The JSON API:

| Call | Who | Fields |
|---|---|---|
| `GET /ccm/shoutbox/boot?since=<id>&limit=<n>` | readers | the member, rights, channels, shouts, pins, `lastId`, `sse` |
| `GET /ccm/shoutbox/subscribe` | readers | a fresh cookie / token |
| `POST /ccm/shoutbox/shout` | shouters | `body`, `channel` |
| `POST /ccm/shoutbox/announce` | moderators | `channel`, `title`, `body`, `minutes` (0 = until unpinned) |
| `POST /ccm/shoutbox/unpin`, `hide` | moderators | `id` |
| `POST /ccm/shoutbox/ban` | moderators | `uID`, `minutes`, `reason` |
| `POST /ccm/shoutbox/unban` | moderators | `id` |

Writes carry the CSRF token as `ccm_token`. Answers are `{ok: true, …}` or `{ok: false, error}` with the HTTP status
(400 token, 401 guest, 403 rights, 404, 422 rule — a refused shout adds `ban`). Live events on the topic:
`{type: "message", message}` (shout or pin), `{type: "hidden", id}`, `{type: "unpinned", id}`,
`{type: "banned", uID, ban}`.

The [Turicane Blackout theme](https://github.com/haeflimi/concretecms-theme_blackout) does exactly this with Vue
components.

## Security

* Reading, shouting and moderating are group rules checked on the server for every call; the page attribute only
  decides where the panel shows.
* Writes need Concrete's CSRF token; moderators cannot be banned; a banned member can read but not shout.
* Shout bodies are stored as plain text and escaped on output; links are only made clickable in the browser.
* The Mercure topic is private: the hub only serves it to browsers with a token the site signed for it.

## Languages

English, German (`de_DE`, `de_CH`). Strings are extracted from the PHP files (`xgettext --keyword=t
--keyword=t2:1,2 --keyword=tc:1c,2`), the browser texts come from `Settings::labels()`.

## Upgrading

* **0.1.0** — first version: feed, channels, pinned announcements, bans with the automatic rate-limit ban, the block
  with three templates, the floating panel on pages with the "Shoutbox" attribute, Mercure in cookie and query-token
  mode, polling fallback, purge task, built-in Bootstrap 5 UI with the theme hand-over.
