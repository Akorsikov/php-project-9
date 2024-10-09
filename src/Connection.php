<?php

namespace Php\Project;

class Connection
{
    private \PDO $connectionDB;

    private function getConnectStringDB(string $stringBaseUrl)
    {
        $databaseUrl = parse_url($stringBaseUrl);

        $host = $databaseUrl['host'];
        $port = $databaseUrl['port'];
        $dbname = ltrim($databaseUrl['path'], '/');
        $user = $databaseUrl['user'];
        $password = $databaseUrl['pass'];

        return sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $host,
            $port,
            $dbname,
            $user,
            $password
        );
    }

    public function __construct(string $DataBaseUrl)
    {
        $connectString = $this->getConnectStringDB($DataBaseUrl);

        $this->connectionDB = new \PDO($connectString);
        $this->connectionDB->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connectionDB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getConnect()
    {
        return $this->connectionDB;
    }
}
