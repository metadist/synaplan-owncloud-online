<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCA\OcoSynaplan\Service\SourceEventQueue;

/**
 * The source event queue in memory, with the table's order and steps.
 *
 * @phpstan-import-type QueuedEvent from SourceEventQueue
 */
final class ArraySourceEventQueue extends SourceEventQueue
{
    /** @var array<int, QueuedEvent> */
    public array $rows = [];
    private int $seq = 0;

    public function __construct()
    {
    }

    public function add(string $uid, array $event, int $ord, string $step = self::STEP_SEND, int $walked = 0): void
    {
        $seq = ++$this->seq;
        $this->rows[$seq] = [
            'seq' => $seq,
            'ord' => $ord,
            'uid' => $uid,
            'file_id' => (int) $event['id'],
            'event' => $event['event'],
            'version' => $event['version'],
            'path' => $event['path'],
            'step' => $step,
            'walked' => $walked,
            'due' => 0,
            'attempts' => 0,
        ];
    }

    public function head(string $uid, int $limit): array
    {
        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['uid'] === $uid));
        usort($rows, static fn (array $a, array $b): int => [$a['ord'], $a['seq']] <=> [$b['ord'], $b['seq']]);

        return array_slice($rows, 0, $limit);
    }

    public function remove(array $seqs): void
    {
        foreach ($seqs as $seq) {
            unset($this->rows[$seq]);
        }
    }

    public function retryLater(array $seqs, int $attempts, int $due): void
    {
        foreach ($seqs as $seq) {
            $this->rows[$seq]['attempts'] = $attempts;
            $this->rows[$seq]['due'] = $due;
        }
    }

    /**
     * The steps of the rows waiting, oldest first, as `uid step file_id`.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return array_map(static fn (array $row): string => $row['uid'] . ' ' . $row['step'] . ' ' . $row['file_id'], $this->ordered());
    }

    /**
     * Each row ready to send as `uid`, then the event Synaplan gets, oldest
     * first.
     *
     * @return list<array{uid: string, id: string, event: string, version: string, path: string}>
     */
    public function events(): array
    {
        $rows = array_values(array_filter($this->ordered(), static fn (array $row): bool => $row['step'] === self::STEP_SEND));

        return array_map(static fn (array $row): array => [
            'uid' => $row['uid'],
            'id' => (string) $row['file_id'],
            'event' => $row['event'],
            'version' => $row['version'],
            'path' => $row['path'],
        ], $rows);
    }

    /**
     * @return list<QueuedEvent>
     */
    private function ordered(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (array $a, array $b): int => [$a['ord'], $a['seq']] <=> [$b['ord'], $b['seq']]);

        return $rows;
    }
}
