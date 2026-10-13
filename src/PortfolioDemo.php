<?php
declare(strict_types=1);

namespace PortfolioDemo;

use RuntimeException;

final class ContractViolation extends RuntimeException
{
}

final class CompositionConflict extends RuntimeException
{
}

final class PortfolioCatalog
{
    /** @var array<int, array{id:int,status:string,title:string,summary:string,terms:list<string>}> */
    private array $recordsById = [];

    /**
     * @param list<array<string, mixed>> $records
     */
    public function __construct(array $records)
    {
        foreach ($records as $index => $record) {
            $validated = self::validateRecord($record, "records[{$index}]");
            if (isset($this->recordsById[$validated['id']])) {
                throw new ContractViolation("Duplicate record id {$validated['id']}.");
            }
            $this->recordsById[$validated['id']] = $validated;
        }
    }

    /**
     * Selects explicitly requested records in editorial order.
     * Requested non-public or missing records cause the whole selection to fail.
     *
     * @param list<int> $ids
     * @return list<array{id:int,status:string,title:string,summary:string,terms:list<string>}>
     */
    public function selectPublished(array $ids, ?string $taxonomy = null): array
    {
        self::assertListOfIntegers($ids, 'ids');
        if ($taxonomy !== null && ($taxonomy === '' || trim($taxonomy) !== $taxonomy)) {
            throw new ContractViolation('taxonomy must be null or a non-empty trimmed string.');
        }

        $seen = [];
        $selected = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                throw new ContractViolation("Duplicate requested id {$id}.");
            }
            $seen[$id] = true;

            if (!isset($this->recordsById[$id])) {
                throw new ContractViolation("Requested record {$id} does not exist.");
            }
            $record = $this->recordsById[$id];
            if ($record['status'] !== 'publish') {
                throw new ContractViolation("Requested record {$id} is not published.");
            }
            if ($taxonomy === null || in_array($taxonomy, $record['terms'], true)) {
                $selected[] = $record;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{id:int,status:string,title:string,summary:string,terms:list<string>}
     */
    private static function validateRecord(array $record, string $path): array
    {
        self::assertExactKeys($record, ['id', 'status', 'title', 'summary', 'terms'], $path);
        if (!is_int($record['id']) || $record['id'] <= 0) {
            throw new ContractViolation("{$path}.id must be a positive integer.");
        }
        if (!is_string($record['status']) || !in_array($record['status'], ['publish', 'draft', 'private'], true)) {
            throw new ContractViolation("{$path}.status must be publish, draft, or private.");
        }
        foreach (['title', 'summary'] as $field) {
            if (!is_string($record[$field])) {
                throw new ContractViolation("{$path}.{$field} must be a string.");
            }
        }
        if (!is_array($record['terms'])) {
            throw new ContractViolation("{$path}.terms must be a list of strings.");
        }
        $terms = array_values($record['terms']);
        foreach ($terms as $term) {
            if (!is_string($term) || $term === '' || trim($term) !== $term) {
                throw new ContractViolation("{$path}.terms must contain non-empty trimmed strings.");
            }
        }
        if (count(array_unique($terms)) !== count($terms)) {
            throw new ContractViolation("{$path}.terms must not contain duplicates.");
        }

        return [
            'id' => $record['id'],
            'status' => $record['status'],
            'title' => $record['title'],
            'summary' => $record['summary'],
            'terms' => $terms,
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    public static function assertExactKeys(array $value, array $keys, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            $unknown = array_values(array_diff($actual, $expected));
            $missing = array_values(array_diff($expected, $actual));
            if ($unknown !== []) {
                throw new ContractViolation("Unsupported attribute at {$path}: " . implode(', ', $unknown) . '.');
            }
            throw new ContractViolation("Missing attribute at {$path}: " . implode(', ', $missing) . '.');
        }
    }

    /** @param array<mixed> $values */
    public static function assertListOfIntegers(array $values, string $path): void
    {
        if (!array_is_list($values)) {
            throw new ContractViolation("{$path} must be a list of positive integers.");
        }
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new ContractViolation("{$path} must be a list of positive integers.");
            }
        }
    }
}

final class PortfolioRenderer
{
    public function __construct(private readonly PortfolioCatalog $catalog)
    {
    }

    /** @param array<string, mixed> $block */
    public function render(array $block): string
    {
        $validated = CompositionContract::validateBlock($block, 'block');
        $records = $this->catalog->selectPublished($validated['ids'], $validated['taxonomy']);

        $html = '<section class="portfolio-collection">';
        $html .= '<h2>' . self::escape($validated['heading']) . '</h2><ul>';
        foreach ($records as $record) {
            $html .= '<li data-record-id="' . $record['id'] . '">';
            $html .= '<h3>' . self::escape($record['title']) . '</h3>';
            $html .= '<p>' . self::escape($record['summary']) . '</p>';
            $html .= '</li>';
        }
        return $html . '</ul></section>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

final class CompositionContract
{
    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>
     */
    public static function validateBlocks(array $blocks): array
    {
        if (!array_is_list($blocks)) {
            throw new ContractViolation('blocks must be a list.');
        }
        $validated = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                throw new ContractViolation("blocks[{$index}] must be an object-like array.");
            }
            $validated[] = self::validateBlock($block, "blocks[{$index}]");
        }
        return $validated;
    }

