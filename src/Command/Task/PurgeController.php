<?php

namespace Shoutbox\Command\Task;

use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Runner\CommandTaskRunner;
use Concrete\Core\Command\Task\Runner\TaskRunnerInterface;
use Concrete\Core\Command\Task\TaskInterface;
use Shoutbox\Command\PurgeCommand;

defined('C5_EXECUTE') or die('Access Denied.');

class PurgeController extends AbstractController
{
    public function getName(): string
    {
        return t('Purge Shoutbox');
    }

    public function getDescription(): string
    {
        return t('Deletes shouts older than the retention set in Dashboard › Shoutbox and the bans that ran out.');
    }

    public function getConsoleCommandName(): string
    {
        return 'shoutbox-purge';
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new CommandTaskRunner($task, new PurgeCommand(), t('Shoutbox purged.'));
    }
}
