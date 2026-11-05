<?php

namespace App\Support;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ToirTransaction
{
    /**
     * Retry an entire SQLite write transaction after lock contention.
     * Retry after rollback, never against a stale read snapshot.
     * Use only with DB-only operations, not external side effects.
     */
    public static function run(Closure $operation, int $attempts = 5): mixed
    {
        $attempts = max(1, min(8, $attempts));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return DB::transaction($operation, 1);
            } catch (QueryException|DeadlockException $exception) {
                if (DB::connection()->getDriverName() !== 'sqlite'
                    || ! preg_match('/database (?:table )?is locked|database is busy/i', $exception->getMessage())) {
                    throw $exception;
                }

                if ($attempt === $attempts) {
                    abort(503, 'База временно занята. Повторите операцию через несколько секунд.');
                }

                usleep(20_000 * $attempt + random_int(0, 20_000));
            }
        }

        throw new \LogicException('Transaction retry loop terminated unexpectedly.');
    }
}
