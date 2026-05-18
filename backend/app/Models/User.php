<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'role',
        'is_active',
        'last_login_at',
        'password',
        'password_plain',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_plain',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdminProgrammer(): bool
    {
        return $this->role === 'admin_programmer';
    }

    public function isStoreAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'admin_programmer'], true);
    }

    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }

    public function canAccessDashboard(): bool
    {
        return $this->isAdmin();
    }

    public function canManageUsers(): bool
    {
        return $this->isAdminProgrammer();
    }

    public function canViewActivityLogs(): bool
    {
        return $this->isAdminProgrammer();
    }

    public function canAccessReports(): bool
    {
        return $this->isAdmin();
    }

    public function canManageCatalog(): bool
    {
        return $this->isAdmin();
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'cashier_id');
    }

    public function toApiArray(bool $includePasswordPlain = false): array
    {
        $data = $this->only([
            'id',
            'name',
            'email',
            'role',
            'is_active',
            'last_login_at',
            'created_at',
            'updated_at',
        ]);

        if ($includePasswordPlain) {
            $data['password_plain'] = $this->password_plain;
        }

        return $data;
    }
}
