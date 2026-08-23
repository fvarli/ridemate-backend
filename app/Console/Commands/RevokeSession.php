<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\TokenService;
use App\Models\AuthSession;
use App\Models\SessionRevocationReason;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Kills one device's session.
 *
 * The narrower tool: a member reports a lost phone and one family dies while
 * their other devices carry on. There is no endpoint for this because there is
 * no screen that would list sessions to choose from, and an endpoint with no
 * caller is a surface with no reviewer.
 */
final class RevokeSession extends Command
{
    protected $signature = 'ridemate:session:revoke {session : The auth session id}';

    protected $description = 'Revoke one authenticated session';

    public function handle(TokenService $tokens): int
    {
        $id = $this->argument('session');

        if (! Str::isUuid($id)) {
            $this->error('That is not a session id.');

            return self::FAILURE;
        }

        $session = AuthSession::query()->find($id);

        if (! $session instanceof AuthSession) {
            $this->error('No such session.');

            return self::FAILURE;
        }

        if (! $tokens->revoke($session, SessionRevocationReason::Operator)) {
            $this->warn('That session was already revoked; nothing changed.');

            return self::SUCCESS;
        }

        $this->info("Revoked {$session->id}.");

        return self::SUCCESS;
    }
}
