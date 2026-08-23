<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credential generation. Written once, then closed by rotation.
 *
 * Rows are never deleted during a session's life and never have their hashes
 * overwritten, because the history IS the reuse detector. See the migration.
 *
 * @property string $id
 * @property string $session_id
 * @property int $generation
 * @property string $access_token_hash
 * @property CarbonImmutable $access_expires_at
 * @property string $refresh_token_hash
 * @property CarbonImmutable $refresh_expires_at
 * @property CarbonImmutable|null $rotated_at
 * @property string|null $succeeded_by_id
 * @property CarbonImmutable $created_at
 * @property-read AuthSession $session
 */
class AuthToken extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<AuthSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class, 'session_id');
    }

    /**
     * Whether this generation's refresh token has already been exchanged.
     *
     * Presenting a generation for which this is true is the reuse signal.
     */
    public function isRotated(): bool
    {
        return $this->rotated_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'access_expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
        ];
    }
}
