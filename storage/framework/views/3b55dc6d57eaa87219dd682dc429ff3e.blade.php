---
name: wayfinder-development
description: "Use this skill for Laravel Wayfinder which auto-generates typed functions for Laravel controllers and routes. ALWAYS use this skill when frontend code needs to call backend routes or controller actions. Trigger when: connecting any React/Vue/Svelte/Inertia frontend to Laravel controllers, routes, building end-to-end features with both frontend and backend, wiring up forms or links to backend endpoints, fixing route-related TypeScript errors, importing from @/actions or @/routes, or running wayfinder:generate. Use Wayfinder route functions instead of hardcoded URLs. Covers: wayfinder() vite plugin, .url()/.get()/.post()/.form(), query params, route model binding, tree-shaking. Do not use for backend-only task"
license: MIT
metadata:
  author: laravel
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Wayfinder Development

## Documentation

Use ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ for detailed Wayfinder patterns and documentation.

## Quick Reference

### Generate Routes

Run after route changes if Vite plugin isn't installed:
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('wayfinder:generate --no-interaction') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
For form helpers, use ___SINGLE_BACKTICK___--with-form___SINGLE_BACKTICK___ flag:
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('wayfinder:generate --with-form --no-interaction') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
### Import Patterns

___BOOST_SNIPPET_0___

### Common Methods

___BOOST_SNIPPET_1___

@if($assist->project->php()->uses(\Laravel\Boost\Support\PackageRegistry::INERTIA_LARAVEL) || $assist->project->js()->uses([\Laravel\Boost\Support\PackageRegistry::INERTIA_REACT, \Laravel\Boost\Support\PackageRegistry::INERTIA_VUE, \Laravel\Boost\Support\PackageRegistry::INERTIA_SVELTE]))
## Wayfinder + Inertia

@if($assist->inertia()->hasFormComponent())
Use Wayfinder with the ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component:
@if($assist->project->js()->uses(\Laravel\Boost\Support\PackageRegistry::INERTIA_REACT))
___BOOST_SNIPPET_2___
@endif
@if($assist->project->js()->uses(\Laravel\Boost\Support\PackageRegistry::INERTIA_VUE))
___BOOST_SNIPPET_3___
@endif
@if($assist->project->js()->uses(\Laravel\Boost\Support\PackageRegistry::INERTIA_SVELTE))
___BOOST_SNIPPET_4___
@endif
@else
Use Wayfinder with ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___:

___BOOST_SNIPPET_5___
@endif
@endif

## Verification

1. Run ___SINGLE_BACKTICK___{{ $assist->artisanCommand('wayfinder:generate') }}___SINGLE_BACKTICK___ to regenerate routes if Vite plugin isn't installed
2. Check TypeScript imports resolve correctly
3. Verify route URLs match expected paths

## Common Pitfalls

- Using default imports instead of named imports (breaks tree-shaking)
- Forgetting to regenerate after route changes
- Not using type-safe parameter objects for route model binding
