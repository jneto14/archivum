@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Do Things the Laravel Way

- Use ___SINGLE_BACKTICK___{{ $assist->artisanCommand('make:') }}___SINGLE_BACKTICK___ commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using ___SINGLE_BACKTICK___{{ $assist->artisanCommand('list') }}___SINGLE_BACKTICK___ and check their parameters with ___SINGLE_BACKTICK___{{ $assist->artisanCommand('[command] --help') }}___SINGLE_BACKTICK___.
- If you're creating a generic PHP class, use ___SINGLE_BACKTICK___{{ $assist->artisanCommand('make:class') }}___SINGLE_BACKTICK___.
- Pass ___SINGLE_BACKTICK___--no-interaction___SINGLE_BACKTICK___ to all Artisan commands to ensure they work without user input. You should also pass the correct ___SINGLE_BACKTICK___--options___SINGLE_BACKTICK___ to ensure correct behavior.

___SCOPED_START_WyJhcHBcL01vZGVsc1wvKioiXQ==___
### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using ___SINGLE_BACKTICK___{{ $assist->artisanCommand('make:model --help') }}___SINGLE_BACKTICK___ to check the available options.
___SCOPED_END___

___SCOPED_START_WyJhcHBcL0h0dHBcLyoqIiwicm91dGVzXC8qKiJd___
## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.
___SCOPED_END___

## URL Generation
- When generating links to other pages, prefer named routes and the ___SINGLE_BACKTICK___route()___SINGLE_BACKTICK___ function.

___SCOPED_START_WyJ0ZXN0c1wvKioiXQ==___
## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as ___SINGLE_BACKTICK___$this->faker->word()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___fake()->randomDigit()___SINGLE_BACKTICK___. Follow existing conventions whether to use ___SINGLE_BACKTICK___$this->faker___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___fake()___SINGLE_BACKTICK___.
- When creating tests, make use of ___SINGLE_BACKTICK___{{ $assist->artisanCommand('make:test [options] {name}') }}___SINGLE_BACKTICK___ to create a feature test, and pass ___SINGLE_BACKTICK___--unit___SINGLE_BACKTICK___ to create a unit test. Most tests should be feature tests.
___SCOPED_END___

## Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run ___SINGLE_BACKTICK___{{ $assist->nodePackageManagerCommand('run build') }}___SINGLE_BACKTICK___ or ask the user to run ___SINGLE_BACKTICK___{{ $assist->nodePackageManagerCommand('run dev') }}___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___{{ $assist->composerCommand('run dev') }}___SINGLE_BACKTICK___.
