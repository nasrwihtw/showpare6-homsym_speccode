<?php declare(strict_types=1);

namespace HomsymImportCSVSpeccode\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Uuid\Uuid;

class ProductSpecCodeSubscriber implements EventSubscriberInterface
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingResultEvent::class => 'onProductListingLoaded',
        ];
    }

    public function onProductListingLoaded(ProductListingResultEvent $event): void
    {
        // Hole Kategorie-ID aus dem Request
        $categoryId = $event->getRequest()->get('navigationId');
        if (!$categoryId) {
            return;
        }

        $categoryIdBin = Uuid::fromHexToBytes($categoryId);

        $products = $event->getResult()->getEntities();
        $productIds = [];

        foreach ($products as $product) {
            $productIds[] = Uuid::fromHexToBytes($product->getId());
        }

        if (empty($productIds)) {
            return;
        }

        // Query nur für die aktuelle Kategorie
        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_id, spec_code 
             FROM product_category 
             WHERE product_id IN (:ids) AND category_id = :catId',
            [
                'ids' => $productIds,
                'catId' => $categoryIdBin,
            ],
            [
                'ids' => ArrayParameterType::STRING,
                'catId' => ParameterType::BINARY,
            ]
        );

        $codesByProduct = [];
        foreach ($rows as $row) {
            $codesByProduct[$row['product_id']][] = $row['spec_code'];
        }

        foreach ($products as $product) {
            $productIdBin = Uuid::fromHexToBytes($product->getId());
            if (isset($codesByProduct[$productIdBin])) {
                $product->addExtension('spec_code', new ArrayEntity([
                    'values' => $codesByProduct[$productIdBin],
                ]));
            }
        }
    }
}
