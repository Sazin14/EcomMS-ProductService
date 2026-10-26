<?php

return [
    // The secret OTHER services (OrderService) must send in the X-Internal-Key header when they call ProductService.
    'key' => env('INTERNAL_SERVICE_KEY'),
];
