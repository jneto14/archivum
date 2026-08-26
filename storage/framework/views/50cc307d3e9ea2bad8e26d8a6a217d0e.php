<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
$browserPlugin = $pest && $assist->hasPackage('pestphp/pest-plugin-browser');
$dusk = $assist->hasPackage('laravel/dusk');
?>
# Endpoint Tests

## How to Write the Test

Fetch ___SINGLE_BACKTICK___https://laravel.com/framework/docs/http-tests___SINGLE_BACKTICK___ for the request helpers, the authentication helpers, and the response assertions. Confirm the name before you use it, and do not guess an assertion.

Choose an assertion based on the subject of the check: the status, a header, a redirect, the JSON body, the session, a validation error, or the view. Laravel provides a named assertion for each subject that identifies the incorrect value.

## The Coverage of an Endpoint

Write a test for each applicable case:

- The request has missing or invalid authentication.
- The request comes from a different tenant, team, or organization.
- The user has an insufficient role or permission.
- The request does not satisfy a route or scope constraint.
- The request fails the validation.
- The request is valid. Assert both the response and the persisted state.

Assert the application's actual behavior rather than a generic status code. An API returns ___SINGLE_BACKTICK___401___SINGLE_BACKTICK___ for a missing or invalid token, while a browser endpoint redirects to the sign-in route.

## The Isolation of a Tenant

Assert the status code returned for a cross-tenant request. Use ___SINGLE_BACKTICK___404___SINGLE_BACKTICK___ rather than ___SINGLE_BACKTICK___403___SINGLE_BACKTICK___ when one tenant must not learn that another tenant's record exists, because ___SINGLE_BACKTICK___403___SINGLE_BACKTICK___ confirms its existence.

## Test Authorization at the Policy Level

An HTTP test shows that the endpoint performs authorization. It cannot identify which mechanism refused the request because middleware, a policy, and a call to ___SINGLE_BACKTICK___abort()___SINGLE_BACKTICK___ can all return ___SINGLE_BACKTICK___403___SINGLE_BACKTICK___.

- Assert the complete matrix of the permissions against the policy or the gate. A failure then names the rule that is not correct.
- Write one HTTP test for one refused role, which shows that the endpoint calls the authorization.
- Use the helper of the project that asserts the ability and the arguments of the gate, if such a helper exists.

<?php if($browserPlugin || $dusk): ?>
## The Browser Tests

Write a browser test only for JavaScript behavior that an HTTP test cannot reach, such as modal interaction, drag-and-drop, live search, or client-side validation. Browser tests are slower than HTTP tests and can fail for reasons unrelated to the code under test.

- Assert the state that the user can see, and assert the state in the database that the interaction saves.
- Wait until the test reaches the required state. Do not wait for a fixed number of seconds, which can fail on a slower machine.
<?php if($browserPlugin): ?>
- Call ___SINGLE_BACKTICK___assertNoJavaScriptErrors()___SINGLE_BACKTICK___ in each browser test. An error in the console is a defect.

### Where a Browser Test Lives and How to Run It

The plugin runs browser tests as normal Pest tests, so they need no separate suite. Put them in ___SINGLE_BACKTICK___tests/Browser___SINGLE_BACKTICK___ to separate them from faster tests and run the directory with one command.

- Run a browser test with ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pest tests/Browser')); ?>___SINGLE_BACKTICK___, and add ___SINGLE_BACKTICK___--parallel___SINGLE_BACKTICK___ for the complete suite.
- Run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pest --debug')); ?>___SINGLE_BACKTICK___ to open the window of the browser and to pause at a failure. Use ___SINGLE_BACKTICK___--headed___SINGLE_BACKTICK___ to watch a run that passes.
- Add ___SINGLE_BACKTICK___--browser firefox___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___--browser safari___SINGLE_BACKTICK___ to run the test in a different browser. The default browser is Chrome.
- The run needs Playwright and a browser on the machine. Follow the plugin documentation for local and CI installation commands.
- Fetch ___SINGLE_BACKTICK___https://pestphp.com/docs/browser-testing___SINGLE_BACKTICK___ for the interactions, the assertions, and the devices that the plugin gives.

