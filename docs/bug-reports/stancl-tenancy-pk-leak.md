# stancl/tenancy Bug Report: `Tenant::create()` returns model with wrong `id`

This file is a **filing-ready** bug report for upstream. Paste the `## Issue Body` section into a new issue at https://github.com/archtechx/tenancy/issues/new

The reproduction script is self-contained and runs against a stock Laravel + stancl/tenancy + sqlite install.

---

## Issue Body

### `Tenant::create()` returns a model whose `id` is the sqlite rowid, not the supplied string PK, when `tenancy.id_generator` is `null`

#### Environment

- `stancl/tenancy` **v3.10.0** (commit `ab64f45`)
- Laravel 12.x
- PHP 8.4
- sqlite in-memory (verified)
- MySQL / PostgreSQL: not verified in this reproduction, but affected via the same code path — see "Other drivers" section below

#### Summary

When `config('tenancy.id_generator')` is `null` (the default, intended for manual string PK assignment) and the user subclass explicitly declares `public $incrementing = false`, `getIncrementing()` from the `GeneratesIds` trait **ignores the property** and returns `true` anyway. Laravel's `Model::performInsert()` then calls `PDO::lastInsertId()` after the INSERT, which on sqlite returns the internal rowid. The returned model's `id` attribute is overwritten with that rowid, silently corrupting every caller that uses the returned object directly.

The DB row is stored correctly — `Tenant::find('acme')` works. Only the **in-memory model returned by `create()`** has the wrong id. This makes the bug extremely hard to notice in application code and nearly impossible to catch in tests that go through `Tenant::create()` and initialize tenancy with the returned object.

#### Steps to reproduce

Drop this script into a Laravel project with `stancl/tenancy:^3.10` installed, sqlite configured as the central connection, and `config/tenancy.php` having `'id_generator' => null` (the default).

```php
<?php

use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

// Schema mirrors the default stancl tenants migration exactly
Schema::create('tenants', function ($table) {
    $table->string('id')->primary();
    $table->string('name');
    $table->timestamps();
    $table->json('data')->nullable();
});

Schema::create('domains', function ($table) {
    $table->increments('id');
    $table->string('domain');
    $table->string('tenant_id');
    $table->timestamps();
    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
});

class TT extends BaseTenant
{
    use HasDomains;

    protected $table = 'tenants';

    // User explicitly declares string PK, matching the migration above.
    public $incrementing = false;
    protected $keyType = 'string';

    public static function getCustomColumns(): array
    {
        return ['id', 'name'];
    }
}

$t = TT::create(['id' => 'acme', 'name' => 'Acme']);

dump([
    'returned id'                => $t->id,
    'returned getKey()'          => $t->getKey(),
    'attributes'                 => $t->getAttributes(),
    '$incrementing (property)'   => $t->incrementing,
    'getIncrementing() (method)' => $t->getIncrementing(),
    'getKeyType()'               => $t->getKeyType(),
    'UniqueIdGenerator bound?'   => app()->bound(\Stancl\Tenancy\Contracts\UniqueIdentifierGenerator::class),
    'tenancy.id_generator cfg'   => config('tenancy.id_generator'),
    'refetch by string PK'       => TT::find('acme')?->getAttributes(),
]);
```

#### Expected output

```
array:9 [
  "returned id"                => "acme",
  "returned getKey()"          => "acme",
  "attributes"                 => ["id" => "acme", "name" => "Acme", "data" => null, ...],
  '$incrementing (property)'   => false,
  "getIncrementing() (method)" => false,
  "getKeyType()"               => "string",
  "UniqueIdGenerator bound?"   => false,
  "tenancy.id_generator cfg"   => null,
  "refetch by string PK"       => ["id" => "acme", "name" => "Acme", ...],
]
```

#### Actual output

```
array:9 [
  "returned id"                => "1",      ← WRONG (sqlite rowid)
  "returned getKey()"          => "1",      ← WRONG
  "attributes"                 => ["id" => 1, "name" => "Acme", "data" => null, ...],
  '$incrementing (property)'   => false,    ← user's declaration
  "getIncrementing() (method)" => true,     ← stancl overrides the property
  "getKeyType()"               => "string",
  "UniqueIdGenerator bound?"   => false,
  "tenancy.id_generator cfg"   => null,
  "refetch by string PK"       => ["id" => "acme", "name" => "Acme", ...],   ← DB row is correct
]
```

The DB row `acme` exists and is retrievable via `find('acme')`. Only the model object returned by `create()` is wrong.

#### Root cause

