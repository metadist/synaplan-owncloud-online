<?php

declare(strict_types=1);

namespace OC\BackgroundJob;

use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\ILogger;

/**
 * Stub of ownCloud's private OC\BackgroundJob\Job, the base of the jobs
 * apps register. execute() marks the run and runs the job, as the server's.
 */
abstract class Job implements IJob
{
    /** @var int */
    protected $id;

    /** @var int */
    protected $lastRun;

    /** @var mixed */
    protected $argument;

    /** @var int */
    protected $lastChecked = 0;

    /** @var int */
    protected $reservedAt = 0;

    /** @var int */
    protected $executionDuration = 0;

    /**
     * @param IJobList $jobList
     */
    public function execute($jobList, ?ILogger $logger = null)
    {
        $jobList->setLastRun($this);
        $this->run($this->argument);
    }

    /**
     * @param mixed $argument
     */
    abstract protected function run($argument);

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setLastRun($lastRun)
    {
        $this->lastRun = $lastRun;
    }

    public function setArgument($argument)
    {
        $this->argument = $argument;
    }

    public function setLastChecked(int $lastChecked): void
    {
        $this->lastChecked = $lastChecked;
    }

    public function setReservedAt(int $reservedAt): void
    {
        $this->reservedAt = $reservedAt;
    }

    public function setExecutionDuration(int $executionDuration): void
    {
        $this->executionDuration = $executionDuration;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getLastRun()
    {
        return $this->lastRun;
    }

    public function getArgument()
    {
        return $this->argument;
    }

    public function getLastChecked(): int
    {
        return $this->lastChecked;
    }

    public function getReservedAt(): int
    {
        return $this->reservedAt;
    }

    public function getExecutionDuration(): int
    {
        return $this->executionDuration;
    }
}
