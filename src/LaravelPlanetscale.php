<?php

namespace X7media\LaravelPlanetscale;

use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use X7media\LaravelPlanetscale\Connection;
use Illuminate\Http\Client\ConnectionException;

class LaravelPlanetscale
{
    protected string $baseUrl = 'https://api.planetscale.com/v1';

    public function __construct(private ?string $service_token_id = '', private ?string $service_token = '')
    {
    }

    public function createBranch(string $name): string
    {
        return $this->post("branches", [
            'name' => $name,
            'parent_branch' => config('planetscale.production_branch')
        ])->json('name');
    }

    public function isBranchReady(string $name): bool
    {
        return $this->get("branches/{$name}")->json('ready');
    }

    public function branchPassword(string $for): Connection
    {
        $response = $this->post("branches/{$for}/passwords");

        return new Connection(
            $response->json('access_host_url'),
            $response->json('username'),
            $response->json('plain_text')
        );
    }

    public function deployRequest(string $from): ?int
    {
        $response = $this->post('deploy-requests', [
            'branch' => $from,
            'into_branch' => config('planetscale.production_branch')
        ]);

        return ($response->successful()) ? $response->json('number') : null;
    }

    public function deploymentState(int $number): string
    {
        return $this->get("deploy-requests/{$number}")->json('deployment_state');
    }

    public function completeDeploy(int $number): void
    {
        $this->post("deploy-requests/{$number}/deploy");
    }

    public function deleteBranch(string $name): void
    {
        $this->baseRequest()->delete($this->getUrl("branches/{$name}"))->throw();
    }

    public function runMigrations(): bool
    {
        return (App::environment() != 'testing');
    }

    private function getUrl(string $endpoint): string
    {
        $organization = config('planetscale.organization');
        $database = config('planetscale.database');

        return "{$this->baseUrl}/organizations/{$organization}/databases/{$database}/{$endpoint}";
    }

    private function get(string $endpoint, array $body = []): Response
    {
        return $this
            ->baseRequest()
            ->get($this->getUrl($endpoint), $body)
            ->throw();
    }

    private function post(string $endpoint, array $body = []): Response
    {
        return $this
            ->baseRequest()
            ->post($this->getUrl($endpoint), $body)
            ->throw();
    }

    private function baseRequest(): PendingRequest
    {
        return Http::withToken("{$this->service_token_id}:{$this->service_token}", '')
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->retry(3, 1000, function (Exception $exception, PendingRequest $request) {
                return $exception instanceof ConnectionException;
            });
    }
}
