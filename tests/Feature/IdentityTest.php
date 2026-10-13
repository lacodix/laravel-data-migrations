<?php

namespace Lacodix\LaravelDataMigrations\Tests\Feature;

use PHPUnit\Framework\TestCase;

class IdentityTest extends TestCase
{
    public function test_public_identity_and_discovery_use_lacodix_without_aliases(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('lacodix/laravel-data-migrations', $manifest['name']);
        $provider = 'Lacodix\\LaravelDataMigrations\\DataMigrationsServiceProvider';
        $this->assertSame([$provider], $manifest['extra']['laravel']['providers']);
        $this->assertTrue(class_exists($provider));
        $this->assertSame(realpath(__DIR__.'/../../src/DataMigrationsServiceProvider.php'), (new \ReflectionClass($provider))->getFileName());
        $this->assertFalse(class_exists('Jlorente\\DataMigrations\\DataMigrationsServiceProvider'));
        $this->assertArrayNotHasKey('replace', $manifest);
        $this->assertArrayNotHasKey('provide', $manifest);
        $this->assertArrayNotHasKey('policy', $manifest['config']);
    }
}
