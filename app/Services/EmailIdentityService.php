<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Connection;
use RuntimeException;

class EmailIdentityService
{
    public function inspect(): array
    {
        return $this->inspectRows(User::select(['id', 'email'])->orderBy('id')->cursor());
    }

    private function inspectRows(iterable $users): array
    {
        $seen = [];
        $changed = 0;
        $conflicts = [];
        foreach ($users as $user) {
            $normalized = User::normalizeEmail($user->email);
            if ($normalized !== $user->email) $changed++;
            if (isset($seen[$normalized])) {
                // Report IDs only; no email addresses or password data in upgrade output.
                if (count($conflicts) < 20) $conflicts[] = [$seen[$normalized], $user->id];
            } else {
                $seen[$normalized] = $user->id;
            }
        }
        return ['changed' => $changed, 'conflicts' => $conflicts];
    }

    public function apply(): int
    {
        $db = (new User())->getConnection();
        if (!in_array($db->getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('邮箱数据升级仅支持 MySQL/MariaDB。');
        }
        $changed = $db->transaction(function () use ($db) {
            // Lock before checking, then recheck on every run; never merge identities.
            $report = $this->inspectRows(User::select(['id', 'email'])->orderBy('id')->lockForUpdate()->cursor());
            if ($report['conflicts']) {
                throw new RuntimeException('邮箱规范化存在冲突，用户 ID：' . json_encode($report['conflicts']));
            }
            foreach (User::select(['id', 'email'])->orderBy('id')->cursor() as $user) {
                $email = User::normalizeEmail($user->email);
                if ($email !== $user->email) {
                    // Do not touch passwords, tokens, sessions or user timestamps.
                    $db->table('v2_user')->where('id', $user->id)->update(['email' => $email]);
                }
            }
            return $report['changed'];
        });
        // MySQL DDL commits implicitly: run it after the normalization transaction.
        // On failure the command stays failed and can be safely rerun.
        if ($db->getDriverName() === 'mysql' && !$this->hasBinaryEmailColumn($db)) {
            $table = $db->getQueryGrammar()->wrapTable('v2_user');
            $db->statement("ALTER TABLE {$table} MODIFY COLUMN `email` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
        }
        return $changed;
    }

    private function hasBinaryEmailColumn(Connection $db): bool
    {
        $column = $db->selectOne(
            'SELECT COLLATION_NAME AS collation_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db->getDatabaseName(), $db->getTablePrefix() . 'v2_user', 'email']
        );
        return $column && $column->collation_name === 'utf8mb4_bin';
    }
}
