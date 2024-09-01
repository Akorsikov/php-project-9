<?php

namespace Php\Project;

class Connection
{
    private \PDO $connectionDB;

    public function __construct(string $stringBaseUrl)
    {
        $databaseUrl = parse_url($stringBaseUrl);

        $host = $databaseUrl['host'] ?? null;
        $port = $databaseUrl['port'] ?? 5432;
        $dbname = ltrim($databaseUrl['path'] ?? '', '/');
        $user = $databaseUrl['user'] ?? null;
        $password = $databaseUrl['pass'] ?? null;

        $connectString = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $host,
            $port,
            $dbname,
            $user,
            $password
        );

        $this->connectionDB = new \PDO($connectString);
        $this->connectionDB->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connectionDB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getConnect()
    {
        return $this->connectionDB;
    }
}
