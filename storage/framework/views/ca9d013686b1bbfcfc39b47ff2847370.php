---
name: infer-conventions
description: "Use this skill to analyze how a Laravel application is actually written and record its conventions as shared rules. Trigger when the user wants to detect, infer, document, or standardize project conventions or coding style, set up or grow ___SINGLE_BACKTICK___.ai/rules___SINGLE_BACKTICK___, resolve mixed or conflicting patterns (e.g. \"are we using Form Requests or inline validation?\"), or onboard agents and teammates to \"how we do things here\". Covers: a systematic sweep of ~49 Laravel convention dimensions (validation, models, architecture, testing, frontend, database, console), open-ended house-pattern discovery, conflict reporting, and recording rules scoped to the right paths via the Boost ___SINGLE_BACKTICK___record-rule___SINGLE_BACKTICK___ MCP tool. Do not use for one-off code review, enforcing formatting a linter already handles, or editing ___SINGLE_BACKTICK___.ai/rules___SINGLE_BACKTICK___ files by hand."
license: MIT
metadata:
  author: laravel
---
<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
?>
# Infer Conventions

Learn how this application writes Laravel, then record what you learn as durable, path-scoped rules other agents will read. You are documenting reality, not improving it.

## Ground Rules (read before you start)

- Consistency first. The codebase's majority style is the convention. Never judge it, never propose a "better" pattern, never record what the code should do. If the app validates inline everywhere, that is the rule, even if Form Requests would be nicer.
- Skip what an active tool produces, keep what a tool would fight. Inspect the project's Pint and Rector configuration first; a Rector transformation is tooling-owned only when its package and relevant rule or set are installed and enabled. Active tools may rewrite code toward one canonical form: ___SINGLE_BACKTICK___$casts___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___casts()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___$fillable___SINGLE_BACKTICK___ to attributes, magic accessors to the ___SINGLE_BACKTICK___Attribute___SINGLE_BACKTICK___ class, pipe-string rules to arrays, ___SINGLE_BACKTICK___$signature___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___#[Signature]___SINGLE_BACKTICK___, named migrations to anonymous, and many more. When the app already sits at an active tool's target form, the tool owns it, so record nothing. But when the app deliberately holds a form an active tool would refactor away, such as legacy ___SINGLE_BACKTICK___getXxxAttribute()___SINGLE_BACKTICK___ accessors the ___SINGLE_BACKTICK___Attribute___SINGLE_BACKTICK___ class would replace, no tool can reproduce that choice and an agent defaults the other way. That against-the-grain hold is exactly what to record.
- Record decisions, not defaults. A consistent pattern earns a rule only when it reflects a choice: the app took one valid option where the framework or common practice offered others, or the pattern would surprise a competent agent. Framework defaults steer nothing, so skip them: anonymous migrations, ___SINGLE_BACKTICK___$signature___SINGLE_BACKTICK___ commands, ___SINGLE_BACKTICK___ShouldQueue___SINGLE_BACKTICK___ jobs, ___SINGLE_BACKTICK___casts()___SINGLE_BACKTICK___ on Laravel 11+, named routes, Rule objects in ___SINGLE_BACKTICK___app/Rules___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___Mail::fake()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___Bus::fake()___SINGLE_BACKTICK___ to isolate framework services. A real fork is not enough on its own. Weigh the side the app took, and record only the side an agent would not reach for by itself: inline closures everywhere, legacy accessors, a bespoke query layer. Watch for the false fork too. "No Mockery" next to facade fakes is not a choice against Mockery, because they double different things. The test for every candidate: without this rule, would the next agent plausibly write it differently? Only "yes" earns a rule.
- Architecture choices are the gold. Record presence and deliberate absence. The structural pattern the app commits to is the highest-signal convention and the one no tool can decide: Action classes and how they are invoked (___SINGLE_BACKTICK___handle___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___execute___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK_____invoke___SINGLE_BACKTICK___), service objects, dedicated query objects exposing ___SINGLE_BACKTICK___builder()___SINGLE_BACKTICK___, DTOs (spatie/laravel-data vs readonly classes), Form Request validation vs inline, an events and listeners spine vs direct calls, and domain or module folders. Also record a consistent non-pattern, such as "query Eloquent directly in controllers, no repository layer", so the next agent matches the app's altitude instead of over-engineering.
- Never duplicate ___SINGLE_BACKTICK___.ai/rules___SINGLE_BACKTICK___. Read ___SINGLE_BACKTICK___.ai/rules/index.md___SINGLE_BACKTICK___ and the area files before the sweep. A dimension already covered there is marked done and skipped.
- Evidence or silence. A convention needs at least 3 consistent examples and no meaningful rival to become a candidate. Every Step 1 verdict applies this bar.
- The recorded rule states the convention, nothing else. One or two imperative lines: this project does X, so do X here. Keep detection evidence out. No counts, ratios, current usage, file lists, or example paths, because that is proof for the confirm step, not part of the rule. One short syntax fragment at most, and point to ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ for API details.

## Process

Each step ends on a checkable completion criterion. Do not advance until it holds.

