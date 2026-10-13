# Engineering contracts and decisions

## System boundary

The executable is a pure PHP in-memory adapter. It isolates four concerns:

1. `PortfolioCatalog` owns structured record validation and retrieval.
2. `CompositionContract` owns the accepted block shape.
3. `PortfolioRenderer` converts validated public records into escaped HTML.
4. `CompositionStore` owns composition versions, guarded replacement, backups, and recovery.

Keeping these concerns separate avoids treating rendered markup as the record database or treating editor input as trusted render data.

## Data contracts

### Portfolio record

Every record must contain exactly:

```php
[
    'id' => 201,                    // positive integer, unique
    'status' => 'publish',          // publish | draft | private
    'title' => 'Community Map',     // string
    'summary' => 'Public record.',  // string
    'terms' => ['mapping'],         // unique, trimmed, non-empty strings
]
```

Unknown and missing fields are rejected. The catalog has a deliberately small status vocabulary so its public-selection policy is unambiguous.

### Collection block

Every supported block must contain exactly:

```php
[
    'block' => 'portfolio/collection',
    'ids' => [203, 201],
    'taxonomy' => 'mapping', // or null
    'heading' => 'Selected work',
]
```

`ids` is an ordered, duplicate-free list of positive integers. The renderer supports no implicit “all records” mode: editorial selection is explicit and reviewable. Unknown blocks and attributes are errors.

### Composition

A composition has a positive integer `version` and a list of validated blocks:

```php
[
    'version' => 12,
    'blocks' => [/* collection blocks */],
]
```

A migration supplies an expected predecessor, a strictly higher successor version, and complete replacement blocks.

`CompositionStore` receives the same `PortfolioCatalog` used by the renderer. Initial state, replacement blocks, and snapshot recovery all resolve their referenced IDs before acceptance. A structurally valid block pointing at a missing, draft, or private record cannot create a successful migration or a backup entry. The catalog is fixed for this in-memory run; a durable adapter must also handle publication changes between validation and activation.

## Selection and rendering data flow

1. Validate every record when constructing the catalog.
2. Validate the complete block contract.
3. Resolve IDs in the exact order supplied by the editor.
4. Refuse the whole selection if any requested ID is missing, draft, or private.
5. If a taxonomy term is supplied, filter the validated public selection without reordering it.
6. Escape heading, title, and summary at the HTML output boundary.
7. Return one deterministic server-rendered fragment.

The fail-closed visibility rule is intentional. Silently dropping a draft, private, or deleted reference can hide an editorial mistake and produce an apparently successful but incomplete page. A production adapter may choose a different policy, but it should make that policy explicit and test it.

## Model ownership

| Concern | Owner in this demo | Production implication |
| --- | --- | --- |
| Record schema and visibility | `PortfolioCatalog` | Companion plugin or domain module, independent of the active theme |
| Editorial ordering and filter | Collection block attributes | Block attributes or post meta with explicit validation |
| HTML structure and escaping | `PortfolioRenderer` | Dynamic block render callback or template function |
| Composition version | `CompositionStore` | Durable revision/CAS field owned by a migration adapter |
| Backups and recovery | Snapshot list | Durable, auditable revision storage with retention rules |

The theme may style output, but it should not become the only owner of durable content records.

## Migration state transitions

```text
CURRENT(vN)
  ├─ validate proposal fails ───────────────> CURRENT(vN), no snapshot
  ├─ predecessor mismatch ─────────────────> CURRENT(vN), no snapshot
  └─ valid + predecessor matches
       ├─ create snapshot of vN
       └─ install successor ────────────────> CURRENT(vN+), snapshot(vN)

CURRENT(vN+) + restore(snapshot(vN))
  ├─ current-version mismatch ─────────────> CURRENT(vN+), no new snapshot
  └─ valid guard
       ├─ snapshot current vN+
       └─ install saved blocks as vR ──────> CURRENT(vR), snapshots(vN, vN+)
```

Recovery is not a destructive rewind. It creates a new forward version from saved block data and snapshots the state it replaces. That preserves an audit-friendly recovery path.

### Atomicity inside the adapter

Proposal validation and predecessor checks occur before either state field is changed. On success, the adapter prepares both the snapshot and successor, then appends/assigns them. In a real persistent adapter those writes require a transaction or an equivalent compare-and-swap operation; PHP object assignment alone does not provide cross-request or database atomicity.

## Failure matrix

| Condition | Result | State mutation | Snapshot mutation |
| --- | --- | --- | --- |
| Missing record ID | `ContractViolation` | None | None |
| Requested draft/private record | `ContractViolation` | None | None |
| Wrong record/attribute type | `ContractViolation` | None | None |
| Unknown block or attribute | `ContractViolation` | None | None |
| Duplicate requested ID | `ContractViolation` | None | None |
| Taxonomy has no matching selected records | Valid empty list | None | None |
| Migration proposal invalid | `ContractViolation` | None | None |
| Expected predecessor is stale | `CompositionConflict` | None | None |
| Successor version is not higher | `ContractViolation` | None | None |
| Restore snapshot is missing | `ContractViolation` | None | None |
| Guarded migration succeeds | Successor installed | Yes | One predecessor snapshot |
| Guarded restore succeeds | Saved blocks installed as new version | Yes | One snapshot of replaced state |

## Alternatives and trade-offs

### Persist rendered HTML

This can make reads simple, but duplicates derived data and risks stale markup when records change. Dynamic rendering keeps one content authority at the cost of render-time queries and cache design.

### Silently omit inaccessible records

This keeps pages rendering when references drift, but can conceal accidental draft/private selections. This demo fails closed. A production site could instead return an editor-visible diagnostic while keeping public output safe.

### Query by taxonomy only

Automatic taxonomy queries reduce editorial work but give weaker control over exact ordering. This model combines explicit IDs with an optional filter. A production block could offer separate explicit and query modes with distinct schemas.

### Last-write-wins migration

It is operationally simple but can overwrite an editor's intervening changes. Version comparison refuses that stale write. Real systems need the comparison and update to be atomic in durable storage.

### Destructive rollback

Replacing current state without preserving it makes recovery itself risky. Forward restoration retains the replaced state, at the cost of more snapshots and retention management.

## Verification strategy

`tests/run.php` exercises behavior through public interfaces without mocks or external dependencies. It verifies order, visibility failure, missing references, taxonomy behavior, type contracts, escaping, unsupported input, accepted migration, stale-write refusal, mutation invariants, and actual snapshot restoration.

The test runner accepts a case-name substring and exits `2` if no tests match, which prevents a mistyped focused test command from looking successful.