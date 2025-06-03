<?php declare(strict_types=1);

namespace HomsymImportCSVSpeccode\Controller;

use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\DBAL\Connection;

class ProductCategoryController extends AbstractController
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    #[Route(
        path: '/api/homsymimportcsvspecode/setspeccode',
        name: 'api.homsymimportcsvspecode.setspeccode',
        defaults: ['_routeScope' => ['api']],
        methods: ['POST']
    )]
    public function setSpecCode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Expected an array of items'], 400);
        }

        $failedItems = [];
        $insertData = [];

        // 1. Sammle Produktnummern und Kategorienamen
        $productNumbers = array_unique(array_column($data, 'productNumber'));
        $categoryNames = array_unique(array_column($data, 'categoryName'));

        // 2. Hole alle Produkte und Kategorien in Bulk
        $products = $this->connection->fetchAllAssociative(
            'SELECT product_number, LOWER(HEX(id)) AS id, LOWER(HEX(version_id)) AS version_id 
             FROM product 
             WHERE product_number IN (:numbers)',
            ['numbers' => $productNumbers],
            ['numbers' => Connection::PARAM_STR_ARRAY]
        );

        $categories = $this->connection->fetchAllAssociative(
            'SELECT ct.name AS name, LOWER(HEX(c.id)) AS id, LOWER(HEX(c.version_id)) AS version_id 
             FROM category c 
             JOIN category_translation ct ON ct.category_id = c.id 
             WHERE ct.name IN (:names)',
            ['names' => $categoryNames],
            ['names' => Connection::PARAM_STR_ARRAY]
        );

        // 3. Erstelle Maps zur schnellen Zuordnung
        $productMap = [];
        foreach ($products as $p) {
            $productMap[$p['product_number']] = ['id' => $p['id'], 'version_id' => $p['version_id']];
        }

        $categoryMap = [];
        foreach ($categories as $c) {
            $categoryMap[$c['name']] = ['id' => $c['id'], 'version_id' => $c['version_id']];
        }

        // 4. Schleife durch die Items
        foreach ($data as $item) {
            if (!isset($item['productNumber'], $item['categoryName'], $item['specCode'])) {
                $failedItems[] = ['error' => 'Missing fields', 'item' => $item];
                continue;
            }

            $product = $productMap[$item['productNumber']] ?? null;
            $category = $categoryMap[$item['categoryName']] ?? null;

            if (!$product || !$category) {
                $failedItems[] = [
                    'productNumber' => $item['productNumber'],
                    'categoryName' => $item['categoryName'],
                    'error' => !$product ? 'Product not found' : 'Category not found',
                ];
                continue;
            }

            $insertData[] = [
                'product_id' => $product['id'],
                'product_version_id' => $product['version_id'],
                'category_id' => $category['id'],
                'category_version_id' => $category['version_id'],
                'spec_code' => $item['specCode'],
            ];
        }

        // 5. Einfügen in Batches
        try {
            $this->connection->beginTransaction();

            $chunks = array_chunk($insertData, 100); // max. 100 Einträge pro Batch

            foreach ($chunks as $chunkIndex => $chunk) {
                $valuesSql = [];
                $params = [];

                foreach ($chunk as $index => $row) {
                    $i = $chunkIndex * 100 + $index;
                    $valuesSql[] = "(UNHEX(:product_id_$i), UNHEX(:product_version_id_$i), UNHEX(:category_id_$i), UNHEX(:category_version_id_$i), :spec_code_$i)";
                    $params["product_id_$i"] = $row['product_id'];
                    $params["product_version_id_$i"] = $row['product_version_id'];
                    $params["category_id_$i"] = $row['category_id'];
                    $params["category_version_id_$i"] = $row['category_version_id'];
                    $params["spec_code_$i"] = $row['spec_code'];
                }

                $sql = "
                    INSERT INTO product_category (product_id, product_version_id, category_id, category_version_id, spec_code)
                    VALUES " . implode(", ", $valuesSql) . "
                    ON DUPLICATE KEY UPDATE spec_code = VALUES(spec_code)
                ";

                $this->connection->executeStatement($sql, $params);
            }

            $this->connection->commit();

            return new JsonResponse([
                'success' => true,
                'importedCount' => count($insertData),
                'failedCount' => count($failedItems),
                'failedItems' => $failedItems,
            ]);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            return new JsonResponse([
                'success' => false,
                'error' => 'Database error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
