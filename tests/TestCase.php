<?php

namespace X7media\LaravelPlanetscale\Tests;

use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getFixture(string $name): string
    {
        return file_get_contents("tests/Fixtures/{$name}.json");
    }

    protected function getPackageProviders($app): array
    {
        return ['X7media\LaravelPlanetscale\LaravelPlanetscaleServiceProvider'];
    }
}
