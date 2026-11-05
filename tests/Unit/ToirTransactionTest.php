<?php

namespace Tests\Unit;

use App\Support\ToirTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ToirTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function locked(): QueryException
    {
        return new QueryException(
            'sqlite',
            'UPDATE toir_stock_items SET quantity_reserved = ?',
            ['1.000'],
            new \PDOException('database is locked', 5)
        );
    }

    #[Test]
    public function it_retries_whole_sqlite_transactions_after_lock_contention(): void
    {
        $attempts = 0;
        $result = ToirTransaction::run(function () use (&$attempts): int {
            $attempts++;
            if ($attempts < 3) {
                throw $this->locked();
            }

            return $attempts;
        }, 4);

        $this->assertSame(3, $result);
        $this->assertSame(3, $attempts);
    }

    #[Test]
    public function it_does_not_retry_unrelated_database_errors(): void
    {
        $attempts = 0;

        try {
            ToirTransaction::run(function () use (&$attempts): void {
                $attempts++;
                throw new QueryException(
                    'sqlite', 'INSERT INTO toir_stock_items (...) VALUES (...)', [],
                    new \PDOException('UNIQUE constraint failed: toir_stock_items.company_id, toir_stock_items.sku', 19)
                );
            }, 4);
            $this->fail('Expected the original constraint violation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint', $exception->getMessage());
        }

        $this->assertSame(1, $attempts);
    }

    #[Test]
    public function it_returns_service_unavailable_when_all_lock_retries_are_exhausted(): void
    {
        $attempts = 0;

        try {
            ToirTransaction::run(function () use (&$attempts): void {
                $attempts++;
                throw $this->locked();
            }, 2);
            $this->fail('Expected an explicit retryable 503 response.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame(2, $attempts);
        }
    }
}
