@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
$pest5 = $assist->hasPackage('pestphp/pest', '>=5.0');
@endphp
# Assertions

## Arrange, Act, Assert

Write each test in three parts: setup, one action, and assertions. Put one blank line between them so readers can identify each part without comments.

Keep each test self-contained. Do not use values created by another test.

## How to Find the Correct Assertion

First identify the subject of the check, then find an assertion designed for it. A subject-specific assertion identifies the incorrect value when the test fails.

1. Search Laravel's assertions for framework subjects such as responses, the database, sessions, models, queues, events, mail, and notifications.
2. Fetch {{ $pest ? '___SINGLE_BACKTICK___https://pestphp.com/docs/expectations.md___SINGLE_BACKTICK___ for the expectations of Pest' : '___SINGLE_BACKTICK___https://docs.phpunit.de/en/13.3/assertions.html___SINGLE_BACKTICK___ for the assertions of PHPUnit' }} for a plain value, a type, a format, or a shape.
3. Build the check by hand only if no assertion exists for the subject.
4. Confirm the name in the documentation before you use it. Do not write an assertion that you did not confirm.

Use the assertion in this table for each subject.

@if($pest)
| Subject | Assertion to use |
| --- | --- |
| A return value, the state of an object, or a transformation of a value | an ___SINGLE_BACKTICK___expect()___SINGLE_BACKTICK___ chain |
| An HTTP status, JSON, a session, or Inertia | a Laravel response assertion |
| The state in the database | a Laravel database assertion |
| The existence of a model | ___SINGLE_BACKTICK___assertModelExists($model)___SINGLE_BACKTICK___ rather than ___SINGLE_BACKTICK___assertDatabaseHas('users', ['id' => $user->id])___SINGLE_BACKTICK___ |

Use a PHPUnit assertion only if no Pest expectation and no Laravel assertion exists for the subject.
@else
| Subject | Assertion to use |
| --- | --- |
| A return value, the state of an object, or a transformation of a value | ___SINGLE_BACKTICK___assertSame()___SINGLE_BACKTICK___, or the assertion for the type |
| An HTTP status, JSON, a session, or Inertia | a Laravel response assertion |
| The state in the database | a Laravel database assertion |
| The existence of a model | ___SINGLE_BACKTICK___assertModelExists($model)___SINGLE_BACKTICK___ rather than ___SINGLE_BACKTICK___assertDatabaseHas('users', ['id' => $user->id])___SINGLE_BACKTICK___ |

Use ___SINGLE_BACKTICK___assertSame()___SINGLE_BACKTICK___ and not ___SINGLE_BACKTICK___assertEquals()___SINGLE_BACKTICK___, because ___SINGLE_BACKTICK___assertSame()___SINGLE_BACKTICK___ also compares the type.
@endif

Assert each fact once. Do not assert a 200 status before ___SINGLE_BACKTICK___assertSee___SINGLE_BACKTICK___, because ___SINGLE_BACKTICK___assertSee___SINGLE_BACKTICK___ already shows that the page rendered.

## The Assertion with a Name for a Response

Use a named response assertion, such as ___SINGLE_BACKTICK___assertNotFound()___SINGLE_BACKTICK___, rather than ___SINGLE_BACKTICK___assertStatus(404)___SINGLE_BACKTICK___. A failure then identifies the broken contract. Laravel provides named assertions for commonly tested status codes.

@if($pest)
Keep one ___SINGLE_BACKTICK___expect()___SINGLE_BACKTICK___ chain on one subject. Start a new chain when the subject changes, or when the chain is difficult to read.
@else
Group the assertions for one subject together. Start a new group when the subject changes.
@endif

@if($pest5)
## The Expectation for a Format

Use Pest's format expectations rather than regular expressions because they provide clearer failure messages. Pest covers email addresses, URLs, UUIDs, IP addresses, and other common formats, and each expectation supports ___SINGLE_BACKTICK___not___SINGLE_BACKTICK___ for the negative case.

@endif
## Assert a Known Value

Write the expected value in the test, or calculate the expected value by a different method. Do not calculate the expected value with the logic of the implementation, because the test then passes when that logic is wrong.

@if($pest)
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// The test calculates the value with the logic of the implementation.
$expected = now()->subHours(24)->floorSeconds(30)->toJson();
expect($from)->toBe($expected);

// The test sets a fixed input and asserts a known value.
travelTo('2025-01-01 00:00:00');
expect($from)->toBe('2024-12-31T00:00:00.000000Z');
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
@else
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// The test calculates the value with the logic of the implementation.
$expected = now()->subHours(24)->floorSeconds(30)->toJson();
$this->assertSame($expected, $from);

// The test sets a fixed input and asserts a known value.
$this->travelTo('2025-01-01 00:00:00');
$this->assertSame('2024-12-31T00:00:00.000000Z', $from);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
@endif

## Assert the Complete Result

A status code is not the complete result of a write operation. Assert each of the following if the operation changes it:

- the response or the return value
- the state in the database
- the jobs and the events that the operation dispatches
- the notifications and the mail that the operation sends

On the failure path, assert that the operation makes none of these changes. A test that asserts only ___SINGLE_BACKTICK___assertOk()___SINGLE_BACKTICK___ passes even when the application saves no record.
