<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo catalog for testing the product service.
 *
 *   php artisan db:seed --class=CatalogDemoSeeder
 *
 * Products are created through ProductService::create(), the same code the admin API uses,
 * so seeding also tests it. Safe to run twice (existing rows are reused / skipped).
 *
 * Creates:
 *   Electronics > Smartphones : Nova X1 (6 variants, one combination not offered),
 *                               Orbit Pro 5 (12 variants)      -> test brand filter inside a category
 *   Clothing > T-Shirts       : Classic Tee, NO brand (8 variants) -> test optional brand
 */
class CatalogDemoSeeder extends Seeder
{
    /** Stock values handed out in turn; the second variant of every product is out of stock (0). */
    private const STOCKS = [12, 0, 7, 20, 5, 9, 14, 3];

    /** @var array<string, array<string, int>> attribute name => [value name => value id] */
    private array $values = [];

    /** @var array<string, int> attribute name => attribute id */
    private array $attributeIds = [];

    private int $order = 0;

    public function run(): void
    {
        // Categories (a tree, to test "Electronics" also showing Smartphones)
        $electronics = $this->category('Electronics');
        $phones = $this->category('Smartphones', $electronics->id);
        $clothing = $this->category('Clothing');
        $tshirts = $this->category('T-Shirts', $clothing->id);

        // Attributes, their predefined values, and which categories can use them.
        // Call order = display order inside a category.
        $this->attribute('RAM', ['8GB', '16GB'], [$phones]);
        $this->attribute('Storage', ['128GB', '256GB', '512GB', '1TB'], [$phones]);
        $this->attribute('Size', ['S', 'M', 'L', 'XL'], [$tshirts]);
        $this->attribute('Color', ['Space Grey', 'Ocean Blue', 'Midnight Green', 'Black', 'White'], [$phones, $tshirts]);

        $this->product([
            'name' => 'Nova X1',
            'code' => 'NX1',
            'category' => $phones,
            'brand' => 'Nova',
            'description' => 'Everyday smartphone with a 6.5" display and two-day battery.',
            'options' => [
                'RAM' => ['8GB', '16GB'],
                'Storage' => ['128GB', '512GB'],
                'Color' => ['Space Grey', 'Ocean Blue'],
            ],
            'base_price' => 600,
            'extras' => ['16GB' => 100, '512GB' => 120],
            // 16GB + 128GB is not sold: tests that admins can drop combinations
            'skip' => fn (array $c) => $c['RAM'] === '16GB' && $c['Storage'] === '128GB',
            'general_images' => 1,
            'value_images' => [['Color', 'Space Grey', 2], ['Color', 'Ocean Blue', 2]],
        ]);

        $this->product([
            'name' => 'Orbit Pro 5',
            'code' => 'OP5',
            'category' => $phones,
            'brand' => 'Orbit',
            'description' => 'Flagship phone with a pro camera system.',
            'options' => [
                'RAM' => ['8GB', '16GB'],
                'Storage' => ['256GB', '512GB', '1TB'],
                'Color' => ['Space Grey', 'Midnight Green'],
            ],
            'base_price' => 850,
            'extras' => ['16GB' => 150, '512GB' => 100, '1TB' => 250],
            'general_images' => 2,
            'value_images' => [['Color', 'Space Grey', 2], ['Color', 'Midnight Green', 2]],
        ]);

        $this->product([
            'name' => 'Classic Tee',
            'code' => 'CT',
            'category' => $tshirts,
            'brand' => null,   // brand is optional
            'description' => '100% cotton t-shirt.',
            'options' => [
                'Size' => ['S', 'M', 'L', 'XL'],
                'Color' => ['Black', 'White'],
            ],
            'base_price' => 20,
            'extras' => ['XL' => 2],
            'general_images' => 1,
            'value_images' => [['Color', 'Black', 2], ['Color', 'White', 2]],
        ]);

        $this->command?->info('Seeded '.Product::count().' products.');
    }

    private function category(string $name, ?int $parentId = null): Category
    {
        return Category::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'parent_id' => $parentId, 'is_active' => true]
        );
    }

    private function attribute(string $name, array $values, array $categories): void
    {
        $attribute = Attribute::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        $this->attributeIds[$name] = $attribute->id;

        foreach ($values as $i => $value) {
            $model = AttributeValue::firstOrCreate(
                ['attribute_id' => $attribute->id, 'slug' => Str::slug($value)],
                ['value' => $value, 'sort_order' => $i]
            );

            $this->values[$name][$value] = $model->id;
        }

        foreach ($categories as $category) {
            $category->attributes()->syncWithoutDetaching([
                $attribute->id => ['is_variant' => true, 'sort_order' => $this->order],
            ]);
        }

        $this->order++;
    }

    private function product(array $p): void
    {
        if (Product::where('name', $p['name'])->exists()) {
            return;
        }

        $definitions = [];

        foreach ($p['options'] as $attribute => $valueNames) {
            $definitions[] = [
                'attribute_id' => $this->attributeIds[$attribute],
                'value_ids' => array_map(fn ($v) => $this->values[$attribute][$v], $valueNames),
            ];
        }

        $variants = [];

        foreach ($this->combinations($p['options']) as $combo) {
            if (isset($p['skip']) && $p['skip']($combo)) {
                continue;
            }

            $price = $p['base_price'];

            foreach ($combo as $value) {
                $price += $p['extras'][$value] ?? 0;
            }

            $variants[] = [
                'attribute_value_ids' => array_map(
                    fn ($attribute, $value) => $this->values[$attribute][$value],
                    array_keys($combo),
                    $combo
                ),
                'sku' => strtoupper($p['code'].'-'.implode('-', array_map(fn ($v) => Str::slug($v, ''), $combo))),
                'purchase_price' => round($price * 0.75, 2),
                'selling_price' => $price,
                'stock' => self::STOCKS[count($variants) % count(self::STOCKS)],
            ];
        }

        $images = [];

        for ($n = 1; $n <= ($p['general_images'] ?? 1); $n++) {
            $images[] = [
                'url' => $this->imageUrl($p['name'], $n),
                'attribute_value_id' => null,   // general image, shown for every variant
                'is_primary' => $n === 1,
                'sort_order' => $n,
            ];
        }

        foreach ($p['value_images'] ?? [] as [$attribute, $value, $count]) {
            for ($n = 1; $n <= $count; $n++) {
                $images[] = [
                    'url' => $this->imageUrl($p['name'].' '.$value, $n),
                    'attribute_value_id' => $this->values[$attribute][$value],
                    'is_primary' => $n === 1,
                    'sort_order' => $n,
                ];
            }
        }

        app(ProductService::class)->create([
            'category_id' => $p['category']->id,
            'brand_name' => $p['brand'],
            'name' => $p['name'],
            'description' => $p['description'],
            'status' => 'active',
            'attributes' => $definitions,
            'variants' => $variants,
            'images' => $images,
        ]);
    }

    /** [['RAM' => '8GB', 'Storage' => '128GB'], ...] for every mix of the given options. */
    private function combinations(array $options): array
    {
        $result = [[]];

        foreach ($options as $attribute => $values) {
            $next = [];

            foreach ($result as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$attribute => $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    private function imageUrl(string $label, int $n): string
    {
        return 'https://placehold.co/800x800?text='.urlencode("{$label} {$n}");
    }
}