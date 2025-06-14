<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Shopware\Core\Defaults;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
abstract class MigrationStep
{
    use AddColumnTrait;

    final public const INSTALL_ENVIRONMENT_VARIABLE = 'SHOPWARE_INSTALL';

    private const MAX_INT_32_BIT = 2147483647;

    /**
     * get creation timestamp
     */
    abstract public function getCreationTimestamp(): int;

    /**
     * update non-destructive changes
     */
    abstract public function update(Connection $connection): void;

    /**
     * update destructive changes
     */
    public function updateDestructive(Connection $connection): void
    {
    }

    public function getPlausibleCreationTimestamp(): int
    {
        $creationTime = $this->getCreationTimestamp();

        if ($creationTime < 1 || $creationTime >= self::MAX_INT_32_BIT) {
            if (Feature::isActive('v6.7.0.0')) {
                throw MigrationException::implausibleCreationTimestamp($creationTime, $this);
            }

            Feature::triggerDeprecationOrThrow(
                'v6.7.0.0',
                \sprintf(
                    'The method "%s::getCreationTimestamp" returned a timestamp of "%d". This method should return a timestamp between 1 and 2147483647 to ensure migration order is deterministic on every system.',
                    static::class,
                    $creationTime
                ),
            );
        }

        return $creationTime;
    }

    public function removeTrigger(Connection $connection, string $name): void
    {
        try {
            $connection->executeStatement(\sprintf('DROP TRIGGER IF EXISTS %s', $name));
        } catch (Exception) {
        }
    }

    public function isInstallation(): bool
    {
        return (bool) EnvironmentHelper::getVariable(self::INSTALL_ENVIRONMENT_VARIABLE, false);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function createTrigger(Connection $connection, string $query, array $params = []): void
    {
        $blueGreenDeployment = EnvironmentHelper::getVariable('BLUE_GREEN_DEPLOYMENT', false);
        if ((int) $blueGreenDeployment === 0) {
            return;
        }

        $connection->executeStatement($query, $params);
    }

    /**
     * @param list<string> $indexerToRun
     */
    protected function registerIndexer(Connection $connection, string $name, array $indexerToRun = []): void
    {
        IndexerQueuer::registerIndexer($connection, $name, $indexerToRun);
    }

    protected function indexExists(Connection $connection, string $table, string $index): bool
    {
        $exists = $connection->fetchOne(
            'SHOW INDEXES FROM `' . $table . '` WHERE `key_name` LIKE :index',
            ['index' => $index]
        );

        return !empty($exists);
    }

    protected function dropTableIfExists(Connection $connection, string $table): void
    {
        $sql = \sprintf('DROP TABLE IF EXISTS `%s`', $table);
        $connection->executeStatement($sql);
    }

    /**
     * @deprecated tag:v6.7.0 - reason:parameter-name-change - Parameter `column` will be renamed to `columnName`
     *
     * @return bool - Returns true when the column has really been deleted
     */
    protected function dropColumnIfExists(Connection $connection, string $table, string $column): bool
    {
        try {
            $connection->executeStatement(\sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
        } catch (\Throwable $e) {
            if ($e instanceof TableNotFoundException) {
                return false;
            }

            // column does not exist
            if (str_contains($e->getMessage(), 'SQLSTATE[42000]')) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * @deprecated tag:v6.7.0 - reason:parameter-name-change - Parameter `column` will be renamed to `foreignKeyName`
     *
     * @return bool - Returns true when the foreign key has really been deleted
     */
    protected function dropForeignKeyIfExists(Connection $connection, string $table, string $column): bool
    {
        $sql = \sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $column);

        try {
            $connection->executeStatement($sql);
        } catch (\Throwable $e) {
            if ($e instanceof TableNotFoundException) {
                return false;
            }

            // fk does not exist
            if (str_contains($e->getMessage(), 'SQLSTATE[42000]')) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * @deprecated tag:v6.7.0 - reason:parameter-name-change - Parameter `index` will be renamed to `indexName`
     *
     * @return bool - Returns true when the index has really been deleted
     */
    protected function dropIndexIfExists(Connection $connection, string $table, string $index): bool
    {
        $sql = \sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index);

        try {
            $connection->executeStatement($sql);
        } catch (\Throwable $e) {
            if ($e instanceof TableNotFoundException) {
                return false;
            }

            // index does not exist
            if (str_contains($e->getMessage(), 'SQLSTATE[42000]')) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * @param array<string, array<string>> $privileges
     *
     * @throws ConnectionException
     * @throws Exception
     * @throws \JsonException
     */
    protected function addAdditionalPrivileges(Connection $connection, array $privileges): void
    {
        $roles = $connection->iterateAssociative('SELECT * from `acl_role`');

        try {
            $connection->beginTransaction();

            foreach ($roles as $role) {
                $currentPrivileges = \json_decode((string) $role['privileges'], true, 512, \JSON_THROW_ON_ERROR);
                $newPrivileges = $this->fixRolePrivileges($privileges, $currentPrivileges);

                if ($currentPrivileges === $newPrivileges) {
                    continue;
                }

                $role['privileges'] = \json_encode($newPrivileges, \JSON_THROW_ON_ERROR);
                $role['updated_at'] = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_FORMAT);

                $connection->update('acl_role', $role, ['id' => $role['id']]);
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param array<string, array<string>> $privilegeChange
     * @param array<string> $rolePrivileges
     *
     * @return array<string>
     */
    private function fixRolePrivileges(array $privilegeChange, array $rolePrivileges): array
    {
        $rolePrivilegesToBeAdded = [];
        foreach ($privilegeChange as $existingPrivilege => $newPrivileges) {
            if (\in_array($existingPrivilege, $rolePrivileges, true)) {
                $rolePrivilegesToBeAdded[] = $newPrivileges;
            }
        }
        $rolePrivileges = \array_merge($rolePrivileges, ...$rolePrivilegesToBeAdded);

        return \array_values(\array_unique($rolePrivileges));
    }
}
