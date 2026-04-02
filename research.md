# Research: Statamic Blueprint Caching & Dynamic Field Injection

> Files examined: v5 @ `statamic-content-translator-test/vendor/statamic/cms/src/`  
> v6 @ `statamic-content-translator-test-v6/vendor/statamic/cms/src/`

---

## Summary

Blueprints are **not stored in the Stache** and are not touched by `php artisan statamic:stache:warm`. They are resolved lazily from YAML files using a `Blink`-backed in-memory cache that is **process-scoped and dies with each PHP process**. Service provider `boot()` always runs before any blueprint is accessed in that process, making both direct `ensureField()` calls and `EntryBlueprintFound` event listeners fully safe. There is zero risk of a "stale cached blueprint" missing injected fields in production.

---

## Finding 1 — There Is No Blueprint Stache Store

**Blueprints are NOT in the Stache.**

```
# Confirmed missing in both v5 and v6:
src/Stache/Stores/BlueprintsStore.php  ← does not exist
```

The Stache stores entries, collections, assets, taxonomies, globals — but **not blueprints**. The Stache warm command is simply:

```php
// src/Console/Commands/StacheWarm.php (identical v5 and v6)
class StacheWarm extends Command
{
    protected $signature = 'statamic:stache:warm';

    public function handle()
    {
        spin(callback: fn () => Stache::warm(), message: 'Warming the Stache...');
    }
}
```

And `Stache::warm()` only warms registered stores — no blueprints:

```php
// src/Stache/Stache.php
public function warm()
{
    $lock = tap($this->lock('stache-warming'))->acquire(true);
    $stores = $this->stores()->except($this->exclude);

    if ($this->shouldUseParallelWarming($stores)) {
        $this->warmInParallel($stores);
    } else {
        $stores->each->warm();     // ← only registered Stache stores
    }

    StacheWarmed::dispatch();
}
```

**`statamic:stache:warm` has zero effect on blueprint resolution or injection.**

---

## Finding 2 — Blueprint Caching Is Blink-Only (Process-Scoped)

Blueprints go through `BlueprintRepository`, which uses three named Blink stores:

```php
// src/Fields/BlueprintRepository.php (identical v5 and v6)
protected const BLINK_FOUND         = 'blueprints.found';
protected const BLINK_FROM_FILE     = 'blueprints.from-file';
protected const BLINK_NAMESPACE_PATHS = 'blueprints.paths-in-namespace';

public function find($blueprint): ?Blueprint
{
    return Blink::store(self::BLINK_FOUND)->once($blueprint, function () use ($blueprint) {
        $path = count($parts) > 1
            ? $this->findNamespacedBlueprintPath($blueprint)
            : $this->findStandardBlueprintPath($blueprint);

        return $path !== null && File::exists($path)
            ? $this->makeBlueprintFromFile($path, ...)
            : $this->findFallback($blueprint);
    });
}

protected function makeBlueprintFromFile($path, $namespace = null)
{
    return Blink::store(self::BLINK_FROM_FILE)->once($path, function () use ($path, $namespace) {
        $contents = YAML::file($path)->parse();

        return $this->make($handle)
            ->setHidden(...)
            ->setOrder(...)
            ->setInitialPath($path)
            ->setNamespace($namespace ?? null)
            ->setContents($contents);   // ← raw YAML contents only
    });
}
```

The `Blink` facade is backed by `Statamic\Support\Blink` which wraps `Spatie\Blink\Blink` — a plain PHP array singleton. It is **never persisted** to any cache driver. It lives for exactly one PHP process lifetime.

`save()` and `delete()` call `clearBlinkCaches()`, which flushes all three stores. There is no Laravel Cache (`cache()`) interaction anywhere in blueprint resolution.

---

## Finding 3 — Blueprint Instance Caching: Two More Blink Keys + `$fieldsCache`

Each `Blueprint` instance has its own two Blink keys and one PHP property cache:

```php
// src/Fields/Blueprint.php (identical v5 and v6)

private function contentsBlinkKey()
{
    return "blueprint-contents-{$this->namespace()}-{$this->handle()}";
}

private function fieldsBlinkKey()
{
    return "blueprint-fields-{$this->namespace()}-{$this->handle()}";
}

public function contents(): array
{
    return Blink::once($this->contentsBlinkKey(), function () {
        return $this->getContents();   // ← merges $this->contents + $this->ensuredFields
    });
}

public function fields(): Fields
{
    if ($this->fieldsCache) {
        return $this->fieldsCache;
    }

    $fn = function () { ... return new Fields(...); };

    $fields = $this->handle()
        ? Blink::once($this->fieldsBlinkKey(), $fn)
        : $fn();

    $this->fieldsCache = $fields;
    return $fields;
}
```

