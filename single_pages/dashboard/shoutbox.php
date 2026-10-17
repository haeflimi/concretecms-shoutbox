<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var Shoutbox\Shout\Settings $settings
 * @var array<int, string> $groups
 * @var string[] $colors
 * @var string[] $icons
 * @var array<int, array<string, mixed>> $bans
 * @var array{enabled: bool, hubUrl: string, auth: string, topic: string} $realtime
 * @var array<int, array{name: string, path: string, url: string}> $pages
 * @var string $attributeHandle
 */

$groupSelect = static function (string $name, array $selected, int $rows = 6) use ($groups): void { ?>
    <select class="form-select" name="<?= $name ?>[]" id="<?= $name ?>" multiple size="<?= min(10, max($rows, count($groups))) ?>">
        <?php foreach ($groups as $gID => $label) { ?>
            <option value="<?= $gID ?>"<?= in_array($gID, $selected, true) ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php } ?>
    </select>
<?php };
$colorLabels = ['primary' => t('Primary'), 'secondary' => t('Secondary'), 'success' => t('Green'), 'danger' => t('Red'), 'warning' => t('Yellow'), 'info' => t('Blue')];
$channels = $settings->getChannels();
?>
<form method="post" action="<?= h($view->action('save')) ?>" id="shoutbox-settings">
    <?php $token->output('shoutbox_save') ?>
    <fieldset>
        <legend><?= t('Access') ?></legend>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="enabled" id="enabled" value="1"<?= $settings->isEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="enabled"><?= t('The shoutbox is on') ?></label>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label" for="read_groups"><?= t('Who may read') ?></label>
                <?php $groupSelect('read_groups', $settings->getReadGroupIDs()) ?>
                <div class="form-text"><?= t('Hold Ctrl (Cmd on a Mac) to pick several. Administrators always read.') ?></div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="shout_groups"><?= t('Who may shout') ?></label>
                <?php $groupSelect('shout_groups', $settings->getShoutGroupIDs()) ?>
                <div class="form-text"><?= t('Guests never shout, whatever is ticked here.') ?></div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="moderator_groups"><?= t('Moderators') ?></label>
                <?php $groupSelect('moderator_groups', $settings->getModeratorGroupIDs()) ?>
                <div class="form-text"><?= t('Pin announcements, hide shouts, ban members. Administrators always moderate. The first of these groups a moderator belongs to is the sender of their announcements ("Catering").') ?></div>
            </div>
        </div>
        <div class="alert alert-info">
            <?= t('The floating panel shows on pages with the page attribute "Shoutbox" (%s) ticked: set it in the page\'s attributes panel. The block "Shoutbox" can be placed on any page regardless.', '<code>' . h($attributeHandle) . '</code>') ?>
            <?php if ($pages !== []) { ?>
                <div class="mt-2"><?= t('Pages with the panel right now:') ?>
                    <?php foreach ($pages as $i => $page) { ?><?= $i > 0 ? ', ' : ' ' ?><a href="<?= h($page['url']) ?>" target="_blank"><?= h($page['name']) ?></a><?php } ?>
                </div>
            <?php } else { ?>
                <div class="mt-2"><?= t('No page has it yet.') ?></div>
            <?php } ?>
        </div>
    </fieldset>

    <fieldset>
        <legend><?= t('Channels') ?></legend>
        <table class="table table-sm align-middle" id="shoutbox-channels">
            <thead><tr><th><?= t('Name') ?></th><th><?= t('Handle') ?></th><th><?= t('Colour') ?></th><th><?= t('Icon') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($channels as $i => $channel) { ?>
                    <tr>
                        <td><input type="text" class="form-control form-control-sm" name="channels[<?= $i ?>][name]" value="<?= h($channel['name']) ?>" maxlength="40" required></td>
                        <td><input type="text" class="form-control form-control-sm font-monospace" name="channels[<?= $i ?>][handle]" value="<?= h($channel['handle']) ?>" maxlength="32" pattern="[a-z0-9_]+"></td>
                        <td><select class="form-select form-select-sm" name="channels[<?= $i ?>][color]">
                            <?php foreach ($colors as $color) { ?><option value="<?= $color ?>"<?= $channel['color'] === $color ? ' selected' : '' ?>><?= h($colorLabels[$color] ?? $color) ?></option><?php } ?>
                        </select></td>
                        <td><select class="form-select form-select-sm" name="channels[<?= $i ?>][icon]">
                            <?php foreach ($icons as $icon) { ?><option value="<?= $icon ?>"<?= $channel['icon'] === $icon ? ' selected' : '' ?>><?= h($icon) ?></option><?php } ?>
                        </select></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-channel aria-label="<?= h(t('Remove')) ?>">&times;</button></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-secondary" id="shoutbox-add-channel"><?= t('Add channel') ?></button>
        <div class="form-text"><?= t('The handle is derived from the name when left empty; shouts already posted keep their channel handle, so rename rather than delete and re-create. Removing all channels restores the defaults.') ?></div>
        <div class="mb-3 mt-3">
            <label class="form-label" for="default_channel"><?= t('Default channel for new shouts') ?></label>
            <select class="form-select" name="default_channel" id="default_channel" style="max-width: 20rem">
                <?php foreach ($channels as $channel) { ?>
                    <option value="<?= h($channel['handle']) ?>"<?= $settings->getDefaultChannel() === $channel['handle'] ? ' selected' : '' ?>><?= h($channel['name']) ?></option>
                <?php } ?>
            </select>
        </div>
    </fieldset>

    <fieldset>
        <legend><?= t('Limits') ?></legend>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label" for="max_length"><?= t('Characters per shout') ?></label>
                <input type="number" class="form-control" name="max_length" id="max_length" min="20" max="1000" value="<?= $settings->getMaxLength() ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="history"><?= t('Shouts loaded in the panel') ?></label>
                <input type="number" class="form-control" name="history" id="history" min="10" max="500" value="<?= $settings->getHistory() ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="rate_count"><?= t('Shouts in a row') ?></label>
                <input type="number" class="form-control" name="rate_count" id="rate_count" min="1" max="50" value="<?= $settings->getRateCount() ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="rate_window"><?= t('… within seconds') ?></label>
                <input type="number" class="form-control" name="rate_window" id="rate_window" min="1" max="3600" value="<?= $settings->getRateWindow() ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="rate_ban"><?= t('Automatic ban, seconds') ?></label>
                <input type="number" class="form-control" name="rate_ban" id="rate_ban" min="10" max="86400" value="<?= $settings->getRateBan() ?>">
                <div class="form-text"><?= t('A member who sends more than the shouts in a row above gets this ban; moderators are exempt.') ?></div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="pin_durations"><?= t('Pin durations, minutes') ?></label>
                <input type="text" class="form-control" name="pin_durations" id="pin_durations" value="<?= h(implode(', ', $settings->getPinDurations())) ?>">
                <div class="form-text"><?= t('Choices for moderators; 0 = until unpinned.') ?></div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="toast_seconds"><?= t('Announcement pop-up, seconds') ?></label>
                <input type="number" class="form-control" name="toast_seconds" id="toast_seconds" min="2" max="60" value="<?= $settings->getToastSeconds() ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label" for="poll_seconds"><?= t('Polling without Mercure, seconds') ?></label>
                <input type="number" class="form-control" name="poll_seconds" id="poll_seconds" min="5" max="300" value="<?= $settings->getPollSeconds() ?>">
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><?= t('Retention') ?></legend>
        <div class="col-md-3 mb-3">
            <label class="form-label" for="retention_days"><?= t('Keep shouts for days') ?></label>
            <input type="number" class="form-control" name="retention_days" id="retention_days" min="1" max="3650" value="<?= $settings->getRetentionDays() ?>">
            <div class="form-text"><?= t('The task "Purge Shoutbox" (Dashboard › System › Automation) deletes older shouts and expired bans; schedule it or run it by hand.') ?></div>
        </div>
    </fieldset>

    <fieldset>
        <legend><?= t('Real-time') ?></legend>
        <?php if ($realtime['enabled']) { ?>
            <p class="mb-1"><span class="badge text-bg-success"><?= t('Mercure on') ?></span> <code><?= h($realtime['hubUrl']) ?></code></p>
            <p class="text-muted small"><?= t('Topic %1$s, browsers authenticate by %2$s.', '<code>' . h($realtime['topic']) . '</code>', $realtime['auth'] === 'header' ? t('token in the request header (hub on another origin)') : t('cookie (hub on the site\'s origin)')) ?></p>
        <?php } else { ?>
            <p class="mb-1"><span class="badge text-bg-secondary"><?= t('Mercure off') ?></span> <?= t('The shoutbox polls every %s seconds instead.', $settings->getPollSeconds()) ?></p>
            <p class="text-muted small"><?= t('Needs server-sent events with a publish URL (Dashboard › System › Notification › Events) and both hub keys in %s.', '<code>application/config/mercure.php</code>') ?></p>
        <?php } ?>
        <p class="text-muted small"><?= t('Set up under %s (server-sent events, hub publish URL, key).', '<a href="' . h(\URL::to('/dashboard/system/notification/events')) . '">' . t('System › Notification › Events') . '</a>') ?></p>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <button type="submit" class="btn btn-primary float-end"><?= t('Save') ?></button>
        </div>
    </div>
</form>

<fieldset class="mt-5">
    <legend><?= t('Bans') ?></legend>
    <?php if ($bans === []) { ?>
        <p class="text-muted"><?= t('Nobody is banned right now.') ?></p>
    <?php } else { ?>
        <table class="table table-sm align-middle">
            <thead><tr><th><?= t('Member') ?></th><th><?= t('Until') ?></th><th><?= t('Reason') ?></th><th><?= t('By') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($bans as $ban) { ?>
                    <tr>
                        <td><?= h($ban['name']) ?></td>
                        <td><?= h(app('helper/date')->formatDateTime($ban['untilIso'], true)) ?></td>
                        <td><?= h((string) $ban['reason']) ?></td>
                        <td><?= h($ban['by']) ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= h($view->action('unban')) ?>" class="d-inline">
                                <?php $token->output('shoutbox_unban') ?>
                                <input type="hidden" name="id" value="<?= (int) $ban['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?= t('Lift the ban') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
    <form method="post" action="<?= h($view->action('ban')) ?>" class="row g-2 align-items-end">
        <?php $token->output('shoutbox_ban') ?>
        <div class="col-md-3">
            <label class="form-label" for="ban-username"><?= t('Username or email') ?></label>
            <input type="text" class="form-control" name="username" id="ban-username" required>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="ban-minutes"><?= t('Minutes') ?></label>
            <input type="number" class="form-control" name="minutes" id="ban-minutes" min="1" max="10080" value="60">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="ban-reason"><?= t('Reason (optional)') ?></label>
            <input type="text" class="form-control" name="reason" id="ban-reason" maxlength="255">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-danger"><?= t('Ban') ?></button>
        </div>
    </form>
</fieldset>

<script>
(function () {
    var table = document.getElementById('shoutbox-channels');
    var body = table.querySelector('tbody');
    var colors = <?= json_encode($colors) ?>, icons = <?= json_encode($icons) ?>, colorLabels = <?= json_encode($colorLabels) ?>;
    var options = function (list, labels) {
        return list.map(function (v) { return '<option value="' + v + '">' + (labels && labels[v] ? labels[v] : v) + '</option>'; }).join('');
    };
    document.getElementById('shoutbox-add-channel').addEventListener('click', function () {
        var i = Date.now();
        var row = document.createElement('tr');
        row.innerHTML = '<td><input type="text" class="form-control form-control-sm" name="channels[' + i + '][name]" maxlength="40" required></td>'
            + '<td><input type="text" class="form-control form-control-sm font-monospace" name="channels[' + i + '][handle]" maxlength="32" pattern="[a-z0-9_]+"></td>'
            + '<td><select class="form-select form-select-sm" name="channels[' + i + '][color]">' + options(colors, colorLabels) + '</select></td>'
            + '<td><select class="form-select form-select-sm" name="channels[' + i + '][icon]">' + options(icons) + '</select></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-channel aria-label="<?= h(t('Remove')) ?>">&times;</button></td>';
        body.appendChild(row);
        row.querySelector('input').focus();
    });
    table.addEventListener('click', function (e) {
        var button = e.target.closest('[data-remove-channel]');
        if (button) {
            button.closest('tr').remove();
        }
    });
})();
</script>
