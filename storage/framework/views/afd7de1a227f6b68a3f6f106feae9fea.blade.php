@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
@endphp
# Fakes, Mocks, and Determinism

Tests that depend on actual time, randomness, sleeping, or network calls can fail for reasons unrelated to the code under test. Control all four.

## How to Isolate a Dependency

Fetch ___SINGLE_BACKTICK___https://laravel.com/framework/docs/mocking___SINGLE_BACKTICK___ for Laravel's fakes, facade doubles, and fake assertions. Confirm each name before using it.

Identify the dependency, then choose the first applicable option. A framework fake preserves the real code path, while a mock replaces the dependency.

1. Use framework fakes for facades such as events, queues, mail, notifications, storage, the HTTP client, time, and sleep.
2. Use the fake implementation of the project for a service of the project, if such a fake exists.
3. Use a mock for a container-resolved contract only when the real implementation leaves the process or is nondeterministic.
4. Use the real implementation for everything else, including the database.

## The Fakes

@if($pest)
- Create each fake inside the test that needs it. Do not create fakes in a file-level ___SINGLE_BACKTICK___beforeEach()___SINGLE_BACKTICK___.
@else
- Create each fake inside the test method that needs it. Do not create fakes in ___SINGLE_BACKTICK___setUp()___SINGLE_BACKTICK___.
@endif
- Pass class names to ___SINGLE_BACKTICK___Event::fake()___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___Queue::fake()___SINGLE_BACKTICK___ when you know which classes the code dispatches. A fake without class names can hide an unexpected dispatch.
- Use a fake without class names only when the test asserts the complete result, including a call to ___SINGLE_BACKTICK___assertNothingPushed()___SINGLE_BACKTICK___.
- Write one assertion for each fake. The assertion states that the code dispatches the item, or that the code does not dispatch the item.
- Assert the data of a job or of an event if that data is part of the behavior.
- Use ___SINGLE_BACKTICK___Exceptions::fake()___SINGLE_BACKTICK___ to assert that the application reports the correct exception. Do not use ___SINGLE_BACKTICK___withoutExceptionHandling()___SINGLE_BACKTICK___, because it changes the response under test.

Create prerequisite factory records before calling ___SINGLE_BACKTICK___Event::fake()___SINGLE_BACKTICK___. Factories use model events, such as a ___SINGLE_BACKTICK___creating___SINGLE_BACKTICK___ hook that generates a UUID, and a fake without class names suppresses those events and can produce an invalid model. Call the fake first only when a factory event is under test, and pass that event's class name.

## The Mocks

Use ___SINGLE_BACKTICK___shouldReceive()___SINGLE_BACKTICK___ before the action to declare an expectation. Use ___SINGLE_BACKTICK___shouldHaveReceived()___SINGLE_BACKTICK___ after the action for a spy. Use ___SINGLE_BACKTICK___Mockery::on()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___withArgs()___SINGLE_BACKTICK___ if an equality check cannot state the expected argument, such as a check of one field of a value object.

@if($pest)
Import the mock function before you use it: ___SINGLE_BACKTICK___use function Pest\Laravel\mock;___SINGLE_BACKTICK___.
@else
Use ___SINGLE_BACKTICK___$this->mock(Contract::class)___SINGLE_BACKTICK___ to put a mock in the container. Do not build a PHPUnit mock for a class that Mockery can double, because the project uses Mockery.
@endif

## The Outbound HTTP

Call ___SINGLE_BACKTICK___Http::preventStrayRequests()___SINGLE_BACKTICK___. Any request without a matching fake then fails without reaching the network.

Fake the exact endpoint used by each test. Do not call ___SINGLE_BACKTICK___Http::fake()___SINGLE_BACKTICK___ without an endpoint because it accepts unexpected requests and can hide defects.

## The Time and the Randomness

- Freeze the time or move the time in each test that depends on a date, a period, or a timestamp.
@if($pest)
- Use the framework helpers ___SINGLE_BACKTICK___freezeTime()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___travelTo()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___travel()___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___travelBack()___SINGLE_BACKTICK___. Do not call ___SINGLE_BACKTICK___Carbon::setTestNow()___SINGLE_BACKTICK___.
@else
- Use the framework helpers ___SINGLE_BACKTICK___$this->freezeTime()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___$this->travelTo()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___$this->travel()___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___$this->travelBack()___SINGLE_BACKTICK___. Do not call ___SINGLE_BACKTICK___Carbon::setTestNow()___SINGLE_BACKTICK___.
@endif
- Use ___SINGLE_BACKTICK___Str::createRandomStringsUsing()___SINGLE_BACKTICK___ to fix a generated string, if the test asserts an identifier or a slug.
- Use ___SINGLE_BACKTICK___Sleep::fake()___SINGLE_BACKTICK___ instead of a real sleep, and assert the sleeps that the code requests.
- Restore the time and the randomness after each test, if the suite does not restore them for every test.

## The Database

- Run the real query against the real records in the test database. Do not mock the query builder, because the test then asserts the mock.
- Assert the exact keys of ___SINGLE_BACKTICK___toArray()___SINGLE_BACKTICK___ if the shape of the serialized model is a contract. The test then fails when the model exposes a new attribute.
- Test application behavior caused by the schema, such as deleting dependent records through a cascade. Do not test the database engine's cascade implementation.
- Use ___SINGLE_BACKTICK___LazilyRefreshDatabase___SINGLE_BACKTICK___ instead of ___SINGLE_BACKTICK___RefreshDatabase___SINGLE_BACKTICK___. A test that does not use the database then does not run the migrations.