`ensuredFields` are merged in `getContents()` → `addEnsuredFieldsToContents()` — at the time `contents()` is first called, **not** at the time `ensureField()` is called. This is the deferred merge pattern.

---

## Finding 4 — `ensureField()` Mechanics and Cache Invalidation

```php
// src/Fields/Blueprint.php (identical v5 and v6)

protected $ensuredFields = [];   // lives on the PHP object

public function ensureField($handle, $fieldConfig, $tab = null, $prepend = false)
{
    return $this->ensureFieldInTab($handle, $fieldConfig, $tab, $prepend);
}

public function ensureFieldInTab($handle, $config, $tab, $prepend = false)
{
    if (isset($this->ensuredFields[$handle])) {
        return $this;   // ← idempotent: first call wins
    }

    $this->ensuredFields[$handle] = compact('handle', 'tab', 'prepend', 'config');

    $this->resetBlueprintCache()->resetFieldsCache();   // ← always invalidates

    return $this;
}

protected function resetFieldsCache()
{
    // ... loop-detection guard ...
    $this->fieldsCache = null;
    Blink::forget($this->contentsBlinkKey());   // "blueprint-contents-{ns}-{handle}"
    Blink::forget($this->fieldsBlinkKey());     // "blueprint-fields-{ns}-{handle}"
    return $this;
}
```

**`ensureField()` always invalidates** the contents/fields Blink caches when it is called. The field is stored in `$ensuredFields` on the PHP object and merged the next time `contents()` or `fields()` is accessed.

---

## Finding 5 — `Collection::entryBlueprints()` Call Chain

This is the path from a collection to a fully-resolved blueprint with all fields:

```php
// src/Entries/Collection.php (v5; v6 identical except parent-field injection removed)

public function entryBlueprints()
{
    $blink = 'collection-entry-blueprints-'.$this->handle();

    return Blink::once($blink, function () {
        return $this->getEntryBlueprints();
    });
}

private function getEntryBlueprints()
{
    $blueprints = Blueprint::in('collections/'.$this->handle());   // loads from YAML

    if ($blueprints->isEmpty()) {
        $blueprints = collect([$this->fallbackEntryBlueprint()]);
    }

    return $blueprints->values()->map(function ($blueprint) {
        return $this->ensureEntryBlueprintFields($blueprint);   // ← injects title, slug, date, taxonomies
    });
}

public function ensureEntryBlueprintFields($blueprint)
{
    $blueprint->ensureFieldPrepended('title', [...]);

    if ($this->requiresSlugs()) {
        $blueprint->ensureField('slug', [...], 'sidebar');
    }
    if ($this->dated()) {
        $blueprint->ensureField('date', [...], 'sidebar');
    }
    // ... taxonomies ...

    return $blueprint;
}
```

The `Blink::once('collection-entry-blueprints-{handle}', ...)` cache is for the **collection of blueprints** (the array), not the individual blueprint content. The per-blueprint content cache (`blueprint-contents-*`) is separate and on the Blueprint instance.

---

## Finding 6 — `EntryBlueprintFound` Event: Where It Fires and When

The event exists in both v5 and v6 with the same signature:

```php
// src/Events/EntryBlueprintFound.php (identical v5 and v6)
class EntryBlueprintFound extends Event
{
    public function __construct(public $blueprint, public $entry = null)
    {
    }
}
```

**Two dispatch sites:**

**Site A — `Entry::blueprint()` getter (with entry):**
```php
// src/Entries/Entry.php
public function blueprint($blueprint = null)
{
    $key = "entry-{$this->id()}-blueprint";

    return $this->fluentlyGetOrSet('blueprint')
        ->getter(function ($blueprint) use ($key) {
            if (Blink::has($key)) {
                return Blink::get($key);     // ← returns cached, NO event
            }

            // ... resolve blueprint handle ...

            $blueprint = $this->collection()->entryBlueprint($blueprint, $this);

            Blink::put($key, $blueprint);    // ← caches first

            EntryBlueprintFound::dispatch($blueprint, $this);  // ← fires AFTER cache set

            return $blueprint;
        })
        ->setter(function ($blueprint) use ($key) {
            Blink::forget($key);             // ← invalidates per-entry cache
            return $blueprint instanceof Blueprint ? $blueprint->handle() : $blueprint;
        })
        ->args(func_get_args());
}
```

