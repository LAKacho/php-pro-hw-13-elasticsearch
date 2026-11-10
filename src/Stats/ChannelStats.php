<?php

declare(strict_types=1);

namespace Pspa\Youtube\Stats;

use InvalidArgumentException;
use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;

 
class ChannelStats
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly ChannelRepository $channels,
    ) {}

    




    public function totalLikesDislikes(string $channelId): array
    {
        return $this->videos->sumLikesDislikes($channelId);
    }

    





    public function topByLikeRatio(int $n): array
    {
        if ($n < 1) {
            throw new InvalidArgumentException('N должно быть не меньше 1');
        }

        $top = $this->videos->topChannelsByLikeRatio($n);
        $known = $this->channels->findMany(array_column($top, 'channel_id'));

        return array_map(static fn (array $row): array => [
            'channel_id' => $row['channel_id'],
            'title' => ($known[$row['channel_id']] ?? null)?->title,
        ] + $row, $top);
    }
}
