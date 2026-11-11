<?php

namespace Tests\Unit;

use App\Support\ConcurrencyErrors;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConcurrencyErrorsTest extends TestCase
{
    public function test_conflicts_are_retried_even_after_a_nested_transaction_rethrows_them(): void
    {
        $detector = new ConcurrencyErrors;
        $mariadb = new PDOException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'fin_boxes\'');
        $postgres = new PDOException('SQLSTATE[40001]: Serialization failure: 7 ERROR:  could not serialize access due to concurrent update');
        $postgres->errorInfo = ['40001'];

        $this->assertTrue($detector->causedByConcurrencyError(new QueryException('mariadb', 'select 1', [], $mariadb)));
        // A nested DB::transaction() rethrows as DeadlockException with code 0: only the message is left.
        $this->assertTrue($detector->causedByConcurrencyError(new DeadlockException($mariadb->getMessage(), 0, $mariadb)));
        $this->assertTrue($detector->causedByConcurrencyError(new DeadlockException($postgres->getMessage(), 0, $postgres)));
        $this->assertTrue($detector->causedByConcurrencyError(new DeadlockException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected', 0)));

        $this->assertFalse($detector->causedByConcurrencyError(new RuntimeException('Box 1020 not found')));
        $this->assertFalse($detector->causedByConcurrencyError(new QueryException('mysql', 'insert', [], new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'))));
    }
}
