<?php

declare(strict_types=1);

namespace Pspa\Youtube\Repository;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Pspa\Youtube\Model\Channel;

class ChannelRepository
{
    public const INDEX = 'channels';

    public function __construct(
        private readonly Client $client,
        private readonly VideoRepository $videos,
    ) {}

    public function save(Channel $channel, bool $refresh = false): void
    {
        $this->client->index([
            'index' => self::INDEX,
            'id' => $channel->channelId,
            'body' => $channel->toArray(),
            'refresh' => $refresh,
        ]);
    }

    





    public function saveMany(array $channels): int
    {
        if ($channels === []) {
            return 0;
        }

        $body = [];
        foreach ($channels as $channel) {
            $body[] = ['index' => ['_index' => self::INDEX, '_id' => $channel->channelId]];
            $body[] = $channel->toArray();
        }

        $response = $this->client->bulk(['body' => $body]);
        if ($response['errors']) {
            $first = array_values(array_filter($response['items'], static fn (array $i): bool => isset($i['index']['error'])))[0] ?? null;
            throw new \RuntimeException('Ошибка bulk-записи каналов: ' . json_encode($first, JSON_UNESCAPED_UNICODE));
        }

        return count($channels);
    }

    public function find(string $channelId): ?Channel
    {
        try {
            $response = $this->client->get([
                'index' => self::INDEX,
                'id' => $channelId,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        return Channel::fromArray($response['_source']);
    }

    




    public function all(int $limit = 200): array
    {
        $response = $this->client->search([
            'index' => self::INDEX,
            'body' => [
                'size' => $limit,
                'query' => ['match_all' => new \stdClass()],
                'sort' => [['title.raw' => 'asc']],
            ],
        ]);

        return array_map(
            static fn (array $hit): Channel => Channel::fromArray($hit['_source']),
            $response['hits']['hits'],
        );
    }

    public function count(): int
    {
        return (int) $this->client->count(['index' => self::INDEX])['count'];
    }

    





    public function findMany(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $response = $this->client->mget([
            'index' => self::INDEX,
            'body' => ['ids' => array_values($channelIds)],
        ]);

        $result = [];
        foreach ($response['docs'] as $doc) {
            if ($doc['found'] ?? false) {
                $result[$doc['_id']] = Channel::fromArray($doc['_source']);
            }
        }

        return $result;
    }

    public function delete(string $channelId, bool $withVideos = true): bool
    {
        if ($withVideos) {
            $this->videos->deleteByChannel($channelId);
        }

        try {
            $this->client->delete([
                'index' => self::INDEX,
                'id' => $channelId,
                'refresh' => true,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }
}