@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
$pest5 = $assist->hasPackage('pestphp/pest', '>=5.0');
@endphp
# How to Find Test Framework Features

@if($pest)
Pest adds features faster than this skill can list them. Find an existing feature before implementing the behavior by hand.

- Give ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ the capability you need rather than the name of a function you remember. It returns features available in the installed version.
- Fetch ___SINGLE_BACKTICK___https://pestphp.com/llms.txt___SINGLE_BACKTICK___ for the complete feature list and additions in each release.
- If a search returns no results, tell the user that the installed version does not provide the feature. Do not write an API that you have not confirmed.

Search for a feature in this table before you write the code by hand.

| Work that you need | Term to search for |
| --- | --- |
| Run one test with many input values | datasets, bound datasets |
| Assert over many values or over a collection | higher-order expectations |
| Remove the same setup from each test in a file | hooks, higher-order tests |
| Apply a convention to the complete codebase | architecture testing |
| Measure if the suite finds a defect | mutation testing |
| Find code with no types | type coverage |
| Reduce the time of a slow suite | parallel, profiling |
@if($pest5)
| Split the suite across the jobs of the CI | sharding, ___SINGLE_BACKTICK___--update-shards___SINGLE_BACKTICK___ |
| Run only the tests that a change affects | Test Impact Analysis, ___SINGLE_BACKTICK___--tia___SINGLE_BACKTICK___ |
| Assert that a value has a known format | validation expectations |
@endif
| Run one test while you debug | filtering, ___SINGLE_BACKTICK___--bail___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___--dirty___SINGLE_BACKTICK___ |
@else
PHPUnit and Laravel provide features for most testing needs. Find an existing feature before implementing the behavior by hand.

- Give ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ the capability you need rather than the name of a method you remember. It returns Laravel testing documentation for the installed version.
- Fetch ___SINGLE_BACKTICK___https://phpunit.de/documentation.html___SINGLE_BACKTICK___ for version-specific PHPUnit attributes, assertions, and command-line options.
- If a search returns no results, tell the user that the installed version does not provide the feature. Do not write an API that you have not confirmed.

Search for a feature in this table before you write the code by hand.

| Work that you need | Term to search for |
| --- | --- |
| Run one test method with many input values | data provider, ___SINGLE_BACKTICK___#[DataProvider]___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[TestWith]___SINGLE_BACKTICK___ |
| Run one test only after another test passes | ___SINGLE_BACKTICK___#[Depends]___SINGLE_BACKTICK___ |
| Select or skip a set of tests in one run | ___SINGLE_BACKTICK___#[Group]___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___--group___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___--exclude-group___SINGLE_BACKTICK___ |
| Skip a test on a version or on a missing extension | ___SINGLE_BACKTICK___#[RequiresPhp]___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[RequiresPhpExtension]___SINGLE_BACKTICK___ |
| Find a test that depends on the order of the run | ___SINGLE_BACKTICK___--order-by=random___SINGLE_BACKTICK___ |
| Reduce the time of a slow suite | ParaTest, ___SINGLE_BACKTICK___--cache-result___SINGLE_BACKTICK___ |
| Stop the run at the first failure while you debug | ___SINGLE_BACKTICK___--stop-on-failure___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___--filter___SINGLE_BACKTICK___ |
@endif

## The Assertions of Laravel

Laravel provides assertions for each part of the framework. Fetch ___SINGLE_BACKTICK___https://laravel.com/framework/docs/testing___SINGLE_BACKTICK___ for the complete list, and search for an assertion before building a check by hand. Examples include ___SINGLE_BACKTICK___assertDatabaseHas()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___assertModelExists()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___assertSoftDeleted()___SINGLE_BACKTICK___, response assertions such as ___SINGLE_BACKTICK___assertRedirectToRoute()___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___assertJsonPath()___SINGLE_BACKTICK___, and fake assertions such as ___SINGLE_BACKTICK___Queue::assertPushed()___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___Notification::assertSentTo()___SINGLE_BACKTICK___.

A hand-built check fails with ___SINGLE_BACKTICK___false is not true___SINGLE_BACKTICK___, which identifies nothing. A framework assertion names the incorrect table, value, or response, so the failure indicates what to fix.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// The failure says that false is not true.
// Instead of this
@if($pest)
expect(User::where('email', 'taylor@laravel.com')->exists())->toBeTrue();
@else
$this->assertTrue(User::where('email', 'taylor@laravel.com')->exists());
@endif

// Use this
// The failure names the table and the attributes that it did not find.
$this->assertDatabaseHas('users', ['email' => 'taylor@laravel.com']);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
