<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Pushes a product's variants (each with its own pre-order flag) to the Inventory service.
 * Queued, so product creation never fails because Inventory is down; it retries with a growing delay.
 * Safe to run many times: Inventory upserts by variant_id.
 */
class SyncProductToInventory implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $productId) {}

    public function backoff(): array
    {
        return [5, 30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $product = Product::withTrashed()->with('variants.attributeValues')->find($this->productId);

        if (! $product || $product->variants->isEmpty()) {
            return;
        }

        $variants = $product->variants->map(fn ($variant) => [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'product_name' => $product->name,
            'variant_label' => $variant->attributeValues->sortBy('attribute_id')->pluck('value')->implode(' / ') ?: null,
            'allow_preorder' => (bool) $variant->allow_preorder,
        ])->values()->all();

        Http::withHeaders(['X-Internal-Key' => (string) config('services.inventory.key')])
            ->acceptJson()
            ->timeout(5)
            ->post(rtrim((string) config('services.inventory.url'), '/').'/api/internal/variants/sync', ['variants' => $variants])
            ->throw();
    }
}
