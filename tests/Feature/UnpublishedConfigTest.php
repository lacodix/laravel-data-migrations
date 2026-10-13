<?php

namespace Lacodix\LaravelDataMigrations\Tests\Feature;

use Illuminate\Support\Facades\Schema;

class UnpublishedConfigTest extends DataMigrationsTest
{
    protected bool $publishConfig = false;

    public function test_unpublished_data_install_executes(): void
    {
        $this->assertSame(0, $this->command('migrate-data:install'));
        $this->assertTrue(Schema::hasTable('migrations_data'));
    }

    public function test_unpublished_config_supplies_default_table(): void
    {
        $this->assertSame('migrations_data', $this->app['config']->get('data-migrations.table'));
        $this->assertSame(0, $this->command('migrate-data:install'));
        $this->assertTrue(Schema::hasTable('migrations_data'));
        $this->assertFalse(Schema::hasTable('migrations'));
    }
}
