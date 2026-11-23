<?php

declare(strict_types=1);

namespace OC\BackgroundJob;

use OCP\BackgroundJob\IJobList;
use OCP\ILogger;

/**
 * Stub of ownCloud's private OC\BackgroundJob\QueuedJob: a job that runs
 * once, removed from the list before it runs, as the server's.
 */
abstract class QueuedJob extends Job
{
    /**
     * @param IJobList $jobList
     */
    public function execute($jobList, ?ILogger $logger = null)
    {
        $jobList->remove($this, $this->argument);
        parent::execute($jobList, $logger);
    }
}
