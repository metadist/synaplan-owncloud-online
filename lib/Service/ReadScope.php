<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * What this person lets Synaplan read. The app decides; Synaplan only keeps
 * a copy so a run can be refused before it asks.
 */
class ReadScope
{
    private const LOCK_WAIT_SECONDS = 10;
    private const CEILING_LOCK = Application::APP_ID . '/read-scope-ceiling';

    public const PREF = 'read_scope';
    public const PENDING = 'read_scope_pending';
    public const CAP = 'read_folder_cap';
    public const DEFAULT_CAP = 20;
    /** The widest choice the administrator lets people make. */
    public const CEILING = 'read_scope_ceiling';

    public const MODE_WATCHED = 'watched';
    public const MODE_ALLOWED = 'allowed';
    public const MODE_ALL = 'all';
    /** From the narrowest choice to the widest. */
    public const MODES = [self::MODE_WATCHED, self::MODE_ALLOWED, self::MODE_ALL];
    public const DEFAULT_CEILING = self::MODE_ALLOWED;

    /** @var array<string, ?string> */
    private array $resolved = [];

    /**
     * Inside a change or a read check: read the values saved now. ownCloud
     * keeps the settings as this request (or a cron run) first read them.
     */
    private bool $fresh = false;

    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private PlatformLinkService $links,
        private IRootFolder $rootFolder,
        private ILockingProvider $locks,
    ) {
    }

    /**
     * The person's setting as it applies, never wider than the ceiling.
     *
     * @return array{mode: string, folders: list<array{id: string, path: string}>}
     */
    public function get(string $uid): array
    {
        $scope = self::decode($this->savedChoice($uid));
        $scope['mode'] = $this->within($scope['mode']);

        return $scope;
    }

    public function cap(): int
    {
        $cap = (int) $this->appValue(self::CAP, (string) self::DEFAULT_CAP);

        return max(1, min(500, $cap));
    }

    public function ceiling(): string
    {
        $ceiling = $this->appValue(self::CEILING, self::DEFAULT_CEILING);

        return in_array($ceiling, self::MODES, true) ? $ceiling : self::DEFAULT_CEILING;
    }

    /**
     * Lowering the ceiling narrows the saved choice of everyone above it, so
     * raising it again widens nobody's without them choosing again. Synaplan
     * hears of each narrowed setting from the hourly job.
     *
     * No personal change runs meanwhile: one that began earlier is saved
     * before the sweep reads it, one that begins later sees the new ceiling.
     *
     * @throws LockedException when personal changes keep running
     */
    public function setCeiling(string $ceiling): void
    {
        if (!in_array($ceiling, self::MODES, true)) {
            return;
        }
        $this->acquire(self::CEILING_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->config->setAppValue(Application::APP_ID, self::CEILING, $ceiling);
            foreach ($this->saved() as $uid => $raw) {
                $scope = self::decode($raw);
                if (self::rank($scope['mode']) <= self::rank($ceiling)) {
                    continue;
                }
                $scope['mode'] = $ceiling;
                $this->config->setUserValue($uid, Application::APP_ID, self::PREF, (string) json_encode($scope, JSON_UNESCAPED_SLASHES));
                $this->config->setUserValue($uid, Application::APP_ID, self::PENDING, '1');
            }
        } finally {
            $this->locks->releaseLock(self::CEILING_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * The folder at this path, which the person named. `ceiling` when the
     * administrator lets nobody name folders, `cap` when the administrator's
     * limit is full, `folder` when the name cannot be used.
     *
     * @throws LockedException when a ceiling change or another change of theirs keeps running
     */
    public function allow(string $uid, string $path): ?string
    {
        return $this->changing($uid, fn (): ?string => $this->allowNow($uid, $path));
    }

    private function allowNow(string $uid, string $path): ?string
    {
        if (self::rank($this->ceiling()) < self::rank(self::MODE_ALLOWED)) {
            return 'ceiling';
        }
        $path = self::clean($path);
        if ($path === '') {
            return 'folder';
        }
        $scope = $this->get($uid);
        $before = $scope;
        // A folder that moved is listed where it is now, never under the
        // name a new folder took.
        foreach ($scope['folders'] as $i => $folder) {
            $now = $folder['id'] !== '' ? $this->currentPath($uid, $folder) : null;
            if ($now !== null) {
                $scope['folders'][$i]['path'] = $now;
            }
        }
        $id = $this->idAt($uid, $path);
        $at = $this->entryFor($uid, $scope['folders'], $id, $path);
        if ($at === null) {
            if (count($scope['folders']) >= $this->cap()) {
                return 'cap';
            }
            $scope['folders'][] = ['id' => $id, 'path' => $path];
        } elseif ($id !== '') {
            $scope['folders'][$at] = ['id' => $id, 'path' => $path];
        }
        // The folder menu promises this folder may be read now: under "Only
        // what I watch" that promise takes the mode switch too.
        if ($scope['mode'] === self::MODE_WATCHED) {
            $scope['mode'] = self::MODE_ALLOWED;
        }
        if ($scope !== $before) {
            $this->store($uid, $scope);
        }

        return null;
    }

    /**
     * The entry that stands for the folder at this path: the one saved with
     * its id, or one saved under this path that has no id or whose folder is
     * gone. An entry for a folder that now lives elsewhere keeps standing
     * for that folder.
     *
     * @param list<array{id: string, path: string}> $folders
     */
    private function entryFor(string $uid, array $folders, string $id, string $path): ?int
    {
        foreach ($folders as $i => $folder) {
            if ($id !== '' && $folder['id'] === $id) {
                return $i;
            }
        }
        foreach ($folders as $i => $folder) {
            if ($folder['path'] === $path && ($folder['id'] === '' || $this->currentPath($uid, $folder) === null)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: string, path: string}> $folders
     * @throws LockedException when a ceiling change or another change of theirs keeps running
     */
    public function save(string $uid, string $mode, array $folders): void
    {
        $this->changing($uid, fn () => $this->saveNow($uid, $mode, $folders));
    }

    /**
     * A folder the person kept stays the folder it was saved as, found or
     * not, whatever now sits at its path; a folder added here is the one at
     * its path now. An id the request carries only says which saved folder
     * it keeps.
     *
     * @param list<array{id: string, path: string}> $folders
     */
    private function saveNow(string $uid, string $mode, array $folders): void
    {
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_WATCHED;
        }
        $saved = $this->get($uid)['folders'];
        $kept = [];
        foreach ($folders as $folder) {
            $path = self::clean($folder['path']);
            if ($path === '') {
                continue;
            }
            $entry = self::savedEntry($saved, $folder['id'], $path) ?? ['id' => $this->idAt($uid, $path), 'path' => $path];
            if (in_array($entry, $kept, true)) {
                continue;
            }
            $kept[] = $entry;
            if (count($kept) >= $this->cap()) {
                break;
            }
        }
        $this->store($uid, ['mode' => $this->within($mode), 'folders' => $kept]);
    }

    /**
     * The saved entry a row of the settings page stands for: the page sends
     * a saved folder back with its id, and a folder saved without one, or
     * added on the page, without an id.
     *
     * @param list<array{id: string, path: string}> $saved
     * @return array{id: string, path: string}|null
     */
    private static function savedEntry(array $saved, string $id, string $path): ?array
    {
        foreach ($saved as $entry) {
            if ($id !== '' ? $entry['id'] === $id : $entry['id'] === '' && $entry['path'] === $path) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The person's own rule, not the copy Synaplan stored. A person who
     * chose nothing allowed nothing.
     *
     * "Only what I watch" names the folders a watch brings. This app has no
     * watches yet (they are a later step), so that choice allows nothing for
     * now: it fails closed and never falls back to the named folders. Naming
     * a folder — here or through the folder's own menu — is what opens a
     * folder in the meantime.
     *
     * Each check reads the setting and the ceiling as saved now, and finds
     * the allowed folders again: ownCloud keeps settings as the process
     * first read them, and a background run sends files for minutes.
     */
    public function mayRead(string $uid, string $folder): bool
    {
        $fresh = $this->fresh;
        $this->fresh = true;
        $this->resolved = [];
        try {
            return $this->mayReadNow($uid, $folder);
        } finally {
            $this->fresh = $fresh;
        }
    }

    private function mayReadNow(string $uid, string $folder): bool
    {
        $scope = $this->get($uid);
        if ($scope['mode'] === self::MODE_ALL) {
            return true;
        }
        if ($scope['mode'] !== self::MODE_ALLOWED) {
            return false;
        }
        $folder = self::clean($folder);
        foreach ($scope['folders'] as $allowed) {
            $path = $this->currentPath($uid, $allowed);
            if ($path === null) {
                continue;
            }
            if ($folder === $path || str_starts_with($folder . '/', $path . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function clean(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return $path;
    }

    /**
     * Send the saved setting again after a report failed or the ceiling
     * narrowed it. True when Synaplan holds it now.
     *
     * The hourly job calls this from a cron run, and ownCloud keeps a shared
     * lock until the process ends, which can be the rest of the run: with the
     * shared ceiling lock no administrator could change the ceiling until
     * then. So this holds only the person's lock, and a ceiling change that
     * narrowed the choice while it was sent leaves it pending.
     *
     * @throws LockedException when another change of theirs keeps running
     */
    public function republish(string $uid): bool
    {
        return $this->alone($uid, fn (): bool => $this->republishNow($uid));
    }

    private function republishNow(string $uid): bool
    {
        $sent = $this->savedChoice($uid);
        if (!$this->report($uid, $this->get($uid))) {
            return false;
        }
        $this->config->deleteUserValue($uid, Application::APP_ID, self::PENDING);
        // A sweep writes the choice before it marks it pending, so a choice
        // it narrowed after this read shows here or keeps its mark.
        if ($this->savedChoice($uid) !== $sent) {
            $this->config->setUserValue($uid, Application::APP_ID, self::PENDING, '1');

            return false;
        }

        return true;
    }

    /**
     * Bring the saved paths up to date after folders moved. Synaplan refuses
     * a run, and names folders to its planner, from the paths in its copy,
     * so a moved allowed folder is reported again. True when a path changed.
     * A folder that cannot be found (deleted, in the trash) keeps its path.
     *
     * @throws LockedException when a ceiling change or another change of theirs keeps running
     */
    public function follow(string $uid): bool
    {
        return $this->changing($uid, fn (): bool => $this->followNow($uid));
    }

    private function followNow(string $uid): bool
    {
        $scope = $this->get($uid);
        if ($scope['folders'] === []) {
            return false;
        }
        $moved = false;
        foreach ($scope['folders'] as $i => $folder) {
            if ($folder['id'] === '') {
                continue;
            }
            $path = $this->currentPath($uid, $folder);
            if ($path !== null && $path !== $folder['path']) {
                $scope['folders'][$i]['path'] = $path;
                $moved = true;
            }
        }
        if ($moved) {
            $this->store($uid, $scope);
        }

        return $moved;
    }

    /**
     * Run a change of the person's choice and its report to Synaplan after
     * any ceiling change under way, and keep the next one from starting
     * until it is done.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     * @throws LockedException when a ceiling change or another change of theirs keeps running
     */
    private function changing(string $uid, callable $change): mixed
    {
        $this->acquire(self::CEILING_LOCK, ILockingProvider::LOCK_SHARED);
        try {
            return $this->alone($uid, $change);
        } finally {
            $this->locks->releaseLock(self::CEILING_LOCK, ILockingProvider::LOCK_SHARED);
        }
    }

    /**
     * Run it after any other change of theirs, on the values saved now
     * rather than those this request read earlier.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     * @throws LockedException when another change of theirs keeps running
     */
    private function alone(string $uid, callable $change): mixed
    {
        // ownCloud's database lock table holds keys of up to 64 characters.
        $person = Application::APP_ID . '/read-scope/' . md5($uid);
        $this->acquire($person, ILockingProvider::LOCK_EXCLUSIVE);
        $fresh = $this->fresh;
        $this->fresh = true;
        $this->resolved = [];
        try {
            return $change();
        } finally {
            $this->fresh = $fresh;
            $this->locks->releaseLock($person, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * @param ILockingProvider::LOCK_* $type
     * @throws LockedException when the lock stays taken
     */
    private function acquire(string $lock, int $type): void
    {
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (true) {
            try {
                $this->locks->acquireLock($lock, $type);

                return;
            } catch (LockedException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(100_000);
            }
        }
    }

    private function appValue(string $key, string $default): string
    {
        if (!$this->fresh) {
            return (string) $this->config->getAppValue(Application::APP_ID, $key, $default);
        }
        $query = $this->db->getQueryBuilder();
        $query->select('configvalue')
            ->from('appconfig')
            ->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter($key)));

        return self::first($query) ?? $default;
    }

    private function savedChoice(string $uid): string
    {
        if (!$this->fresh) {
            return (string) $this->config->getUserValue($uid, Application::APP_ID, self::PREF, '');
        }
        $query = $this->db->getQueryBuilder();
        $query->select('configvalue')
            ->from('preferences')
            ->where($query->expr()->eq('userid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter(self::PREF)));

        return self::first($query) ?? '';
    }

    private static function first(IQueryBuilder $query): ?string
    {
        $result = $query->execute();
        if (!$result instanceof Result) {
            return null;
        }
        $value = $result->fetchOne();
        $result->free();

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{mode: string, folders: list<array{id: string, path: string}>}
     */
    private static function decode(string $raw): array
    {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $mode = is_array($decoded) ? (string) ($decoded['mode'] ?? '') : '';
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_WATCHED;
        }
        $folders = [];
        foreach (is_array($decoded['folders'] ?? null) ? $decoded['folders'] : [] as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $path = self::clean((string) ($folder['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $folders[] = ['id' => (string) ($folder['id'] ?? ''), 'path' => $path];
        }

        return ['mode' => $mode, 'folders' => $folders];
    }

    private static function rank(string $mode): int
    {
        return (int) array_search($mode, self::MODES, true);
    }

    private function within(string $mode): string
    {
        $ceiling = $this->ceiling();

        return self::rank($mode) > self::rank($ceiling) ? $ceiling : $mode;
    }

    /**
     * Every saved setting, by person. ownCloud.online reads a person's
     * settings one person at a time, so the table is read directly.
     *
     * @return array<string, string>
     */
    private function saved(): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select(['userid', 'configvalue'])
            ->from('preferences')
            ->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter(self::PREF)));
        $result = $query->execute();
        $saved = [];
        if ($result instanceof Result) {
            while (($row = $result->fetchAssociative()) !== false) {
                $saved[(string) $row['userid']] = (string) $row['configvalue'];
            }
            $result->free();
        }

        return $saved;
    }

    /**
     * Where an allowed folder is now. The person allowed a folder, not a
     * name: a folder moved or renamed keeps its permission, and a new folder
     * at the old name does not get it. An entry saved without an id is
     * matched by its path.
     *
     * @param array{id: string, path: string} $allowed
     */
    private function currentPath(string $uid, array $allowed): ?string
    {
        if ($allowed['id'] === '') {
            return $allowed['path'];
        }
        $key = $uid . "\0" . $allowed['id'];
        if (!array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = null;
            try {
                $userFolder = $this->rootFolder->getUserFolder($uid);
                foreach ($userFolder->getById((int) $allowed['id']) as $node) {
                    if ($node instanceof Folder) {
                        $path = self::clean((string) $userFolder->getRelativePath($node->getPath()));
                        $this->resolved[$key] = $path !== '' ? $path : null;
                        break;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->resolved[$key];
    }

    /**
     * The id of the folder at this path as this ownCloud.online knows it, or
     * '' when no folder is there.
     */
    private function idAt(string $uid, string $path): string
    {
        try {
            $node = $this->rootFolder->getUserFolder($uid)->get($path);
        } catch (\Throwable) {
            return '';
        }

        return $node instanceof Folder ? (string) $node->getId() : '';
    }

    /**
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    private function store(string $uid, array $scope): void
    {
        $this->resolved = [];
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::PREF,
            (string) json_encode($scope, JSON_UNESCAPED_SLASHES),
        );
        if ($this->report($uid, $scope)) {
            $this->config->deleteUserValue($uid, Application::APP_ID, self::PENDING);
        } else {
            // The setting here is what a read checks, but Synaplan refuses a
            // run from its copy before it asks. Keep the debt where the hourly
            // job finds it instead of dropping it.
            $this->config->setUserValue($uid, Application::APP_ID, self::PENDING, '1');
        }
    }

    /**
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    private function report(string $uid, array $scope): bool
    {
        // Synaplan opens a run for any folder in its copy, whatever the
        // choice, so the list kept for "Also folders I allow" goes out only
        // with that choice.
        $folders = $scope['mode'] === self::MODE_ALLOWED ? $scope['folders'] : [];
        try {
            return $this->links->reportReadScope($uid, $scope['mode'], $folders);
        } catch (\Throwable) {
            return false;
        }
    }
}