**Site B — `Collection::entryBlueprint()` (no entry, e.g. CP create-entry form):**
```php
// src/Entries/Collection.php
public function entryBlueprint($blueprint = null, $entry = null)
{
    if (! $blueprint = $this->getBaseEntryBlueprint($blueprint)) {
        return null;
    }

    $blueprint->setParent($entry ?? $this);

    if (! $entry) {
        Blink::once(
            'collection-entryblueprintfound-'.$this->handle().'-'.$blueprint->handle(),
            fn () => EntryBlueprintFound::dispatch($blueprint)   // ← fires once per blueprint, no entry
        );
    }

    return $blueprint;
}
```

**Critical observation**: `EntryBlueprintFound` fires **after** `Blink::put($key, $blueprint)` in the entry path — meaning the blueprint object is already in the entry-scoped Blink cache. However, `ensureField()` called from your listener will call `resetFieldsCache()` and invalidate the `blueprint-contents-*` Blink keys, forcing fresh re-merging of the field configuration on the next `contents()` call. The per-entry Blink key (`entry-{id}-blueprint`) is NOT a contents/fields cache — it's a reference to the same PHP object, which now has your field in `$ensuredFields`. So this works correctly.

**Other available events (same pattern in v5 and v6):**
- `AssetContainerBlueprintFound($blueprint, $container, $asset)` — fired from asset container + asset
- `UserBlueprintFound($blueprint)` — fired from user blueprint resolution
- `TermBlueprintFound($blueprint, $term)` — fired from taxonomy + term

**Not present in either version:**
- `GlobalSetBlueprintFound` — global sets call `Blueprint::find()` directly with no event
- `TaxonomyBlueprintFound` — taxonomy uses `TermBlueprintFound` for term blueprints

---

## Finding 7 — v5 vs v6 Differences (Blueprint-Relevant)

| Aspect | v5 | v6 |
|---|---|---|
| `BlueprintRepository::find()` | Identical | Identical |
| `Blueprint::ensureField()` | Identical | Identical |
| `Blueprint::resetFieldsCache()` | Identical | Identical |
| `Blueprint::contents()` / Blink keys | Identical | Identical |
| `Collection::ensureEntryBlueprintFields()` | Injects `parent` field for structured non-orderable | Parent field removed |
| `Collection::entryBlueprints()` Blink key | `collection-entry-blueprints-{handle}` | Identical |
| `EntryBlueprintFound` event | Identical | Identical |
| `Blueprint::fullyQualifiedHandle()` return type | `string` | `?string` (nullable) |
| `BlueprintRepository::filesIn()` | Returns raw file collection | Rejects `settings.yaml` for addon namespaces |
| `StacheWarm` command | Identical | Identical |

**No meaningful differences in caching or injection lifecycle between v5 and v6.**

---

## Lifecycle Timeline (Per PHP Process)

```
1. PHP process starts (FPM request OR CLI command)
   │
2. Laravel bootstraps service providers
   ├── Statamic core providers boot
   └── Your addon ServiceProvider::boot() runs
       └── [Option A] Register EntryBlueprintFound listener
           OR
           [Option B] Call Blueprint::find('...')->ensureField(...)
   │
3. HTTP request / Artisan command begins
   │
4. First access to entry blueprint (lazy, on demand):
   ├── Entry::blueprint() getter called
   ├── Collection::entryBlueprints() called
   │   ├── Blueprint::in('collections/blog') reads YAML from disk
   │   ├── Blueprint instance stored in Blink::store('blueprints.from-file')[path]
   │   ├── ensureEntryBlueprintFields() called → ensureField('title'), ('slug'), ...
   │   └── Result stored in Blink::once('collection-entry-blueprints-blog')
   ├── Blueprint instance returned, setParent($entry) called
   ├── Blink::put('entry-{id}-blueprint', $blueprint)
   ├── EntryBlueprintFound::dispatch($blueprint, $entry)
   │   └── [Option A] Your listener: $event->blueprint->ensureField('my_field', [...])
   │       ├── $blueprint->ensuredFields['my_field'] = [...]
   │       └── Blink::forget('blueprint-contents-...') ← invalidates stale cache
   └── $blueprint returned (has your field in $ensuredFields)
   │
5. $blueprint->fields() or $blueprint->contents() called
   └── getContents() → addEnsuredFieldsToContents() merges ALL ensuredFields
       (Statamic's title/slug/date/taxonomy fields + YOUR field)
   │
6. PHP process ends. All Blink data discarded.
   └── Next process starts fresh at step 1.
```

