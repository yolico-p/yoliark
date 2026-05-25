<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;
use App\Core\Config;

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = [
        'username', 'password_hash', 'email', 'avatar', 'role',
        'storage_limit', 'storage_used', 'encryption_key',
        'created_at', 'updated_at', 'last_login',
    ];
    protected static array $guarded = ['id'];

    // ========================================================================
    //  业务方法
    // ========================================================================

    public static function findByUsername(string $username): ?self
    {
        return self::where('username = ?', [$username])->first();
    }

    public static function findByEmail(string $email): ?self
    {
        return self::where('email = ?', [$email])->first();
    }

    public function verifyPassword(string $password): bool
    {
        return Security::verifyPassword($password, $this->attributes['password_hash'] ?? '');
    }

    public function setPassword(string $password): static
    {
        $this->attributes['password_hash'] = Security::hashPassword($password);
        return $this;
    }

    public function isAdmin(): bool
    {
        return ($this->attributes['role'] ?? '') === 'admin';
    }

    public function hasRole(string $role): bool
    {
        return ($this->attributes['role'] ?? '') === $role;
    }

    public function getRemainingStorage(): int
    {
        return max(0, ($this->attributes['storage_limit'] ?? 0) - ($this->attributes['storage_used'] ?? 0));
    }

    public function hasStorageFor(int $bytes): bool
    {
        return ($this->attributes['storage_used'] ?? 0) + $bytes <= ($this->attributes['storage_limit'] ?? 0);
    }

    public function addStorage(int $bytes): void
    {
        $this->attributes['storage_used'] = ($this->attributes['storage_used'] ?? 0) + $bytes;
    }

    public function removeStorage(int $bytes): void
    {
        $this->attributes['storage_used'] = max(0, ($this->attributes['storage_used'] ?? 0) - $bytes);
    }

    /**
     * 从键名换取完整用户后执行 update，避免 TOCTOU 窗口。
     */
    public static function updateStorageUsedRaw(int $userId, int $bytes, bool $increase = true): void
    {
        $op = $increase ? '+' : '-';
        static::db()->query(
            "UPDATE users SET storage_used = GREATEST(0, storage_used {$op} ?), updated_at = ? WHERE id = ?",
            [$bytes, time(), $userId]
        );
        static::db()->invalidateTableCache('users');
    }
}
