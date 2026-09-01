# Development

## Getting started

Requires Docker. Everything runs through [Laravel Sail](https://laravel.com/docs/sail);
nothing needs PHP, Node or MySQL on the host.

```bash
git clone https://github.com/jneto14/archivum.git
cd archivum

cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail composer setup
```

`composer setup` installs dependencies, generates the app key, migrates, seeds
the first administrator and default workspace, and builds the front end.

Then, in a second terminal:

```bash
./vendor/bin/sail composer dev
```

which starts four processes together: the Vite dev server, a queue listener,
`pail` tailing the logs, and `artisan serve` (redundant under Sail, which is
already serving the app, but harmless).

The application is on `http://localhost`, and Mailpit catches outgoing mail on
`http://localhost:8025`.

**Keep the queue listener running.** Attachment text extraction, exports and
bulk moves all happen on the queue; without a worker they sit as "queued"
forever and nothing tells you why.

The seeded administrator is `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`. Leave
the password unset and the seeder generates one and prints it once.

### The one thing that will bite you

**Run `npm` through Sail, never from the host.** The Vite build triggers
`wayfinder:generate`, which reads route parameter types from the live database.
Built from the host, where MySQL is unreachable, it silently types UUID keys as
`number` — and that shows up as a TypeScript error in a file you never touched.

```bash
./vendor/bin/sail npm run build     # yes
npm run build                        # no
```

If `types:check` fails somewhere you did not edit, regenerate before believing
it:

```bash
./vendor/bin/sail artisan wayfinder:generate --with-form
```

`--with-form` is not optional either: without it the `.form` variants the
Fortify screens import are missing and the build breaks.

## Checks

One command runs everything CI runs:

```bash
./vendor/bin/sail composer ci:check
```

which is ESLint, Prettier, `tsc`, Pint, PHPStan (via Larastan) and the Pest
suite. Run this rather than the individual tools — it is what catches the
formatting failures the others miss.

Narrower loops while working:

```bash
./vendor/bin/sail artisan test --compact tests/Feature/Documents/DocumentIndexTest.php
./vendor/bin/sail artisan test --compact --filter=test_admin_can_change_a_members_role
./vendor/bin/sail php vendor/bin/pint --dirty
```

## Testing

Pest, in class-based style — the suite uses PHPUnit-shaped test classes rather
than Pest's closure functions, so match the file you are editing.

Guidance that is enforced by review rather than by tooling:

- **Test the behaviour and its important failure modes**, not the getters.
- **Use factories**, and check for a custom state before setting fields by hand.
- **Name the test after the rule it protects**, not the method it calls.
  `test_last_admin_cannot_be_demoted` beats `test_update_role`.
- **Say why in a comment when the reason is not obvious** — particularly when a
  test calls an Action directly because the controller would 404 first.

Some suites use `DatabaseMigrations` rather than `RefreshDatabase`: the search
assertions go through MySQL's full-text index, and InnoDB's FTS does not see
rows written inside an uncommitted transaction.

There are also guards that are not ordinary feature tests, and they are worth
knowing about before you trip one:

| Test | Protects |
| --- | --- |
| `QueryBudgetTest` | The per-request query count of the main pages, and that the documents index does not scale with row count |
| `QueueTimeoutTest` | The ordering of the OCR timeout chain |
| `OcrToolchainParityTest` | That the three places installing the OCR binaries still agree |
| `InstallRecipeTest` | That the documented install recipe still sets the variables whose absence is silently wrong |
| `WorkspaceUsageTest` | Through `EXPLAIN`, that the storage total stays index-only |
| `BrandingTest` | That no Laravel starter-kit asset or string has crept back |

A failure in `QueryBudgetTest` is not automatically a bug. Adding a feature may
legitimately add a query — re-measure, satisfy yourself it is necessary, and
move the number deliberately in the same change.

## Conventions

Path-scoped conventions live in `.ai/rules/`, indexed by `.ai/rules/index.md`.
They are short and specific, and they exist because none of them are caught by
CI:

| File | Covers |
| --- | --- |
| `pages.md` | Every page goes through `PageContainer`; never set your own width or padding |
| `layouts.md` | Layouts are bound by the page rules too |
| `components.md` | Use container queries, not viewport breakpoints, where the sidebar governs the space |
| `css.md` | The four surface tokens must stay distinct and ordered |
| `js.md` | In a row mixing user text with actions, say explicitly what may shrink |
| `general.md` | Generate Wayfinder inside Sail |

Most of them are there because something shipped broken first.

### Design work has no safety net

`ci:check` passes on layouts that are visibly broken. ESLint, Prettier, `tsc`,
Pint, PHPStan and Pest do not know what overflow, clipping, an invisible active
state or an unreadable colour ramp look like.

For any change to layout, spacing, colour tokens or responsive behaviour: open
the application and look at it — at phone width, at tablet width with the
sidebar both open and collapsed, and in both themes. "CI is green" is not "the
layout is validated".

## Internationalisation

Two locales, `en` and `pt`, resolved per user. Server strings live in
`lang/{en,pt}/`, client strings in `resources/js/lib/translations/`.

A string shown to a user goes through the translation layer. A string that only
an operator or a log reader sees does not.

## Code style

- PHP 8.5, `declare(strict_types=1)`, explicit return types and parameter types.
- PHPDoc blocks over inline comments, with real `@param`/`@return`/`@throws` —
  a one-line summary that repeats the method name is not a docblock.
- Curly braces always, even on single-line bodies.
- Constructor property promotion.
- Descriptive names: `isRegisteredForDiscounts`, not `discount()`.

Comments should explain **why**, not what. The what is in the code.

## Contributing

Branch, open a pull request against `main`, and describe what changed and why.
See [CONTRIBUTING.md](../CONTRIBUTING.md).

GitHub is where public collaboration happens. Internal development management
uses YouTrack, but contributors never need access to it and nothing in the
project depends on it.
