<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\BackgroundJob;

use OC\BackgroundJob\TimedJob;
use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Once this ownCloud.online is registered, moves the accounts provisioned
 * before the registration onto it (`02` §3.1), so that deleting, disabling
 * or pausing those people reaches Synaplan. Runs hourly until one pass leaves
 * nobody to try again, once for each registration.
 */
class AdoptIdentitiesJob extends TimedJob
{
    /** The registration whose earlier accounts were all adopted. */
    public const DONE = 'legacy_accounts_adopted';

    private IConfig $config;
    private SynaplanConfig $synaplanConfig;
    private UserAccountService $userAccounts;
    private IUserManager $users;

    /**
     * The server builds jobs in its own container, which looks for this app
     * by its namespace rather than its id: it cannot build the app's services
     * and passes the defaults instead, so they come from the app's container.
     */
    public function __construct(
        ?IConfig $config = null,
        ?SynaplanConfig $synaplanConfig = null,
        ?UserAccountService $userAccounts = null,
        ?IUserManager $users = null,
    ) {
        if ($config === null || $synaplanConfig === null || $userAccounts === null || $users === null) {
            $container = (new Application())->getContainer();
            $config = $container->query(IConfig::class);
            $synaplanConfig = $container->query(SynaplanConfig::class);
            $userAccounts = $container->query(UserAccountService::class);
            $users = $container->query(IUserManager::class);
        }
        $this->config = $config;
        $this->synaplanConfig = $synaplanConfig;
        $this->userAccounts = $userAccounts;
        $this->users = $users;
        $this->setInterval(3600);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        $instanceId = $this->synaplanConfig->getLinkInstanceId();
        if ($instanceId === '' || $this->synaplanConfig->getAdminApiKey() === '') {
            return;
        }
        if ($this->config->getAppValue(Application::APP_ID, self::DONE, '') === $instanceId) {
            return;
        }
        $clean = true;
        $this->users->callForAllUsers(function (IUser $user) use (&$clean): void {
            if (!$this->userAccounts->adoptLegacyAccount($user->getUID())) {
                $clean = false;
            }
        });
        if ($clean) {
            $this->config->setAppValue(Application::APP_ID, self::DONE, $instanceId);
        }
    }
}
