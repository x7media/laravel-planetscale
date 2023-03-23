<?php

namespace X7media\LaravelPlanetscale;

class Connection
{
    public readonly string $database;

    public function __construct(public readonly string $host, public readonly string $username, public readonly string $password)
    {
        $this->database = config('planetscale.database');
    }
}
