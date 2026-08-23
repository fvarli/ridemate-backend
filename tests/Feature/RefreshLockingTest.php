<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\DeviceDescription;
use App\Auth\TokenKind;
use App\Auth\TokenSecret;
use App\Auth\TokenService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * `SELECT ... FOR UPDATE` genuinely blocks a second transaction.
 *
 * TokenServiceTest already proves the OUTCOME of reuse — present a rotated
 * generation and the family dies. What it cannot prove is the mechanism that
 * makes two SIMULTANEOUS refreshes safe, because both of its calls run one
 * after the other on one connection, where the lock is never contended. If the
 * `lockForUpdate()` were quietly dropped, every test in that class would still
 * pass and two concurrent requests could both rotate the same generation.
 *
 * WHY THIS CLASS DOES NOT USE RefreshDatabase
 *
 * That trait wraps each test in a transaction on a single connection. A second
 * connection could never observe uncommitted work, so the contention this test
 * needs cannot exist inside it. The rows are therefore real and are cleaned up
 * explicitly.
 *
 * WHY THERE IS A lock_timeout
 *
 * The failure mode of a lock test is a hang, and a hung CI job is fifteen
 * minutes of nothing followed by a timeout that names no cause. With
 * lock_timeout the regression is a fast, specific error instead.
 */
final class RefreshLockingTest extends TestCase
{
    use CreatesAccounts;

    private const SECOND = 'rm_concurrent';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->truncate();

        // A genuinely separate PDO connection to the same test database.
        // Same configuration, different handle — which is the only way to have
        // two transactions at once from one process.
        config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::SECOND);
        $this->truncate();

        parent::tearDown();
    }

    private function truncate(): void
    {
        DB::statement('truncate table auth_tokens, auth_sessions, accounts cascade');
    }

    public function test_a_second_transaction_cannot_take_the_same_token_row(): void
    {
        $pair = app(TokenService::class)->issue(
            $this->createAccount(),
            DeviceDescription::unknown(),
        );

        $tokenId = TokenSecret::parse(TokenKind::Refresh, $pair->refreshToken)?->id;
        self::assertNotNull($tokenId);

        // Transaction A takes the row, exactly as rotation does.
        DB::beginTransaction();

        try {
            $held = DB::table('auth_tokens')->where('id', $tokenId)->lockForUpdate()->first();
            self::assertNotNull($held);

            $second = DB::connection(self::SECOND);
            $second->statement("set lock_timeout = '1s'");

            try {
                $second->table('auth_tokens')->where('id', $tokenId)->lockForUpdate()->first();
                self::fail('the second transaction acquired a lock that was already held');
            } catch (QueryException $e) {
                // 55P03 lock_not_available. Reaching this means the row lock is
                // real: without it the read would have returned immediately.
                self::assertSame('55P03', $e->getCode());
            }
        } finally {
            DB::rollBack();
        }
    }

    /**
     * The lock is released on commit, so the second caller is delayed rather
     * than permanently locked out — and what it then reads is the FIRST
     * transaction's committed state, which is what makes it recognise the
     * generation as already rotated.
     */
    public function test_the_row_is_readable_again_once_the_first_transaction_ends(): void
    {
        $pair = app(TokenService::class)->issue(
            $this->createAccount(),
            DeviceDescription::unknown(),
        );
        $tokenId = TokenSecret::parse(TokenKind::Refresh, $pair->refreshToken)?->id;
        self::assertNotNull($tokenId);

        DB::beginTransaction();
        DB::table('auth_tokens')->where('id', $tokenId)->lockForUpdate()->first();
        DB::table('auth_tokens')->where('id', $tokenId)->update(['rotated_at' => now()]);
        DB::commit();

        $second = DB::connection(self::SECOND);
        $second->statement("set lock_timeout = '1s'");
        $row = $second->table('auth_tokens')->where('id', $tokenId)->lockForUpdate()->first();

        self::assertNotNull($row);
        self::assertNotNull(
            $row->rotated_at,
            'the waiting transaction must observe the committed rotation, not a stale snapshot',
        );
    }
}
