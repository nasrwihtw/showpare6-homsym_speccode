<?php declare(strict_types=1);

namespace HomsymImportCSVSpeccode\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration202505280000AddSpecCodeToProductCategory extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 202505150000;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        // Moderne Methode: createSchemaManager()
        $schemaManager = $connection->createSchemaManager();

        // Prüfen, ob die Spalte bereits existiert
        $columns = $schemaManager->listTableColumns('product_category');

        if (!array_key_exists('spec_code', $columns)) {
            $connection->executeStatement("
                ALTER TABLE `product_category`
                ADD COLUMN `spec_code` VARCHAR(255) NULL AFTER `category_version_id`;
            ");
        }

        // Index prüfen und ggf. hinzufügen
        $indexes = $schemaManager->listTableIndexes('product_category');
        if (!array_key_exists('idx_product_category', $indexes)) {
            $connection->executeStatement("
            ALTER TABLE `product_category`
            ADD UNIQUE INDEX `idx_product_category` (`product_id`, `category_id`);
        ");
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // keine destruktiven Änderungen in dieser Migration
    }
}
