<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserManager;
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
        private IUserMountCache $mounts,
        private IUserManager $users,
        private SourceEventQueue $queue,
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

    /**
     * Whether a person who can tell Synaplan has no way left to the node:
     * the share API still names them for a share they removed or declined,
     * so only their own files, which leave it out, say so.
     */
    public function lost(Node $node, string $uid): bool
    {
        if ($uid === '' || !$this->accounts->holdsCredential($uid)) {
            return false;
        }
        try {
            return $this->rootFolder->getUserFolder($uid)->getById((int) $node->getId()) === [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The file cache paths of the folders below a folder that a person still
     * has mounted on their own, such as a share of a subfolder. The mount
     * cache knows a mount's root by its id, the file cache knows its path.
     *
     * @param array{storage: int, path: string} $folder
     * @return list<string>
     */
    public function keptBelow(string $uid, array $folder): array
    {
        $user = $this->users->get($uid);
        if ($user === null) {
            return [];
        }
        $prefix = $folder['path'] === '' ? '' : $folder['path'] . '/';
        $kept = [];
        foreach ($this->mounts->getMountsForUser($user) as $mount) {
            if ((int) $mount->getStorageId() !== $folder['storage']) {
                continue;
            }
            $root = $this->queue->location((int) $mount->getRootId());
            if ($root !== null && $root['path'] !== $folder['path'] && str_starts_with($root['path'], $prefix)) {
                $kept[] = $root['path'];
            }
        }

        return $kept;
    }

    /**
     * The people who can tell Synaplan and have a folder or file below a
     * folder mounted on its own, such as a share of a subfolder: the share
     * API names only those who reach the folder itself. The mount cache
     * knows a mount's root by its id, the file cache whether it is below.
     *
     * @param array{storage: int, path: string} $folder
     * @param list<string> $except the people already told
     * @return list<array{uid: string, root: int}>
     */
    public function mountedBelow(array $folder, array $except): array
    {
        $mounts = [];
        try {
            foreach ($this->mounts->getMountsForStorageId($folder['storage']) as $mount) {
                $uid = (string) $mount->getUser()->getUID();
                if ($uid !== '' && !in_array($uid, $except, true)) {
                    $mounts[$uid . '/' . $mount->getRootId()] = ['uid' => $uid, 'root' => (int) $mount->getRootId()];
                }
            }
            $below = $mounts === [] ? [] : $this->queue->within($folder, array_values(array_unique(array_column($mounts, 'root'))));
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $mounts,
            fn (array $mount): bool => in_array($mount['root'], $below, true) && $this->accounts->holdsCredential($mount['uid']),
        ));
    }
}