    /**
     * @param array<string, mixed> $block
     * @return array{block:string,ids:list<int>,taxonomy:?string,heading:string}
     */
    public static function validateBlock(array $block, string $path): array
    {
        PortfolioCatalog::assertExactKeys($block, ['block', 'ids', 'taxonomy', 'heading'], $path);
        if (!is_string($block['block']) || $block['block'] !== 'portfolio/collection') {
            $value = is_scalar($block['block']) ? (string) $block['block'] : get_debug_type($block['block']);
            throw new ContractViolation("Unsupported block at {$path}: {$value}.");
        }
        if (!is_array($block['ids'])) {
            throw new ContractViolation("{$path}.ids must be a list of positive integers.");
        }
        PortfolioCatalog::assertListOfIntegers($block['ids'], "{$path}.ids");
        if (count(array_unique($block['ids'])) !== count($block['ids'])) {
            throw new ContractViolation("{$path}.ids must not contain duplicates.");
        }
        if (!is_null($block['taxonomy']) && (!is_string($block['taxonomy']) || $block['taxonomy'] === '' || trim($block['taxonomy']) !== $block['taxonomy'])) {
            throw new ContractViolation("{$path}.taxonomy must be null or a non-empty trimmed string.");
        }
        if (!is_string($block['heading'])) {
            throw new ContractViolation("{$path}.heading must be a string.");
        }

        return [
            'block' => $block['block'],
            'ids' => array_values($block['ids']),
            'taxonomy' => $block['taxonomy'],
            'heading' => $block['heading'],
        ];
    }
}

final class CompositionStore
{
    /** @var array{version:int,blocks:list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>} */
    private array $current;

    /** @var list<array{snapshot_id:string,source_version:int,blocks:list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>}> */
    private array $snapshots = [];

    /** @param array<string, mixed> $initial */
    public function __construct(array $initial, private readonly PortfolioCatalog $catalog)
    {
        PortfolioCatalog::assertExactKeys($initial, ['version', 'blocks'], 'composition');
        if (!is_int($initial['version']) || $initial['version'] < 1) {
            throw new ContractViolation('composition.version must be a positive integer.');
        }
        if (!is_array($initial['blocks'])) {
            throw new ContractViolation('composition.blocks must be a list.');
        }
        $this->current = [
            'version' => $initial['version'],
            'blocks' => $this->validateResolvableBlocks($initial['blocks']),
        ];
    }

    /** @return array{version:int,blocks:list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>} */
    public function current(): array
    {
        return $this->current;
    }

    /** @return list<array{snapshot_id:string,source_version:int,blocks:list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>}> */
    public function snapshots(): array
    {
        return $this->snapshots;
    }

    /**
     * @param list<array<string, mixed>> $replacementBlocks
     * @return array{version:int,blocks:list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>}
     */
    public function migrate(int $expectedPredecessor, int $newVersion, array $replacementBlocks): array
    {
        $validated = $this->validateResolvableBlocks($replacementBlocks);
        $this->assertVersionTransition($expectedPredecessor, $newVersion);

        $snapshot = [
            'snapshot_id' => 'snapshot-v' . $this->current['version'] . '-' . (count($this->snapshots) + 1),
            'source_version' => $this->current['version'],
            'blocks' => $this->current['blocks'],
        ];
        $next = ['version' => $newVersion, 'blocks' => $validated];

        $this->snapshots[] = $snapshot;
        $this->current = $next;
        return $this->current;
    }

    /**
     * @return array{version:int,blocks:list<array{block:string,ids:list<int>,taxonomy:?string,heading:string}>}
     */
    public function restoreSnapshot(string $snapshotId, int $expectedCurrentVersion, int $newVersion): array
    {
        $snapshot = null;
        foreach ($this->snapshots as $candidate) {
            if ($candidate['snapshot_id'] === $snapshotId) {
                $snapshot = $candidate;
                break;
            }
        }
        if ($snapshot === null) {
            throw new ContractViolation("Snapshot {$snapshotId} does not exist.");
        }
        $validated = $this->validateResolvableBlocks($snapshot['blocks']);
        $this->assertVersionTransition($expectedCurrentVersion, $newVersion);

        $recoverySnapshot = [
            'snapshot_id' => 'snapshot-v' . $this->current['version'] . '-' . (count($this->snapshots) + 1),
            'source_version' => $this->current['version'],
            'blocks' => $this->current['blocks'],
        ];
        $next = ['version' => $newVersion, 'blocks' => $validated];

        $this->snapshots[] = $recoverySnapshot;
        $this->current = $next;
        return $this->current;
    }

    private function validateResolvableBlocks(array $blocks): array
    {
        $validated = CompositionContract::validateBlocks($blocks);
        foreach ($validated as $block) {
            $this->catalog->selectPublished($block['ids'], $block['taxonomy']);
        }
        return $validated;
    }

    private function assertVersionTransition(int $expectedPredecessor, int $newVersion): void
    {
        if ($expectedPredecessor < 1 || $newVersion < 1) {
            throw new ContractViolation('Versions must be positive integers.');
        }
        if ($this->current['version'] !== $expectedPredecessor) {
            throw new CompositionConflict(
                "Expected predecessor version {$expectedPredecessor}, but current version is {$this->current['version']}; refusing stale write."
            );
        }
        if ($newVersion <= $expectedPredecessor) {
            throw new ContractViolation('newVersion must be greater than expectedPredecessor.');
        }
    }
}
