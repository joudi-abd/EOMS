<?php

namespace App\Support;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Str;
use Throwable;

/**
 * Laravel's detector, plus the conflicts it misses once a nested transaction has rethrown the error as a
 * DeadlockException (the SQLSTATE code is lost, only the message is left): PostgreSQL serialization
 * failures and MariaDB's snapshot check by its code. A detected error retries the whole transaction.
 */
class ConcurrencyErrors extends ConcurrencyErrorDetector implements ConcurrencyErrorDetectorContract
{
    public function causedByConcurrencyError(Throwable $e): bool
    {
        if (parent::causedByConcurrencyError($e)) {
            return true;
        }
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if ((string) $error->getCode() === '40001' || (string) $error->getCode() === '40P01') {
                return true;
            }
        }

        return Str::contains($e->getMessage(), [
            'could not serialize access',
            'Serialization failure',
            'Record has changed since last read',
        ]);
    }
}
