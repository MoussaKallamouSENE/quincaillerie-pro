<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_OWNER = 'created_by_migration_2026_11_13_165657';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertForeignKey('sales', 'sales_user_id_foreign', ['user_id']);
        $this->assertForeignKey('payments', 'payments_sale_id_foreign', ['sale_id']);

        $this->ensureSupportIndex('sales', 'sales_user_id_fk_index', ['user_id']);
        $this->ensureSupportIndex('payments', 'payments_sale_id_fk_index', ['sale_id']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertForeignKey('sales', 'sales_user_id_foreign', ['user_id']);
        $this->assertForeignKey('payments', 'payments_sale_id_foreign', ['sale_id']);

        $this->assertSupportIndexDroppable('sales', 'sales_user_id_fk_index', ['user_id'], 'sales_user_id_foreign');
        $this->assertSupportIndexDroppable('payments', 'payments_sale_id_fk_index', ['sale_id'], 'payments_sale_id_foreign');

        $this->dropSupportIndex('sales', 'sales_user_id_fk_index', ['user_id'], 'sales_user_id_foreign');
        $this->dropSupportIndex('payments', 'payments_sale_id_fk_index', ['sale_id'], 'payments_sale_id_foreign');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertForeignKey(string $table, string $name, array $columns): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (
                $foreignKey['columns'] === $columns
                && ($foreignKey['name'] === null || $foreignKey['name'] === $name)
            ) {
                return;
            }
        }

        throw new RuntimeException(
            "Foreign key [{$name}] on [{$table}] is missing or does not cover [".implode(', ', $columns).'].'
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function ensureSupportIndex(string $table, string $name, array $columns): void
    {
        $indexes = Schema::getIndexes($table);
        $namedIndex = $this->findIndex($indexes, $name);

        if ($namedIndex !== null) {
            if ($namedIndex['unique'] || ! $this->indexCoversColumns($namedIndex, $columns)) {
                throw new RuntimeException(
                    "Index [{$name}] on [{$table}] exists but is not a non-unique support index for [".implode(', ', $columns).'].'
                );
            }

            return;
        }

        foreach ($indexes as $index) {
            if (! $index['unique'] && $this->indexCoversColumns($index, $columns)) {
                return;
            }
        }

        $this->addSupportIndex($table, $name, $columns);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addSupportIndex(string $table, string $name, array $columns): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        $tableName = $connection->getTablePrefix().$table;

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement(sprintf(
                'ALTER TABLE %s ADD INDEX %s (%s) COMMENT %s',
                $this->quoteMySqlIdentifier($tableName),
                $this->quoteMySqlIdentifier($name),
                implode(', ', array_map(fn (string $column): string => $this->quoteMySqlIdentifier($column), $columns)),
                "'".self::INDEX_OWNER."'",
            ));

            return;
        }

        if ($driver === 'sqlite') {
            $connection->statement(sprintf(
                'CREATE INDEX %s ON %s (%s) /* %s */',
                $this->quoteSqliteIdentifier($name),
                $this->quoteSqliteIdentifier($tableName),
                implode(', ', array_map(fn (string $column): string => $this->quoteSqliteIdentifier($column), $columns)),
                self::INDEX_OWNER,
            ));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
            $blueprint->index($columns, $name);
        });
    }

    private function isOwnedIndex(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $connection->scalar(
                'select index_comment from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
                [$connection->getTablePrefix().$table, $name],
                false,
            ) === self::INDEX_OWNER;
        }

        if ($driver === 'sqlite') {
            $sql = $connection->scalar(
                "select sql from sqlite_master where type = 'index' and name = ?",
                [$name],
                false,
            );

            return is_string($sql) && str_contains($sql, self::INDEX_OWNER);
        }

        return false;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertSupportIndexDroppable(string $table, string $name, array $columns, string $foreignKey): void
    {
        $indexes = Schema::getIndexes($table);
        $namedIndex = $this->findIndex($indexes, $name);

        if ($namedIndex === null || ! $this->isOwnedIndex($table, $name)) {
            return;
        }

        if ($namedIndex['unique'] || ! $this->indexCoversColumns($namedIndex, $columns)) {
            throw new RuntimeException(
                "Index [{$name}] on [{$table}] is not a valid support index and will not be dropped."
            );
        }

        foreach ($indexes as $index) {
            if ($index['name'] !== $name && $this->indexCoversColumns($index, $columns)) {
                return;
            }
        }

        throw new RuntimeException(
            "Cannot safely drop [{$name}] on [{$table}]: no remaining index supports foreign key [{$foreignKey}]."
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropSupportIndex(string $table, string $name, array $columns, string $foreignKey): void
    {
        if ($this->findIndex(Schema::getIndexes($table), $name) === null || ! $this->isOwnedIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });

        $this->assertForeignKey($table, $foreignKey, $columns);
    }

    /**
     * @param  array<int, array{name: string, columns: array<int, string>, type: string, unique: bool, primary: bool}>  $indexes
     * @return array{name: string, columns: array<int, string>, type: string, unique: bool, primary: bool}|null
     */
    private function findIndex(array $indexes, string $name): ?array
    {
        foreach ($indexes as $index) {
            if ($index['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, columns: array<int, string>, type: string, unique: bool, primary: bool}  $index
     * @param  array<int, string>  $columns
     */
    private function indexCoversColumns(array $index, array $columns): bool
    {
        return array_slice($index['columns'], 0, count($columns)) === $columns;
    }

    private function quoteMySqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
