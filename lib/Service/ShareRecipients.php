<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share;

/**
 * The people a file or folder is shared with, directly, through a group or
 * through a folder around it, who can tell Synaplan of a change to their
 * copies: Synaplan applies a source event to the copies of the person who
 * reports it.
 */
class ShareRecipients
{
    /** @var \Closure(string, string): mixed */
    private \Closure $sharing;

    /**
     * @param (\Closure(string, string): mixed)|null $sharing who can reach a path below an owner's
     *                                                        files; ownCloud.online's share API
     */
    public function __construct(
        private UserAccountService $accounts,
        private IRootFolder $rootFolder,
        ?\Closure $sharing = null,
    ) {
        // ownCloud.online's IManager::getAccessList() is empty.
        $this->sharing = $sharing ?? static fn (string $path, string $owner): mixed => Share::getUsersSharingFile($path, $owner);
    }

    /**
     * @param list<string> $except the people already told
     * @return list<string>
     */
    public function of(Node $node, array $except): array
    {
        try {
            // A storage without an owner answers null despite the signature.
            $owner = (string) $node->getOwner()->getUID();
            $files = '/' . $owner . '/files/';
            $theirs = $owner === '' ? null : ($this->rootFolder->getUserFolder($owner)->getById((int) $node->getId())[0] ?? null);
            if (!$theirs instanceof Node || !str_starts_with($theirs->getPath(), $files)) {
                return [];
            }
            $access = ($this->sharing)(substr($theirs->getPath(), strlen($files)), $owner);
        } catch (\Throwable) {
            return [];
        }
        $users = is_array($access) && is_array($access['users'] ?? null) ? $access['users'] : [];
        $out = [];
        foreach (array_unique(array_map(static fn (mixed $uid): string => is_scalar($uid) ? (string) $uid : '', $users)) as $uid) {
            if ($uid !== '' && !in_array($uid, $except, true) && $this->accounts->holdsCredential($uid)) {
                $out[] = $uid;
            }
        }

        return $out;
    }
}
