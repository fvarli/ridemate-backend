<?php

declare(strict_types=1);

namespace App\Models;

use App\Otp\OtpChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One issued passcode.
 *
 * The plaintext code is NOT on this model and never will be. It exists in a
 * local variable during issuance, travels to the sender, and is gone.
 *
 * `destination` is read through `channel`: an E.164 number for `Sms`, and an
 * email address for `Email`. It was called `phone_e164` while there was only
 * one kind, and the rename is what stops the next channel being stored in a
 * column that says it is something else.
 *
 * `registration_id` is the SCOPE: null for a challenge that belongs to no
 * registration — the sign-in namespace — and otherwise the registration whose
 * proof it may earn. It is what stops a code issued for one registration
 * verifying another that named the same address, and what stops either being
 * spent at `POST /auth/otp/verify`. See `App\Otp\OtpScope`.
 *
 * @property string $id
 * @property string|null $registration_id
 * @property OtpChannel $channel
 * @property string $destination
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $invalidated_at
 * @property CarbonImmutable $created_at
 */
class OtpChallenge extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * Neither used nor superseded — the state the partial unique index counts.
     *
     * Says nothing about expiry: an expired row is still unresolved until
     * something invalidates it, which is exactly why issuance invalidates
     * predecessors unconditionally.
     */
    public function isUnresolved(): bool
    {
        return $this->consumed_at === null && $this->invalidated_at === null;
    }

    /** Whether this challenge can still be verified right now. */
    public function isUsable(int $maxAttempts): bool
    {
        return $this->isUnresolved()
            && $this->expires_at->isFuture()
            && $this->attempts < $maxAttempts;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'expires_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'consumed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }
}
