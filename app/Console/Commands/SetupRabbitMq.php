<?php

namespace App\Console\Commands;

use App\Support\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Wire\AMQPTable;

class SetupRabbitMq extends Command
{
    protected $signature = 'rabbitmq:setup {--debug : also create a queue that keeps a copy of every catalog.* event, to look at in the management UI}';

    protected $description = 'Create the RabbitMQ exchanges (and optional debug queue) this service needs';

    public function handle(RabbitMqConnection $mq): int
    {
        $channel = $mq->channel();   // declares the exchanges ecom.events and ecom.retry

        $this->info('Exchanges ready: '.config('rabbitmq.exchange').', '.config('rabbitmq.retry_exchange'));

        // ProductService only publishes, so it owns no queues. The services that consume
        // (InventoryService) declare their own queues.
        if ($this->option('debug')) {
            $channel->queue_declare('debug.catalog', false, true, false, false, false, new AMQPTable(['x-max-length' => 1000]));
            $channel->queue_bind('debug.catalog', config('rabbitmq.exchange'), 'catalog.#');
            $this->info('Debug queue "debug.catalog" keeps the last 1000 catalog.* events.');
        }

        $mq->close();

        return self::SUCCESS;
    }
}
