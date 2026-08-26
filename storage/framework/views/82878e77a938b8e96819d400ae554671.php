# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in ___SINGLE_BACKTICK___<?php echo e($assist->inertia()->pagesDirectory()); ?>___SINGLE_BACKTICK___ (unless specified in ___SINGLE_BACKTICK___vite.config.js___SINGLE_BACKTICK___). Use ___SINGLE_BACKTICK___Inertia::render()___SINGLE_BACKTICK___ for server-side routing instead of Blade views.
- ALWAYS use ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ tool for version-specific Inertia documentation and updated code examples.
<?php if($assist->hasPackage(\Laravel\Boost\Support\PackageRegistry::INERTIA_REACT)): ?>
- IMPORTANT: Activate ___SINGLE_BACKTICK___inertia-react-development___SINGLE_BACKTICK___ when working with Inertia client-side patterns.
<?php elseif($assist->hasPackage(\Laravel\Boost\Support\PackageRegistry::INERTIA_VUE)): ?>
- IMPORTANT: Activate ___SINGLE_BACKTICK___inertia-vue-development___SINGLE_BACKTICK___ when working with Inertia Vue client-side patterns.
<?php elseif($assist->hasPackage(\Laravel\Boost\Support\PackageRegistry::INERTIA_SVELTE)): ?>
- IMPORTANT: Activate ___SINGLE_BACKTICK___inertia-svelte-development___SINGLE_BACKTICK___ when working with Inertia Svelte client-side patterns.
<?php endif; ?>

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (___SINGLE_BACKTICK___useHttp___SINGLE_BACKTICK___ hook), optimistic updates with automatic rollback, layout props (___SINGLE_BACKTICK___useLayoutProps___SINGLE_BACKTICK___ hook), instant visits, simplified SSR via ___SINGLE_BACKTICK___@inertiajs/vite___SINGLE_BACKTICK___ plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- ___SINGLE_BACKTICK___Inertia::lazy()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___LazyProp___SINGLE_BACKTICK___ has been removed. Use ___SINGLE_BACKTICK___Inertia::optional()___SINGLE_BACKTICK___ instead.
- Prop types (___SINGLE_BACKTICK___Inertia::optional()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Inertia::defer()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Inertia::merge()___SINGLE_BACKTICK___) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with ___SINGLE_BACKTICK___@inertiajs/vite___SINGLE_BACKTICK___ - no separate Node.js server needed during development.
- Event renames: ___SINGLE_BACKTICK___invalid___SINGLE_BACKTICK___ is now ___SINGLE_BACKTICK___httpException___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___exception___SINGLE_BACKTICK___ is now ___SINGLE_BACKTICK___networkError___SINGLE_BACKTICK___.
- ___SINGLE_BACKTICK___router.cancel()___SINGLE_BACKTICK___ replaced by ___SINGLE_BACKTICK___router.cancelAll()___SINGLE_BACKTICK___.
- The ___SINGLE_BACKTICK___future___SINGLE_BACKTICK___ configuration namespace has been removed - all v2 future options are now always enabled.<?php /**PATH /tmp/claude-1000/-home-neto-Projects-Archivum/68a100c7-112d-496c-b1b6-82b0f1ebfc76/scratchpad/archivum-scaffold/storage/framework/views/d8538b296a27d310f6b71584696c8836.blade.php ENDPATH**/ ?>