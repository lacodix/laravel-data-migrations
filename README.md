# Data migrations for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lacodix/laravel-data-migrations.svg?style=flat-square)](https://packagist.org/packages/lacodix/laravel-data-migrations)
[![Total Downloads](https://img.shields.io/packagist/dt/lacodix/laravel-data-migrations.svg?style=flat-square)](https://packagist.org/packages/lacodix/laravel-data-migrations)
[![License](https://img.shields.io/packagist/l/lacodix/laravel-data-migrations.svg?style=flat-square)](LICENSE.txt)

Keep changes to existing data separate from schema migrations. Laravel's `migrate` commands continue to use `database/migrations` and the `migrations` history table. This package uses `database/migrations_data` and a separate `migrations_data` history table.

This is a fork of [jlorente/laravel-data-migrations](https://github.com/jlorente/laravel-data-migrations), originally authored by José Lorente Martín. [Lacodix](https://github.com/lacodix) has taken over ongoing maintenance and development. Original author and MIT attribution are preserved.

Lacodix 3.x is a breaking package and namespace change: the Composer package is `lacodix/laravel-data-migrations`, and the public namespace changes from `Jlorente\DataMigrations` to `Lacodix\LaravelDataMigrations`. Existing consumers must replace the upstream dependency and update imports and manual provider/class references; see [Upgrade from jlorente 2.x](#upgrade-from-jlorente-2x).

Lacodix 3.x uses the `Lacodix\LaravelDataMigrations` namespace. It supports PHP 8.2+ with Laravel 11 or 12, and PHP 8.3+ with Laravel 13. Laravel 11 compatibility does not imply security support: Laravel 11 has reached the end of its security support period. Prefer a supported, patched Laravel version.

## Installation and configuration

```shell
composer require lacodix/laravel-data-migrations:^3.0
php artisan vendor:publish --tag=data-migrations
```

Laravel discovers `Lacodix\LaravelDataMigrations\DataMigrationsServiceProvider` automatically. If you disable discovery, add that provider to your application's `bootstrap/providers.php` list.

Publication creates `config/data-migrations.php` and the default migration directory. The default table is also available without publishing the configuration. To customize it, edit the published file:

```php
<?php

return [
    'table' => 'migrations_data',
];
```

Choose a table name different from Laravel's schema migration history table. Existing application settings take precedence over the package default.

## Create, run and roll back

```shell
php artisan make:data-migration insert_example_setting
php artisan migrate-data
php artisan migrate-data:rollback
```

The maker creates a migration file only. `migrate-data` creates its tracking table when needed, then runs pending data migrations. `migrate-data:install` creates just the tracking table and accepts `--database`.

Run your schema migrations first so the tables used by your data migrations already exist. For example, given an `example_settings` table with `key` and `value` string columns and a unique `key`, a data migration could contain:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('example_settings')->insert([
            'key' => 'timezone',
            'value' => 'UTC',
        ]);
    }

    public function down(): void
    {
        DB::table('example_settings')->where('key', 'timezone')->delete();
    }
};
```

The filename and batch are recorded in `migrations_data`. Running `migrate-data` again skips recorded files; rollback calls `down()` and removes the corresponding history rows. Schema history remains independent. Write reversible migrations suited to your own data: this example assumes it owns the inserted setting.

Use relative paths for the maker. Run and rollback also support arrays of paths and absolute paths with `--realpath`:

```shell
php artisan make:data-migration insert_example_setting --path=database/custom_data
php artisan migrate-data --path=database/custom_data --database=sqlite --step
php artisan migrate-data:rollback --path=database/custom_data --database=sqlite --step=1
php artisan migrate-data --path=/absolute/path/to/data_migrations --realpath
php artisan migrate-data:rollback --path=/absolute/path/to/data_migrations --realpath
```

`make:data-migration` retains the upstream limitation that its `--realpath` flag does not change path resolution: its `--path` is relative to the application root. Use a relative maker path even though the flag appears in help. `--create` and `--table` use Laravel's migration stubs; avoid putting schema changes in data migrations. The inherited `--fullpath` option is deprecated in modern Laravel; output formatting follows the installed framework.

`migrate-data` and rollback accept `--pretend`; no migration changes are applied, but a first run can still create the tracking table. `--step` on run gives each migration its own batch; on rollback it limits the number rolled back. In production, commands require confirmation or `--force`. `--graceful` on run returns success even if a migration throws, so inspect the output and migration history before treating it as complete. `--seed` is not supported; run `php artisan db:seed` separately.

Use `php artisan help migrate-data` and `php artisan help migrate-data:rollback` for the options available in your framework version. There are no data-specific reset, fresh or status commands. Laravel's schema rollback does not roll back data history; plan deployment and rollback order explicitly.

## Upgrade from jlorente 2.x

You must remove `jlorente/laravel-data-migrations` and install `lacodix/laravel-data-migrations:^3.0`, rather than adding both packages. The package has no legacy namespace aliases or Composer `replace` declaration for upstream.

| Identifier | Upstream 2.x | Lacodix 3.x |
|---|---|---|
| Composer package | `jlorente/laravel-data-migrations` | `lacodix/laravel-data-migrations` |
| Public namespace | `Jlorente\DataMigrations` | `Lacodix\LaravelDataMigrations` |

You must update all application imports and manual provider/class references from the old namespace to the new one. Refresh the application's autoloader and any cached configuration/provider metadata during your normal deployment process.

Keep existing migration filenames, the configured table, rows, batches and directories. For correctly configured 2.x installations, no data rewrite or migration replay is needed for the namespace change. If the required 2.x configuration was not published or otherwise provided, especially with SQLite, verify where existing migration history is stored and point the configuration at that existing history before running new migrations. Class basenames, command names, the `data-migrations` configuration key and publication tag remain unchanged. If your own code resolves services directly, `migration.data.repository` and `migrator.data` remain the same.

The 3.x candidate includes the command-registration fix from [upstream PR10](https://github.com/jlorente/laravel-data-migrations/pull/10). It preserves Laravel's native `migrate:install` command on Laravel 13.24+ while retaining inherited command options.

## Contributors and AI-assisted changes

```shell
composer install
composer test
composer quality
```

With no local lockfile, Composer resolves current stable dependencies. `composer format` applies the project's PHP CS Fixer rules. `composer quality` checks formatting and runs Larastan/PHPStan. Tests use Orchestra Testbench with private generated migration directories and SQLite in memory; no application database or external provider is needed. CI tests stable and lowest installable dependencies on PHP 8.2–8.5 and Laravel 11–13, excluding Laravel 13 on PHP 8.2. The lane helper is for disposable CI manifests only; it allows four explicitly identified Laravel 11 advisories for isolated console compatibility tests. The package manifest itself has no advisory exceptions.

For AI-assisted work, run the same tests and quality commands and describe the versions actually exercised. Keep native and data command registrations, repository separation, custom configuration and real migration up/down behavior covered. Use isolated SQLite fixtures and explicit paths for experiments. Do not edit already applied migration files to introduce new behavior; add a new migration instead. Verify assumptions about inherited Laravel options against the installed framework, and keep unrelated features outside a compatibility fix.

## License

Copyright © 2018 José Lorente Martín <jose.lorente.martin@gmail.com>. Originally developed as [jlorente/laravel-data-migrations](https://github.com/jlorente/laravel-data-migrations).

Licensed under the MIT license. See [LICENSE.txt](LICENSE.txt).
