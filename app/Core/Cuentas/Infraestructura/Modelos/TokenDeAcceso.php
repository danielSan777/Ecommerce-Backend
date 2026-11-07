<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Infraestructura\Modelos;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'token_hash', 'device', 'last_used_at', 'expires_at'])]
final class TokenDeAcceso extends Model
{
    protected $table = 'api_tokens';

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