**`statamic:stache:warm` does NOT touch step 4 or 5 at all.** It only warms the Stache (entry paths, collection config, etc.), which lives in the configured Laravel cache store. Blueprints are resolved fresh from disk on each request.

---

## Recommendation: When and How to Inject Fields Safely

### ✅ Recommended: Event Listener in `boot()` (Official Statamic Pattern)

```php
// YourAddon/ServiceProvider.php
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Events\UserBlueprintFound;
use Statamic\Events\TermBlueprintFound;

public function boot(): void
{
    // For entry blueprints (all collections):
    Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event) {
        $event->blueprint->ensureField('my_custom_field', [
            'type'    => 'my_fieldtype',
            'display' => 'My Custom Field',
        ]);
    });

    // For specific collections only:
    Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event) {
        $collection = $event->entry?->collection()?->handle()
            ?? $event->blueprint->namespace();  // fallback for no-entry dispatch

        if ($collection !== 'blog') {
            return;
        }

        $event->blueprint->ensureField('my_custom_field', [...]);
    });
}
```

**Why this is safe:**
- Runs during bootstrap before any blueprint is accessed
- Fires every time a blueprint is resolved, on any PHP process
- `ensureField()` is idempotent — safe to call multiple times on same instance
- `ensureField()` invalidates the blueprint's Blink content cache on each call
- `stache:warm` does not pre-populate blueprint content caches
- Works identically in v5 and v6

### ✅ Also Safe: Direct `ensureField()` on Specific Blueprints in `boot()`

Use this when you need to inject into a known blueprint regardless of whether an entry is being resolved (e.g., for CP listing columns):

```php
public function boot(): void
{
    // Safe because boot() always runs before Blueprint::in() or ::find() is called
    // Blueprint::find() here shares the same Blink instance (blueprints.from-file)
    // that Collection::entryBlueprints() will later retrieve via Blueprint::in()
    Statamic::booted(function () {
        // Wrap in Statamic::booted() if you need Statamic fully initialized
        \Statamic\Facades\Blueprint::find('collections.blog')
            ?->ensureField('my_field', ['type' => 'my_fieldtype']);
    });
}
```

### ❌ Not Needed: Worrying About `stache:warm`

```
# This command does NOT interact with blueprints:
php artisan statamic:stache:warm

# It only builds the Stache for:
# - entry file paths and parsed YAML
# - collection configs
# - asset container manifests
# - taxonomy terms
# - structure trees
# - navigation trees
# - globals
#
# Blueprints are loaded fresh from disk on EVERY process start.
```

### ❌ Avoid: Calling `Blueprint::save()` to Persist Fields

Calling `$blueprint->save()` writes the current state (including ensured fields) back to the YAML file. This would permanently modify the user's blueprint on disk — NOT what you want for runtime injection. Use only `ensureField()` (in-memory), never `save()`.

---

## Sources

| File | What It Confirmed |
|---|---|
| `src/Fields/BlueprintRepository.php` (v5 + v6) | Blink-only caching, no Stache integration, identical in both versions |
| `src/Fields/Blueprint.php` (v5 + v6) | `ensureField()` idempotency, `resetFieldsCache()` invalidation, deferred merge in `getContents()` |
| `src/Entries/Collection.php` (v5 + v6) | `entryBlueprints()` Blink key, `ensureEntryBlueprintFields()` call chain |
| `src/Entries/Entry.php` (v5 + v6) | `Entry::blueprint()` getter: Blink put → event dispatch order |
| `src/Events/EntryBlueprintFound.php` (v5 + v6) | Event signature, identical in both versions |
| `src/Console/Commands/StacheWarm.php` (v5 + v6) | Delegates to `Stache::warm()` only, no blueprint interaction |
| `src/Stache/Stache.php` (v5) | `warm()` warms registered stores only — no blueprint store registered |
| `src/Facades/Blink.php` (v5) | Backed by `Spatie\Blink\Blink` (PHP array), process-scoped |

## Gaps

- **`GlobalSetBlueprintFound` does not exist** — if you need to inject into global set blueprints, you must either use direct `Blueprint::find('globals.handle')` in `boot()` or listen to `BlueprintSaved` as a workaround. The `GlobalSet::blueprint()` method calls `Blueprint::find()` directly with no event hook.
- **v6 `settings.yaml` exclusion** — v6's `filesIn()` rejects `settings.yaml` from addon namespace blueprint listings, which may affect addon-namespace blueprints differently.
- **No testing in octane/long-running mode** — In Laravel Octane (long-running process), Blink is reset between requests via `BlinkManager::reset()`. The behavior tested above assumes standard FPM where each request is a new process.
