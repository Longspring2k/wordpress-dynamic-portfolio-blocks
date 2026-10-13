<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PortfolioDemo.php';

use PortfolioDemo\CompositionConflict;
use PortfolioDemo\CompositionStore;
use PortfolioDemo\ContractViolation;
use PortfolioDemo\PortfolioCatalog;
use PortfolioDemo\PortfolioRenderer;

$tests = [];

$test = static function (string $name, callable $case) use (&$tests): void {
    $tests[$name] = $case;
};

$assertSame = static function (mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(($message === '' ? 'Values differ.' : $message) . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
};

$assertThrows = static function (string $class, callable $operation, string $messageContains = ''): Throwable {
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw new RuntimeException("Expected {$class}, got " . $error::class . ': ' . $error->getMessage());
        }
        if ($messageContains !== '' && !str_contains($error->getMessage(), $messageContains)) {
            throw new RuntimeException("Exception message did not contain '{$messageContains}': " . $error->getMessage());
        }
        return $error;
    }
    throw new RuntimeException("Expected {$class}, but no exception was thrown.");
};

$records = static fn (): array => [
    ['id' => 101, 'status' => 'publish', 'title' => 'Forest Survey', 'summary' => 'Field notes', 'terms' => ['research', 'mapping']],
    ['id' => 102, 'status' => 'draft', 'title' => 'Draft Study', 'summary' => 'Not public', 'terms' => ['research']],
    ['id' => 103, 'status' => 'private', 'title' => 'Private Study', 'summary' => 'Restricted', 'terms' => ['research']],
    ['id' => 104, 'status' => 'publish', 'title' => 'River Atlas', 'summary' => 'Open data', 'terms' => ['mapping']],
];

$test('selection preserves requested editorial order for published records', static function () use ($records, $assertSame): void {
    $catalog = new PortfolioCatalog($records());
    $selected = $catalog->selectPublished([104, 101]);
    $assertSame([104, 101], array_column($selected, 'id'));
});

$test('selection fails closed when a requested record is draft or private', static function () use ($records, $assertThrows): void {
    $catalog = new PortfolioCatalog($records());
    $assertThrows(ContractViolation::class, static fn () => $catalog->selectPublished([101, 102]), 'not published');
    $assertThrows(ContractViolation::class, static fn () => $catalog->selectPublished([103]), 'not published');
});

$test('selection rejects missing IDs rather than silently dropping them', static function () use ($records, $assertThrows): void {
    $catalog = new PortfolioCatalog($records());
    $assertThrows(ContractViolation::class, static fn () => $catalog->selectPublished([999]), 'does not exist');
});

$test('taxonomy filtering retains selected relative order', static function () use ($records, $assertSame): void {
    $catalog = new PortfolioCatalog($records());
    $selected = $catalog->selectPublished([104, 101], 'research');
    $assertSame([101], array_column($selected, 'id'));
});

$test('catalog rejects wrong field types', static function () use ($assertThrows): void {
    $assertThrows(ContractViolation::class, static fn () => new PortfolioCatalog([
        ['id' => '101', 'status' => 'publish', 'title' => 'Bad', 'summary' => 'Bad', 'terms' => []],
    ]), 'id');
});

