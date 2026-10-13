<?php

namespace Lacodix\LaravelDataMigrations\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Console\Migrations\InstallCommand as NativeInstall;
use Illuminate\Database\Console\Migrations\MigrateCommand as NativeMigrate;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand as NativeMake;
use Illuminate\Database\Console\Migrations\RollbackCommand as NativeRollback;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lacodix\LaravelDataMigrations\Console\Commands\InstallCommand;
use Lacodix\LaravelDataMigrations\Console\Commands\MakeMigrateDataCommand;
use Lacodix\LaravelDataMigrations\Console\Commands\MigrateDataCommand;
use Lacodix\LaravelDataMigrations\Console\Commands\RollbackDataCommand;
use Lacodix\LaravelDataMigrations\DataMigrationsServiceProvider;
use Lacodix\LaravelDataMigrations\Repositories\DatabaseDataMigrationRepository;
use Orchestra\Testbench\TestCase;

class DataMigrationsTest extends TestCase
{
    protected string $fixturePath;

    protected bool $publishConfig = true;

    protected function getPackageProviders($app): array
    {
        return [DataMigrationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->fixturePath = sys_get_temp_dir().'/data-migrations-'.bin2hex(random_bytes(8));
        mkdir($this->fixturePath, 0777, true);
        mkdir($this->fixturePath.'/migrations_data');
        mkdir($this->fixturePath.'/migrations');
        $app->useDatabasePath($this->fixturePath);
        $app['config']->set('database.default', 'testing');
        foreach (['testing', 'other'] as $connection) {
            $app['config']->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ]);
        }
        // Original README requires publication. Characterize that configured boot.
        if ($this->publishConfig) {
            $app['config']->set('data-migrations', require __DIR__.'/../../assets/config/data-migrations.php');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('example_settings', function ($table) {
            $table->string('key')->primary();
            $table->string('value');
        });
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->fixturePath);
        parent::tearDown();
    }

    protected function fixture(string $directory, string $name = 'insert_example_setting', string $key = 'timezone'): string
    {
        $file = $directory.'/2026_01_01_000000_'.$name.'.php';
        file_put_contents($file, '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { DB::table("example_settings")->insert(["key" => "'.$key.'", "value" => "UTC"]); }
    public function down(): void { DB::table("example_settings")->where("key", "'.$key.'")->delete(); }
};');
        return $file;
    }

    protected function command(string $name, array $options = []): int
    {
        return $this->app[Kernel::class]->call($name, $options);
    }

    public function test_command_classes_names_options_and_repositories_coexist(): void
    {
        $commands = $this->app[Kernel::class]->all();
        $pairs = [
            ['migrate-data', 'command.migrate-data', MigrateDataCommand::class, 'migrate', NativeMigrate::class],
            ['migrate-data:install', 'command.migrate-data.install', InstallCommand::class, 'migrate:install', NativeInstall::class],
            ['migrate-data:rollback', 'command.migrate-data.rollback', RollbackDataCommand::class, 'migrate:rollback', NativeRollback::class],
            ['make:data-migration', 'command.migrate-data.make', MakeMigrateDataCommand::class, 'make:migration', NativeMake::class],
        ];
        foreach ($pairs as [$name, $binding, $class, $native, $nativeClass]) {
            $this->assertArrayHasKey($name, $commands);
            $this->assertSame($class, get_class($commands[$name]));
            $this->assertSame($this->app[$binding], $commands[$name]);
            $this->assertSame($name, $commands[$name]->getName());
            $this->assertSame($nativeClass, get_class($commands[$native]));
        }
        foreach ([['migrate-data:install', 'migrate:install'], ['migrate-data:rollback', 'migrate:rollback']] as [$data, $native]) {
            $this->assertEquals($commands[$native]->getDefinition()->getOptions(), $commands[$data]->getDefinition()->getOptions());
            $this->assertEquals($commands[$native]->getDefinition()->getArguments(), $commands[$data]->getDefinition()->getArguments());
        }
        foreach (['database', 'force', 'path', 'realpath', 'pretend', 'step', 'graceful'] as $option) {
            $this->assertTrue($commands['migrate-data']->getDefinition()->hasOption($option));
        }
        $repository = $this->app['migration.data.repository'];
        $this->assertInstanceOf(DatabaseDataMigrationRepository::class, $repository);
        $this->assertNotSame($repository, $this->app['migration.repository']);
        $this->assertSame($repository, $this->app['migrator.data']->getRepository());
        foreach (['migrate-data:install' => 'repository', 'migrate-data' => 'migrator', 'migrate-data:rollback' => 'migrator'] as $name => $property) {
            $this->assertSame($property === 'repository' ? $repository : $this->app['migrator.data'], (new \ReflectionProperty($commands[$name], $property))->getValue($commands[$name]));
        }
        $this->assertSame($this->app['migration.repository'], (new \ReflectionProperty($commands['migrate:install'], 'repository'))->getValue($commands['migrate:install']));
    }

    public function test_native_install_creates_only_schema_tracking(): void
    {
        $commands = $this->app[Kernel::class]->all();
        $this->assertSame(0, $this->command('migrate:install'));
        $snapshot = [
            'framework' => $this->app->version(),
            'data_install_name' => $this->app['command.migrate-data.install']->getName(),
            'native_install_class' => get_class($commands['migrate:install']),
            'native_repository_class' => get_class((new \ReflectionProperty($commands['migrate:install'], 'repository'))->getValue($commands['migrate:install'])),
            'schema_table_exists' => Schema::hasTable('migrations'),
            'data_table_exists' => Schema::hasTable('migrations_data'),
        ];
        if ($path = getenv('DATA_MIGRATIONS_SNAPSHOT')) {
            $snapshot['php'] = PHP_VERSION;
            $snapshot['packages'] = [];
            foreach (['laravel/framework', 'orchestra/testbench', 'phpunit/phpunit'] as $package) {
                $snapshot['packages'][$package] = [
                    'version' => \Composer\InstalledVersions::getPrettyVersion($package),
                    'reference' => \Composer\InstalledVersions::getReference($package),
                ];
            }
            $snapshot['commands'] = [];
            foreach (['migrate', 'migrate:install', 'migrate:rollback', 'make:migration', 'migrate-data', 'migrate-data:install', 'migrate-data:rollback', 'make:data-migration'] as $name) {
                $command = $commands[$name];
                $source = (new \ReflectionClass($command))->getFileName();
                $options = [];
                foreach ($command->getDefinition()->getOptions() as $option) {
                    $options[$option->getName()] = [
                        'shortcut' => $option->getShortcut(),
                        'required' => $option->isValueRequired(),
                        'optional' => $option->isValueOptional(),
                        'array' => $option->isArray(),
                        'default' => $option->getDefault(),
                    ];
                }
                $snapshot['commands'][$name] = [
                    'class' => get_class($command), 'source' => $source,
                    'sha256' => hash_file('sha256', $source), 'options' => $options,
                ];
            }

            file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT)."\n");
        }
        $this->assertTrue($snapshot['schema_table_exists']);
        $this->assertFalse($snapshot['data_table_exists']);
    }

    public function test_real_sqlite_lifecycle_is_idempotent_and_separate_from_schema_history(): void
    {
        $this->fixture($this->fixturePath.'/migrations_data');
        $this->assertSame(0, $this->command('migrate:install'));
        $this->assertTrue(Schema::hasTable('migrations'));
        $this->assertFalse(Schema::hasTable('migrations_data'));
        $this->assertSame(0, $this->command('migrate-data'));
        $this->assertSame('UTC', DB::table('example_settings')->value('value'));
        $this->assertSame(1, DB::table('migrations_data')->value('batch'));
        $this->assertSame('2026_01_01_000000_insert_example_setting', DB::table('migrations_data')->value('migration'));
        $this->assertSame(0, DB::table('migrations')->count());
        $this->assertSame(0, $this->command('migrate-data'));
        $this->assertSame(1, DB::table('example_settings')->count());
        $this->assertSame(0, $this->command('migrate-data:rollback'));
        $this->assertSame(0, DB::table('example_settings')->count());
        $this->assertSame(0, DB::table('migrations_data')->count());
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_custom_table_and_explicit_connection_are_isolated(): void
    {
        $this->app['config']->set('data-migrations.table', 'custom_data_history');
        $this->assertSame(0, $this->command('migrate-data:install', ['--database' => 'other']));
        $this->assertTrue(Schema::connection('other')->hasTable('custom_data_history'));
        $this->assertFalse(Schema::hasTable('custom_data_history'));
        $this->assertFalse(Schema::connection('other')->hasTable('migrations'));
    }

    public function test_custom_realpath_step_pretend_and_rollback_options_execute(): void
    {
        $directory = $this->fixturePath.'/custom';
        mkdir($directory);
        $this->fixture($directory);
        $this->fixture($directory, 'insert_second_setting', 'locale');
        $options = ['--path' => [$directory], '--realpath' => true];
        $this->assertSame(0, $this->command('migrate-data', $options + ['--pretend' => true]));
        $this->assertSame(0, DB::table('example_settings')->count());
        $this->assertSame(0, DB::table('migrations_data')->count());
        $this->assertSame(0, $this->command('migrate-data', $options + ['--step' => true]));
        $this->assertSame([1, 2], DB::table('migrations_data')->orderBy('batch')->pluck('batch')->all());
        $this->assertSame(0, $this->command('migrate-data:rollback', $options + ['--step' => 1, '--pretend' => true]));
        $this->assertSame(2, DB::table('example_settings')->count());
        $this->assertSame(0, $this->command('migrate-data:rollback', $options + ['--step' => 1]));
        $this->assertSame(1, DB::table('example_settings')->count());
        $this->assertSame(0, $this->command('migrate-data:rollback', $options));
        $this->assertSame(0, DB::table('example_settings')->count());
    }

    public function test_relative_paths_and_make_output_do_not_write_schema_folder(): void
    {
        $relative = 'private-data-migrations-'.bin2hex(random_bytes(6));
        $directory = $this->app->basePath($relative);
        mkdir($directory);
        try {
            $this->assertSame(0, $this->command('make:data-migration', ['name' => 'insert_custom_setting', '--path' => $relative, '--fullpath' => true]));
            $files = glob($directory.'/*.php');
            $this->assertCount(1, $files);
            $this->assertStringContainsString(basename($files[0]), $this->app[Kernel::class]->output());
            $this->assertSame([], glob($this->fixturePath.'/migrations/*.php'));
            // The maker creates a file only; execution creates the tracking table.
            $this->assertFalse(Schema::hasTable('migrations_data'));
            unlink($files[0]);
            $this->fixture($directory);
            $this->assertSame(0, $this->command('migrate-data', ['--path' => [$relative]]));
            $this->assertSame(1, DB::table('example_settings')->count());
            $this->assertSame(0, $this->command('migrate-data:rollback', ['--path' => [$relative]]));
        } finally {
            $this->app['files']->deleteDirectory($directory);
        }
        $this->assertSame(0, $this->command('make:data-migration', ['name' => 'insert_default_setting']));
        $this->assertCount(1, glob($this->fixturePath.'/migrations_data/*.php'));
    }

    public function test_graceful_returns_success_on_failed_migration_and_preserves_history(): void
    {
        file_put_contents($this->fixturePath.'/migrations_data/2026_01_01_000000_fail.php', '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { throw new \\RuntimeException("fixture failure"); } };');
        $this->assertSame(0, $this->command('migrate-data', ['--graceful' => true]));
        $this->assertSame(0, DB::table('migrations_data')->count());
    }

    public function test_production_refuses_without_confirmation_and_force_runs(): void
    {
        $this->app[Kernel::class]->bootstrap();
        $this->app->detectEnvironment(fn () => 'production');
        $this->fixture($this->fixturePath.'/migrations_data');
        $this->assertSame(1, $this->command('migrate-data', ['--no-interaction' => true]));
        $this->assertStringContainsString('application in production', strtolower($this->app[Kernel::class]->output()));
        $this->assertFalse(Schema::hasTable('migrations_data'));
        $this->assertSame(0, $this->command('migrate-data', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('migrations_data'));
        $this->assertSame(1, $this->command('migrate-data', ['--no-interaction' => true]));
        $this->assertSame(0, $this->command('migrate-data', ['--force' => true]));
        $this->assertSame(1, DB::table('example_settings')->count());
        $this->assertSame(1, DB::table('migrations_data')->count());
        $this->assertSame(1, $this->command('migrate-data:rollback', ['--no-interaction' => true]));
        $this->assertSame(1, DB::table('example_settings')->count());
        $this->assertSame(0, $this->command('migrate-data:rollback', ['--force' => true]));
        $this->assertSame(0, DB::table('example_settings')->count());
        $this->assertSame(0, DB::table('migrations_data')->count());
    }

    public function test_native_schema_and_data_cli_lifecycles_coexist(): void
    {
        file_put_contents($this->fixturePath.'/migrations/2026_01_01_000000_create_schema_owned.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create("schema_owned", fn ($table) => $table->id()); }
    public function down(): void { Schema::drop("schema_owned"); }
};');
        $this->fixture($this->fixturePath.'/migrations_data');
        $this->assertSame(0, $this->command('migrate'));
        $this->assertTrue(Schema::hasTable('schema_owned'));
        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertSame(0, $this->command('migrate-data'));
        $this->assertSame(1, DB::table('migrations_data')->count());
        $this->assertSame(0, $this->command('migrate:rollback'));
        $this->assertFalse(Schema::hasTable('schema_owned'));
        $this->assertSame(1, DB::table('example_settings')->count());
        $this->assertSame(1, DB::table('migrations_data')->count());
        $this->assertSame(0, $this->command('migrate-data:rollback'));
        $this->assertSame(0, DB::table('example_settings')->count());
        $this->assertSame(0, DB::table('migrations')->count());
    }

    public function test_explicit_database_runs_and_rolls_back_only_selected_connection(): void
    {
        Schema::connection('other')->create('example_settings', function ($table) {
            $table->string('key')->primary();
            $table->string('value');
        });
        $this->fixture($this->fixturePath.'/migrations_data');
        $this->assertSame(0, $this->command('migrate-data', ['--database' => 'other']));
        $this->assertSame(1, DB::connection('other')->table('example_settings')->count());
        $this->assertSame(1, DB::connection('other')->table('migrations_data')->value('batch'));
        $this->assertSame(0, DB::table('example_settings')->count());
        $this->assertFalse(Schema::hasTable('migrations_data'));
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->assertSame(0, $this->command('migrate-data:rollback', ['--database' => 'other']));
        $this->assertSame(0, DB::connection('other')->table('example_settings')->count());
        $this->assertSame(0, DB::connection('other')->table('migrations_data')->count());
        $this->assertSame('testing', DB::getDefaultConnection());
    }

    public function test_laravel_package_manifest_discovers_the_public_provider(): void
    {
        $directory = $this->fixturePath.'/discovery';
        mkdir($directory.'/vendor/composer', 0777, true);
        $manifest = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/vendor/composer/installed.json', json_encode(['packages' => [$manifest]], JSON_THROW_ON_ERROR));
        $discovery = new \Illuminate\Foundation\PackageManifest($this->app['files'], $directory, $directory.'/packages.php');
        $this->assertSame([DataMigrationsServiceProvider::class], $discovery->providers());
        $this->assertSame([], $discovery->aliases());
        $this->assertInstanceOf(DataMigrationsServiceProvider::class, $this->app->register($discovery->providers()[0]));
    }

    public function test_readme_migration_example_executes_on_sqlite(): void
    {
        $readme = file_get_contents(__DIR__.'/../../README.md');
        preg_match_all('/```php\n(.*?)```/s', $readme, $blocks);
        $examples = array_values(array_filter($blocks[1], fn ($block) => str_contains($block, 'return new class extends Migration')));
        $this->assertCount(1, $examples);
        file_put_contents($this->fixturePath.'/migrations_data/2026_01_01_000000_readme_example.php', $examples[0]);
        $this->assertSame(0, $this->command('migrate-data'));
        $this->assertSame('UTC', DB::table('example_settings')->where('key', 'timezone')->value('value'));
        $this->assertSame(0, $this->command('migrate-data:rollback'));
        $this->assertSame(0, DB::table('example_settings')->count());
    }

    public function test_publish_tag_and_service_keys_remain_available(): void
    {
        $provider = $this->app->getProvider(DataMigrationsServiceProvider::class);
        $this->assertSame(['migrator.data', 'migration.data.repository', 'command.migrate-data', 'command.migrate-data.install', 'command.migrate-data.rollback', 'command.migrate-data.make'], $provider->provides());
        $paths = DataMigrationsServiceProvider::pathsToPublish(DataMigrationsServiceProvider::class, 'data-migrations');
        $this->assertContains($this->app->configPath('data-migrations.php'), $paths);
        $this->assertContains($this->fixturePath.'/migrations_data', $paths);
    }
}
