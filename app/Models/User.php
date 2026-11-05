<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = [
        'traffic_reset_anchor_at', 'traffic_reset_offset', 'traffic_reset_at',
        'traffic_reset_plan_id', 'traffic_reset_method',
    ];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    // ASCII identities only: do not fold accents or rewrite dots, plus tags or domains.
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function setEmailAttribute(string $email): void
    {
        $this->attributes['email'] = self::normalizeEmail($email);
    }

    public function scopeByEmail(Builder $query, string $email): Builder
    {
        $column = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('email'));
        $expression = "LOWER(TRIM({$column}))";
        // The connection's default collation may consider e and é equal.
        $comparison = match ($this->getConnection()->getDriverName()) {
            'mysql' => "CAST({$expression} AS BINARY) = CAST(? AS BINARY)",
            'pgsql' => "{$expression} COLLATE \"C\" = ?",
            'sqlsrv' => "{$expression} COLLATE Latin1_General_100_BIN2 = ?",
            default => "{$expression} COLLATE BINARY = ?",
        };
        return $query->whereRaw($comparison, [self::normalizeEmail($email)]);
    }

    public static function findByEmail(string $email): ?self
    {
        $email = self::normalizeEmail($email);
        // Keep the indexed lookup for normalized accounts. The fallback supports
        // historical case/space variants until the guarded data upgrade runs.
        $user = static::where('email', $email)->first();
        if ($user && self::normalizeEmail($user->email) === $email) return $user;
        return static::byEmail($email)->get()->first(
            static fn (self $candidate) => self::normalizeEmail($candidate->email) === $email
        );
    }
}