### Browser Test Pitfalls

- The plugin waits five seconds for an element. Raise the value with ___SINGLE_BACKTICK___pest()->browser()->timeout(10000)___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___Pest.php___SINGLE_BACKTICK___ for a page that is slower, and do not add a wait for a number of seconds to the test.
- Apply ___SINGLE_BACKTICK___RefreshDatabase___SINGLE_BACKTICK___ to the browser tests in ___SINGLE_BACKTICK___Pest.php___SINGLE_BACKTICK___. A browser test hits the application through a real request, and the records that it leaves break the next test.
- Add ___SINGLE_BACKTICK___tests/Browser/Screenshots___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___.gitignore___SINGLE_BACKTICK___. A failure writes a screenshot, and the file is not part of the repository.
- Give ___SINGLE_BACKTICK___withKeyDown()___SINGLE_BACKTICK___ a key code, such as ___SINGLE_BACKTICK___KeyA___SINGLE_BACKTICK___. A letter such as ___SINGLE_BACKTICK___'a'___SINGLE_BACKTICK___ gives the lowercase character, whatever modifier the test holds.
- Interact inside the callback of ___SINGLE_BACKTICK___withinFrame()___SINGLE_BACKTICK___. An interaction outside the callback does not reach the frame.
<?php else: ?>
- Put each browser test in ___SINGLE_BACKTICK___tests/Browser___SINGLE_BACKTICK___, which is the suite that Dusk runs.
- Run the browser tests with ___SINGLE_BACKTICK___<?php echo e($assist->artisanCommand('dusk')); ?>___SINGLE_BACKTICK___. The run needs a ChromeDriver, and ___SINGLE_BACKTICK___<?php echo e($assist->artisanCommand('dusk:install')); ?>___SINGLE_BACKTICK___ downloads it.
- Fetch ___SINGLE_BACKTICK___https://laravel.com/framework/docs/dusk___SINGLE_BACKTICK___ for the selectors, the interactions, and the assertions of Dusk.
<?php endif; ?>
<?php endif; ?>

## The Validation

- Write one test for each validation rule when each failure represents a separate contract.
- Write one test with an empty payload to assert several required fields together.
- Give the status code in the name of a test for an API.
- Assert the text of the message that the user gets. A message that is present but wrong is a defect.
<?php if($pest): ?>
- Use a dataset for input values that need the same setup and the same assertions.
<?php else: ?>
- Use a data provider with the ___SINGLE_BACKTICK___#[DataProvider]___SINGLE_BACKTICK___ attribute for input values that need the same setup and the same assertions. Use the ___SINGLE_BACKTICK___#[TestWith]___SINGLE_BACKTICK___ attribute for a small set of values.
<?php endif; ?>

Send an input value that is not valid through the application, and assert the error. Do not assert that an array of rules contains a string, because that assertion tests the declaration and not the behavior. Use such an assertion only for a rule that no request can reach, and write the reason in the test.

### Which Layer Owns Which Case

The rule-class test owns the matrix of values that pass and fail. The endpoint test proves that the endpoint applies the rule and that the user receives the message.

When both tests contain the matrix, move it to the rule-class test and retain one case in the endpoint test. Never remove the last case, because the rule-class test still passes if the request omits the rule. The same division applies to policies, scopes, and other classes called by a request.
<?php /**PATH /tmp/claude-1000/-home-neto-Projects-Archivum/68a100c7-112d-496c-b1b6-82b0f1ebfc76/scratchpad/archivum-scaffold/storage/framework/views/fe979937b972dc852d402f66bc9f87bd.blade.php ENDPATH**/ ?>