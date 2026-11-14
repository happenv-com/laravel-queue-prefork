# Laravel Queue Prefork

[![Latest Version](https://img.shields.io/github/v/release/happenv-com/laravel-queue-prefork?style=flat-square&label=version)](https://github.com/happenv-com/laravel-queue-prefork/releases)
[![Tests](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-queue-prefork/tests.yml?label=tests&style=flat-square)](https://github.com/happenv-com/laravel-queue-prefork/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-queue-prefork/phpstan.yml?label=phpstan&style=flat-square)](https://github.com/happenv-com/laravel-queue-prefork/actions/workflows/phpstan.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-queue-prefork/quality.yml?label=code%20quality&style=flat-square)](https://github.com/happenv-com/laravel-queue-prefork/actions/workflows/quality.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/happenv-com/laravel-queue-prefork.svg?style=flat-square)](https://packagist.org/packages/happenv-com/laravel-queue-prefork)
[![License](https://img.shields.io/github/license/happenv-com/laravel-queue-prefork.svg?style=flat-square)](LICENSE.md)

Run Laravel queue workers as forked children of one booted parent, so the whole pool shares a single OPcache copy of your code.

<!--
  One or two paragraphs: what the package does and for whom. Link the library
  it builds on, if any. Then show the smallest useful example.
-->

## Key features

<!--
  3–8 bullets. Each one starts with a bold outcome, then one sentence on how
  or when. Say what the user GETS, not how it is implemented. Link the section
  of this README that covers it.
-->

- **First feature.** What it gives the user, in one sentence.
- **Second feature.** What it gives the user, in one sentence.
- **Tested.** Covered by a Pest suite on every supported version combination.

## Requirements

| Package  | Versions  |
|----------|-----------|
| PHP      | 8.3 – 8.5 |
| Laravel  | 12, 13    |

## Installation

Install the package via Composer:

```bash
composer require happenv-com/laravel-queue-prefork
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="queue-prefork-config"
```

<!-- Show the published config, or a table of the options that matter. -->

Optionally, publish the views and translations:

```bash
php artisan vendor:publish --tag="queue-prefork-views"
php artisan vendor:publish --tag="queue-prefork-translations"
```

## Usage

<!--
  Task-oriented subsections ("### Doing X"), each with a short example.
  Start with the most common task.
-->

## Testing your application

<!--
  If the package ships test helpers (a Livewire `Testable` mixin, fakes,
  assertions), document them here with a Pest example. Otherwise remove this
  section.
-->

## Translations

<!--
  List the shipped locales (a table of "Language (`code`)" cells reads well)
  and how to publish them. tests/Unit/TranslationsTest.php checks that every
  locale has exactly the keys English has.
-->

## Development

```bash
composer test          # unit and feature tests
composer phpstan       # static analysis
composer cs            # fix code style: composer normalize, Rector, Pint
composer ci            # everything CI checks, locally
```

## Upgrading

Breaking changes and how to migrate are described in [UPGRADING](UPGRADING.md) for every major version.

## Changelog

See [CHANGELOG](CHANGELOG.md) and [GitHub releases](https://github.com/happenv-com/laravel-queue-prefork/releases) for what has changed recently.

## Contributing

See [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Happenv sp. z o.o.](https://happenv.com)
- [webard](https://github.com/webard)
- [All contributors](../../contributors)

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.

---

<p align="center">
    <a href="https://happenv.com">
        <img src="art/happenv-logo.png" alt="Happenv" width="400">
    </a>
</p>
