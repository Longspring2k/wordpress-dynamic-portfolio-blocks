# WordPress integration mapping

This document maps the executable model to WordPress concepts. The repository does not call these APIs and does not claim native integration testing.

## Suggested ownership

### Companion plugin or domain plugin

A production plugin would normally own:

- custom post type registration for portfolio records;
- taxonomy registration and term assignment;
- post-meta registration, sanitization, and authorization callbacks;
- block registration metadata and server render callbacks;
- migration service, durable versions, snapshots, and audit details;
- capability and nonce enforcement for write operations.

Keeping these responsibilities outside the theme lets records survive a presentation change.

### Theme

The theme should own presentation concerns such as layout, typography, spacing, and responsive styling. It may provide templates around the dynamic block, but should not be the only location where the structured record schema exists.

### Block editor

A real editor implementation would need controls for ordered record selection, taxonomy choice, heading text, error display, and preview behavior. It should obtain selectable records through an authorized data source and make draft/private visibility clear to the editor.

## Concept mapping

| Demo concept | Possible WordPress concept | Adapter work not implemented here |
| --- | --- | --- |
| `PortfolioCatalog` | `WP_Query`, post objects, registered meta, terms | Query construction, permissions, caches, object normalization |
| `status === publish` | `post_status` policy | User capability context, previews, password protection |
| `terms` | Registered taxonomy relationships | Term existence, term IDs/slugs, REST exposure |
| Collection block contract | `block.json` attributes plus PHP validation | Block registration, editor script, deprecations, transforms |
| `PortfolioRenderer` | Dynamic block `render_callback` | WordPress escaping helpers, image APIs, hooks, caching |
| Composition version | Post meta, option, custom table, or revision token | Atomic compare-and-swap and transaction boundaries |
| Snapshot | Post revision or custom immutable snapshot record | Durable storage, retention, access control, audit metadata |
| `CompositionConflict` | `WP_Error`, REST `409 Conflict`, or CLI failure | HTTP/CLI serialization and editor conflict UI |

## Required production adapter guarantees

### Reads

- Normalize WordPress objects into the bounded record contract.
- Define whether IDs refer to posts, reusable entities, or another stable identity.
- Apply status and capability rules before rendering.
- Preserve explicit editorial order; do not rely on database default order.
- Decide whether missing/deleted records fail the block, show an editor diagnostic, or produce a safe public fallback.

### Rendering

- Validate attributes server-side even if editor controls already validate them.
- Escape at the output context: text, attributes, URLs, and permitted rich HTML need different treatment.
- Use WordPress media/image helpers if images are introduced.
- Define cache invalidation when a selected record or taxonomy relationship changes.

### Writes and migrations

- Authenticate the actor and authorize the exact operation.
- Verify a nonce for browser-originated state changes.
- Validate the complete successor before any durable mutation.
- Compare the predecessor token and write the successor atomically. A read followed by an unrelated update is not enough.
- Persist the backup durably before marking the successor active.
- Return an explicit conflict for stale proposals; never silently merge or overwrite.
- Record enough audit metadata to identify version, actor, time, and migration identity without leaking sensitive payloads.
- Test recovery against the real persistence adapter, including failure between backup and activation.

## Persistence options

### Post revisions

Useful when composition is naturally represented as post content and native revision workflows satisfy retention and access needs. Exact optimistic-concurrency behavior still needs verification.

### Post meta or options

Simple for small state, but multi-row updates and atomic compare-and-swap may be awkward. Avoid assuming separate calls form a transaction.

### Custom table

Can model immutable snapshots and unique version constraints directly, but adds schema migration, maintenance, and operational cost.

The correct choice depends on ownership, write frequency, recovery requirements, and hosting/database constraints. This demo intentionally does not choose one on behalf of a production system.

## Explicit non-claims

The in-memory adapter does not provide:

- WordPress persistence or database concurrency guarantees;
- editor controls, Gutenberg packages, or native block metadata;
- REST routes, authentication, capabilities, or nonce checks;
- `WP_Query`, taxonomy, media, cache, revision, or WP-CLI integration;
- multisite, localization, accessibility, or performance verification;
- deployment or production migration tooling.

Those are adapter responsibilities, not hidden features of the demo.
