<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\CatalogEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResyncCatalog extends Command
{
    protected $signature = 'catalog:resync-inventory {--product= : only this product id}';

    protected $description = 'Publish catalog.variant.upserted again for every variant (recovery: Inventory missed events, or a queue was empty)';

    public function handle(): int
    {
        $count = 0;

        Product::query()
            ->when($this->option('product'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('variants')
            ->chunkById(100, function ($products) use (&$count) {
                foreach ($products as $product) {
                    DB::transaction(fn () => CatalogEvents::productUpserted($product));
                    $count++;
                }
            });

        $this->info("Queued events for {$count} product(s). The outbox worker will send them.");

        return self::SUCCESS;
    }
}
