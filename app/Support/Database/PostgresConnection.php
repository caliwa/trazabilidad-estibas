<?php

namespace App\Support\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * PostgreSQL connection that sends dates with microseconds and their UTC
 * offset. Laravel's default format ("Y-m-d H:i:s") drops both: a
 * timestamptz column then reads a Bogotá time as if it were in the session
 * time zone, and the order of events within one second is lost.
 *
 * Plain "timestamp" columns (users, sessions, jobs) ignore the offset, so
 * they keep their previous behavior.
 */
class PostgresConnection extends BasePostgresConnection
{
    public const DATE_FORMAT = 'Y-m-d H:i:s.uP';

    protected function getDefaultQueryGrammar()
    {
        return new class($this) extends PostgresGrammar
        {
            public function getDateFormat()
            {
                return PostgresConnection::DATE_FORMAT;
            }
        };
    }
}
