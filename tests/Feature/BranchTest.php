<?php

namespace X7media\LaravelPlanetscale\Tests\Feature;

use Illuminate\Support\Facades\Http;
use X7media\LaravelPlanetscale\Tests\TestCase;

class BranchTest extends TestCase
{
    public function test_that_a_branch_migration_succeeds(): void
    {
        config([
            'planetscale.service_token.id' => '1',
            'planetscale.service_token.value' => 'valid',
            'planetscale.organization' => 'laravel-test',
            'planetscale.database' => 'laravel-test',
        ]);

        Http::preventStrayRequests();

        $base_url = 'api.planetscale.com/v1/organizations/laravel-test/databases/laravel-test';

        Http::fake([
            "{$base_url}/branches" => Http::response($this->getFixture('branch.success'), 201),
            "{$base_url}/branches/artisan-migrate-0000000000" => Http::response($this->getFixture('branch-ready.success'), 200),
            "{$base_url}/branches/artisan-migrate-0000000000/passwords" => Http::response($this->getFixture('branch-password.success'), 201),
            "{$base_url}/deploy-requests" => Http::response($this->getFixture('new-deploy-request.success'), 200),
            "{$base_url}/deploy-requests/1" => Http::response($this->getFixture('deployed.success'), 200),
            "{$base_url}/deploy-requests/1/deploy" => Http::response($this->getFixture('apply-deploy-request.success'), 200),
        ]);

        $this->assertNotEquals(config('database.connections.testing.username'), 'xxxxxxxxxxxxxxxxxxxx');
        $this->assertNotEquals(config('database.connections.testing.password'), 'pscale_pw_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        $this->artisan('pscale:migrate')
            ->assertExitCode(0);

        $this->assertEquals(config('database.connections.testing.username'), 'xxxxxxxxxxxxxxxxxxxx');
        $this->assertEquals(config('database.connections.testing.password'), 'pscale_pw_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    }
}
