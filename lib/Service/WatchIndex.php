<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Result;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The watches of the people here, by target id, as Synaplan last listed
 * them, and the files that wait out their quiet period before they go to a
 * watch. Synaplan is the record: a person's rows are replaced by each list.
 *
 * `since` is when the app started feeding the watch: a file last changed
 * before it existed already and only goes in when the person asked for the
 * files already there (0).
 *
 * @phpstan-type WatchRow array{id: int, uid: string, kind: string, target: int, state: string, reason: string, since: int, reconciled: int}
 * @phpstan-type QueueRow array{watch: int, file: int, event: string, changed: int, generation: string, attempts: int}
 */
class WatchIndex
{
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const ATTENTION = 'attention';
    public const TARGET_GONE = 'target_gone';
    public const TABLE = 'synaplan_watches';
    public const QUEUE = 'synaplan_watch_queue';
    private const KINDS = ['folder', 'file'];

    private ?bool $ready = null;

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * A watch this person just started, before Synaplan lists it.
     *
     * @param array<mixed> $watch a watch as Synaplan answers it
     */
    public function remember(string $uid, array $watch, int $since): void
    {
        $row = self::parse($watch);
        if ($row === null || $uid === '') {
            return;
        }
        $this->upsert($uid, $row, $since, true);
    }

    /**
     * Synaplan's list of this person's watches replaces the person's rows.
     * A watch it no longer lists was stopped; its waiting files go too.
     *
     * @param list<mixed> $watches
     */
    public function replace(string $uid, array $watches, int $now): void
    {
        if ($uid === '') {
            return;
        }
        $listed = [];
        foreach ($watches as $watch) {
            $row = is_array($watch) ? self::parse($watch) : null;
            if ($row === null) {
                continue;
            }
            $listed[] = $row['id'];
            $this->upsert($uid, $row, $now, false);
        }
        foreach ($this->rowsOf($uid) as $known) {
            if (!in_array($known['id'], $listed, true)) {
                $this->drop($known['id']);
            }
        }
    }

    /**
     * The owner paused or resumed a watch here. A resumed watch gets its
     * next pass right away, for the files that arrived while it was paused.
     *
     * @param array<mixed> $watch a watch as Synaplan answers it
     */
    public function update(string $uid, array $watch, int $now): void
    {
        $row = self::parse($watch);
        if ($row === null || $uid === '') {
            return;
        }
        $this->upsert($uid, $row, $now, false);
        if ($row['state'] === self::ACTIVE) {
            $this->markReconciled($row['id'], 0);
        }
    }

