<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Built-in view (Bootstrap 5): the feed rendered by the server, taken over live by js/shoutbox.js which reads the
 * JSON props and re-renders. The templates Compact and Display include this file with $variant set.
 *
 * @var bool $denied
 * @var bool $editMode
 * @var array<string, mixed> $props config, boot, block
 * @var array<string, string> $labels
 * @var string|null $variant full (default), compact or display
 */

$variant = $variant ?? 'full';
if (!empty($denied)) {
    if (!empty($editMode)) { ?>
        <div class="alert alert-secondary mb-0"><?= t('Shoutbox: not shown to this visitor (the read rule in Dashboard › Shoutbox decides).') ?></div>
    <?php }

    return;
}
$block = $props['block'];
$boot = $props['boot'];
$channelNames = [];
foreach ($block['channels'] as $channel) {
    $channelNames[$channel['handle']] = $channel['name'];
}
$sprintf = static fn (string $label, ...$args) => vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $label), $args);
?>
<div class="shoutbox shoutbox-<?= h($variant) ?> card" data-shoutbox="block" data-variant="<?= h($variant) ?>" id="shoutbox-<?= (int) $block['bID'] ?>">
    <script type="application/json" data-shoutbox-props><?= json_encode($props, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
    <div class="card-header d-flex align-items-end justify-content-between gap-3">
        <div>
            <div class="small text-uppercase text-muted"><?= h($block['eyebrow']) ?></div>
            <h3 class="h5 mb-0"><?= h($block['title']) ?></h3>
        </div>
        <span class="small text-muted text-nowrap" data-shoutbox-count><?= h($sprintf($labels['shouts'], count($boot['shouts']))) ?></span>
    </div>
    <div class="card-body" data-shoutbox-feed>
        <?php foreach ($boot['pins'] as $pin) {
            if (!isset($channelNames[$pin['channel']])) {
                continue;
            } ?>
            <div class="alert alert-warning py-2 mb-2">
                <strong><?= h($pin['title']) ?></strong>
                <span class="badge text-bg-secondary ms-1"><?= h($channelNames[$pin['channel']]) ?></span>
                <?php if ($pin['body'] !== '') { ?><div class="small"><?= nl2br(h($pin['body'])) ?></div><?php } ?>
                <div class="small text-muted"><?= h($sprintf($labels['pinnedBy'], (string) $pin['by'], $pin['time'])) ?></div>
            </div>
        <?php } ?>
        <?php $shown = array_values(array_filter($boot['shouts'], static fn ($shout) => isset($channelNames[$shout['channel']])));
        if ($shown === []) { ?>
            <p class="text-muted mb-0"><?= h($labels['emptyAll']) ?></p>
        <?php } else { ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($shown as $shout) { ?>
                    <li class="list-group-item px-0 d-flex gap-2">
                        <?php if ($shout['user']['avatar']) { ?>
                            <img src="<?= h($shout['user']['avatar']) ?>" alt="" width="32" height="32" class="rounded-circle flex-shrink-0" loading="lazy">
                        <?php } else { ?>
                            <span class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center flex-shrink-0 small" style="width:32px;height:32px"><?= h($shout['user']['initials']) ?></span>
                        <?php } ?>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-baseline gap-2">
                                <strong><?= h($shout['user']['name']) ?></strong>
                                <small class="text-muted"><?= h(implode(' · ', array_filter([$shout['seat'], $channelNames[$shout['channel']]]))) ?></small>
                                <small class="text-muted ms-auto"><?= h($shout['time']) ?></small>
                            </div>
                            <div><?= nl2br(h($shout['body'])) ?></div>
                        </div>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </div>
    <?php if ($variant !== 'display') { ?>
        <div class="card-footer" data-shoutbox-composer>
            <?php if (empty($boot['me'])) { ?>
                <span class="text-muted"><?= h($labels['loginPrompt']) ?></span>
                <a class="btn btn-sm btn-secondary ms-2" href="<?= h($props['config']['loginUrl']) ?>"><?= h($labels['login']) ?></a>
            <?php } elseif (!$boot['canShout']) { ?>
                <span class="text-muted"><?= t('You may read along but not shout here.') ?></span>
            <?php } else { ?>
                <noscript><span class="text-muted"><?= t('Shouting needs JavaScript.') ?></span></noscript>
            <?php } ?>
        </div>
    <?php } ?>
</div>