Fan out when you can. The sweep is embarrassingly parallel. If your environment can spawn subagents (a Task, dispatch, or equivalent tool), do Step 0 yourself, then hand each checklist group (A to J) and the architecture map to its own subagent. Each subagent runs the greps, reads a few representative files, and returns structured verdicts (dimension, verdict, evidence, proposed glob / title / note). You aggregate, dedupe, then run Steps 3 to 5. It is far faster on a real app. No subagents available? Run the steps in sequence, with the same bar and the same output.

### Step 0: Orient

Read ___SINGLE_BACKTICK___composer.json___SINGLE_BACKTICK___ (installed packages tell you which checklist groups apply), the ___SINGLE_BACKTICK___pint.json___SINGLE_BACKTICK___ / PHPStan / Rector config, ___SINGLE_BACKTICK___.ai/rules/index.md___SINGLE_BACKTICK___ if present, and most important, map the ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ tree. List every directory under ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ (and any ___SINGLE_BACKTICK___Modules/___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___src/___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___packages/___SINGLE_BACKTICK___, or domain root). Every folder beyond Laravel's default skeleton (___SINGLE_BACKTICK___Http___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Models___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Providers___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Console___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Exceptions___SINGLE_BACKTICK___) is a structural pattern the app committed to and a high-value rule waiting to be written: ___SINGLE_BACKTICK___Actions___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Services___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Data___SINGLE_BACKTICK___ or DTOs, ___SINGLE_BACKTICK___Queries___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Repositories___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___ViewModels___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Pipelines___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Support___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Enums___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Contracts___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Observers___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___Domain___SINGLE_BACKTICK___ and module roots. Note each one. You will confirm how it is used in Step 2.

<?php if($assist->hasPackage('livewire/livewire') || $assist->hasPackage('inertiajs/inertia-laravel') || $assist->hasPackage('livewire/flux') || $assist->hasPackage('livewire/flux-pro')): ?>
This app ships a frontend stack, so the frontend checklist group applies. Sweep it.
<?php else: ?>
This app has no Livewire/Inertia/Flux packages installed. Treat the frontend group as likely API-only: confirm from ___SINGLE_BACKTICK___resources/views___SINGLE_BACKTICK___ before spending time there, and skip the Livewire/Inertia/Flux dimensions.
<?php endif; ?>

Done when: you have the applicable checklist groups, the dimensions already recorded in ___SINGLE_BACKTICK___.ai/rules___SINGLE_BACKTICK___, and a list of every non-default ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ directory mapped to the pattern it represents.

### Step 1: Predefined sweep

Open ___SINGLE_BACKTICK___references/checklist.md___SINGLE_BACKTICK___ and work every applicable dimension using its search hints. Give each exactly one verdict:

- Pattern. Clears the bar, rival under ~20% of sites, and reflects a real choice (passes the decisions-not-defaults test). A recording candidate. Cite 2 to 3 example files.
- Conflict. Both styles present in meaningful numbers. Report the split with counts and example files. Never record a preferred winner while the code remains mixed, even in yolo, because that would describe an aspiration rather than reality. Record only if the user identifies a stable path or context boundary that explains both styles; otherwise defer until the code is reconciled.
- Default. Consistent, but a framework or common-practice default the agent already writes unprompted. Skip it as a no-op, not a convention.
- No signal. Under the bar: feature unused, or too few examples. Skip silently (one summary line at most).
- Tooling-owned or Already-recorded. Skip per the ground rules.

Done when: every applicable dimension carries exactly one of those verdicts.

### Step 2: Open-ended pass

First, close out the architecture map from Step 0. For every non-default ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ directory you listed, confirm how the pattern is used and apply the same evidence and decisions-not-defaults tests as Step 1. Generator-standard or sparsely used directories such as ___SINGLE_BACKTICK___Rules___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Observers___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Mail___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___Notifications___SINGLE_BACKTICK___ are signals to inspect, not automatic conventions. Make genuine structural patterns candidates: Action classes invoked via ___SINGLE_BACKTICK___handle___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___execute___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK_____invoke___SINGLE_BACKTICK___, Services constructor-injected, ___SINGLE_BACKTICK___Queries___SINGLE_BACKTICK___ objects exposing ___SINGLE_BACKTICK___builder(): Builder___SINGLE_BACKTICK___, DTOs as readonly classes or spatie/laravel-data, module or domain folders as the unit of organization. Scope each qualifying pattern to its own directory glob. Also record a consistent deliberate absence, such as "no repository layer, controllers query Eloquent directly", so the next agent matches the app's altitude.

Then find what else makes this codebase itself: base or abstract classes most code extends, traits used everywhere, tenancy or authorization scoping woven through queries, naming schemes, and custom helpers. Same evidence bar, cite files. Record every genuine structural pattern, and cap the other house findings at ~5 so the pass stays high-signal.

Done when: every non-default ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ directory from Step 0 has a verdict, and the pass has produced its cited house findings (or concluded there are none).

### Step 3: Confirm