`Stancl\Tenancy\Database\Concerns\GeneratesIds::getIncrementing()` unconditionally overrides Eloquent's `$incrementing` property:

https://github.com/archtechx/tenancy/blob/v3.10.0/src/Database/Concerns/GeneratesIds.php#L20-L23

```php
public function getIncrementing()
{
    return ! app()->bound(UniqueIdentifierGenerator::class);
}
```

And the binding is skipped whenever `config('tenancy.id_generator')` is `null`:

https://github.com/archtechx/tenancy/blob/v3.10.0/src/TenancyServiceProvider.php#L57-L60

```php
if (! is_null($this->app['config']['tenancy.id_generator'])) {
    $this->app->bind(Contracts\UniqueIdentifierGenerator::class, $this->app['config']['tenancy.id_generator']);
}
```

So:

| `tenancy.id_generator` | `UniqueIdGenerator` bound? | `getIncrementing()` returns |
|---|---|---|
| `null` (manual string PKs) | no | **`true`** ← wrong |
| `SomeGenerator::class` | yes | `false` |

The user's explicit `public $incrementing = false` on the subclass is silently ignored in the first (very common) case.

Downstream, Laravel's `Model::performInsert()` executes:

```php
if ($this->getIncrementing()) {
    $this->insertAndSetId($query, $attributes);  // calls lastInsertId
} else {
    // ... just insert
}
```

`insertAndSetId` calls `PDO::lastInsertId()`, which on sqlite returns the row's hidden integer rowid (always `1` for the first insert), and assigns that to the model's primary key attribute — clobbering the `'acme'` the user supplied.

#### Other drivers

This reproduction uses sqlite in-memory, which returns the row's hidden integer rowid from `PDO::lastInsertId()` (always `1` for the first row). The sqlite backing is not a precondition for the bug — the broken code path is the same on any driver. On MySQL, `LAST_INSERT_ID()` returns `0` for inserts into non-`AUTO_INCREMENT` tables, so the returned model would have `id = 0` rather than `id = 1` — equally incorrect, just differently visible. PostgreSQL should behave similarly. **I have not empirically verified this on MySQL or PostgreSQL** in this report, but the upstream fix is the same regardless of driver.

#### Why this escapes existing test suites

The stancl test suite uses `Tenant::create([...])` followed by `Tenant::find($id)` as the universal pattern (see e.g. `tests/TenantModelTest.php`). The `find()` re-fetch masks the bug entirely — it returns the correctly-stored row, not the corrupted in-memory object.

Downstream projects that assume `create()` returns a usable model (standard Eloquent contract) hit the bug hard: `tenancy()->initialize($created)` initializes with the wrong tenant key, and every `BelongsToTenant` `creating` hook then writes `tenant_id = '1'` (or `0` on MySQL) to child tables, triggering foreign key violations or silently corrupting tenant scoping.

#### Proposed fixes

**Option A — honor the explicit property (minimal diff, non-breaking):**

```php
// GeneratesIds.php
public function getIncrementing()
{
    // If the model explicitly declares non-incrementing, respect it.
    // Otherwise fall back to the id-generator heuristic.
    if ($this->incrementing === false) {
        return false;
    }

    return ! app()->bound(UniqueIdentifierGenerator::class);
}
```

Subclasses that declare `$incrementing = false` get the expected Laravel behavior. Subclasses that don't declare anything get the current heuristic.

**Option B — treat `id_generator => null` as an explicit signal:**

Document clearly that `id_generator => null` means "I will provide string PKs manually, treat the model as non-incrementing." Change the default behavior of `getIncrementing()` to return `false` when the generator is unbound **and** the model's `$incrementing` hasn't been explicitly set to `true`.

Option A is the smaller, safer change.

#### Workaround for downstream users (current release)

Always re-fetch after create:

```php
Tenant::create(['id' => 'acme', 'name' => 'Acme']);
$tenant = Tenant::find('acme');  // ← required, returned object from create() is corrupt
```

This should be documented prominently in the tenancy README's "Manual tenant creation" section until the bug is fixed.

---

## Filing instructions (for the pw2d team)

1. Open https://github.com/archtechx/tenancy/issues/new
2. Title: `Tenant::create() returns model with wrong id when tenancy.id_generator is null (GeneratesIds::getIncrementing overrides $incrementing property)`
3. Paste the content between the two `---` markers above (the `## Issue Body` section)
4. Attach the reproduction script at `/tmp/f5-repro-stancl-base.php` if still on disk, or recreate it from the snippet above

Once filed, link the issue URL in `docs/lessons.md` and mark F5 done in `docs/tasks/todo.md`.
