<?php

namespace X7media\LaravelPlanetscale\Console\Commands;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use X7media\LaravelPlanetscale\Connection;
use Illuminate\Http\Client\RequestException;
use X7media\LaravelPlanetscale\LaravelPlanetscale;
use Illuminate\Database\Console\Migrations\BaseCommand;

class PscaleMigrateCommand extends BaseCommand
{
    protected $signature = 'pscale:migrate {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--schema-path= : The path to a schema dump file}
        {--pretend : Dump the SQL queries that would be run}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step : Force the migrations to be run so they can be rolled back individually}';

    protected $description = 'Prepare and run laravel migrations against a planetscale database';

    protected bool $hasError = false;
    protected int $pollRate = 5; // in seconds

    public function __construct(protected LaravelPlanetscale $pscale)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Laravel migration tool for Planetscale databases.');
        $this->newLine();

        if ($this->hasNoPendingMigrations() && $this->pscale->runMigrations()) {
            $this->info('There are no pending migrations needing to be ran.');
            return 0;
        }

        $this->branch();

        return ($this->hasError) ? 1 : 0;
    }

    public function error($string, $verbosity = null): void
    {
        parent::error($string, $verbosity);
        $this->hasError = true;
    }

    private function branch()
    {
        // create the development branch
        $this->line('Creating development branch to run migrations on...');
        try {
            $dev_branch = $this->pscale->createBranch('artisan-migrate-' . time());
        } catch (RequestException $e) {
            return $this->error('Failed to create development branch.');
        }

        // Gotta wait for the new branch to initalize
        $this->line('Waiting for development branch to initalize...');
        do {
            sleep($this->pollRate);
        } while (!$this->pscale->isBranchReady($dev_branch));

        // Get a username and password to connect to the new dev branch
        $this->line('Obtaining credentials to development branch...');
        try {
            $connection = $this->pscale->branchPassword($dev_branch);
        } catch (RequestException $e) {
            return $this->error('Unable to obtain credentials for development branch.');
        }

        // Swapping DB connection to dev branch
        $this->line('Connecting application to development branch...');
        if (!$this->setDatabaseConnection($connection))
            return;

        // Defer to `artisan migrate` to run the migrations on the dev branch
        $this->line('Running Laravel migrations on development branch...');
        if ($this->pscale->runMigrations()) {
            if ($this->call('migrate', [
                '--database' => $this->option('database'),
                '--force' => $this->option('force'),
                '--path' => $this->option('path'),
                '--realpath' => $this->option('realpath'),
                '--schema-path' => $this->option('schema-path'),
                '--pretend' => $this->option('pretend'),
                '--seed' => $this->option('seed'),
                '--seeder' => $this->option('seeder'),
                '--step' => $this->option('step'),
            ]) > 0)
                return $this->error('An error occured while trying to complete the migration on the development branch.');
        } else {
            $this->warn("Testing detected. Skip running `php artisan migrate`...");
        }

        // Create a deploy request to merge the dev branch back into production
        $this->line('Creating deploy request from development branch...');
        try {
            $deploy_id = $this->pscale->deployRequest($dev_branch);
        } catch (RequestException $e) {
            return $this->error('Unable to create the deploy request on Planetscale.');
        }

        // Wait for it to be deployable
        $this->line('Verifying deploy request is mergeable...');
        do {
            sleep($this->pollRate);
        } while ($this->pscale->deploymentState($deploy_id) == 'pending');

        $this->line('Applying changes back to production branch...');
        try {
            $this->pscale->completeDeploy($deploy_id);
        } catch (RequestException $e) {
            return $this->error('Unable to deploy the development branch to the production branch.');
        }

        //Check deployment status
        $this->line('Confirming deployment was successful...');
        do {
            sleep($this->pollRate);
            $deployment_state = $this->pscale->deploymentState($deploy_id);
        } while (!in_array($deployment_state, ['complete', 'complete_cancel', 'complete_error']));

        if ($deployment_state == 'complete_cancel')
            return $this->error('The deployment was unexpectedly canceled.');

        if ($deployment_state == 'complete_error')
            return $this->error('An unexcepected error occured during the deployment.');

        //Delete dev branch
        $this->line('Deleting the development branch...');
        try {
            $this->pscale->deleteBranch($dev_branch);
        } catch (RequestException $e) {
            return $this->error('The deployment was successful, but we were unable to delete the dev branch afterwards.');
        }

        $this->newLine();
        $this->info('Migrations successfully applied to production branch!');
    }

    // adapted from 'spatie/laravel-multitenancy' (also MIT licensed) tenant database switching task,
    // source here: https://github.com/spatie/laravel-multitenancy/blob/928cb24a087d8a9f00a963936446cb30841aa86a/src/Tasks/SwitchTenantDatabaseTask.php#L25
    protected function setDatabaseConnection(Connection $connection): bool
    {
        $connectionName = $this->option('database') ?? config('database.default');

        if (is_null(config("database.connections.{$connectionName}"))) {
            $this->error("The database connection `{$connectionName}` is not a valid connection configured on this application.");
            return false;
        }

        config([
            "database.connections.{$connectionName}.host" => $connection->host,
            "database.connections.{$connectionName}.database" => $connection->database,
            "database.connections.{$connectionName}.username" => $connection->username,
            "database.connections.{$connectionName}.password" => $connection->password,
        ]);

        app('db')->extend($connectionName, function ($config, $name) use ($connection) {
            $config['host'] = $connection->host;
            $config['database'] = $connection->database;
            $config['username'] = $connection->username;
            $config['password'] = $connection->password;

            return app('db.factory')->make($config, $name);
        });

        DB::purge($connectionName);

        // Octane will have an old `db` instance in the Model::$resolver.
        Model::setConnectionResolver(app('db'));

        return true;
    }

    protected function hasNoPendingMigrations(): bool
    {
        // Use migrate:status to get any pending migrations.
        Artisan::call('migrate:status', [
            '--pending' => true,
            '--database' => $this->option('database'),
            '--path' => $this->option('path'),
            '--realpath' => $this->option('realpath')
        ]);

        // After trimming excess line endings,
        // the number of line endings should match the number of pending migrations.
        return substr_count(trim(Artisan::output()), "\n") == 0;
    }
}