$test('renderer escapes XSS-like record text', static function () use ($assertSame): void {
    $catalog = new PortfolioCatalog([
        ['id' => 1, 'status' => 'publish', 'title' => '<script>alert(1)</script>', 'summary' => 'A & B', 'terms' => ['safe']],
    ]);
    $html = (new PortfolioRenderer($catalog))->render([
        'block' => 'portfolio/collection',
        'ids' => [1],
        'taxonomy' => null,
        'heading' => 'Work <now>',
    ]);
    $assertSame(false, str_contains($html, '<script>'));
    $assertSame(true, str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
    $assertSame(true, str_contains($html, 'A &amp; B'));
    $assertSame(true, str_contains($html, 'Work &lt;now&gt;'));
});

$test('renderer rejects unsupported blocks and attributes', static function () use ($records, $assertThrows): void {
    $renderer = new PortfolioRenderer(new PortfolioCatalog($records()));
    $assertThrows(ContractViolation::class, static fn () => $renderer->render([
        'block' => 'core/query', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Work',
    ]), 'Unsupported block');
    $assertThrows(ContractViolation::class, static fn () => $renderer->render([
        'block' => 'portfolio/collection', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Work', 'layout' => 'grid',
    ]), 'Unsupported attribute');
});

$test('renderer rejects wrong attribute types', static function () use ($records, $assertThrows): void {
    $renderer = new PortfolioRenderer(new PortfolioCatalog($records()));
    $assertThrows(ContractViolation::class, static fn () => $renderer->render([
        'block' => 'portfolio/collection', 'ids' => '101', 'taxonomy' => null, 'heading' => 'Work',
    ]), 'ids');
});

$test('migration accepts exact predecessor and records a restorable snapshot', static function () use ($records, $assertSame): void {
    $store = new CompositionStore([
        'version' => 4,
        'blocks' => [['block' => 'portfolio/collection', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Selected work']],
    ], new PortfolioCatalog($records()));
    $result = $store->migrate(4, 5, [
        ['block' => 'portfolio/collection', 'ids' => [104, 101], 'taxonomy' => 'mapping', 'heading' => 'Mapping work'],
    ]);
    $assertSame(5, $result['version']);
    $assertSame(1, count($store->snapshots()));
    $restored = $store->restoreSnapshot($store->snapshots()[0]['snapshot_id'], 5, 6);
    $assertSame(6, $restored['version']);
    $assertSame([101], $restored['blocks'][0]['ids']);
});

$test('migration refuses an intervening edit without state or backup mutation', static function () use ($records, $assertSame, $assertThrows): void {
    $store = new CompositionStore([
        'version' => 8,
        'blocks' => [['block' => 'portfolio/collection', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Current']],
    ], new PortfolioCatalog($records()));
    $before = $store->current();
    $assertThrows(CompositionConflict::class, static fn () => $store->migrate(7, 9, [
        ['block' => 'portfolio/collection', 'ids' => [104], 'taxonomy' => null, 'heading' => 'Stale replacement'],
    ]), 'Expected predecessor version 7');
    $assertSame($before, $store->current());
    $assertSame([], $store->snapshots());
});

$test('invalid migration proposal leaves state and snapshots unchanged', static function () use ($records, $assertSame, $assertThrows): void {
    $store = new CompositionStore([
        'version' => 2,
        'blocks' => [['block' => 'portfolio/collection', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Current']],
    ], new PortfolioCatalog($records()));
    $before = $store->current();
    $assertThrows(ContractViolation::class, static fn () => $store->migrate(2, 3, [
        ['block' => 'portfolio/unknown', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Invalid'],
    ]), 'Unsupported block');
    $assertSame($before, $store->current());
    $assertSame([], $store->snapshots());
});

$test('migration rejects missing and non-public references before state or backup mutation', static function () use ($records, $assertSame, $assertThrows): void {
    $store = new CompositionStore([
        'version' => 1,
        'blocks' => [['block' => 'portfolio/collection', 'ids' => [101], 'taxonomy' => null, 'heading' => 'Current']],
    ], new PortfolioCatalog($records()));
    $before = $store->current();
    foreach ([999, 102, 103] as $id) {
        $assertThrows(ContractViolation::class, static fn () => $store->migrate(1, 2, [
            ['block' => 'portfolio/collection', 'ids' => [$id], 'taxonomy' => null, 'heading' => 'Invalid reference'],
        ]));
        $assertSame($before, $store->current());
        $assertSame([], $store->snapshots());
    }
});

$filter = $argv[1] ?? null;
$selectedTests = $filter === null
    ? $tests
    : array_filter($tests, static fn (string $name): bool => stripos($name, $filter) !== false, ARRAY_FILTER_USE_KEY);

if ($selectedTests === []) {
    fwrite(STDERR, "No tests matched" . ($filter === null ? '' : " '{$filter}'") . ".\n");
    exit(2);
}

$failures = 0;
foreach ($selectedTests as $name => $case) {
    try {
        $case();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}\n  " . str_replace("\n", "\n  ", $error->getMessage()) . "\n");
    }
}

echo sprintf("\n%d passed, %d failed\n", count($selectedTests) - $failures, $failures);
exit($failures === 0 ? 0 : 1);
