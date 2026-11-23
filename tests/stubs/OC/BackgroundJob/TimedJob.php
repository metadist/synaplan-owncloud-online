<?php

declare(strict_types=1);

namespace OC\BackgroundJob;

use OCP\BackgroundJob\IJobList;
use OCP\ILogger;

/**
 * Stub of ownCloud's private OC\BackgroundJob\TimedJob: a job that runs again
 * once its interval has passed since the last run, as the server's.
 */
abstract class TimedJob extends Job
{
    /** @var int */
    protected $interval = 0;

    /**
     * @param int $interval seconds
     */
    public function setInterval($interval)
    {
        $this->interval = $interval;
    }

    /**
     * @param IJobList $jobList
     */
    public function execute($jobList, ?ILogger $logger = null)
    {
        if ((time() - (int) $this->lastRun) > $this->interval) {
            parent::execute($jobList, $logger);
        }
    }
}
