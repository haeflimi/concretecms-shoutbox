<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string $eyebrow
 * @var string $title
 * @var array<int, array{handle: string, name: string}> $allChannels
 * @var string[] $selectedChannels
 * @var string $defaultChannel
 * @var int $count
 * @var string $hint
 */
?>
<div class="form-group">
    <label class="form-label" for="sb-eyebrow"><?= t('Eyebrow') ?></label>
    <input type="text" class="form-control" name="eyebrow" id="sb-eyebrow" maxlength="64" value="<?= h($eyebrow) ?>" placeholder="<?= h(t('Shoutbox')) ?>">
</div>
<div class="form-group">
    <label class="form-label" for="sb-title"><?= t('Title') ?></label>
    <input type="text" class="form-control" name="title" id="sb-title" maxlength="120" value="<?= h($title) ?>" placeholder="<?= h(t('From the hall')) ?>">
</div>
<p class="text-muted small mb-1"><?= t('Channels') ?></p>
<?php foreach ($allChannels as $channel) { ?>
    <div class="form-check">
        <input type="checkbox" class="form-check-input" name="channels[]" id="sb-ch-<?= h($channel['handle']) ?>" value="<?= h($channel['handle']) ?>"<?= in_array($channel['handle'], $selectedChannels, true) ? ' checked' : '' ?>>
        <label class="form-check-label" for="sb-ch-<?= h($channel['handle']) ?>"><?= h($channel['name']) ?></label>
    </div>
<?php } ?>
<p class="text-muted small mt-1"><?= t('Nothing ticked shows all channels. The channels themselves are set up under Dashboard › Shoutbox.') ?></p>
<div class="form-group">
    <label class="form-label" for="sb-default"><?= t('Default channel for new shouts') ?></label>
    <select class="form-select" name="defaultChannel" id="sb-default">
        <option value=""><?= t('First channel of the block') ?></option>
        <?php foreach ($allChannels as $channel) { ?>
            <option value="<?= h($channel['handle']) ?>"<?= $defaultChannel === $channel['handle'] ? ' selected' : '' ?>><?= h($channel['name']) ?></option>
        <?php } ?>
    </select>
</div>
<div class="form-group">
    <label class="form-label" for="sb-count"><?= t('Number of shouts shown') ?></label>
    <input type="number" class="form-control" name="count" id="sb-count" min="5" max="200" value="<?= (int) $count ?>">
</div>
<div class="form-group">
    <label class="form-label" for="sb-hint"><?= t('Hint on the big screen') ?></label>
    <input type="text" class="form-control" name="hint" id="sb-hint" maxlength="255" value="<?= h($hint) ?>" placeholder="<?= h(t('e.g. lan.turicane.ch/shout · Discord #shoutbox')) ?>">
    <div class="form-text"><?= t('Only the Display template shows it, next to "Write with us:".') ?></div>
</div>
