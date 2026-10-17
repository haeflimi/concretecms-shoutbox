/*
 * Shoutbox, built-in UI (Bootstrap 5 markup, no build step, no dependencies). Mounts:
 *  - the floating panel on pages with window.ShoutboxConfig (page attribute "Shoutbox"): launcher button bottom
 *    right with the unread badge, a slide-in panel with the feed, toasts for pinned announcements;
 *  - every block element [data-shoutbox="block"] (templates full / compact / display) from its JSON props.
 * All feeds on a page share one live connection (Mercure EventSource, or polling when Mercure is off).
 * A theme that renders the shoutbox itself sets config.ui = "theme"; then this file is not even loaded.
 */
(function () {
    'use strict';
    if (window.Shoutbox) {
        return;
    }

    // ---------- helpers ----------
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var linkify = function (s) {
        return esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
    };
    var fmt = function (label) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(label || '').replace(/%(\d+)\$s|%s/g, function (m, n) {
            return n ? String(args[Number(n) - 1] == null ? '' : args[Number(n) - 1]) : String(args[i++] == null ? '' : args[i - 1]);
        });
    };
    var timeOf = function (iso) {
        try {
            return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    };
    var store = {
        get: function (k) { try { return localStorage.getItem('shoutbox.' + k); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem('shoutbox.' + k, String(v)); } catch (e) { /* private mode */ } }
    };
    var el = function (html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    };
    // the channel filter chips: switched off for now (not needed yet), kept for later
    var SHOW_FILTER = false;
    var icon = function (channel) {
        return channel && channel.faIcon ? '<i class="' + esc(channel.faIcon) + '" aria-hidden="true"></i> ' : '';
    };

    // ---------- API ----------
    function Api(config) {
        this.config = config;
    }
    Api.prototype.get = function (name, params) {
        var url = this.config.endpoints[name], q = new URLSearchParams(params || {}).toString();
        return fetch(url + (q ? '?' + q : ''), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(handle);
    };
    Api.prototype.post = function (name, fields) {
        var body = new FormData();
        body.append('ccm_token', this.config.token);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k] == null ? '' : String(fields[k])); });
        return fetch(this.config.endpoints[name], { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(handle);
    };
    function handle(response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
            if (!response.ok || data.ok === false) {
                var err = new Error(data.error || ('HTTP ' + response.status));
                err.status = response.status;
                err.data = data;
                throw err;
            }
            return data;
        });
    }

    // ---------- EventSource with an Authorization header (hub on another origin, see Realtime) ----------
    function HeaderSource(url, jwt) {
        var self = this;
        this.readyState = 0;
        this.controller = new AbortController();
        fetch(url, { headers: { Authorization: 'Bearer ' + jwt, Accept: 'text/event-stream' }, signal: this.controller.signal, cache: 'no-store' }).then(function (response) {
            if (!response.ok || !response.body) {
                throw new Error('HTTP ' + response.status);
            }
            self.readyState = 1;
            if (self.onopen) { self.onopen(); }
            var reader = response.body.getReader(), decoder = new TextDecoder(), buffer = '', data = [];
            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        throw new Error('stream ended');
                    }
                    buffer += decoder.decode(chunk.value, { stream: true });
                    var lines = buffer.split(/\r\n|\r|\n/);
                    buffer = lines.pop();
                    lines.forEach(function (line) {
                        if (line === '') {
                            if (data.length && self.onmessage) { self.onmessage({ data: data.join('\n') }); }
                            data = [];
                        } else if (line.indexOf('data:') === 0) {
                            data.push(line.slice(5).replace(/^ /, ''));
                        }
                    });
                    return pump();
                });
            }
            return pump();
        }).catch(function () {
            if (self.readyState === 2) {
                return;
            }
            self.readyState = 2;
            if (self.onerror) { self.onerror(); }
        });
    }
    HeaderSource.prototype.close = function () {
        this.readyState = 2;
        this.controller.abort();
    };

    // ---------- one live connection per page ----------
    var live = {
        api: null, sse: null, source: null, listeners: [], timer: null, poller: null, dropped: false, pollSeconds: 15,
        start: function (api, sse, pollSeconds) {
            if (this.api) {
                return;
            }
            this.api = api;
            this.sse = sse;
            this.pollSeconds = pollSeconds || 15;
            if (sse && sse.enabled) {
                this.open();
                var self = this;
                this.timer = setInterval(function () { self.refresh(); }, 20 * 60 * 1000);
            } else {
                this.poll();
            }
        },
        on: function (fn) {
            this.listeners.push(fn);
        },
        emit: function (type, data) {
            this.listeners.forEach(function (fn) {
                try { fn(type, data); } catch (e) { console.error('[shoutbox]', e); }
            });
        },
        url: function () {
            var u = new URL(this.sse.hubUrl, location.href);
            u.searchParams.append('match', this.sse.topic);
            return u.toString();
        },
        open: function () {
            var self = this;
            this.close();
            try {
                this.source = this.sse.auth === 'header'
                    ? new HeaderSource(this.url(), this.sse.jwt)
                    : new EventSource(this.url(), { withCredentials: true });
            } catch (e) {
                console.warn('[shoutbox] no EventSource, polling instead', e);
                this.poll();
                return;
            }
            this.source.onmessage = function (e) {
                var data;
                try { data = JSON.parse(e.data); } catch (err) { return; }
                self.emit(data.type, data);
            };
            this.source.onopen = function () {
                if (self.dropped) {
                    self.dropped = false;
                    self.emit('reconnected');
                }
            };
            this.source.onerror = function () {
                self.dropped = true;
                // the hub closed the stream (token ran out) or the network is gone: fresh token, then reopen
                if (self.source && self.source.readyState === EventSource.CLOSED) {
                    clearTimeout(self.retry);
                    self.retry = setTimeout(function () { self.refresh(true); }, 3000);
                }
            };
        },
        close: function () {
            if (this.source) {
                this.source.close();
                this.source = null;
            }
        },
        refresh: function (reopen) {
            var self = this;
            this.api.get('subscribe').then(function (data) {
                if (data.sse && data.sse.enabled) {
                    var changed = self.sse.auth === 'header' && data.sse.jwt !== self.sse.jwt;
                    self.sse = data.sse;
                    if (reopen || changed || !self.source || self.source.readyState === EventSource.CLOSED) {
                        self.open();
                    }
                }
            }).catch(function () {
                clearTimeout(self.retry);
                self.retry = setTimeout(function () { self.refresh(true); }, 15000);
            });
        },
        poll: function () {
            var self = this;
            if (this.poller) {
                return;
            }
            this.poller = setInterval(function () { self.emit('poll'); }, this.pollSeconds * 1000);
        }
    };

    // ---------- the feed (block, panel, display) ----------
    function Feed(root, options) {
        this.root = root;
        this.variant = options.variant || 'full';
        this.config = options.config;
        this.block = options.block || {};
        this.api = new Api(this.config);
        this.labels = this.config.labels || {};
        this.filter = 'all';
        this.mode = 'shout';
        this.busy = false;
        this.onMessage = options.onMessage || null;
        this.limit = this.block.count || this.config.history || 100;
        this.applyBoot(options.boot || { shouts: [], pins: [], channels: this.config.channels, canShout: this.config.canShout, canModerate: this.config.canModerate, me: null, sse: { enabled: false } });
        if (!options.boot) {
            this.load();
        } else {
            this.live();
        }
        this.render();
    }

    Feed.prototype.applyBoot = function (boot) {
        var allowed = this.block.channels || boot.channels || this.config.channels || [];
        this.channels = allowed;
        this.byHandle = {};
        allowed.forEach(function (c) { this.byHandle[c.handle] = c; }, this);
        this.defaultChannel = this.block.defaultChannel || boot.defaultChannel || this.config.defaultChannel || (allowed[0] && allowed[0].handle) || '';
        // the site's default channel ("Allgemein"): its shouts carry no channel icon
        this.generalChannel = boot.defaultChannel || this.config.defaultChannel || (allowed[0] && allowed[0].handle) || '';
        this.me = boot.me || null;
        this.canShout = !!boot.canShout;
        this.canModerate = !!boot.canModerate;
        this.pinDurations = boot.pinDurations || this.config.pinDurations || [0];
        this.maxLength = boot.maxLength || this.config.maxLength || 200;
        this.shouts = (boot.shouts || []).filter(this.inScope, this);
        this.pins = (boot.pins || []).filter(this.inScope, this);
        this.lastId = boot.lastId || 0;
        this.ban = this.me && this.me.ban ? this.me.ban : null;
        this.sse = boot.sse || { enabled: false };
    };
    Feed.prototype.inScope = function (m) {
        return !!this.byHandle[m.channel];
    };
    Feed.prototype.load = function () {
        var self = this;
        this.api.get('boot', { limit: this.limit }).then(function (boot) {
            self.applyBoot(boot);
            self.live();
            self.render();
        }).catch(function (e) {
            self.error = e.message;
            self.render();
        });
    };
    Feed.prototype.live = function () {
        var self = this;
        live.start(this.api, this.sse, this.config.pollSeconds);
        live.on(function (type, data) { self.apply(type, data); });
    };
    Feed.prototype.catchUp = function () {
        var self = this;
        this.api.get('boot', { since: this.lastId, limit: this.limit }).then(function (boot) {
            boot.pins.concat(boot.shouts).sort(function (a, b) { return a.id - b.id; }).forEach(function (m) { self.apply('message', { message: m }); });
            self.lastId = Math.max(self.lastId, boot.lastId || 0);
            // what was hidden or unpinned meanwhile is only known after a full reload
            self.pins = self.pins.filter(function (p) { return boot.pins.some(function (q) { return q.id === p.id; }) || p.id <= self.lastId; });
        }).catch(function () { /* next time */ });
    };
    Feed.prototype.apply = function (type, data) {
        if (type === 'poll' || type === 'reconnected') {
            this.catchUp();
            return;
        }
        if (type === 'message') {
            var m = data.message;
            if (!this.inScope(m)) {
                return;
            }
            var list = m.kind === 'pin' ? this.pins : this.shouts;
            if (list.some(function (x) { return x.id === m.id; })) {
                return;
            }
            list.unshift(m);
            if (m.kind === 'shout' && this.shouts.length > this.limit) {
                this.shouts.length = this.limit;
            }
            this.lastId = Math.max(this.lastId, m.id);
            if (this.onMessage) {
                this.onMessage(m);
            }
        } else if (type === 'hidden') {
            this.shouts = this.shouts.filter(function (x) { return x.id !== data.id; });
            this.pins = this.pins.filter(function (x) { return x.id !== data.id; });
        } else if (type === 'unpinned') {
            this.pins = this.pins.filter(function (x) { return x.id !== data.id; });
        } else if (type === 'banned') {
            if (this.me && data.uID === this.me.uID) {
                this.ban = data.ban;
                this.watchBan();
            }
        }
        this.render();
    };
    Feed.prototype.watchBan = function () {
        var self = this;
        clearTimeout(this.banTimer);
        if (this.ban && this.ban.seconds > 0) {
            this.banTimer = setTimeout(function () { self.ban = null; self.render(); }, this.ban.seconds * 1000 + 500);
        }
    };

    // ----- actions -----
    Feed.prototype.send = function () {
        var self = this, L = this.labels;
        if (this.busy) {
            return;
        }
        var text = this.root.querySelector('[data-sb-text]');
        var body = text ? text.value : '';
        var channel = this.target();
        var done = function () { self.busy = false; self.render(); };
        var fail = function (e) {
            if (e.status === 401) {
                location.href = self.config.loginUrl;
                return;
            }
            if (e.data && e.data.ban) {
                self.ban = e.data.ban;
                self.watchBan();
            }
            self.notice = e.message;
            done();
        };
        this.busy = true;
        if (this.mode === 'pin') {
            var title = this.root.querySelector('[data-sb-title]');
            var minutes = this.root.querySelector('[data-sb-minutes]');
            var pinChannel = this.root.querySelector('[data-sb-pin-channel]');
            this.api.post('announce', { channel: pinChannel ? pinChannel.value : channel, title: title ? title.value : '', body: body, minutes: minutes ? minutes.value : 0 })
                .then(function (data) { live.emit('message', { message: data.message }); self.draft = ''; self.draftTitle = ''; self.notice = ''; done(); })
                .catch(fail);
        } else {
            if (!body.trim()) {
                this.busy = false;
                return;
            }
            this.api.post('shout', { body: body, channel: channel })
                .then(function (data) { live.emit('message', { message: data.message }); self.draft = ''; self.notice = ''; done(); })
                .catch(fail);
        }
        void L;
    };
    Feed.prototype.target = function () {
        var select = this.root.querySelector('[data-sb-channel]');
        if (select) {
            return select.value;
        }
        return this.filter !== 'all' ? this.filter : this.defaultChannel;
    };
    Feed.prototype.moderate = function (action, m) {
        var self = this, L = this.labels, fields = { id: m.id };
        if (action === 'hide' && !confirm(L.confirmHide || 'Hide?')) {
            return;
        }
        if (action === 'unpin' && !confirm(L.confirmUnpin || 'Unpin?')) {
            return;
        }
        if (action === 'ban') {
            var minutes = prompt(fmt(L.banFor, m.user.name), '10');
            if (!minutes) {
                return;
            }
            var reason = prompt(L.banReason || '', '') || '';
            fields = { uID: m.user.uID, minutes: minutes, reason: reason };
        }
        this.api.post(action, fields).then(function () {
            if (action === 'hide') {
                live.emit('hidden', { id: m.id });
            } else if (action === 'unpin') {
                live.emit('unpinned', { id: m.id });
            }
        }).catch(function (e) { alert(e.message); });
    };

    // ----- rendering -----
    Feed.prototype.render = function () {
        var self = this, L = this.labels, compact = this.variant === 'compact' || this.variant === 'panel', display = this.variant === 'display';
        var visible = function (list) { return list.filter(function (m) { return self.filter === 'all' || m.channel === self.filter; }); };
        var pins = visible(this.pins), shouts = visible(this.shouts);
        var name = function (h) { return self.byHandle[h] ? self.byHandle[h].name : h; };
        var html = '';

        // head
        var eyebrow = this.block.eyebrow || L.eyebrow, title = this.block.title || L.title;
        if (display) {
            html += '<div class="sb-display-head d-flex align-items-end justify-content-between gap-3 mb-4">'
                + '<div><div class="sb-eyebrow text-uppercase text-muted">' + esc(L.live) + '</div><h2 class="sb-display-title mb-0">' + esc(title) + '</h2></div>'
                + (this.block.hint ? '<div class="sb-display-hint text-muted">' + esc(L.writeWith) + ' <span class="text-primary">' + esc(this.block.hint) + '</span></div>' : '')
                + '</div>';
        } else if (this.variant !== 'panel') {
            html += '<div class="card-header d-flex align-items-end justify-content-between gap-3">'
                + '<div><div class="small text-uppercase text-muted">' + esc(eyebrow) + '</div><h3 class="h5 mb-0">' + esc(title) + '</h3></div>'
                + '<span class="small text-muted text-nowrap">' + esc(fmt(L.shouts, this.shouts.length)) + '</span></div>';
        }

        html += '<div class="' + (display ? 'sb-display-body' : 'card-body sb-body') + '">';

        // channel chips
        if (SHOW_FILTER && !display && this.channels.length > 1) {
            html += '<div class="sb-chips mb-3" role="group" aria-label="' + esc(L.channel) + '">'
                + '<button type="button" class="btn btn-sm ' + (this.filter === 'all' ? 'btn-primary' : 'btn-outline-secondary') + '" data-sb-filter="all">' + esc(L.all) + ' <span class="badge text-bg-light">' + this.shouts.length + '</span></button>';
            this.channels.forEach(function (c) {
                var n = self.shouts.filter(function (m) { return m.channel === c.handle; }).length;
                html += '<button type="button" class="btn btn-sm ' + (self.filter === c.handle ? 'btn-' + c.color : 'btn-outline-' + c.color) + '" data-sb-filter="' + esc(c.handle) + '">'
                    + (compact ? '' : icon(c)) + esc(c.name) + (n ? ' <span class="badge text-bg-light">' + n + '</span>' : '') + '</button>';
            });
            html += '</div>';
        }

        if (this.error) {
            html += '<div class="alert alert-warning">' + esc(this.error) + '</div>';
        }

        // pins
        if (pins.length) {
            html += '<div class="sb-pins mb-3">';
            pins.forEach(function (p) {
                var c = self.byHandle[p.channel];
                html += '<div class="sb-pin alert alert-' + esc(c ? c.color : 'warning') + ' d-flex gap-2' + (display ? ' sb-pin-lg' : ' py-2 mb-2') + '">'
                    + '<span class="sb-pin-icon"><i class="fa fa-thumbtack" aria-hidden="true"></i></span>'
                    + '<div class="flex-grow-1 min-w-0">'
                    + '<div class="d-flex flex-wrap align-items-center gap-2"><strong class="sb-pin-title">' + esc(p.title) + '</strong>'
                    + (compact ? '' : '<span class="badge text-bg-secondary">' + icon(c) + esc(name(p.channel)) + '</span>') + '</div>'
                    + (p.body ? '<div class="sb-pin-text">' + linkify(p.body) + '</div>' : '')
                    + '<div class="sb-meta text-muted small">' + esc(fmt(L.pinnedBy, p.by || '', timeOf(p.createdAt))) + '</div></div>'
                    + (self.canModerate && !display ? '<button type="button" class="btn btn-sm btn-outline-secondary align-self-start" data-sb-action="unpin" data-sb-id="' + p.id + '" title="' + esc(L.unpin) + '" aria-label="' + esc(L.unpin) + '">&times;</button>' : '')
                    + '</div>';
            });
            html += '</div>';
        }

        // shouts
        if (!shouts.length) {
            html += '<div class="sb-empty text-center text-muted py-4"><i class="fa fa-broadcast-tower fa-2x d-block mb-2" aria-hidden="true"></i>'
                + esc(this.filter === 'all' ? L.emptyAll : fmt(L.emptyChannel, name(this.filter))) + '</div>';
        } else if (display) {
            html += '<div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 sb-grid">';
            shouts.forEach(function (m, i) {
                html += '<div class="col"><div class="card h-100 sb-card' + (i === 0 ? ' border-primary' : '') + '"><div class="card-body d-flex gap-3">' + self.avatar(m, 56) + '<div class="flex-grow-1 min-w-0">'
                    + '<div class="d-flex align-items-baseline gap-2 flex-wrap"><strong class="sb-nick">' + esc(m.user.name) + '</strong>'
                    + '<span class="sb-meta text-muted">' + esc([m.seat, name(m.channel)].filter(Boolean).join(' · ')) + '</span><span class="sb-meta text-muted ms-auto">' + esc(timeOf(m.createdAt)) + '</span></div>'
                    + '<div class="sb-text">' + linkify(m.body) + '</div></div></div></div></div>';
            });
            html += '</div>';
        } else {
            html += '<ul class="list-group list-group-flush sb-list' + (compact ? ' sb-scroll' : '') + '">';
            shouts.forEach(function (m) {
                html += '<li class="list-group-item px-0 d-flex gap-2' + (self.me && m.user.uID === self.me.uID ? ' sb-mine' : '') + '">' + self.avatar(m, compact ? 28 : 32)
                    + '<div class="flex-grow-1 min-w-0"><div class="d-flex align-items-baseline gap-2">'
                    + '<strong class="sb-nick text-truncate">' + esc(m.user.name) + '</strong>'
                    + '<small class="text-muted text-nowrap">' + esc(m.seat || '') + '</small>'
                    + (self.filter === 'all' && m.channel !== self.generalChannel && self.byHandle[m.channel] && self.byHandle[m.channel].faIcon
                        ? '<small class="sb-channel text-' + esc(self.byHandle[m.channel].color) + '" title="' + esc(name(m.channel)) + '" role="img" aria-label="' + esc(name(m.channel)) + '"><i class="' + esc(self.byHandle[m.channel].faIcon) + '" aria-hidden="true"></i></small>'
                        : '')
                    + '<small class="text-muted ms-auto text-nowrap">' + esc(timeOf(m.createdAt)) + '</small></div>'
                    + '<div class="sb-text">' + linkify(m.body) + '</div></div>'
                    + (self.canModerate ? '<div class="sb-actions d-flex gap-1 align-self-start">'
                        + '<button type="button" class="btn btn-sm btn-outline-secondary" data-sb-action="hide" data-sb-id="' + m.id + '" title="' + esc(L.hide) + '" aria-label="' + esc(L.hide) + '">&minus;</button>'
                        + (self.me && m.user.uID !== self.me.uID ? '<button type="button" class="btn btn-sm btn-outline-danger" data-sb-action="ban" data-sb-id="' + m.id + '" title="' + esc(L.ban) + '" aria-label="' + esc(L.ban) + '"><i class="fa fa-ban" aria-hidden="true"></i></button>' : '')
                        + '</div>' : '')
                    + '</li>';
            });
            html += '</ul>';
        }
        html += '</div>';

        // composer
        if (!display) {
            html += '<div class="' + (this.variant === 'panel' ? 'sb-composer' : 'card-footer sb-composer') + '">' + this.composer(compact) + '</div>';
        }

        this.root.innerHTML = html;
        this.bind();
    };
    Feed.prototype.avatar = function (m, size) {
        return m.user.avatar
            ? '<img src="' + esc(m.user.avatar) + '" alt="" width="' + size + '" height="' + size + '" class="rounded-circle flex-shrink-0 sb-avatar" loading="lazy">'
            : '<span class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center flex-shrink-0 sb-avatar" style="width:' + size + 'px;height:' + size + 'px;font-size:' + Math.round(size * 0.38) + 'px">' + esc(m.user.initials) + '</span>';
    };
    Feed.prototype.composer = function (compact) {
        var self = this, L = this.labels;
        if (!this.me) {
            return '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2"><span class="text-muted">' + esc(L.loginPrompt) + '</span>'
                + '<a class="btn btn-sm btn-secondary" href="' + esc(this.config.loginUrl) + '"><i class="fa fa-user" aria-hidden="true"></i> ' + esc(L.login) + '</a></div>';
        }
        if (!this.canShout) {
            return '<span class="text-muted small">' + esc(fmt(L.shoutIn, '…')) + ' &middot; ' + esc(L.loginPrompt) + '</span>';
        }
        if (this.ban) {
            return '<div class="alert alert-warning mb-0 py-2">' + esc(fmt(L.banned, timeOf(this.ban.untilIso))) + (this.ban.reason ? ' <span class="text-muted">(' + esc(this.ban.reason) + ')</span>' : '') + '</div>';
        }
        var html = '';
        if (this.canModerate) {
            html += '<div class="btn-group btn-group-sm mb-2" role="group">'
                + '<button type="button" class="btn ' + (this.mode === 'shout' ? 'btn-primary' : 'btn-outline-primary') + '" data-sb-mode="shout">' + esc(L.shout) + '</button>'
                + '<button type="button" class="btn ' + (this.mode === 'pin' ? 'btn-primary' : 'btn-outline-primary') + '" data-sb-mode="pin"><i class="fa fa-thumbtack" aria-hidden="true"></i> ' + esc(L.announcement) + '</button></div>';
        }
        var channelSelect = function (attr, selected) {
            var s = '<select class="form-select form-select-sm w-auto" ' + attr + ' aria-label="' + esc(L.channel) + '">';
            self.channels.forEach(function (c) { s += '<option value="' + esc(c.handle) + '"' + (c.handle === selected ? ' selected' : '') + '>' + esc(c.name) + '</option>'; });
            return s + '</select>';
        };
        if (this.mode === 'pin') {
            var options = '';
            this.pinDurations.forEach(function (m) {
                options += '<option value="' + m + '">' + esc(m > 0 ? fmt(L.minutes, m) : L.untilUnpinned) + '</option>';
            });
            html += '<div class="d-flex flex-wrap gap-2 mb-2">' + channelSelect('data-sb-pin-channel', this.pinChannel || this.target())
                + '<select class="form-select form-select-sm w-auto" data-sb-minutes aria-label="' + esc(L.pinFor) + '">' + options + '</select></div>'
                + '<input type="text" class="form-control form-control-sm mb-2" data-sb-title maxlength="120" placeholder="' + esc(L.titleField) + '" value="' + esc(this.draftTitle || '') + '">';
        } else if (this.channels.length > 1 && (compact || this.filter === 'all')) {
            html += '<div class="mb-2">' + channelSelect('data-sb-channel', this.pinChannel || this.target()) + '</div>';
        }
        html += '<div class="d-flex align-items-end gap-2">'
            + '<textarea class="form-control form-control-sm" data-sb-text rows="' + (compact ? 1 : 2) + '" maxlength="' + (this.mode === 'pin' ? 1000 : this.maxLength) + '" placeholder="' + esc(this.mode === 'pin' ? L.text : fmt(L.shoutIn, this.byHandle[this.target()] ? this.byHandle[this.target()].name : '')) + '">' + esc(this.draft || '') + '</textarea>'
            + '<button type="button" class="btn btn-primary btn-sm text-nowrap" data-sb-send' + (this.busy ? ' disabled' : '') + '>' + (this.mode === 'pin' ? '<i class="fa fa-thumbtack" aria-hidden="true"></i> ' + esc(L.pin) : esc(L.send) + ' <i class="fa fa-arrow-right" aria-hidden="true"></i>') + '</button></div>'
            + '<div class="d-flex justify-content-between small text-muted mt-1"><span data-sb-notice class="text-danger">' + esc(this.notice || '') + '</span>'
            + '<span data-sb-counter>' + (this.mode === 'pin' ? esc(L.pinHint) : esc(fmt(L.chars, (this.draft || '').length, this.maxLength))) + '</span></div>';
        return html;
    };
    Feed.prototype.bind = function () {
        var self = this, root = this.root, L = this.labels;
        root.querySelectorAll('[data-sb-filter]').forEach(function (b) {
            b.addEventListener('click', function () { self.filter = b.getAttribute('data-sb-filter'); self.render(); });
        });
        root.querySelectorAll('[data-sb-mode]').forEach(function (b) {
            b.addEventListener('click', function () { self.mode = b.getAttribute('data-sb-mode'); self.notice = ''; self.render(); });
        });
        root.querySelectorAll('[data-sb-action]').forEach(function (b) {
            b.addEventListener('click', function () {
                var id = Number(b.getAttribute('data-sb-id'));
                var m = self.shouts.concat(self.pins).filter(function (x) { return x.id === id; })[0];
                if (m) {
                    self.moderate(b.getAttribute('data-sb-action'), m);
                }
            });
        });
        var text = root.querySelector('[data-sb-text]'), send = root.querySelector('[data-sb-send]'), counter = root.querySelector('[data-sb-counter]');
        var title = root.querySelector('[data-sb-title]'), channel = root.querySelector('[data-sb-channel], [data-sb-pin-channel]');
        if (text) {
            text.addEventListener('input', function () {
                self.draft = text.value;
                if (counter && self.mode !== 'pin') {
                    counter.textContent = fmt(L.chars, text.value.length, self.maxLength);
                }
            });
            text.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.send();
                }
            });
        }
        if (title) {
            title.addEventListener('input', function () { self.draftTitle = title.value; });
        }
        if (channel) {
            channel.addEventListener('change', function () { self.pinChannel = channel.value; });
        }
        if (send) {
            send.addEventListener('click', function () { self.send(); });
        }
    };

    // ---------- the floating panel ----------
    function Panel(config) {
        var self = this, L = config.labels || {};
        this.config = config;
        this.open = false;
        this.muted = store.get('muted') === '1';
        this.seen = Number(store.get('seen') || 0);
        this.unread = 0;

        this.launcher = el('<button type="button" class="sb-launcher btn btn-primary rounded-circle" aria-label="' + esc(L.open) + '" title="' + esc(L.open) + '">'
            + '<i class="fa fa-comments" aria-hidden="true"></i><span class="sb-badge badge rounded-pill text-bg-danger" hidden>0</span></button>');
        this.panel = el('<aside class="sb-panel card shadow" hidden aria-label="' + esc(L.eyebrow) + '">'
            + '<div class="card-header d-flex align-items-center gap-2"><div class="flex-grow-1"><div class="small text-uppercase text-muted">' + esc(L.eyebrow) + '</div><h3 class="h6 mb-0">' + esc(L.title) + '</h3></div>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-sb-mute title="' + esc(this.muted ? L.unmute : L.mute) + '" aria-pressed="' + (this.muted ? 'true' : 'false') + '"><i class="fa ' + (this.muted ? 'fa-bell-slash' : 'fa-bell') + '" aria-hidden="true"></i></button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-sb-close aria-label="' + esc(L.close) + '">&times;</button></div>'
            + '<div class="sb-panel-feed" data-sb-panel-feed></div></aside>');
        this.toasts = el('<div class="sb-toasts toast-container position-fixed bottom-0 end-0 p-3"></div>');
        document.body.appendChild(this.launcher);
        document.body.appendChild(this.panel);
        document.body.appendChild(this.toasts);

        this.feed = new Feed(this.panel.querySelector('[data-sb-panel-feed]'), {
            variant: 'panel', config: config, boot: null,
            onMessage: function (m) { self.arrived(m); }
        });
        this.launcher.addEventListener('click', function () { self.toggle(); });
        this.panel.querySelector('[data-sb-close]').addEventListener('click', function () { self.close(); });
        this.panel.querySelector('[data-sb-mute]').addEventListener('click', function () {
            self.muted = !self.muted;
            store.set('muted', self.muted ? '1' : '0');
            var b = self.panel.querySelector('[data-sb-mute]');
            b.setAttribute('aria-pressed', self.muted ? 'true' : 'false');
            b.title = self.muted ? L.unmute : L.mute;
            b.querySelector('i').className = 'fa ' + (self.muted ? 'fa-bell-slash' : 'fa-bell');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && self.open) {
                self.close();
            }
        });
        if (location.hash === '#shoutbox') {
            this.show();
        }
    }
    Panel.prototype.arrived = function (m) {
        var L = this.config.labels || {};
        if (this.open) {
            this.seen = Math.max(this.seen, m.id);
            store.set('seen', this.seen);
            return;
        }
        if (this.feed.me && m.user.uID === this.feed.me.uID) {
            return;
        }
        if (m.id > this.seen) {
            this.unread += 1;
            this.badge();
        }
        if (m.kind === 'pin' && !this.muted) {
            this.toast(m, L);
        }
    };
    Panel.prototype.badge = function () {
        var b = this.launcher.querySelector('.sb-badge');
        b.textContent = String(this.unread);
        b.hidden = this.unread === 0;
    };
    Panel.prototype.toast = function (m, L) {
        var self = this, c = this.feed.byHandle[m.channel];
        var t = el('<div class="toast show sb-toast border-' + esc(c ? c.color : 'warning') + '" role="status"><div class="toast-header">' + icon(c)
            + '<strong class="me-auto">' + esc(m.title || m.user.name) + '</strong><small class="text-muted">' + esc(c ? c.name : '') + '</small>'
            + '<button type="button" class="btn-close" aria-label="' + esc(L.close) + '"></button></div>'
            + (m.body ? '<div class="toast-body">' + linkify(m.body) + '</div>' : '') + '</div>');
        var remove = function () { if (t.parentNode) { t.parentNode.removeChild(t); } };
        t.querySelector('.btn-close').addEventListener('click', function (e) { e.stopPropagation(); remove(); });
        t.addEventListener('click', function () { remove(); self.show(); });
        this.toasts.appendChild(t);
        setTimeout(remove, (this.config.toastSeconds || 8) * 1000);
    };
    Panel.prototype.toggle = function () {
        if (this.open) {
            this.close();
        } else {
            this.show();
        }
    };
    Panel.prototype.show = function () {
        this.open = true;
        this.panel.hidden = false;
        this.launcher.classList.add('sb-launcher-open');
        this.unread = 0;
        this.badge();
        if (this.feed.lastId) {
            this.seen = Math.max(this.seen, this.feed.lastId);
            store.set('seen', this.seen);
        }
        var text = this.panel.querySelector('[data-sb-text]');
        if (text) {
            text.focus();
        }
    };
    Panel.prototype.close = function () {
        this.open = false;
        this.panel.hidden = true;
        this.launcher.classList.remove('sb-launcher-open');
    };

    // ---------- mounting ----------
    var panel = null;
    function mountBlock(node) {
        if (node.getAttribute('data-shoutbox-mounted')) {
            return;
        }
        var json = node.querySelector('script[data-shoutbox-props]');
        if (!json) {
            return;
        }
        var props;
        try {
            props = JSON.parse(json.textContent);
        } catch (e) {
            return;
        }
        node.setAttribute('data-shoutbox-mounted', '1');
        var variant = node.getAttribute('data-variant') || 'full';
        var root = document.createElement('div');
        root.className = 'sb-feed';
        node.innerHTML = '';
        node.appendChild(root);
        new Feed(root, { variant: variant, config: props.config, boot: props.boot, block: props.block });
    }
    function init() {
        document.querySelectorAll('[data-shoutbox="block"]').forEach(mountBlock);
        var cfg = window.ShoutboxConfig;
        if (cfg && cfg.ui !== 'theme' && !panel) {
            panel = new Panel(cfg);
        }
    }
    window.Shoutbox = {
        open: function () { if (panel) { panel.show(); } },
        close: function () { if (panel) { panel.close(); } },
        mount: mountBlock
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
