@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Pint Code Formatter

@if($assist->supportsPintAgentFormatter())
- If you have modified any PHP files, you must run ___SINGLE_BACKTICK___{{ $assist->binCommand('pint') }} --dirty --format agent___SINGLE_BACKTICK___ before finalizing changes to ensure your code matches the project's expected style.
- Do not run ___SINGLE_BACKTICK___{{ $assist->binCommand('pint') }} --test --format agent___SINGLE_BACKTICK___, simply run ___SINGLE_BACKTICK___{{ $assist->binCommand('pint') }} --format agent___SINGLE_BACKTICK___ to fix any formatting issues.
@else
- If you have modified any PHP files, you must run ___SINGLE_BACKTICK___{{ $assist->binCommand('pint') }} --dirty___SINGLE_BACKTICK___ before finalizing changes to ensure your code matches the project's expected style.
- Do not run ___SINGLE_BACKTICK___{{ $assist->binCommand('pint') }} --test___SINGLE_BACKTICK___, simply run ___SINGLE_BACKTICK___{{ $assist->binCommand('pint') }}___SINGLE_BACKTICK___ to fix any formatting issues.
@endif
