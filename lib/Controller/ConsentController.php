<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Controller;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Exception\EmailConflictException;
use OCA\OcoSynaplan\Http\Failure;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Per-user "Use Synaplan" consent.
 *
 * In the per-user modes, using AI provisions or links a personal account on
 * the Synaplan server for the ownCloud.online user. We do not create that
 * external account silently — the person agrees once.
 */
class ConsentController extends Controller
{
    public function __construct(
        IRequest $request,
        private UserAccountService $userAccounts,
        private SynaplanConfig $synaplanConfig,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private ILogger $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Current consent state for the logged-in user, with what the consent
     * card offers: the mode, whether connecting is possible, the host files
     * go to, and the address people open Synaplan at.
     *
     * @NoAdminRequired
     */
    public function getConsent(): JSONResponse
    {
        $public = $this->synaplanConfig->getPublicBaseUrl();

        return new JSONResponse([
            'required' => $this->userAccounts->consentRequired(),
            'granted' => $this->userAccounts->hasConsentForCurrentUser(),
            'host' => (string) (parse_url($public, PHP_URL_HOST) ?: ''),
            'url' => $public,
        ] + $this->userAccounts->getLinkStatus());
    }

    /**
     * Grant or withdraw consent. In link mode with automatic accounts the
     * person may ask for an account to be created for them.
     *
     * @NoAdminRequired
     */
    public function setConsent(bool $granted = false, bool $createAccount = false): JSONResponse
    {
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && array_key_exists('granted', $decoded)) {
                $granted = (bool) $decoded['granted'];
            }
            if (is_array($decoded) && array_key_exists('create_account', $decoded)) {
                $createAccount = (bool) $decoded['create_account'];
            }
        }

        if ($granted) {
            if ($createAccount && $this->synaplanConfig->isAutoProvisionEnabled()) {
                $user = $this->userSession->getUser();
                if ($user instanceof IUser) {
                    try {
                        $this->userAccounts->provisionForLinkMode($user);
                    } catch (EmailConflictException $e) {
                        if ($this->synaplanConfig->isLinkAvailable()) {
                            return new JSONResponse([
                                'success' => false,
                                'conflict' => true,
                                'link_url' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.link.start'),
                            ]);
                        }

                        return (new Failure(Http::STATUS_CONFLICT, Failure::EMAIL_ALREADY_USED))->toResponse();
                    } catch (\Throwable $e) {
                        $failure = Failure::fromThrowable($e);
                        $this->logger->error('Creating a Synaplan account failed: {message}', [
                            'app' => Application::APP_ID,
                            'message' => $e->getMessage(),
                            'code' => $failure->code,
                        ]);

                        return $failure->toResponse();
                    }
                }
            }
            $this->userAccounts->grantConsent();
        } else {
            $this->userAccounts->revokeConsent();
        }

        return new JSONResponse([
            'success' => true,
            'required' => $this->userAccounts->consentRequired(),
            'granted' => $this->userAccounts->hasConsentForCurrentUser(),
        ]);
    }
}
