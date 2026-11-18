<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER = 'payments_cash_session_guard_insert';

    private const UPDATE_TRIGGER = 'payments_cash_session_guard_update';

    public function up(): void
    {
        if (! $this->supportsTriggers()) {
            return;
        }

        $table = $this->qualifiedTable('payments');

        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $this->quote(self::INSERT_TRIGGER)));
        DB::unprepared(sprintf(
            "CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW BEGIN IF NEW.method = 'cash' AND NEW.cash_session_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_session_id is required for cash payments'; END IF; END",
            $this->quote(self::INSERT_TRIGGER),
            $table,
        ));

        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $this->quote(self::UPDATE_TRIGGER)));
        DB::unprepared(sprintf(
            "CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW BEGIN IF NEW.method = 'cash' AND NEW.cash_session_id IS NULL AND NOT (OLD.method = 'cash' AND OLD.cash_session_id IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_session_id cannot be cleared for cash payments'; END IF; END",
            $this->quote(self::UPDATE_TRIGGER),
            $table,
        ));
    }

    public function down(): void
    {
        if (! $this->supportsTriggers()) {
            return;
        }

        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $this->quote(self::INSERT_TRIGGER)));
        DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $this->quote(self::UPDATE_TRIGGER)));
    }

    private function supportsTriggers(): bool
    {
        return in_array(
            Schema::getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        );
    }

    private function qualifiedTable(string $table): string
    {
        $connection = Schema::getConnection();

        return $this->quote($connection->getTablePrefix().$table);
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};
