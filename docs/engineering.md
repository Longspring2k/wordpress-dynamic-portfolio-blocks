# Engineering decisions

Dynamic WordPress Blocks and Content Models

## Content outlives presentation

Keep content types and metadata in a plugin so switching themes does not remove the editorial model.

## One render authority

Use PHP dynamic blocks to resolve current structured content rather than persisting a second copy of rendered data.

## Guarded migrations

Verify the expected predecessor before replacing a composition; a newer edit should block an outdated migration.

## Reading the architecture

Editorial controls → Content plugin and taxonomy → Dynamic PHP blocks → Theme presentation

The diagram reduces the implementation to its relevant responsibilities. It does not expose private endpoints, exact deployment layout, resource identifiers or operational configuration.
