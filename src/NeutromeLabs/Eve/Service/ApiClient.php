<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Serialize\SerializerInterface;
use NeutromeLabs\Eve\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * HTTP Client for Eve API communication
 * 
 * Uses Vector for event ingestion and Hasura GraphQL for queries/scoring
 */
class ApiClient
{
    private ?Client $vectorClient = null;
    private ?Client $graphqlClient = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $clientFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get Vector HTTP client (for event ingestion)
     */
    private function getVectorClient(): Client
    {
        if ($this->vectorClient === null) {
            $this->vectorClient = $this->clientFactory->create([
                'config' => [
                    'base_uri' => $this->config->getVectorEndpoint(),
                    'timeout' => $this->config->getTimeout(),
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ],
            ]);
        }
        return $this->vectorClient;
    }

    /**
     * Get GraphQL client (for queries and scoring)
     */
    private function getGraphQLClient(): Client
    {
        if ($this->graphqlClient === null) {
            $this->graphqlClient = $this->clientFactory->create([
                'config' => [
                    'base_uri' => $this->config->getGraphQLEndpoint(),
                    'timeout' => $this->config->getTimeout(),
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-hasura-admin-secret' => $this->config->getHasuraSecret(),
                    ],
                ],
            ]);
        }
        return $this->graphqlClient;
    }

    /**
     * Send event to Vector for ingestion (async - fire and forget)
     *
     * @param string $series Series name
     * @param string $userId User identifier
     * @param array $data Event payload
     * @param float|null $inputScore Ground truth score (for training)
     */
    public function sendEvent(
        string $series,
        string $userId,
        array $data,
        ?float $inputScore = null
    ): void {
        $payload = [
            'series' => $series,
            'user_id' => $userId,
            'data' => $data,
            'happened_at' => (new \DateTime())->format(\DateTime::ATOM),
        ];

        if ($inputScore !== null) {
            $payload['input_score'] = $inputScore;
        }

        $this->postToVector($payload);
    }

    /**
     * Get real-time score from Eve via Hasura Action (sync endpoint)
     *
     * @param string $series Series name
     * @param string $userId User identifier
     * @param array $data Event payload
     * @return array|null Score response ['score' => float, 'confidence' => float, 'flagged' => bool]
     */
    public function getScore(string $series, string $userId, array $data): ?array
    {
        $query = <<<'GRAPHQL'
            mutation ScoreEvent($series: String!, $userId: String!, $data: jsonb!) {
                scoreEvent(series_name: $series, user_id: $userId, data_payload: $data) {
                    score
                    confidence
                    flagged
                }
            }
        GRAPHQL;

        $result = $this->executeGraphQL($query, [
            'series' => $series,
            'userId' => $userId,
            'data' => $data,
        ]);

        return $result['scoreEvent'] ?? null;
    }

    /**
     * Get recent events for a user
     */
    public function getUserEvents(string $series, string $userId, int $limit = 10): array
    {
        $query = <<<'GRAPHQL'
            query GetUserEvents($series: String!, $userId: String!, $limit: Int!) {
                events(
                    where: {
                        series_name: { _eq: $series },
                        user_id: { _eq: $userId }
                    },
                    order_by: { happened_at: desc },
                    limit: $limit
                ) {
                    id
                    data_payload
                    predicted_score
                    confidence
                    flagged
                    happened_at
                }
            }
        GRAPHQL;

        $result = $this->executeGraphQL($query, [
            'series' => $series,
            'userId' => $userId,
            'limit' => $limit,
        ]);

        return $result['events'] ?? [];
    }

    /**
     * Get series information
     */
    public function getSeriesInfo(string $series): ?array
    {
        $query = <<<'GRAPHQL'
            query GetSeries($name: String!) {
                series_registry_by_pk(name: $name) {
                    name
                    description
                    is_locked
                    created_at
                }
            }
        GRAPHQL;

        $result = $this->executeGraphQL($query, ['name' => $series]);
        return $result['series_registry_by_pk'] ?? null;
    }

    /**
     * Get feed entries for a series (RSS-style notifications)
     *
     * @param string $series Series name
     * @param string|null $since Only entries after this timestamp (ISO 8601)
     * @param int $limit Max entries to return
     * @return array Feed entries
     */
    public function getFeedEntries(string $series, ?string $since = null, int $limit = 50): array
    {
        if ($since !== null) {
            $query = <<<'GRAPHQL'
                query GetFeedEntries($series: String!, $since: timestamptz!, $limit: Int!) {
                    feed_entries(
                        where: {
                            series_name: { _eq: $series },
                            published_at: { _gt: $since }
                        },
                        order_by: { published_at: asc },
                        limit: $limit
                    ) {
                        id
                        series_name
                        entry_type
                        title
                        content
                        published_at
                    }
                }
            GRAPHQL;

            $result = $this->executeGraphQL($query, [
                'series' => $series,
                'since' => $since,
                'limit' => $limit,
            ]);
        } else {
            $query = <<<'GRAPHQL'
                query GetFeedEntriesInitial($series: String!, $limit: Int!) {
                    feed_entries(
                        where: { series_name: { _eq: $series } },
                        order_by: { published_at: desc },
                        limit: $limit
                    ) {
                        id
                        series_name
                        entry_type
                        title
                        content
                        published_at
                    }
                }
            GRAPHQL;

            $result = $this->executeGraphQL($query, [
                'series' => $series,
                'limit' => $limit,
            ]);
        }

        return $result['feed_entries'] ?? [];
    }

    /**
     * Post to Vector (synchronous with short timeout)
     */
    private function postToVector(array $payload): void
    {
        try {
            $this->log("Vector POST", ['payload' => $payload]);

            // Use synchronous request with short timeout
            // async requests get killed when PHP request ends
            $response = $this->getVectorClient()->post('', [
                RequestOptions::JSON => $payload,
                RequestOptions::TIMEOUT => 2,
                RequestOptions::CONNECT_TIMEOUT => 1,
            ]);
            
            $this->log("Vector response: " . $response->getStatusCode());
        } catch (GuzzleException $e) {
            // Log but don't fail - events are best-effort
            $this->logger->warning('Vector POST request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute GraphQL query/mutation
     */
    private function executeGraphQL(string $query, array $variables = []): ?array
    {
        try {
            $this->log("GraphQL request", ['query' => $query, 'variables' => $variables]);

            $response = $this->getGraphQLClient()->post('', [
                RequestOptions::JSON => [
                    'query' => $query,
                    'variables' => $variables,
                ],
            ]);

            $body = $response->getBody()->getContents();
            $result = $this->serializer->unserialize($body);

            if (isset($result['errors'])) {
                $this->logger->error('GraphQL errors', ['errors' => $result['errors']]);
                return null;
            }

            $this->log("GraphQL response", ['data' => $result['data'] ?? null]);
            return $result['data'] ?? null;

        } catch (GuzzleException $e) {
            $this->logger->error('GraphQL request failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log message if logging enabled
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->config->isLoggingEnabled()) {
            $this->logger->debug('[Eve] ' . $message, $context);
        }
    }
}
