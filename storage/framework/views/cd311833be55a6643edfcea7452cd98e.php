<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
?>
___SCOPED_START_WyJ0ZXN0c1wvKioiXQ==___
# Pest

- This project uses Pest. Create tests with ___SINGLE_BACKTICK___<?php echo e($assist->artisanCommand('make:test --pest {name}')); ?>___SINGLE_BACKTICK___.
- Do not include the test suite directory in ___SINGLE_BACKTICK___{name}___SINGLE_BACKTICK___. Use ___SINGLE_BACKTICK___SomeFeatureTest___SINGLE_BACKTICK___, not ___SINGLE_BACKTICK___Feature/SomeFeatureTest___SINGLE_BACKTICK___.
- Read the ___SINGLE_BACKTICK___testing-best-practices___SINGLE_BACKTICK___ skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or ___SINGLE_BACKTICK___--filter=testName___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___<?php echo e($assist->artisanCommand('test --compact')); ?>___SINGLE_BACKTICK___.
- Rerun a test after each change to it.
- Run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pest')); ?>___SINGLE_BACKTICK___ to call the test runner directly. It accepts the same file path and ___SINGLE_BACKTICK___--filter=testName___SINGLE_BACKTICK___ arguments.
- After the feature tests pass, ask the user to run the complete suite with ___SINGLE_BACKTICK___<?php echo e($assist->artisanCommand('test --compact')); ?>___SINGLE_BACKTICK___.
___SCOPED_END___
<?php /**PATH /tmp/claude-1000/-home-neto-Projects-Archivum/68a100c7-112d-496c-b1b6-82b0f1ebfc76/scratchpad/archivum-scaffold/storage/framework/views/a7d0dd8b7b094c80b59286bb0452dc54.blade.php ENDPATH**/ ?>