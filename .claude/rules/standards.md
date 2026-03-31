---
description: Laravel coding standards, testing rules, and workflow enforcement. Supplements CLAUDE.md sections 5-6.
globs: "**/*.php, **/*.blade.php"
---
# Standards (additive to CLAUDE.md)

## Self-Review Checklist
After writing code, silently verify before presenting:
- No N+1 queries (use `with()` for relations in loops)
- No business logic in controllers — delegate to Services/Actions
- Validation via Form Requests, not inline controller logic
- `$fillable` defined on all models
- Authorization via Policies/Gates where applicable

## Laravel Performance
- `Cache::remember()` for heavy/infrequent-change queries
- `chunk()` or `cursor()` for large datasets — never `get()` on unbounded sets
- Indexes on FKs and frequently filtered columns

## Testing
- Pest preferred. Use `RefreshDatabase` trait.
- Use Model Factories (`::factory()->create()`), not raw DB inserts.
- Cover: happy path (200), validation (422), auth (403/401).
- No mocks unless hitting external APIs.

## Documentation
- Self-documenting code first. Comment the "why", not the "what".
- PHPDoc blocks on classes and complex methods with `@param`/`@return` when not obvious from type hints.