<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
?>
# Laravel Pint Code Formatter

<?php if($assist->supportsPintAgentFormatter()): ?>
- If you have modified any PHP files, you must run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pint')); ?> --dirty --format agent___SINGLE_BACKTICK___ before finalizing changes to ensure your code matches the project's expected style.
- Do not run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pint')); ?> --test --format agent___SINGLE_BACKTICK___, simply run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pint')); ?> --format agent___SINGLE_BACKTICK___ to fix any formatting issues.
<?php else: ?>
- If you have modified any PHP files, you must run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pint')); ?> --dirty___SINGLE_BACKTICK___ before finalizing changes to ensure your code matches the project's expected style.
- Do not run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pint')); ?> --test___SINGLE_BACKTICK___, simply run ___SINGLE_BACKTICK___<?php echo e($assist->binCommand('pint')); ?>___SINGLE_BACKTICK___ to fix any formatting issues.
<?php endif; ?>
<?php /**PATH /tmp/claude-1000/-home-neto-Projects-Archivum/68a100c7-112d-496c-b1b6-82b0f1ebfc76/scratchpad/archivum-scaffold/storage/framework/views/2b3b75ec9892892981b355f1d066492b.blade.php ENDPATH**/ ?>