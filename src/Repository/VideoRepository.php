<?php

declare(strict_types=1);

namespace Pspa\Youtube\Repository;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Pspa\Youtube\Model\Video;

class VideoRepository
{
    public const INDEX = 'videos';

     
    private const MAX_CHANNELS = 10000;

    public function __construct(
        private readonly Client $client,
    ) {}

    public function save(Video $video, bool $refresh = false): void
    {
        $this->client->index([
            'index' => self::INDEX,
            'id' => $video->videoId,
            'body' => $video->toArray(),
            'refresh' => $refresh,
        ]);
    }

    





    public function saveMany(array $videos): int
    {
        if ($videos === []) {
            return 0;
        }

        $body = [];
        foreach ($videos as $video) {
            $body[] = ['index' => ['_index' => self::INDEX, '_id' => $video->videoId]];
            $body[] = $video->toArray();
        }

        $response = $this->client->bulk(['body' => $body]);
        if ($response['errors']) {
            $first = array_values(array_filter($response['items'], static fn (array $i): bool => isset($i['index']['error'])))[0] ?? null;
            throw new \RuntimeException('Ошибка bulk-записи видео: ' . json_encode($first, JSON_UNESCAPED_UNICODE));
        }

        return count($videos);
    }

    public function find(string $videoId): ?Video
    {
        try {
            $response = $this->client->get([
                'index' => self::INDEX,
                'id' => $videoId,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        return Video::fromArray($response['_source']);
    }

    public function delete(string $videoId, bool $refresh = false): bool
    {
        try {
            $this->client->delete([
                'index' => self::INDEX,
                'id' => $videoId,
                'refresh' => $refresh,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function deleteByChannel(string $channelId): int
    {
        
        $this->client->indices()->refresh(['index' => self::INDEX]);

        $response = $this->client->deleteByQuery([
            'index' => self::INDEX,
            'refresh' => true,
            'body' => [
                'query' => [
                    'term' => ['channel_id' => $channelId],
                ],
            ],
        ]);

        return (int) $response['deleted'];
    }

    




    public function byChannel(string $channelId, int $limit = 100): array
    {
        $response = $this->client->search([
            'index' => self::INDEX,
            'body' => [
                'size' => $limit,
                'query' => ['term' => ['channel_id' => $channelId]],
                'sort' => [['published_at' => 'desc']],
            ],
        ]);

        return array_map(
            static fn (array $hit): Video => Video::fromArray($hit['_source']),
            $response['hits']['hits'],
        );
    }

    public function count(): int
    {
        return (int) $this->client->count(['index' => self::INDEX])['count'];
    }

    




    public function sumLikesDislikes(string $channelId): array
    {
        $response = $this->client->search([
            'index' => self::INDEX,
            'body' => [
                'size' => 0,
                'track_total_hits' => true,
                'query' => [
                    'term' => ['channel_id' => $channelId],
                ],
                'aggs' => [
                    'likes' => ['sum' => ['field' => 'likes']],
                    'dislikes' => ['sum' => ['field' => 'dislikes']],
                ],
            ],
        ]);

        return [
            'videos' => (int) $response['hits']['total']['value'],
            'likes' => (int) $response['aggregations']['likes']['value'],
            'dislikes' => (int) $response['aggregations']['dislikes']['value'],
        ];
    }

    





    public function topChannelsByLikeRatio(int $limit): array
    {
        $response = $this->client->search([
            'index' => self::INDEX,
            'body' => [
                'size' => 0,
                'aggs' => [
                    'by_channel' => [
                        'terms' => ['field' => 'channel_id', 'size' => self::MAX_CHANNELS],
                        'aggs' => [
                            'likes' => ['sum' => ['field' => 'likes']],
                            'dislikes' => ['sum' => ['field' => 'dislikes']],
                            'ratio' => [
                                'bucket_script' => [
                                    'buckets_path' => ['l' => 'likes', 'd' => 'dislikes'],
                                    'script' => 'params.d == 0 ? params.l : params.l / params.d',
                                ],
                            ],
                            'top' => [
                                'bucket_sort' => [
                                    'sort' => [
                                        ['ratio' => ['order' => 'desc']],
                                        ['likes' => ['order' => 'desc']],
                                    ],
                                    'size' => $limit,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return array_map(static fn (array $b): array => [
            'channel_id' => (string) $b['key'],
            'videos' => (int) $b['doc_count'],
            'likes' => (int) $b['likes']['value'],
            'dislikes' => (int) $b['dislikes']['value'],
            'ratio' => (float) $b['ratio']['value'],
        ], $response['aggregations']['by_channel']['buckets']);
    }
}