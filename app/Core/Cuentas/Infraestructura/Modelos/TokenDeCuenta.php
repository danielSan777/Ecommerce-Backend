<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Infraestructura\Modelos;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'token_hash', 'expires_at'])]
final class TokenDeCuenta extends Model
{
    public const CONFIRMAR_CORREO = 'verify_email';

    public const RESTABLECER_CLAVE = 'reset_password';

    public const UPDATED_AT = null;

    protected $table = 'account_tokens';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
