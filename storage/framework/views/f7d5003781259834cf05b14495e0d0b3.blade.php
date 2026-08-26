@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
@endphp
# Factories and Test Data

## Each Test Makes Its Own Data

@if($pest)
Create mutable records inside the test that uses them. This keeps setup visible and lets each test select its factory state.

Use ___SINGLE_BACKTICK___beforeEach()___SINGLE_BACKTICK___ only for configuration that applies to every test in the file. Do not create records in it.
@else
Create mutable records inside each test or through a private helper that the test calls. This keeps setup visible and lets each test select its factory state.

Use ___SINGLE_BACKTICK___setUp()___SINGLE_BACKTICK___ only for configuration that applies to every test in the class. Do not create records in it, because its objects remain in memory until the suite ends.
@endif

## Record Construction

- Use ___SINGLE_BACKTICK___create()___SINGLE_BACKTICK___ if the test needs the record in the database.
- Use ___SINGLE_BACKTICK___make()___SINGLE_BACKTICK___ only if the test does not need the database. Examples include rendering a notification and testing a value object's behavior.
- Use a named factory state instead of a raw attribute. ___SINGLE_BACKTICK___User::factory()->unverified()->create()___SINGLE_BACKTICK___ gives the state meaning; ___SINGLE_BACKTICK___create(['email_verified_at' => null])___SINGLE_BACKTICK___ gives only its value.
- Use ___SINGLE_BACKTICK___for()___SINGLE_BACKTICK___ or the relationship helper of the project to declare the owner of a record.
- Use ___SINGLE_BACKTICK___recycle()___SINGLE_BACKTICK___ if several records must share one parent record.
- Use ___SINGLE_BACKTICK___sequence()___SINGLE_BACKTICK___ if several records need different attributes.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
$organization = Organization::factory()->onPlan(BillingPlan::PRO)->create();

$environment = Environment::factory()->recycle($organization)->create();

$organizations = Organization::factory()
    ->count(3)
    ->sequence(
        ['created_at' => now()->setSeconds(30)],
        ['created_at' => now()->setSeconds(1)],
    )
    ->create();
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Create only the records required to arrange the behavior or support an assertion.

@if($pest)
## The Datasets

Use a dataset when the setup, test body, and assertions remain the same across input values.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
it('forbids roles other than admin', function (Role $role) {
    actingAs(User::factory()->hasOrganization($role)->create())
        ->post('/settings')
        ->assertForbidden();
})->with(collect(Role::cases())->reject(fn (Role $role) => $role === Role::ADMIN));
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
@else
## The Data Providers

Use a data provider when the setup, test body, and assertions remain the same across input values.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
public static function nonAdminRoles(): array
{
    return collect(Role::cases())
        ->reject(fn (Role $role): bool => $role === Role::ADMIN)
        ->mapWithKeys(fn (Role $role): array => [$role->value => [$role]])
        ->all();
}

#[DataProvider('nonAdminRoles')]
public function test_forbids_roles_other_than_admin(Role $role): void
{
    $this->actingAs(User::factory()->hasOrganization($role)->create())
        ->post('/settings')
        ->assertForbidden();
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Declare each data provider method as ___SINGLE_BACKTICK___public static___SINGLE_BACKTICK___.
@endif

Use parameterized tests for:

- the cases of an enum
- the roles and the plans
- the boundary values
- the input values that are not valid in the same way
- the pairs of an input value and an output value

Write separate tests if the cases need a different setup, a different behavior, or different assertions. One test function with a branch in the body is two tests in one function.

@if($pest)
Give each dataset case a name that states the difference. A failure then identifies the case without requiring you to count positions.
@else
Give each data-provider case a key that states the difference. A failure then identifies the case without requiring you to count positions.
@endif
