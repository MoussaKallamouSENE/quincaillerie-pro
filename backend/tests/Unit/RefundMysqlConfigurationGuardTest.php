<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Feature\RefundConcurrencyMysqlTest;

/**
 * Controle pur PHPUnit, sans boot Laravel ni migration : toute configuration
 * MySQL qui n'est pas explicitement la base jetable autorisee est refusee.
 */
class RefundMysqlConfigurationGuardTest extends TestCase
{
    public function test_authorized_disposable_configuration_is_accepted(): void
    {
        RefundConcurrencyMysqlTest::assertAuthorizedConfiguration(self::authorizedConfiguration());

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: array<string, string|null>}>
     */
    public static function refusedConfigurations(): array
    {
        $safe = self::authorizedConfiguration();

        return [
            'base de production' => [array_merge($safe, ['DB_DATABASE' => 'quincaillerie_db'])],
            'nom contenant test mais different' => [array_merge($safe, ['DB_DATABASE' => 'quincaillerie_refund_concurrency_test_v2'])],
            'base absente' => [array_merge($safe, ['DB_DATABASE' => null])],
            'host localhost' => [array_merge($safe, ['DB_HOST' => 'localhost'])],
            'host distant' => [array_merge($safe, ['DB_HOST' => '192.168.1.10'])],
            'host absent' => [array_merge($safe, ['DB_HOST' => null])],
            'port standard' => [array_merge($safe, ['DB_PORT' => '3306'])],
            'port voisin' => [array_merge($safe, ['DB_PORT' => '33306'])],
            'port absent' => [array_merge($safe, ['DB_PORT' => null])],
            'driver sqlite' => [array_merge($safe, ['DB_CONNECTION' => 'sqlite'])],
            'driver absent' => [array_merge($safe, ['DB_CONNECTION' => null])],
            'flag absent' => [array_merge($safe, ['REFUND_MYSQL_CONCURRENCY' => null])],
            'flag incorrect' => [array_merge($safe, ['REFUND_MYSQL_CONCURRENCY' => '0'])],
            'url de contournement' => [array_merge($safe, ['DB_URL' => 'mysql://refund_test:secret@127.0.0.1:33307/quincaillerie_refund_concurrency_test'])],
            'socket de contournement' => [array_merge($safe, ['DB_SOCKET' => '/var/run/mysqld/mysqld.sock'])],
        ];
    }

    /**
     * @param  array<string, string|null>  $configuration
     */
    #[DataProvider('refusedConfigurations')]
    public function test_unknown_mysql_configuration_is_refused(array $configuration): void
    {
        $this->expectException(RuntimeException::class);

        RefundConcurrencyMysqlTest::assertAuthorizedConfiguration($configuration);
    }

    /**
     * @return array<string, string|null>
     */
    private static function authorizedConfiguration(): array
    {
        return [
            'REFUND_MYSQL_CONCURRENCY' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '33307',
            'DB_DATABASE' => 'quincaillerie_refund_concurrency_test',
            'DB_URL' => '',
            'DB_SOCKET' => '',
        ];
    }
}
