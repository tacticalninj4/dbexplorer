<div align="center">
    <h1>DbExplorer</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://img.shields.io/packagist/v/tacticalninj4/dbexplorer.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://img.shields.io/packagist/php-v/tacticalninj4/dbexplorer.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://badge.laravel.cloud/badge/tacticalninj4/dbexplorer?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/tacticalninj4/dbexplorer/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/tacticalninj4/dbexplorer/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/tacticalninj4/dbexplorer"><img src="https://img.shields.io/packagist/dt/tacticalninj4/dbexplorer.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Laravel DB Explorer for web

## Installation

You can install the package via Composer:

```bash
composer require tacticalninj4/dbexplorer
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="dbexplorer"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="dbexplorer-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="dbexplorer-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="dbexplorer-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="dbexplorer-lang"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to DbExplorer! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Jhanarthananraja](https://github.com/tacticalninj4)
- [All Contributors](../../contributors)

## License

DbExplorer is open-sourced software licensed under the [MIT license](LICENSE.md).
