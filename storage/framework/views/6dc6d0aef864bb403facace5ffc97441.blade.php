@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Detection Checklist

Every dimension here is a genuine fork: Laravel offers two or more valid approaches, the app's choice changes what the next agent writes, and no active project tool can pick for you. Left out on purpose: pure formatting (Pint owns it), any form an installed and enabled Rector rule rewrites to one canonical shape (___SINGLE_BACKTICK___$casts___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___casts()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___$fillable___SINGLE_BACKTICK___ to attributes, pipe-string rules to arrays, named to anonymous migrations, ___SINGLE_BACKTICK___$signature___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___#[Signature]___SINGLE_BACKTICK___), and framework defaults any agent writes unprompted (___SINGLE_BACKTICK___ShouldQueue___SINGLE_BACKTICK___ jobs, relation return types, ___SINGLE_BACKTICK___HasFactory___SINGLE_BACKTICK___).

Each item gives the fork, then a hint (a grep or dir to spot which side the app takes). Hints are only a start. Read the matched files, never record on a raw count. Apply the ground rules to every verdict: a consistent choice that is a default or a tool's target form is not a pattern. Rows tagged (architecture) are the highest-signal, so record presence and deliberate absence.

---

## A. Validation & HTTP input

1. Validation entry point: inline ___SINGLE_BACKTICK___$request->validate()___SINGLE_BACKTICK___ vs Form Request classes vs ___SINGLE_BACKTICK___Validator::make()___SINGLE_BACKTICK___.
   - Hint: ___SINGLE_BACKTICK___ls app/Http/Requests___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___->validate(___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___Validator::make(___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Http/Controllers___SINGLE_BACKTICK___.
2. Custom rule location: invokable rule objects in ___SINGLE_BACKTICK___app/Rules___SINGLE_BACKTICK___ vs inline closures vs ___SINGLE_BACKTICK___Validator::extend()___SINGLE_BACKTICK___ in a provider. Rule objects are the default ___SINGLE_BACKTICK___make:rule___SINGLE_BACKTICK___ path, so record only if the app leans on closures or ___SINGLE_BACKTICK___Validator::extend___SINGLE_BACKTICK___ instead. "No rule objects" alone is just no-signal.
   - Hint: ___SINGLE_BACKTICK___ls app/Rules___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___Validator::extend___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Providers___SINGLE_BACKTICK___.
3. Typed input retrieval: typed getters (___SINGLE_BACKTICK___$request->string()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->integer()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->enum()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->date()___SINGLE_BACKTICK___) vs raw ___SINGLE_BACKTICK___$request->input()___SINGLE_BACKTICK___ / dynamic properties.
   - Hint: grep ___SINGLE_BACKTICK___->string(___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___->integer(___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___->enum(___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___->input(___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Http___SINGLE_BACKTICK___.
4. Custom messages/attributes: ___SINGLE_BACKTICK___lang/*/validation.php___SINGLE_BACKTICK___ vs Form Request ___SINGLE_BACKTICK___messages()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___attributes()___SINGLE_BACKTICK___ methods.
   - Hint: ___SINGLE_BACKTICK___ls lang___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___function messages___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___function attributes___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Http/Requests___SINGLE_BACKTICK___.

## B. Controllers & routing

5. Controller shape: invokable single-action (___SINGLE_BACKTICK_____invoke___SINGLE_BACKTICK___) vs resource controllers vs plain multi-method.
   - Hint: grep ___SINGLE_BACKTICK_____invoke___SINGLE_BACKTICK___ in controllers; ___SINGLE_BACKTICK___Route::resource___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___apiResource___SINGLE_BACKTICK___ vs verb routes.
6. Business-logic location (architecture): fat controllers vs delegated to Actions / Services / Jobs.
   - Hint: read a few controller methods; ___SINGLE_BACKTICK___ls app/Actions app/Services___SINGLE_BACKTICK___.
7. Route handler style: closures in ___SINGLE_BACKTICK___routes/*.php___SINGLE_BACKTICK___ vs controller classes.
   - Hint: count ___SINGLE_BACKTICK___function ()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___::class___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___routes/web.php___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___routes/api.php___SINGLE_BACKTICK___.
8. Middleware assignment: route/group ___SINGLE_BACKTICK___->middleware()___SINGLE_BACKTICK___ vs controller ___SINGLE_BACKTICK___HasMiddleware::middleware()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___#[Middleware]___SINGLE_BACKTICK___ attribute.
   - Hint: grep ___SINGLE_BACKTICK___implements HasMiddleware___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[Middleware(___SINGLE_BACKTICK___ in controllers vs ___SINGLE_BACKTICK___->middleware(___SINGLE_BACKTICK___ in routes.
9. Route model binding: implicit (type-hinted models) vs explicit ___SINGLE_BACKTICK___Route::bind___SINGLE_BACKTICK___ vs manual ___SINGLE_BACKTICK___findOrFail___SINGLE_BACKTICK___.
   - Hint: typed model params in signatures vs ___SINGLE_BACKTICK___findOrFail(___SINGLE_BACKTICK___ in controllers; grep ___SINGLE_BACKTICK___Route::bind___SINGLE_BACKTICK___.
10. Rate limiting: named ___SINGLE_BACKTICK___RateLimiter::for()___SINGLE_BACKTICK___ + ___SINGLE_BACKTICK___throttle:name___SINGLE_BACKTICK___ vs inline ___SINGLE_BACKTICK___throttle:60,1___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___RateLimiter::for___SINGLE_BACKTICK___ in providers vs ___SINGLE_BACKTICK___throttle:___SINGLE_BACKTICK___ in route files.

## C. Authorization

11. Authorization home: Gates (___SINGLE_BACKTICK___Gate::define___SINGLE_BACKTICK___) vs Policy classes in ___SINGLE_BACKTICK___app/Policies___SINGLE_BACKTICK___.
    - Hint: ___SINGLE_BACKTICK___ls app/Policies___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___Gate::define___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Providers___SINGLE_BACKTICK___.
12. Authorization call site: ___SINGLE_BACKTICK___$this->authorize()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___Gate::authorize()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___$user->can()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___can___SINGLE_BACKTICK___ middleware vs ___SINGLE_BACKTICK___#[Authorize]___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK______CAN_DIRECTIVE______SINGLE_BACKTICK___ in Blade.
    - Hint: grep ___SINGLE_BACKTICK___authorize(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->can(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___middleware('can:___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[Authorize(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK______CAN_DIRECTIVE___(___SINGLE_BACKTICK___.

## D. Eloquent & models

13. Mass assignment: ___SINGLE_BACKTICK___$fillable___SINGLE_BACKTICK___ allow-list vs ___SINGLE_BACKTICK___$guarded___SINGLE_BACKTICK___ block-list.
    - Hint: grep ___SINGLE_BACKTICK___protected $fillable___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___protected $guarded___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Models___SINGLE_BACKTICK___.
14. Accessors/mutators: modern ___SINGLE_BACKTICK___Attribute___SINGLE_BACKTICK___ class vs legacy ___SINGLE_BACKTICK___getXxxAttribute()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___setXxxAttribute()___SINGLE_BACKTICK___. Record a legacy hold, it goes against the tool's grain.
    - Hint: grep ___SINGLE_BACKTICK___: Attribute___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___Attribute::make___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___function get[A-Z].*Attribute___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Models___SINGLE_BACKTICK___.
15. Primary keys: auto-increment vs ___SINGLE_BACKTICK___HasUuids___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___HasUlids___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___HasUuids___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___HasUlids___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Models___SINGLE_BACKTICK___; migration ___SINGLE_BACKTICK___id()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___uuid('id')___SINGLE_BACKTICK___.
16. Custom casts: dedicated ___SINGLE_BACKTICK___CastsAttributes___SINGLE_BACKTICK___ classes (___SINGLE_BACKTICK___app/Casts___SINGLE_BACKTICK___) vs inline ___SINGLE_BACKTICK___Attribute___SINGLE_BACKTICK___ vs built-in cast strings.
    - Hint: ___SINGLE_BACKTICK___ls app/Casts___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___Cast::class___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___AsStringable::class___SINGLE_BACKTICK___ in models.
17. Data/query layer (architecture): Eloquent directly in controllers vs repositories vs dedicated query objects (e.g. classes exposing ___SINGLE_BACKTICK___builder(): Builder___SINGLE_BACKTICK___).
    - Hint: ___SINGLE_BACKTICK___ls app/Repositories app/Queries___SINGLE_BACKTICK___; see where non-trivial queries are built.
18. Query scopes: local ___SINGLE_BACKTICK___scope___SINGLE_BACKTICK___/___SINGLE_BACKTICK___#[Scope]___SINGLE_BACKTICK___ methods vs dedicated builder classes.
    - Hint: grep ___SINGLE_BACKTICK___function scope___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___#[Scope]___SINGLE_BACKTICK___ in models; ___SINGLE_BACKTICK___ls app/*/Builders___SINGLE_BACKTICK___.
19. Model events: observers (___SINGLE_BACKTICK___app/Observers___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[ObservedBy]___SINGLE_BACKTICK___) vs ___SINGLE_BACKTICK___booted()___SINGLE_BACKTICK___ closures vs event classes.
    - Hint: ___SINGLE_BACKTICK___ls app/Observers___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___booted___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___::observe___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[ObservedBy]___SINGLE_BACKTICK___.
20. Eager-load posture: explicit per-query ___SINGLE_BACKTICK___->with()___SINGLE_BACKTICK___ vs model-level ___SINGLE_BACKTICK___$with___SINGLE_BACKTICK___ defaults. Treat ___SINGLE_BACKTICK___preventLazyLoading()___SINGLE_BACKTICK___ separately as a development guard because it can complement either posture.
    - Hint: grep ___SINGLE_BACKTICK___protected $with___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->with(___SINGLE_BACKTICK___, and separately ___SINGLE_BACKTICK___preventLazyLoading___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___.

## E. Architecture & organization

21. Action/Service structure (architecture): Action classes (invoked via ___SINGLE_BACKTICK___handle___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___execute___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK_____invoke___SINGLE_BACKTICK___) vs service objects vs neither. Cross-check the Step 0 ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ map: any ___SINGLE_BACKTICK___Actions___SINGLE_BACKTICK___/___SINGLE_BACKTICK___Services___SINGLE_BACKTICK___/___SINGLE_BACKTICK___Pipelines___SINGLE_BACKTICK___/___SINGLE_BACKTICK___Jobs___SINGLE_BACKTICK___-as-actions folder is this pattern, so record how it is invoked.
    - Hint: ___SINGLE_BACKTICK___ls app/___SINGLE_BACKTICK___ (the whole tree, not just ___SINGLE_BACKTICK___Actions___SINGLE_BACKTICK___/___SINGLE_BACKTICK___Services___SINGLE_BACKTICK___); grep the invocation method in the folder you find.
22. DTOs (architecture): spatie/laravel-data vs plain readonly classes vs arrays everywhere.
    - Hint: ___SINGLE_BACKTICK___ls app/Data___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___extends Data___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___readonly class___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___.
23. Dependency acquisition: constructor/method injection vs ___SINGLE_BACKTICK___app()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___resolve()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___App::make()___SINGLE_BACKTICK___ service location.
    - Hint: grep ___SINGLE_BACKTICK___app(___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___resolve(___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___::make(___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ vs promoted constructor deps.
24. Decoupling: events + listeners vs direct service calls.
    - Hint: ___SINGLE_BACKTICK___ls app/Events app/Listeners___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___event(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___::dispatch(___SINGLE_BACKTICK___.
25. Helper vs facade idiom: global helpers (___SINGLE_BACKTICK___config()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___auth()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___response()___SINGLE_BACKTICK___) vs facades (___SINGLE_BACKTICK___Config::___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Auth::___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Response::___SINGLE_BACKTICK___).
    - Hint: ratio of ___SINGLE_BACKTICK___config(___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___Config::___SINGLE_BACKTICK___ (etc.) across ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___.
26. Namespace layout (architecture): default ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ skeleton vs domain/module folders (___SINGLE_BACKTICK___app/Domain/**___SINGLE_BACKTICK___, modules).
    - Hint: ___SINGLE_BACKTICK___ls app/___SINGLE_BACKTICK___, look for ___SINGLE_BACKTICK___Domain/___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Modules/___SINGLE_BACKTICK___, bounded-context folders.
27. Enums: backed vs pure; case naming; where they live.
    - Hint: ___SINGLE_BACKTICK___ls app/Enums___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___enum .*: string___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___enum .*: int___SINGLE_BACKTICK___.

## F. Frontend & views

@if($assist->hasPackage('livewire/livewire') || $assist->hasPackage('inertiajs/inertia-laravel') || $assist->hasPackage('livewire/flux') || $assist->hasPackage('livewire/flux-pro'))
This app ships a frontend stack, so the items below apply.
@else
No Livewire/Inertia/Flux package is installed. This app may be API-only. Confirm from ___SINGLE_BACKTICK___resources/views___SINGLE_BACKTICK___ before sweeping, and treat the Livewire/Flux dimensions as not applicable.
@endif

28. Frontend stack: Blade+Livewire vs Inertia (Vue/React/Svelte) vs Blade-only / API + separate SPA.
    - Hint: ___SINGLE_BACKTICK___composer.json___SINGLE_BACKTICK___ + ___SINGLE_BACKTICK___package.json___SINGLE_BACKTICK___; ___SINGLE_BACKTICK___ls resources/js/pages___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___resources/views___SINGLE_BACKTICK___.
29. Blade composition: class ___SINGLE_BACKTICK______BLADE_COMPONENT_OPEN___*>___SINGLE_BACKTICK___ components vs anonymous components (___SINGLE_BACKTICK______PROPS_DIRECTIVE______SINGLE_BACKTICK___) vs ___SINGLE_BACKTICK______INCLUDE_DIRECTIVE______SINGLE_BACKTICK___ partials.
    - Hint: ___SINGLE_BACKTICK___ls app/View/Components___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK______BLADE_COMPONENT_OPEN______SINGLE_BACKTICK___, ___SINGLE_BACKTICK______INCLUDE_DIRECTIVE______SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___resources/views___SINGLE_BACKTICK___.
@if($assist->hasPackage('livewire/livewire'))
30. Livewire component format: Volt functional/class components, native Livewire 4 single-file (SFC), multi-file (MFC), view-based, or class-based components. Evaluate full-page vs nested separately because it is an independent usage choice.
    - Hint: check the installed Livewire major and ___SINGLE_BACKTICK___livewire/volt___SINGLE_BACKTICK___; inspect ___SINGLE_BACKTICK___app/Livewire___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___resources/views/livewire___SINGLE_BACKTICK___, and Livewire 4 component/page directories for ___SINGLE_BACKTICK______VOLT_DIRECTIVE______SINGLE_BACKTICK___, SFC, MFC, view-based, and class-based formats.
@endif
@if($assist->hasPackage('livewire/flux') || $assist->hasPackage('livewire/flux-pro'))
31. UI kit (Flux): Flux components vs custom components vs another library.
    - Hint: grep ___SINGLE_BACKTICK___<flux:___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___resources/views___SINGLE_BACKTICK___.
@endif
32. Localization: short keys (___SINGLE_BACKTICK___lang/*/*.php___SINGLE_BACKTICK___ + ___SINGLE_BACKTICK_____('messages.welcome')___SINGLE_BACKTICK___) vs JSON string keys (___SINGLE_BACKTICK___lang/*.json___SINGLE_BACKTICK___ + ___SINGLE_BACKTICK_____('Full sentence')___SINGLE_BACKTICK___).
    - Hint: ___SINGLE_BACKTICK___ls lang___SINGLE_BACKTICK___; grep dotted ___SINGLE_BACKTICK_____('___SINGLE_BACKTICK___ vs sentence keys.

## G. Database & migrations

33. Foreign keys: ___SINGLE_BACKTICK___foreignId()->constrained()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___foreignIdFor(Model::class)___SINGLE_BACKTICK___ vs manual ___SINGLE_BACKTICK___foreign()->references()->on()___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___foreignId(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___foreignIdFor(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->foreign(___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___database/migrations___SINGLE_BACKTICK___.
34. ___SINGLE_BACKTICK___down()___SINGLE_BACKTICK___ methods: real reverse logic vs omitted / one-way migrations.
    - Hint: grep ___SINGLE_BACKTICK___function down___SINGLE_BACKTICK___ vs the migration count.
35. Enum storage: DB ___SINGLE_BACKTICK___enum()___SINGLE_BACKTICK___ column vs ___SINGLE_BACKTICK___string()___SINGLE_BACKTICK___ + PHP-enum cast on the model.
    - Hint: grep ___SINGLE_BACKTICK___->enum(___SINGLE_BACKTICK___ in migrations vs string columns cast to enums.
36. Transactions: ___SINGLE_BACKTICK___DB::transaction(fn ...)___SINGLE_BACKTICK___ closure vs manual ___SINGLE_BACKTICK___beginTransaction___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___commit___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___rollBack___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___DB::transaction___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___beginTransaction___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___.
37. Idempotent writes: ___SINGLE_BACKTICK___upsert___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___updateOrCreate___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___firstOrCreate___SINGLE_BACKTICK___ vs find-then-save.
    - Hint: grep ___SINGLE_BACKTICK___upsert(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___updateOrCreate(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___firstOrCreate(___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___.

## H. Testing

38. Framework: Pest (___SINGLE_BACKTICK___it()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___test()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___expect()___SINGLE_BACKTICK___) vs PHPUnit classes.
    - Hint: ___SINGLE_BACKTICK___ls tests/Pest.php___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___it(___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___test(___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___extends TestCase___SINGLE_BACKTICK___.
39. DB reset: ___SINGLE_BACKTICK___RefreshDatabase___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___DatabaseTruncation___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___DatabaseMigrations___SINGLE_BACKTICK___.
    - Hint: grep those trait names in ___SINGLE_BACKTICK___tests/___SINGLE_BACKTICK___.
40. Fixtures: compare how equivalent test-owned records are created, such as factories vs manual inserts. Track seeders separately for shared reference data because ___SINGLE_BACKTICK___$this->seed()___SINGLE_BACKTICK___ commonly and legitimately coexists with factories.
    - Hint: grep ___SINGLE_BACKTICK___::factory(___SINGLE_BACKTICK___ and direct inserts in ___SINGLE_BACKTICK___tests/___SINGLE_BACKTICK___; separately inspect ___SINGLE_BACKTICK___$this->seed(___SINGLE_BACKTICK___ calls and what those seeders provide.
41. Collaborator isolation: how the app doubles its own classes, Mockery ___SINGLE_BACKTICK___mock()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___spy()___SINGLE_BACKTICK___ vs real integration. Ignore facade fakes like ___SINGLE_BACKTICK___Mail::fake()___SINGLE_BACKTICK___ here, they isolate framework services by default and are not a fork against Mockery.
    - Hint: grep ___SINGLE_BACKTICK___->mock(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->spy(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Mockery::___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___tests/___SINGLE_BACKTICK___.
42. Endpoint assertions: array ___SINGLE_BACKTICK___assertJson([...])___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___assertJsonFragment___SINGLE_BACKTICK___ vs fluent ___SINGLE_BACKTICK___AssertableJson___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___AssertableJson___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___assertJsonFragment___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___tests/___SINGLE_BACKTICK___.

## I. Responses & API resources

43. Response shape: API Resource classes vs ___SINGLE_BACKTICK___response()->json()___SINGLE_BACKTICK___ vs returning models/arrays directly.
    - Hint: ___SINGLE_BACKTICK___ls app/Http/Resources___SINGLE_BACKTICK___; grep ___SINGLE_BACKTICK___JsonResource___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->json(___SINGLE_BACKTICK___ in controllers.
44. Resource relationship inclusion: ___SINGLE_BACKTICK___whenLoaded()___SINGLE_BACKTICK___ guards vs unconditional relationship access. Do not count ordinary scalar attributes as rivals to conditional relationships, and evaluate general ___SINGLE_BACKTICK___when()___SINGLE_BACKTICK___ fields separately.
    - Hint: compare relationship fields using ___SINGLE_BACKTICK___whenLoaded(___SINGLE_BACKTICK___ with unconditional relationship property access in ___SINGLE_BACKTICK___app/Http/Resources___SINGLE_BACKTICK___.
45. Pagination contracts: within comparable endpoint categories, length-aware ___SINGLE_BACKTICK___paginate()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___simplePaginate()___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___cursorPaginate()___SINGLE_BACKTICK___. These have different totals, navigation, ordering, and performance contracts, so record only a stable path-scoped API policy, never a project-wide majority.
    - Hint: grep those in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___, then group matches by endpoint type and client contract before comparing them.
46. Web redirects/URLs: ___SINGLE_BACKTICK___route('name')___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___url('/path')___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___action([...])___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___route('___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___url('/___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___action([___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___app/Http___SINGLE_BACKTICK___ and views.

## J. Strings, collections & dates

47. Iteration idiom: ___SINGLE_BACKTICK___collect()->map()->filter()___SINGLE_BACKTICK___ pipelines vs ___SINGLE_BACKTICK___array_map___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___foreach___SINGLE_BACKTICK___.
    - Hint: grep ___SINGLE_BACKTICK___collect(___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___->map(___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___array_map___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___foreach___SINGLE_BACKTICK___ density in ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___.
48. String API: fluent ___SINGLE_BACKTICK___Str::of()->...___SINGLE_BACKTICK___ (Stringable) vs static ___SINGLE_BACKTICK___Str::___SINGLE_BACKTICK___ vs native (___SINGLE_BACKTICK___trim___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___strtoupper___SINGLE_BACKTICK___).
    - Hint: grep ___SINGLE_BACKTICK___Str::of(___SINGLE_BACKTICK___ vs ___SINGLE_BACKTICK___Str::___SINGLE_BACKTICK___ vs native string funcs.
49. Dates: compare equivalent construction call styles (___SINGLE_BACKTICK___now()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___today()___SINGLE_BACKTICK___ helpers vs ___SINGLE_BACKTICK___Carbon::___SINGLE_BACKTICK___) separately from the application's mutable/immutable date policy. ___SINGLE_BACKTICK___Date::use(CarbonImmutable::class)___SINGLE_BACKTICK___ can make helpers return immutable dates, so those signals are complementary rather than conflicting.
    - Hint: grep ___SINGLE_BACKTICK___now(___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___Carbon::___SINGLE_BACKTICK___ for call style; separately inspect ___SINGLE_BACKTICK___CarbonImmutable___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___Date::use___SINGLE_BACKTICK___ for mutability policy.

---

Genuine forks only. Every row survived the "no tool can decide this, and it isn't the default" filter. Give each applicable dimension exactly one verdict: pattern, conflict, default, no-signal, tooling-owned, or already-recorded. The rows tagged (architecture) are where the highest-value rules come from.
