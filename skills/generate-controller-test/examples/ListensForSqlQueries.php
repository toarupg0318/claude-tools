<?php

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

trait ListensForSqlQueries
{
    protected array $capturedQueries = [];

    protected function startSqlListener(): void
    {
        $this->capturedQueries = [];

        DB::listen(function (QueryExecuted $query) {
            $this->capturedQueries[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time_ms' => $query->time,
                'raw_sql' => method_exists($query, 'toRawSql') ? $query->toRawSql() : null,
            ];
        });
    }

    protected function capturedQueries(): array
    {
        return $this->capturedQueries;
    }
}