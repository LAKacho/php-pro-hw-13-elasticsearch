<?php

declare(strict_types=1);










use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;
use Pspa\Youtube\Stats\ChannelStats;
use Pspa\Youtube\Storage\ClientFactory;

require __DIR__ . '/../vendor/autoload.php';

const BATCH = 500;

$client = ClientFactory::create();
$videos = new VideoRepository($client);
$channels = new ChannelRepository($client, $videos);
$stats = new ChannelStats($videos, $channels);

if (($argv[1] ?? '') === 'clean') {
    foreach (['channels', 'videos'] as $index) {
        $r = $client->deleteByQuery([
            'index' => $index,
            'refresh' => true,
            'body' => ['query' => ['prefix' => [$index === 'channels' ? 'channel_id' : 'video_id' => 'BULK_']]],
        ]);
        echo "Удалено из {$index}: {$r['deleted']}" . PHP_EOL;
    }
    exit;
}

$total = max(2, (int) ($argv[1] ?? 4000));
$channelsCount = max(1, min($total - 1, (int) ($argv[2] ?? 40)));
$videosCount = $total - $channelsCount;

$countries = ['RU', 'US', 'DE', 'GB', 'FR', 'JP', 'BR', 'IN'];
$topics = ['Обзор', 'Урок', 'Влог', 'Разбор', 'Новости', 'Тест', 'Гайд', 'Стрим'];
$now = new DateTimeImmutable();

$started = microtime(true);


$channelDocs = [];
$quality = []; 
for ($c = 1; $c <= $channelsCount; $c++) {
    $id = sprintf('BULK_ch%03d', $c);
    $quality[$id] = mt_rand(30, 98) / 100;
    $channelDocs[] = new Channel(
        $id,
        "Канал {$c}",
        "Тестовый канал номер {$c}",
        $countries[array_rand($countries)],
        mt_rand(1_000, 3_000_000),
        $now->modify('-' . mt_rand(100, 2000) . ' days'),
    );
}
$written = $channels->saveMany($channelDocs);


$batch = [];
$batches = 0;
for ($v = 1; $v <= $videosCount; $v++) {
    $channelId = sprintf('BULK_ch%03d', (($v - 1) % $channelsCount) + 1);
    $reactions = mt_rand(50, 20_000);
    $likes = (int) round($reactions * $quality[$channelId] * mt_rand(80, 120) / 100);
    $batch[] = new Video(
        sprintf('BULK_v%05d', $v),
        $channelId,
        $topics[array_rand($topics)] . " №{$v}",
        "Описание видео {$v}",
        [strtolower($topics[array_rand($topics)]), 'bulk'],
        $reactions * mt_rand(10, 40),
        $likes,
        max(0, $reactions - $likes),
        $now->modify('-' . mt_rand(0, 900) . ' days'),
    );

    if (count($batch) === BATCH) {
        $written += $videos->saveMany($batch);
        $batch = [];
        $batches++;
    }
}
if ($batch !== []) {
    $written += $videos->saveMany($batch);
    $batches++;
}

$client->indices()->refresh(['index' => 'channels,videos']);
$seconds = microtime(true) - $started;

printf("Записано документов: %d (каналов %d, видео %d), bulk-запросов к видео: %d\n", $written, $channelsCount, $videosCount, $batches);
printf("Время: %.2f c, скорость: %d документов/с\n\n", $seconds, (int) ($written / max($seconds, 0.001)));

echo "Топ 5 каналов по отношению лайков к дизлайкам (по всем данным):" . PHP_EOL;
foreach ($stats->topByLikeRatio(5) as $i => $row) {
    printf("%d. %-14s (%s): видео %d, лайков %d, дизлайков %d, отношение %.2f\n", $i + 1, $row['title'], $row['channel_id'], $row['videos'], $row['likes'], $row['dislikes'], $row['ratio']);
}
