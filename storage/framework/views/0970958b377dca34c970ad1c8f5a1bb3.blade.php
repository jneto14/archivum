# PHP

@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: ___SINGLE_BACKTICK___public function __construct(public GitHub $github) { }___SINGLE_BACKTICK___. Do not leave empty zero-parameter ___SINGLE_BACKTICK_____construct()___SINGLE_BACKTICK___ methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: ___SINGLE_BACKTICK___function isAccessible(User $user, ?string $path = null): bool___SINGLE_BACKTICK___
@if(empty($assist->enums()) || !preg_match('/^\s*case\s+[A-Z][A-Z0-9_]{2,}/m', $assist->enumContents()))
- Use TitleCase for Enum keys: ___SINGLE_BACKTICK___FavoritePerson___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___BestLake___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Monthly___SINGLE_BACKTICK___.
@else
- Follow existing application Enum naming conventions.
@endif
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.