Present every candidate in one batch. Per item: dimension, verdict, evidence (counts and files), and the exact proposed ___SINGLE_BACKTICK___glob___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___globs___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___title___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___note___SINGLE_BACKTICK___. Conflicts are presented as questions about an existing context boundary or deferred cleanup, not as a choice of future style.

Default mode is confirm: record only what the user approves. Switch to yolo only when the invocation said so ("yolo", "don't ask", "just record them"), then record all pattern candidates without asking. Conflicts still go to the user in yolo.

Done when: every candidate is approved, rejected, or (conflicts) decided.

### Step 4: Record

Make one ___SINGLE_BACKTICK___record-rule___SINGLE_BACKTICK___ call for each glob an approved convention applies to. Choose the most specific globs that cover the cited evidence from the mapping table below; if a convention spans models and migrations, record it under both domains so agents discover it from either path. The ___SINGLE_BACKTICK___note___SINGLE_BACKTICK___ is the bare convention: strip every trace of detection (see the ground rule). If ___SINGLE_BACKTICK___record-rule___SINGLE_BACKTICK___ is unavailable (rules disabled), report the full rule text so the user can enable ___SINGLE_BACKTICK___BOOST_RULES_ENABLED___SINGLE_BACKTICK___ or add it by hand.

Record this:

> Accessors and mutators: use the legacy magic-method style (___SINGLE_BACKTICK___getXxxAttribute()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___setXxxAttribute()___SINGLE_BACKTICK___), not the ___SINGLE_BACKTICK___Attribute___SINGLE_BACKTICK___ class. Match it in models.

Not this:

> Accessors/mutators use the legacy magic-method style; the ___SINGLE_BACKTICK___Attribute___SINGLE_BACKTICK___-class style is not used anywhere (13 legacy, 0 Attribute-class), e.g. ___SINGLE_BACKTICK___app/Models/Post.php___SINGLE_BACKTICK___. Match the legacy style in existing models.

Done when: every approved item has a successful tool response, and any failure is reported with its rule text.

### Step 5: Summarize

List recorded rules (file and title), conflicts the user deferred, notable no-signals, and remind the user to commit ___SINGLE_BACKTICK___.ai/rules___SINGLE_BACKTICK___ so their team and agents share the conventions.

## Glob mapping

Attach each rule to the most specific path that covers its evidence. Never a lazy ___SINGLE_BACKTICK___app/**___SINGLE_BACKTICK___ when a subtree fits. Match the glob to where the code actually lives, which is not the same in a default skeleton and in a modular or DDD layout. Use the Step 0 ___SINGLE_BACKTICK___app/___SINGLE_BACKTICK___ map to pick the real path.

Examples:

- Models: ___SINGLE_BACKTICK___app/Models/**___SINGLE_BACKTICK___ in a default app, or ___SINGLE_BACKTICK___app/Modules/Blog/Models/**___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___src/Domain/Blog/**___SINGLE_BACKTICK___ in a modular one.
- Controllers, routing, validation, responses: ___SINGLE_BACKTICK___app/Http/**___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___app/Modules/*/Http/**___SINGLE_BACKTICK___ when each module owns its HTTP layer.
- Actions, Services, DTOs: ___SINGLE_BACKTICK___app/Actions/**___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___app/Services/**___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___app/Data/**___SINGLE_BACKTICK___, or the module path the app actually uses.
- Tests: ___SINGLE_BACKTICK___tests/**___SINGLE_BACKTICK___.
- Migrations and database: ___SINGLE_BACKTICK___database/migrations/**___SINGLE_BACKTICK___.
- Truly app-wide (rare, e.g. auth retrieval): ___SINGLE_BACKTICK___app/**___SINGLE_BACKTICK___.

___SINGLE_BACKTICK___record-rule___SINGLE_BACKTICK___ takes one glob. When a convention genuinely spans two domains (e.g. UUID keys touch models and migrations), call it once per domain with the same title and note; mentioning another path in the note does not make the rule discoverable there.

## Edge cases

- Rules disabled or ___SINGLE_BACKTICK___record-rule___SINGLE_BACKTICK___ missing: detection is read-only, so Steps 0 to 3 still run, and recording falls back to the manual path in Step 4.
- Tiny or fresh app: most dimensions land on no-signal. Say so honestly ("not enough code to infer conventions yet") and record nothing.
- Huge app: each dimension is a bounded grep plus a handful of file reads. Sample representative files, do not read everything.
- Re-runs: reading ___SINGLE_BACKTICK___.ai/rules___SINGLE_BACKTICK___ in Step 0 makes re-runs incremental, so only new or undecided dimensions surface.
- Non-standard layout (modules, DDD): the open-ended pass catches the layout itself as convention #1. Adapt the globs in the mapping table to the observed paths.
<?php /**PATH /tmp/claude-1000/-home-neto-Projects-Archivum/68a100c7-112d-496c-b1b6-82b0f1ebfc76/scratchpad/archivum-scaffold/storage/framework/views/2f4c5b316b4cb31ef0cd8c1e35ddd7b4.blade.php ENDPATH**/ ?>