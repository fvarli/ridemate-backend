<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\DeviceDescription;
use App\Auth\TokenService;
use App\Models\AccountStatus;
use App\Models\AuthSession;
use App\Models\SessionRevocationReason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The console is the operations surface for Phase 9.
 *
 * There is no admin panel and no endpoint for any of this, because there is no
 * screen that would drive one. These commands are what "manual ops without
 * editing production rows by hand" actually means at this size — and they are
 * also the only producer of the `suspended` state, which is what stops it
 * being a status the application can never reach.
 */
final class OperatorCommandsTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    /**
     * artisan() is typed as PendingCommand|int, because it returns the exit
     * code once the command has been run. Narrowing it here keeps the
     * assertion chain honest rather than suppressing the analyser at
     * seventeen call sites.
     *
     * @param  array<string, string>  $arguments
     */
    private function runCommand(string $command, array $arguments): PendingCommand
    {
        $pending = $this->artisan($command, $arguments);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Suspension has to close the window, not just set a flag.
     *
     * Setting the status alone would leave the member with a working access
     * token until it expired — precisely the minutes an operator is trying to
     * take away.
     */
    public function test_suspending_revokes_live_sessions_immediately(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $this->runCommand('ridemate:account:suspend', ['phone' => '+905321234567'])
            ->assertSuccessful();

        self::assertSame(AccountStatus::Suspended, $account->refresh()->status);
        self::assertSame(
            SessionRevocationReason::AccountSuspended,
            AuthSession::query()->findOrFail($pair->sessionId)->revoked_reason,
        );

        $this->expectException(AuthenticationException::class);
        app(TokenService::class)->authenticate($pair->accessToken);
    }

    /**
     * An operator types the number the way the member gave it to them, not in
     * E.164. The same normalizer runs here as at the request boundary.
     */
    public function test_the_operator_may_type_the_number_in_any_format(): void
    {
        $account = $this->createAccount('+905321234567');

        $this->runCommand('ridemate:account:suspend', ['phone' => '0532 123 45 67'])
            ->assertSuccessful();

        self::assertSame(AccountStatus::Suspended, $account->refresh()->status);
    }

    public function test_suspending_refuses_an_unparseable_number(): void
    {
        $this->runCommand('ridemate:account:suspend', ['phone' => 'not a phone'])
            ->assertFailed();
    }

    public function test_suspending_refuses_an_unknown_account(): void
    {
        $this->runCommand('ridemate:account:suspend', ['phone' => '+905329876543'])
            ->assertFailed();
    }

    /**
     * Restoring undoes the status and nothing else.
     *
     * A revoked family stays revoked. Reviving sessions that were deliberately
     * killed — possibly because a token was stolen — is not something an
     * operator should be able to do by accident while fixing a mistake.
     */
    public function test_restoring_does_not_revive_revoked_sessions(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $this->runCommand('ridemate:account:suspend', ['phone' => '+905321234567'])->assertSuccessful();
        $this->runCommand('ridemate:account:restore', ['phone' => '+905321234567'])->assertSuccessful();

        self::assertSame(AccountStatus::Active, $account->refresh()->status);
        self::assertNotNull(AuthSession::query()->findOrFail($pair->sessionId)->revoked_at);

        $this->expectException(AuthenticationException::class);
        app(TokenService::class)->authenticate($pair->accessToken);
    }

    public function test_restoring_lets_the_member_sign_in_again(): void
    {
        $account = $this->createAccount();

        $this->runCommand('ridemate:account:suspend', ['phone' => '+905321234567'])->assertSuccessful();
        $this->runCommand('ridemate:account:restore', ['phone' => '+905321234567'])->assertSuccessful();

        // A fresh session works, which is the point: the account is usable
        // again without any old credential coming back to life.
        $fresh = app(TokenService::class)->issue($account->refresh(), DeviceDescription::unknown());
        $context = app(TokenService::class)->authenticate($fresh->accessToken);

        self::assertSame($account->id, $context->account->id);
        self::assertNotSame($fresh->sessionId, '');
    }

    public function test_revoking_one_session_leaves_the_others_alone(): void
    {
        $account = $this->createAccount();
        $tokens = app(TokenService::class);

        $lost = $tokens->issue($account, new DeviceDescription('Old phone'));
        $kept = $tokens->issue($account, new DeviceDescription('New phone'));

        $this->runCommand('ridemate:session:revoke', ['session' => $lost->sessionId])
            ->assertSuccessful();

        self::assertSame(
            SessionRevocationReason::Operator,
            AuthSession::query()->findOrFail($lost->sessionId)->revoked_reason,
        );

        $tokens->authenticate($kept->accessToken);
    }

    public function test_revoking_refuses_something_that_is_not_a_session_id(): void
    {
        $this->runCommand('ridemate:session:revoke', ['session' => 'nonsense'])->assertFailed();
    }

    public function test_revoking_an_unknown_session_fails(): void
    {
        $this->runCommand('ridemate:session:revoke', ['session' => '0198a1b2-c3d4-7000-8000-000000000000'])
            ->assertFailed();
    }

    /**
     * Re-revoking is not an error, and must not overwrite the original reason.
     */
    public function test_revoking_twice_is_harmless(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $this->runCommand('ridemate:session:revoke', ['session' => $pair->sessionId])->assertSuccessful();
        $this->runCommand('ridemate:session:revoke', ['session' => $pair->sessionId])->assertSuccessful();

        self::assertSame(
            SessionRevocationReason::Operator,
            AuthSession::query()->findOrFail($pair->sessionId)->revoked_reason,
        );
    }

    public function test_a_suspended_account_cannot_refresh_either(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $this->runCommand('ridemate:account:suspend', ['phone' => '+905321234567'])->assertSuccessful();

        // The session was revoked by suspension, so this refuses as
        // unauthenticated before it ever reaches the status check.
        $this->expectException(AuthenticationException::class);
        app(TokenService::class)->rotate($pair->refreshToken);
    }

    /**
     * Suspension without revocation — the status check standing on its own.
     *
     * Reached by suspending the account directly rather than through the
     * command, so the session is still live and the ONLY thing refusing the
     * refresh is the account status. Without this, the 403 branch in rotation
     * would never be exercised.
     */
    public function test_refresh_is_forbidden_when_only_the_status_blocks_it(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->expectException(AuthorizationException::class);
        app(TokenService::class)->rotate($pair->refreshToken);
    }
}
