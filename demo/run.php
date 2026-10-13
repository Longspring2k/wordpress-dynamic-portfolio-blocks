<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PortfolioDemo.php';

use PortfolioDemo\CompositionConflict;
use PortfolioDemo\CompositionStore;
use PortfolioDemo\PortfolioCatalog;
use PortfolioDemo\PortfolioRenderer;

$catalog = new PortfolioCatalog([
    ['id' => 201, 'status' => 'publish', 'title' => 'Community Map', 'summary' => 'A public mapping record.', 'terms' => ['mapping']],
    ['id' => 202, 'status' => 'draft', 'title' => 'Unreleased Notes', 'summary' => 'Editorial work in progress.', 'terms' => ['mapping']],
    ['id' => 203, 'status' => 'publish', 'title' => 'Waterway Index', 'summary' => 'A structured research record.', 'terms' => ['mapping', 'research']],
]);

$renderer = new PortfolioRenderer($catalog);
$initialBlocks = [[
    'block' => 'portfolio/collection',
    'ids' => [203, 201],
    'taxonomy' => 'mapping',
    'heading' => 'Selected mapping work',
]];

$store = new CompositionStore(['version' => 12, 'blocks' => $initialBlocks], $catalog);

echo "== Render from structured records ==\n";
echo $renderer->render($initialBlocks[0]) . "\n\n";

echo "== Guarded migration ==\n";
$migrated = $store->migrate(12, 13, [[
    'block' => 'portfolio/collection',
    'ids' => [201, 203],
    'taxonomy' => null,
    'heading' => 'Editorially reordered work',
]]);
echo "Applied version {$migrated['version']}; snapshots: " . count($store->snapshots()) . "\n";

echo "\n== Stale writer ==\n";
try {
    $store->migrate(12, 14, $initialBlocks);
} catch (CompositionConflict $error) {
    echo "REFUSED: {$error->getMessage()}\n";
}

echo "Current version remains {$store->current()['version']}; snapshots remain " . count($store->snapshots()) . ".\n";
