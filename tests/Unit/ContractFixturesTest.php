<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The cloud-apps contract fixtures are Synaplan's files, byte for byte.
 * scripts/vendor-contract.sh copies them from the commit the pinned image was
 * built from and writes the checksums; nobody edits them here.
 */
final class ContractFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private const SOURCE = '#^\# metadist/synaplan [0-9a-f]{40} backend/tests/Fixtures/cloud-apps-contract$#';

    public function testTheManifestNamesTheSynaplanCommit(): void
    {
        $lines = $this->manifest();

        self::assertMatchesRegularExpression(self::SOURCE, $lines[0] ?? '');
    }

    public function testEveryVendoredFileIsListedAndUnchanged(): void
    {
        $listed = [];
        foreach (array_slice($this->manifest(), 1) as $line) {
            self::assertSame(1, preg_match('#^([0-9a-f]{64})  (synaplan-contract/[^/]+)$#', $line, $match), "Not a checksum line: $line");
            $listed[$match[2]] = $match[1];
        }

        $present = array_map(
            static fn (string $path): string => 'synaplan-contract/' . basename($path),
            glob(self::FIXTURES . '/synaplan-contract/*') ?: [],
        );
        self::assertEqualsCanonicalizing(array_keys($listed), $present);
        foreach ($listed as $file => $sum) {
            self::assertSame($sum, hash_file('sha256', self::FIXTURES . '/' . $file), "$file differs from Synaplan's.");
        }
    }

    public function testTheContractHasTheFilesThisAppReads(): void
    {
        $paths = json_decode((string) file_get_contents(self::FIXTURES . '/synaplan-contract/paths.json'), true);
        self::assertIsArray($paths);
        self::assertContains('owncloud', $paths['apps'] ?? []);
        self::assertIsArray($paths['paths'] ?? null);

        $vectors = json_decode((string) file_get_contents(self::FIXTURES . '/synaplan-contract/notify-vectors.json'), true);
        self::assertIsArray($vectors);
        self::assertIsArray($vectors['vectors'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function manifest(): array
    {
        $lines = file(self::FIXTURES . '/synaplan-contract.sha256', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
