<?php

declare(strict_types=1);

use Pspa\Youtube\Storage\ClientFactory;

require __DIR__ . '/../vendor/autoload.php';

$client = ClientFactory::create();

foreach (['channels', 'videos'] as $index) {
    if ($client->indices()->exists(['index' => $index])->asBool()) {
        continue;
    }

    $mapping = json_decode(
        file_get_contents(__DIR__ . '/../mappings/' . $index . '.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $client->indices()->create([
        'index' => $index,
        'body' => $mapping,
    ]);
}

echo "Indexes are ready\n";
