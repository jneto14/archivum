---
name: inertia-react-development
description: "Develops Inertia.js v3 React client-side applications. Activates when creating React pages, forms, or navigation; using <Link>, <Form>, useForm, useHttp, setLayoutProps, or router; working with deferred props, prefetching, optimistic updates, instant visits, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation."
license: MIT
metadata:
  author: laravel
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Inertia React Development

## When to Apply

Activate this skill when:

- Creating or modifying React page components for Inertia
- Working with forms in React (using ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___useHttp___SINGLE_BACKTICK___)
- Implementing client-side navigation with ___SINGLE_BACKTICK___<Link>___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___router___SINGLE_BACKTICK___
- Using v3 features: deferred props, prefetching, optimistic updates, instant visits, layout props, HTTP requests, WhenVisible, InfiniteScroll, once props, flash data, or polling
- Building React-specific features with the Inertia protocol

## Documentation

Use ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ for detailed Inertia v3 React patterns and documentation.

## Basic Usage

### Page Components Location

React page components should be placed in the ___SINGLE_BACKTICK___{{ $assist->inertia()->pagesDirectory() }}___SINGLE_BACKTICK___ directory.

### Page Component Structure

___BOOST_SNIPPET_0___

## Client-Side Navigation

### Basic Link Component

Use ___SINGLE_BACKTICK___<Link>___SINGLE_BACKTICK___ for client-side navigation instead of traditional ___SINGLE_BACKTICK___<a>___SINGLE_BACKTICK___ tags:

___BOOST_SNIPPET_1___

### Link with Method

___BOOST_SNIPPET_2___

### Prefetching

Prefetch pages to improve perceived performance:

___BOOST_SNIPPET_3___

### Programmatic Navigation

___BOOST_SNIPPET_4___

## Form Handling

@if($assist->inertia()->hasFormComponent())
### Form Component (Recommended)

The recommended way to build forms is with the ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component:

___BOOST_SNIPPET_5___

### Form Component With All Props

___BOOST_SNIPPET_6___

@if($assist->inertia()->hasFormComponentResets())
### Form Component Reset Props

The ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component supports automatic resetting:

- ___SINGLE_BACKTICK___resetOnError___SINGLE_BACKTICK___ - Reset form data when the request fails
- ___SINGLE_BACKTICK___resetOnSuccess___SINGLE_BACKTICK___ - Reset form data when the request succeeds
- ___SINGLE_BACKTICK___setDefaultsOnSuccess___SINGLE_BACKTICK___ - Update default values on success

Use the ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ tool with a query of ___SINGLE_BACKTICK___form component resetting___SINGLE_BACKTICK___ for detailed guidance.

___BOOST_SNIPPET_7___
@else
Note: This version of Inertia does not support ___SINGLE_BACKTICK___resetOnError___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___resetOnSuccess___SINGLE_BACKTICK___, or ___SINGLE_BACKTICK___setDefaultsOnSuccess___SINGLE_BACKTICK___ on the ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component. Using these props will cause errors. Upgrade to Inertia v2.2.0+ to use these features.
@endif

Forms can also be built using the ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___ helper for more programmatic control. Use the ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ tool with a query of ___SINGLE_BACKTICK___useForm helper___SINGLE_BACKTICK___ for guidance.

@endif

### ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___ Hook

@if($assist->inertia()->hasFormComponent() === false)
For Inertia v2.0.x: Build forms using the ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___ helper as the ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component is not available until v2.1.0+.
@else
For more programmatic control or to follow existing conventions, use the ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___ hook:
@endif

___BOOST_SNIPPET_8___

## Inertia v3 Features

### HTTP Requests

Use the ___SINGLE_BACKTICK___useHttp___SINGLE_BACKTICK___ hook for standalone HTTP requests that do not trigger Inertia page visits. It provides the same developer experience as ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___, but for plain JSON endpoints.

___BOOST_SNIPPET_9___

### Optimistic Updates

Apply data changes instantly before the server responds, with automatic rollback on failure:

___BOOST_SNIPPET_10___

Optimistic updates also work with ___SINGLE_BACKTICK___useForm___SINGLE_BACKTICK___ and the ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component:

___BOOST_SNIPPET_11___

### Instant Visits

Navigate to a new page immediately without waiting for the server response. The target component renders right away with shared props, while page-specific props load in the background.

@verbatim
___BOOST_SNIPPET_12___
@endverbatim

### Layout Props

Share dynamic data between pages and persistent layouts:

___BOOST_SNIPPET_13___

___BOOST_SNIPPET_14___

### Deferred Props

Use deferred props to load data after initial page render:

___BOOST_SNIPPET_15___

### Polling

Use the ___SINGLE_BACKTICK___usePoll___SINGLE_BACKTICK___ hook to automatically refresh data at intervals. It handles cleanup on unmount and throttles polling when the tab is inactive.

___BOOST_SNIPPET_16___

___BOOST_SNIPPET_17___

- ___SINGLE_BACKTICK___autoStart___SINGLE_BACKTICK___ (default ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___) - set to ___SINGLE_BACKTICK___false___SINGLE_BACKTICK___ to start polling manually via the returned ___SINGLE_BACKTICK___start()___SINGLE_BACKTICK___ function
- ___SINGLE_BACKTICK___keepAlive___SINGLE_BACKTICK___ (default ___SINGLE_BACKTICK___false___SINGLE_BACKTICK___) - set to ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___ to prevent throttling when the browser tab is inactive

### WhenVisible

Lazy-load a prop when an element scrolls into view. Useful for deferring expensive data that sits below the fold:

___BOOST_SNIPPET_18___

### InfiniteScroll

Automatically load additional pages of paginated data as users scroll:

___BOOST_SNIPPET_19___

The server must use ___SINGLE_BACKTICK___Inertia::scroll()___SINGLE_BACKTICK___ to configure the paginated data. Use the ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ tool with a query of ___SINGLE_BACKTICK___infinite scroll___SINGLE_BACKTICK___ for detailed guidance on buffers, manual loading, reverse mode, and custom trigger elements.

## Server-Side Patterns

Server-side patterns (Inertia::render, props, middleware) are covered in inertia-laravel guidelines.

## Common Pitfalls

- Using traditional ___SINGLE_BACKTICK___<a>___SINGLE_BACKTICK___ links instead of Inertia's ___SINGLE_BACKTICK___<Link>___SINGLE_BACKTICK___ component (breaks SPA behavior)
- Forgetting to add loading states (skeleton screens) when using deferred props
- Not handling the ___SINGLE_BACKTICK___undefined___SINGLE_BACKTICK___ state of deferred props before data loads
- Using ___SINGLE_BACKTICK___<form>___SINGLE_BACKTICK___ without preventing default submission (use ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component or ___SINGLE_BACKTICK___e.preventDefault()___SINGLE_BACKTICK___)
- Forgetting to check if ___SINGLE_BACKTICK___<Form>___SINGLE_BACKTICK___ component is available in your Inertia version
- Using ___SINGLE_BACKTICK___router.cancel()___SINGLE_BACKTICK___ instead of ___SINGLE_BACKTICK___router.cancelAll()___SINGLE_BACKTICK___ (v3 breaking change)
- Using ___SINGLE_BACKTICK___router.on('invalid', ...)___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___router.on('exception', ...)___SINGLE_BACKTICK___ instead of the renamed ___SINGLE_BACKTICK___httpException___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___networkError___SINGLE_BACKTICK___ events