    /**
     * Count only files that change from now on: the owner resumed the watch
     * without the files that arrived while it was paused, so the files
     * queued before now go too. Events of the target stay.
     */
    public function restartFrom(int $watchId, int $since): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('since', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->execute();
        $drop = $this->db->getQueryBuilder();
        $drop->delete(self::QUEUE)
            ->where($drop->expr()->eq('watch_id', $drop->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($drop->expr()->isNull('event'))
            ->andWhere($drop->expr()->lt('changed', $drop->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->execute();
    }

    /**
     * The owner stopped a watch here; its waiting files go too.
     */
    public function stop(int $watchId): void
    {
        $this->drop($watchId);
    }

    public function forget(string $uid): void
    {
        foreach ($this->rowsOf($uid) as $known) {
            $this->drop($known['id']);
        }
    }

    /**
     * Watches that take files arriving on any of these nodes: active ones,
     * and paused ones, which keep them until the owner resumes.
     *
     * @param list<int> $nodeIds
     * @return list<WatchRow>
     */
    public function queueingOn(array $nodeIds): array
    {
        return array_values(array_filter(
            $this->on($nodeIds),
            static fn (array $row): bool => $row['state'] === self::ACTIVE || $row['state'] === self::PAUSED,
        ));
    }

    /**
     * Watches in any state on any of these nodes.
     *
     * @param list<int> $nodeIds
     * @return list<WatchRow>
     */
    public function on(array $nodeIds): array
    {
        $nodeIds = array_values(array_unique(array_filter($nodeIds, static fn (int $id): bool => $id > 0)));
        if ($nodeIds === [] || !$this->ready()) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->in('target_id', $qb->createNamedParameter($nodeIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $this->fetchRows($qb);
    }

    /**
     * @return list<WatchRow>
     */
    public function active(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('state', $qb->createNamedParameter(self::ACTIVE)))
            ->orderBy('reconciled', 'ASC');

        return $this->fetchRows($qb);
    }

    /**
     * @return WatchRow|null
     */
    public function find(int $watchId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)));

        return $this->fetchRows($qb)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function uids(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('uid')->from(self::TABLE);
        $result = $this->query($qb);
        $uids = [];
        while (($row = $result->fetchAssociative()) !== false) {
            if (is_string($row['uid'] ?? null)) {
                $uids[] = $row['uid'];
            }
        }
        $result->free();

        return $uids;
    }

    public function markReconciled(int $watchId, int $now): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('reconciled', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->execute();
    }

    /**
     * Queue a file, or an event of the watch's target, as changed at
     * $changed. A file waits out the quiet period from then; an event goes
     * at once. Nothing goes before $due. A change already queued for the file
     * is replaced.
     */
    public function enqueue(int $watchId, int $fileId, string $event, int $changed, int $due = 0): void
    {
        if ($this->enqueueIfAbsent($watchId, $fileId, $event, $changed, $due)) {
            return;
        }
        $update = $this->db->getQueryBuilder();
        $update->update(self::QUEUE)
            ->set('event', $update->createNamedParameter($event !== '' ? $event : null))
            ->set('changed', $update->createNamedParameter($changed, IQueryBuilder::PARAM_INT))
            ->set('generation', $update->createNamedParameter(self::generation()))
            ->set('due', $update->createNamedParameter($due, IQueryBuilder::PARAM_INT))
            ->set('attempts', $update->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($update->expr()->eq('watch_id', $update->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($update->expr()->eq('file_id', $update->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->execute();
    }

    /**
     * Like enqueue(), but a change already queued for the file stays as it
     * is: it is at least as new. False when one was there.
     */
    public function enqueueIfAbsent(int $watchId, int $fileId, string $event, int $changed, int $due = 0): bool
    {
        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::QUEUE)->values([
            'watch_id' => $insert->createNamedParameter($watchId, IQueryBuilder::PARAM_INT),
            'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'event' => $insert->createNamedParameter($event !== '' ? $event : null),
            'changed' => $insert->createNamedParameter($changed, IQueryBuilder::PARAM_INT),
            'generation' => $insert->createNamedParameter(self::generation()),
            'due' => $insert->createNamedParameter($due, IQueryBuilder::PARAM_INT),
            'attempts' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
        ]);
        try {
            $insert->execute();

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * The files queued for a watch, events of its target left out: on a
     * paused watch, the files that arrived while it was paused.
     *
     * @return list<int>
     */
    public function queuedFiles(int $watchId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')->from(self::QUEUE)
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('event'));
        $result = $this->query($qb);
        $files = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $files[] = (int) ($row['file_id'] ?? 0);
        }
        $result->free();

        return $files;
    }

    public function queued(int $watchId, int $fileId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')->from(self::QUEUE)
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $this->query($qb);
        $found = $result->fetchOne();
        $result->free();

        return $found !== false;
    }

    /**
     * The rows to send now. Files queued for a watch that is not running
     * wait in the queue for it: a paused watch takes them on resume, or the
     * owner lets them go.
     *
     * @return list<QueueRow>
     */
    public function due(int $now, int $quietSeconds, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('q.*')->from(self::QUEUE, 'q')
            ->leftJoin('q', self::TABLE, 'w', $qb->expr()->eq('q.watch_id', 'w.watch_id'))
            ->where($qb->expr()->lte('q.due', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX()->addMultiple([
                $qb->expr()->isNotNull('q.event'),
                $qb->expr()->andX()->addMultiple([
                    $qb->expr()->lte('q.changed', $qb->createNamedParameter($now - $quietSeconds, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->orX()->addMultiple([
                        $qb->expr()->eq('w.state', $qb->createNamedParameter(self::ACTIVE)),
                        $qb->expr()->isNull('w.watch_id'),
                    ]),
                ]),
            ]))
            ->orderBy('q.changed', 'ASC')
            ->setMaxResults($limit);
        $result = $this->query($qb);
        $rows = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $rows[] = [
                'watch' => (int) ($row['watch_id'] ?? 0),
                'file' => (int) ($row['file_id'] ?? 0),
                'event' => is_string($row['event'] ?? null) ? $row['event'] : '',
                'changed' => (int) ($row['changed'] ?? 0),
                'generation' => is_string($row['generation'] ?? null) ? $row['generation'] : '',
                'attempts' => (int) ($row['attempts'] ?? 0),
            ];
        }
        $result->free();

        return $rows;
    }

    public function dequeue(int $watchId, int $fileId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::QUEUE)
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->execute();
    }

    /**
     * Take back a lifecycle event of a watch's target that has not gone out.
     */
    public function cancelEvent(int $watchId, int $fileId, string $event): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::QUEUE)
            ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('event', $qb->createNamedParameter($event)))
            ->execute();
    }

    /**
     * A row from due() was handled. A change queued while it was being sent
     * replaced the row and stays.
     *
     * @param QueueRow $row
     */
    public function done(array $row): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::QUEUE);
        $this->whereUnchanged($qb, $row);
        $qb->execute();
    }

    /**
     * Try a row from due() again at $due, unless a newer change replaced it.
     *
     * @param QueueRow $row
     */
    public function retryLater(array $row, int $due, int $attempts): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::QUEUE)
            ->set('due', $qb->createNamedParameter($due, IQueryBuilder::PARAM_INT))
            ->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT));
        $this->whereUnchanged($qb, $row);
        $qb->execute();
    }

    /**
     * The row as due() read it: each enqueue() gives the row a new
     * generation, so a change queued while it was being sent tells apart
     * even within the same second.
     *
     * @param QueueRow $row
     */
    private function whereUnchanged(IQueryBuilder $qb, array $row): void
    {
        $qb->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($row['watch'], IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($row['file'], IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($row['generation'])));
    }

    private static function generation(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<mixed> $watch
     * @return array{id: int, kind: string, target: int, state: string, reason: string}|null
     */
    public static function parse(array $watch): ?array
    {
        $id = $watch['id'] ?? null;
        $kind = $watch['target_kind'] ?? null;
        $target = $watch['target_id'] ?? null;
        $state = $watch['state'] ?? null;
        $reason = $watch['reason'] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (is_int($target)) {
            $target = (string) $target;
        }
        if (!is_int($id) || $id <= 0
            || !in_array($kind, self::KINDS, true)
            || !is_string($target) || !ctype_digit($target) || (int) $target <= 0
            || !is_string($state) || $state === '' || strlen($state) > 16) {
            return null;
        }

        $reason = is_string($reason) && strlen($reason) <= 32 ? $reason : '';

        return ['id' => $id, 'kind' => $kind, 'target' => (int) $target, 'state' => $state, 'reason' => $reason];
    }

    /**
     * Whether Synaplan holds this watch because its target is gone: only
     * then does it take a restored target back.
     *
     * @param array{state: string, reason: string} $watch
     */
    public static function targetGone(array $watch): bool
    {
        return $watch['state'] === self::ATTENTION && $watch['reason'] === self::TARGET_GONE;
    }

    /**
     * @param array{id: int, kind: string, target: int, state: string, reason: string} $row
     */
    private function upsert(string $uid, array $row, int $since, bool $replaceSince): void
    {
        $known = $this->find($row['id']);
        if ($known === null) {
            $insert = $this->db->getQueryBuilder();
            $insert->insert(self::TABLE)->values([
                'watch_id' => $insert->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT),
                'uid' => $insert->createNamedParameter($uid),
                'target_kind' => $insert->createNamedParameter($row['kind']),
                'target_id' => $insert->createNamedParameter($row['target'], IQueryBuilder::PARAM_INT),
                'state' => $insert->createNamedParameter($row['state']),
                'reason' => $insert->createNamedParameter($row['reason']),
                'since' => $insert->createNamedParameter($since, IQueryBuilder::PARAM_INT),
                'reconciled' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            ]);
            try {
                $insert->execute();

                return;
            } catch (UniqueConstraintViolationException) {
                // Noted by another request a moment ago: update it.
            }
        }
        $update = $this->db->getQueryBuilder();
        $update->update(self::TABLE)
            ->set('uid', $update->createNamedParameter($uid))
            ->set('target_kind', $update->createNamedParameter($row['kind']))
            ->set('target_id', $update->createNamedParameter($row['target'], IQueryBuilder::PARAM_INT))
            ->set('state', $update->createNamedParameter($row['state']))
            ->set('reason', $update->createNamedParameter($row['reason']))
            ->where($update->expr()->eq('watch_id', $update->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)));
        if ($replaceSince) {
            $update->set('since', $update->createNamedParameter($since, IQueryBuilder::PARAM_INT));
        }
        $update->execute();
    }

    private function drop(int $watchId): void
    {
        foreach ([self::TABLE, self::QUEUE] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->eq('watch_id', $qb->createNamedParameter($watchId, IQueryBuilder::PARAM_INT)))
                ->execute();
        }
    }

    /**
     * @return list<WatchRow>
     */
    public function rowsOf(string $uid): array
    {
        if (!$this->ready()) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

        return $this->fetchRows($qb);
    }

    /**
     * Node events arrive before the app's migration ran (an app updated in
     * place); matching then finds nothing instead of failing the write.
     */
    private function ready(): bool
    {
        return $this->ready ??= $this->db->tableExists(self::TABLE);
    }

    private function query(IQueryBuilder $qb): Result
    {
        $result = $qb->execute();
        if (!$result instanceof Result) {
            throw new \UnexpectedValueException('The database did not answer a query with rows');
        }

        return $result;
    }

    /**
     * @return list<WatchRow>
     */
    private function fetchRows(IQueryBuilder $qb): array
    {
        $result = $this->query($qb);
        $rows = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $rows[] = [
                'id' => (int) ($row['watch_id'] ?? 0),
                'uid' => (string) ($row['uid'] ?? ''),
                'kind' => (string) ($row['target_kind'] ?? ''),
                'target' => (int) ($row['target_id'] ?? 0),
                'state' => (string) ($row['state'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'since' => (int) ($row['since'] ?? 0),
                'reconciled' => (int) ($row['reconciled'] ?? 0),
            ];
        }
        $result->free();

        return $rows;
    }
}
