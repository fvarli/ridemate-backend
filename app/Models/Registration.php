<?php

declare(strict_types=1);

namespace App\Models;

use App\Otp\OtpChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One registration in progress, and the proofs it has accumulated so far.
 *
 * The plaintext credential is NOT on this model and never will be. It exists
 * in a local variable during minting, travels to the caller, and is gone — only
 * `credential_hash` reaches the database.
 *
 * Nothing here decides anything. Whether a registration may still be advanced
 * is `isAdvanceable()`, and whether it has earned an account is
 * `isFullyProven()`. What is done about either answer belongs to
 * `App\Registration\CompleteRegistration`, which owns the transaction.
 *
 * `completed_at` AND `account_id` ANSWER TWO DIFFERENT QUESTIONS
 *
 * The first says completion happened, and is what makes the credential
 * non-advanceable. The second says WHICH account this registration produced,
 * which nothing else in the schema records — it is durable provenance,
 * deliberately not reconstructed by matching `email` or `phone_e164` against
 * `accounts`. That match is an inference, sound only while identifiers are
 * immutable and never reused, and it answers with the wrong account rather than
 * with nothing once either assumption fails. The two columns are written
 * together, in one statement, and a database CHECK refuses a row holding one
 * without the other.
 *
 * @property string $id
 * @property string $credential_hash
 * @property string|null $email
 * @property string|null $phone_e164
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $phone_verified_at
 * @property CarbonImmutable $expires_at
 * @property string|null $account_id
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 */
class Registration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Deliberately narrow, as on every model here.
     *
     * `credential_hash` in particular: a mass-assignable one would let a
     * careless controller accept the hash a caller claims and hand them
     * somebody else's registration.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Whether this registration may still be advanced at all.
     *
     * Expiry and completion are two different endings and both are final, so
     * they collapse into one answer here. A caller that could tell them apart
     * would learn whether a credential it does not hold was ever finished.
     */
    public function isAdvanceable(): bool
    {
        return $this->completed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Whether both possessions have been proven for THIS registration.
     *
     * The mature account-creation invariant in one place, so that the action
     * which creates the account reads it rather than restating it. It says
     * nothing about whether the registration may still be advanced, which is
     * the separate question `isAdvanceable()` answers — completion needs both.
     */
    public function isFullyProven(): bool
    {
        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }

    /**
     * The destination this registration will prove on a channel, if one is
     * bound yet.
     *
     * The ONLY source of a destination for verification. A caller that could
     * supply one would be able to verify a code sent to an address it owns and
     * attach the proof to a registration naming somebody else's — which is the
     * single thing the pre-account boundary exists to prevent. The channel is
     * the discriminator here for the same reason it is on `otp_challenges`:
     * one column per kind, read through the value that says which kind.
     */
    public function destinationOn(OtpChannel $channel): ?string
    {
        return match ($channel) {
            OtpChannel::Sms => $this->phone_e164,
            OtpChannel::Email => $this->email,
        };
    }

    /** Whether possession of this channel's destination has already been proven. */
    public function provenOn(OtpChannel $channel): bool
    {
        return $this->verifiedAtOn($channel) !== null;
    }

    public function verifiedAtOn(OtpChannel $channel): ?CarbonImmutable
    {
        return match ($channel) {
            OtpChannel::Sms => $this->phone_verified_at,
            OtpChannel::Email => $this->email_verified_at,
        };
    }

    /** The column a proof on this channel is written to. */
    public function proofColumnOn(OtpChannel $channel): string
    {
        return match ($channel) {
            OtpChannel::Sms => 'phone_verified_at',
            OtpChannel::Email => 'email_verified_at',
        };
    }

    /** The column this channel's destination is bound to. */
    public function destinationColumnOn(OtpChannel $channel): string
    {
        return match ($channel) {
            OtpChannel::Sms => 'phone_e164',
            OtpChannel::Email => 'email',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
