@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
$pest5 = $assist->hasPackage('pestphp/pest', '>=5.0');
@endphp
# Test Suite Performance

These settings apply to the project and CI, not to individual tests. Read ___SINGLE_BACKTICK___rules/isolation.md___SINGLE_BACKTICK___ for choices within a test.

@if($pest)
Fetch ___SINGLE_BACKTICK___https://pestphp.com/docs/optimizing-tests___SINGLE_BACKTICK___ for Pest options that make test runs faster.
@else
Fetch ___SINGLE_BACKTICK___https://docs.phpunit.de/en/13.3/___SINGLE_BACKTICK___ for PHPUnit options that make test runs faster.
@endif
Verify each flag in the documentation before adding it to CI.

Measure before changing a setting. Find the slow test first, and apply a project-wide setting only after identifying the costly work.

## The Environment

- Set ___SINGLE_BACKTICK___BCRYPT_ROUNDS=4___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___.env.testing___SINGLE_BACKTICK___ or in ___SINGLE_BACKTICK___phpunit.xml___SINGLE_BACKTICK___. The default value is 12, and the hash then takes most of the time of each test that signs a user in.
- Disable XDebug. Disable pcov also, unless the run needs the coverage.
- Disable packages that perform work on every request in the test environment. Examples are Pulse, Telescope, and Nightwatch.
- Use the ___SINGLE_BACKTICK___WithCachedConfig___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___WithCachedRoutes___SINGLE_BACKTICK___ traits, so the run does not parse the configuration and the routes for every test.
- Call ___SINGLE_BACKTICK___withoutVite()___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___withoutMix()___SINGLE_BACKTICK___, so the framework does not resolve a built asset.

## The Global Fakes

@if($pest)
Put these three calls in the base ___SINGLE_BACKTICK___Pest.php___SINGLE_BACKTICK___ of the project:
@else
Put these three calls in the ___SINGLE_BACKTICK___setUp()___SINGLE_BACKTICK___ of the base ___SINGLE_BACKTICK___TestCase___SINGLE_BACKTICK___ of the project:
@endif

- ___SINGLE_BACKTICK___Http::preventStrayRequests()___SINGLE_BACKTICK___, because one request that reaches the network can slow the suite. This catches requests made through Laravel's HTTP client. Check direct Guzzle and cURL usage separately.
- ___SINGLE_BACKTICK___Sleep::fake(syncWithCarbon: true)___SINGLE_BACKTICK___, so a retry and a backoff do not sleep.
- ___SINGLE_BACKTICK___Exceptions::fake()___SINGLE_BACKTICK___, so the suite does not report an exception to an external service.

## How to Run the Suite in Parallel

@if($pest)
Run ___SINGLE_BACKTICK___{{ $assist->binCommand('pest --parallel') }}___SINGLE_BACKTICK___ to spread tests across the machine's CPU cores. Add ___SINGLE_BACKTICK___--processes=N___SINGLE_BACKTICK___ if the default count is unsuitable for the machine or CI.
@else
Run ___SINGLE_BACKTICK___{{ $assist->artisanCommand('test --parallel') }}___SINGLE_BACKTICK___, which uses ParaTest, to spread tests across the machine's CPU cores. Add ___SINGLE_BACKTICK___--processes=N___SINGLE_BACKTICK___ if the default count is unsuitable for the machine or CI.
@endif

A parallel run gives each process a separate database. Tests must meet these conditions; a test that fails only in parallel breaks one of them:

- The test creates each record that it reads. It does not read a record that another test creates.
- The test does not depend on the order of the run.
- The test does not share a file, a cache key, or a queue with another test. Give each process a separate name for such a resource.

@if($pest5)
## How to Run Fewer Tests

Run ___SINGLE_BACKTICK___{{ $assist->binCommand('pest --parallel --tia') }}___SINGLE_BACKTICK___ to run only the tests that the recent changes affect. Pest replays the cached result of each other test.

Pest replays cached results rather than skipping unaffected tests. The cache includes each produced value and the covered lines and branches. Pest finds affected Laravel, Symfony, Livewire, and Inertia tests without configuration.

## How to Split the Tests Across the CI

Run ___SINGLE_BACKTICK___{{ $assist->binCommand('pest --update-shards') }}___SINGLE_BACKTICK___ to measure the time of each test. Run ___SINGLE_BACKTICK___{{ $assist->binCommand('pest --shard=1/4') }}___SINGLE_BACKTICK___ in each job of the CI, and change the first number for each job.

Commit ___SINGLE_BACKTICK___tests/.pest/shards.json___SINGLE_BACKTICK___ so each CI job gets the same shard and the shards remain balanced by runtime rather than test count.

@endif
## How to Find a Slow Test

@if($pest)
Run ___SINGLE_BACKTICK___{{ $assist->binCommand('pest --profile') }}___SINGLE_BACKTICK___ to list the slowest tests. Start with the ten slowest tests, because the same cause often applies to the complete suite.
@else
Run ___SINGLE_BACKTICK___{{ $assist->artisanCommand('test --profile') }}___SINGLE_BACKTICK___ to list the slowest tests. Start with the ten slowest tests, because the same cause often applies to the complete suite.
@endif

If the cause of a slow test is unclear, add an event listener or temporary log entry to identify its work.

## Common Errors

- The run loads XDebug for a test that does not need it.
- ___SINGLE_BACKTICK___BCRYPT_ROUNDS___SINGLE_BACKTICK___ keeps the default value, because the project has no ___SINGLE_BACKTICK___.env.testing___SINGLE_BACKTICK___.
- The code under test calls the real ___SINGLE_BACKTICK___sleep()___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___Sleep::fake()___SINGLE_BACKTICK___ then does not help.
