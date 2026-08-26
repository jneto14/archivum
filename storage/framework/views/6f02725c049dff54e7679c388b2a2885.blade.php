@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
@endphp
# Naming and Structure

## File Layout

- Name each test file ___SINGLE_BACKTICK___{ClassName}Test.php___SINGLE_BACKTICK___.
- Place each test file at the same relative path as the class under test. The class ___SINGLE_BACKTICK___app/Actions/DeleteTeam.php___SINGLE_BACKTICK___ gets the test ___SINGLE_BACKTICK___tests/Unit/Actions/DeleteTeamTest.php___SINGLE_BACKTICK___.
- Follow the project's convention for fixture files. If none exists, put fixtures in ___SINGLE_BACKTICK___tests/Fixtures/___SINGLE_BACKTICK___ and load them by path.
- Move large literal values out of the test body and into fixture files.

@if($pest)
## The Test Function

Use the test function used by other files in the same directory. If no neighboring test files exist:

- Use ___SINGLE_BACKTICK___it()___SINGLE_BACKTICK___ for the behavior of the code, and write the name as a verb phrase.
- Use ___SINGLE_BACKTICK___test()___SINGLE_BACKTICK___ for a declarative fact, such as a grant in a policy, the labels of an enum, or the shape of a serialized model.

Use one Pest declaration style in each file. Use either ___SINGLE_BACKTICK___it()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___test()___SINGLE_BACKTICK___ consistently.

## The Names of the Tests

The name of a test is a specification. State the user-visible result and the condition that causes it.

- Name the behavior, and not the method under test. The file name already gives the class.
- Give the exact status code in the name of a test for an API error.
- Do not write ___SINGLE_BACKTICK___Given___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___When___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___Then___SINGLE_BACKTICK___ in the name.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
it('returns 401 when no token is provided', function () { ... });
it('does not include deployments from deleted environments', function () { ... });
it('falls back to the default region when none is configured', function () { ... });
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
@else
## The Test Class and the Test Methods

- Extend the base ___SINGLE_BACKTICK___TestCase___SINGLE_BACKTICK___ of the project in each test class.
- Give each test method the prefix ___SINGLE_BACKTICK___test____SINGLE_BACKTICK___, or add the ___SINGLE_BACKTICK___#[Test]___SINGLE_BACKTICK___ attribute to the method. Use the convention of the other files in the same directory.

## The Names of the Tests

The name of a test method is a specification. Separate the words with underscores. State the user-visible result and the condition that causes it.

- Name the behavior, and not the method under test. The file name already gives the class.
- Give the exact status code in the name of a test for an API error.
- Do not write ___SINGLE_BACKTICK___given___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___when___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___then___SINGLE_BACKTICK___ in the name.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
public function test_unauthenticated_request_redirects_to_login(): void { ... }
public function test_returns_401_when_no_token_is_provided(): void { ... }
public function test_valid_payload_creates_record_and_returns_201(): void { ... }
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
@endif

Use a verb that describes a result, such as ___SINGLE_BACKTICK___returns___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___renders___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___creates___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___dispatches___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___rejects___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___forbids___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___falls back___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___does not___SINGLE_BACKTICK___.

@if($pest)
Do not write ___SINGLE_BACKTICK___it('works correctly')___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___it('returns data')___SINGLE_BACKTICK___, because neither specifies a meaningful result. Do not write ___SINGLE_BACKTICK___it('handleMethod creates record')___SINGLE_BACKTICK___, because it names a method rather than behavior.
@else
Do not write ___SINGLE_BACKTICK___test_store()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___test_it_works()___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___test_validation()___SINGLE_BACKTICK___, because none of them gives a result.
@endif

## Grouping

@if($pest)
Use ___SINGLE_BACKTICK___describe()___SINGLE_BACKTICK___ if one file covers separate actions in a lifecycle. An example is a controller with the actions ___SINGLE_BACKTICK___index___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___show___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___store___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___update___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___destroy___SINGLE_BACKTICK___.

Do not use ___SINGLE_BACKTICK___describe()___SINGLE_BACKTICK___ in these cases:

- The file covers one action or one flow.
- The tests are different only in the input value. Use a dataset instead.
- The group adds a level but does not make the file easier to read.
@else
Write one test class for each class under test. Write a separate test class if one file covers separate actions in a lifecycle, such as ___SINGLE_BACKTICK___StoreOrderControllerTest___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___UpdateOrderControllerTest___SINGLE_BACKTICK___.

Use the ___SINGLE_BACKTICK___#[Group]___SINGLE_BACKTICK___ attribute to mark the tests that a run must select or must skip. Do not use a group to give structure to a file, because a class gives the structure.
@endif
