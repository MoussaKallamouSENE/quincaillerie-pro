<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function receptions()
    {
        return $this->hasMany(Reception::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function cashSessions()
    {
        return $this->hasMany(CashSession::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function cashMovements()
    {
        return $this->hasMany(CashMovement::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function refundsRequested(): HasMany
    {
        return $this->hasMany(Refund::class, 'requested_by_id');
    }

    public function refundsApproved(): HasMany
    {
        return $this->hasMany(Refund::class, 'approved_by_id');
    }

    public function refundsRejected(): HasMany
    {
        return $this->hasMany(Refund::class, 'rejected_by_id');
    }

    public function refundsExecuted(): HasMany
    {
        return $this->hasMany(Refund::class, 'executed_by_id');
    }

    public function refundsCancelled(): HasMany
    {
        return $this->hasMany(Refund::class, 'cancelled_by_id');
    }

    public function refundsReconciled(): HasMany
    {
        return $this->hasMany(Refund::class, 'reconciled_by_id');
    }

    public function refundDocumentsUploaded(): HasMany
    {
        return $this->hasMany(RefundDocument::class, 'uploaded_by_id');
    }
}
