<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\QuoteItem;
use App\Models\SaleItem;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Jeu de donnees de demonstration du catalogue produits.
 *
 * Le seeder possede le catalogue de demonstration qu'il cree (prefixe SKU
 * `PRD-`) : il le recharge integralement a chaque execution, de sorte que la
 * base reflete toujours exactement le meme jeu de test.
 */
class ProductSeeder extends Seeder
{
    /**
     * Prefixe des SKU du catalogue de demonstration.
     */
    private const DEMO_SKU_PREFIX = 'PRD-';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->removeDemoCatalog();

        $units = $this->seedUnits();
        $categories = $this->seedCategories();
        $products = $this->products();

        foreach ($products as $product) {
            Product::updateOrCreate(
                ['sku' => $product['sku']],
                [
                    'name' => $product['name'],
                    'category_id' => $categories[$product['category']]->id,
                    'unit_id' => $units[$product['unit']]->id,
                    'purchase_price' => $product['purchase_price'],
                    'sale_price' => $product['sale_price'],
                    'stock_quantity' => $product['stock_quantity'],
                    'min_stock_alert' => $product['min_stock_alert'],
                    'is_active' => true,
                ],
            );
        }

        $this->command?->info(sprintf(
            'Catalogue de demonstration charge : %d unites, %d categories, %d produits.',
            count($units),
            count($categories),
            count($products),
        ));
    }

    /**
     * Retire le catalogue de demonstration charge precedemment.
     *
     * Les produits de demonstration sont supprimes, ainsi que les categories
     * et les unites orphelines, c'est-a-dire celles qui ne servent plus aucun
     * produit. Les lignes de vente, d'achat ou de devis les referencent
     * bloquent la suppression : elles doivent etre purgees au prealable.
     */
    private function removeDemoCatalog(): void
    {
        $demoProducts = Product::where('sku', 'like', self::DEMO_SKU_PREFIX.'%')
            ->get(['id', 'category_id', 'unit_id']);

        if ($demoProducts->isEmpty()) {
            return;
        }

        $productIds = $demoProducts->pluck('id')->all();

        $references = SaleItem::whereIn('product_id', $productIds)->count()
            + PurchaseItem::whereIn('product_id', $productIds)->count()
            + QuoteItem::whereIn('product_id', $productIds)->count();

        if ($references > 0) {
            throw new RuntimeException(
                'Le catalogue de demonstration ne peut pas etre recharge : '
                .$references.' ligne(s) de vente, d\'achat ou de devis le referencent.'
            );
        }

        Product::whereIn('id', $productIds)->delete();

        // Les categories et unites devenues orphelines sont retirees : le
        // catalogue de demonstration doit reposer sur le seul jeu de donnees
        // declare plus bas, sans reference de testes obsolete.
        Category::whereDoesntHave('products')->delete();
        Unit::whereDoesntHave('products')->delete();
    }

    /**
     * Unites de mesure du catalogue, indexees par symbole.
     *
     * @return array<string, Unit>
     */
    private function seedUnits(): array
    {
        $units = [
            ['name' => 'Pièce', 'symbol' => 'pcs', 'description' => 'Article vendu à l’unité'],
            ['name' => 'Kilogramme', 'symbol' => 'kg', 'description' => 'Article vendu au poids'],
            ['name' => 'Mètre', 'symbol' => 'm', 'description' => 'Article vendu à la longueur'],
        ];

        $seeded = [];

        foreach ($units as $unit) {
            $seeded[$unit['symbol']] = Unit::updateOrCreate(
                ['symbol' => $unit['symbol']],
                [...$unit, 'is_active' => true],
            );
        }

        return $seeded;
    }

    /**
     * Categories du catalogue, indexees par nom.
     *
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $categories = [
            ['name' => 'Outillage', 'description' => 'Outils manuels et électroportables'],
            ['name' => 'Matériaux', 'description' => 'Matériaux de construction et quincaillerie'],
            ['name' => 'Peinture', 'description' => 'Peintures, enduits et accessoires'],
        ];

        $seeded = [];

        foreach ($categories as $category) {
            $seeded[$category['name']] = Category::updateOrCreate(
                ['name' => $category['name']],
                [...$category, 'is_active' => true],
            );
        }

        return $seeded;
    }

    /**
     * Catalogue de demonstration.
     *
     * La peinture blanche est volontairement stockee sous son seuil d'alerte :
     * elle met en evidence la mise en forme rouge du tableau des produits.
     *
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        return [
            [
                'sku' => 'PRD-0001',
                'name' => 'Marteau 500 g',
                'category' => 'Outillage',
                'unit' => 'pcs',
                'purchase_price' => 3500,
                'sale_price' => 5500,
                'stock_quantity' => 120,
                'min_stock_alert' => 20,
            ],
            [
                'sku' => 'PRD-0002',
                'name' => 'Ciment 50 kg',
                'category' => 'Matériaux',
                'unit' => 'kg',
                'purchase_price' => 5500,
                'sale_price' => 7000,
                'stock_quantity' => 500,
                'min_stock_alert' => 100,
            ],
            [
                'sku' => 'PRD-0003',
                'name' => 'Vis 5 cm',
                'category' => 'Matériaux',
                'unit' => 'pcs',
                'purchase_price' => 25,
                'sale_price' => 50,
                'stock_quantity' => 2000,
                'min_stock_alert' => 500,
            ],
            [
                'sku' => 'PRD-0004',
                'name' => 'Peinture blanche 5 L',
                'category' => 'Peinture',
                'unit' => 'pcs',
                'purchase_price' => 18000,
                'sale_price' => 25000,
                'stock_quantity' => 3,
                'min_stock_alert' => 5,
            ],
            [
                'sku' => 'PRD-0005',
                'name' => 'Fil de fer 2 mm',
                'category' => 'Matériaux',
                'unit' => 'm',
                'purchase_price' => 1200,
                'sale_price' => 1800,
                'stock_quantity' => 60,
                'min_stock_alert' => 10,
            ],
        ];
    }
}
