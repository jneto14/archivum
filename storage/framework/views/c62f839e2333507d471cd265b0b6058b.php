<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
$pest = $assist->hasPackage('pestphp/pest');
?>
# Reviewing Tests

Check every item in this file. A passing test may still provide no value. For each test, identify the defect it would catch.

Report each finding. Do not delete or rewrite a test without the user's approval. When an issue appears throughout the suite as a convention, report the pattern once rather than every affected file.

## The Value of the Test

<?php if($pest): ?>
Apply this section to behavioral tests. An architecture test states a convention for a directory, so these items do not apply to it.

<?php endif; ?>
- [ ] Each test covers observable behavior or an application contract, and passes after a change to the implementation that keeps the behavior.
- [ ] Each tested declaration is exercised through behavior, and no test asserts the behavior of the framework. A test of what this project configures, such as a relation with a constraint, a cast, or a scope, belongs to this project.
- [ ] Each test detects a distinct defect that no other test covers. A duplicate shrinks at the higher layer to the one case that proves the wiring.
- [ ] Every changed decision and each applicable high-value failure mode has coverage.

## Names and Structure

- [ ] Each file has the name ___SINGLE_BACKTICK___{ClassName}Test.php___SINGLE_BACKTICK___ and the relative path of the class under test.
- [ ] Each name states a result, the condition that causes it, and the status code for an API error.
<?php if($pest): ?>
- [ ] Each file uses one declaration style consistently, and each ___SINGLE_BACKTICK___describe()___SINGLE_BACKTICK___ group holds separate behavior.
<?php else: ?>
- [ ] Each test class extends the base ___SINGLE_BACKTICK___TestCase___SINGLE_BACKTICK___ of the project, and each file uses either the prefix ___SINGLE_BACKTICK___test____SINGLE_BACKTICK___ or the ___SINGLE_BACKTICK___#[Test]___SINGLE_BACKTICK___ attribute consistently.
<?php endif; ?>

## The Coverage

- [ ] HTTP tests cover authentication, authorization, role, scope, and validation when applicable.
- [ ] A request for a record of a different tenant gets a status code that does not confirm that the record exists.
- [ ] The complete permission matrix belongs in policy tests, not controller tests.
- [ ] Each validation rule has one test that asserts the user-visible message. When a unit test owns a matrix, reduce duplicate higher-level coverage to one case rather than deleting it.
- [ ] Rendered user input and each dynamic part of a query have a security test.

## The Data and the Determinism

- [ ] Each test creates its mutable records directly or through a helper that it calls, and every created record arranges the behavior or supports an assertion.
<?php if($pest): ?>
- [ ] Each ___SINGLE_BACKTICK___beforeEach()___SINGLE_BACKTICK___ holds configuration only.
<?php else: ?>
- [ ] ___SINGLE_BACKTICK___setUp()___SINGLE_BACKTICK___ holds configuration only.
<?php endif; ?>
- [ ] Each factory state and each relationship gives the meaning of the data.
- [ ] Each call to ___SINGLE_BACKTICK___make()___SINGLE_BACKTICK___ is in a test that does not need the database.
- [ ] Time, randomness, sleep, and outbound HTTP are controlled.
- [ ] Each test passes alone, and passes in the complete suite in any order.

## The Assertions

- [ ] Each expected value is a known value, and the test does not calculate the value with the logic of the implementation.
- [ ] Each test of a write operation asserts the response, the state in the database, and the side effects.
- [ ] Each fake has one assertion, and gives the class names unless the test asserts the complete result.
<?php if($pest): ?>
- [ ] Each ___SINGLE_BACKTICK___expect()___SINGLE_BACKTICK___ chain stays on one subject.
<?php else: ?>
- [ ] Each group of assertions stays on one subject, and each comparison uses ___SINGLE_BACKTICK___assertSame()___SINGLE_BACKTICK___.
<?php endif; ?>

## The Defects to Report

A review can find defects in the code rather than the tests. Report each defect below, and do not write a test that codifies it as correct behavior.

- [ ] A method with no body.
- [ ] A policy that exists, but that no action calls.
- [ ] A write action with no validation.
- [ ] A status code or a response shape that is different from the shape of a similar endpoint.
<?php /**PATH /tmp/claude-1000/-home-neto-Projects-Archivum/68a100c7-112d-496c-b1b6-82b0f1ebfc76/scratchpad/archivum-scaffold/storage/framework/views/f885d1d9a87cdcbe0bc36bca9b549f55.blade.php ENDPATH**/ ?>