<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Service;

use Magento\Framework\Serialize\SerializerInterface;
use NeutromeLabs\Eve\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Service for polling Eve RSS feed and processing entries
 * 
 * Replaces webhook-based notifications with pull-based feed consumption.
 * Can be run via cron or CLI command.
 */
class FeedProcessor
{
    private const LAST_ENTRY_CACHE_KEY = 'neutrome_eve_last_feed_entry_';

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly CustomerBlockService $customerBlockService,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly \Magento\Framework\App\CacheInterface $cache
    ) {
    }

    /**
     * Poll and process new feed entries for the configured series
     *
     * @param int $limit Max entries to fetch per poll
     * @return array Processing summary
     */
    public function poll(int $limit = 50): array
    {
        if (!$this->config->isEnabled()) {
            return ['success' => false, 'message' => 'Eve integration is disabled'];
        }

        $seriesName = $this->config->getSeriesName();
        if (empty($seriesName)) {
            return ['success' => false, 'message' => 'Series name not configured'];
        }

        try {
            // Get last processed entry timestamp
            $since = $this->getLastProcessedTimestamp($seriesName);
            
            // Fetch new entries from Eve
            $entries = $this->apiClient->getFeedEntries($seriesName, $since, $limit);
            
            if (empty($entries)) {
                return [
                    'success' => true,
                    'message' => 'No new feed entries',
                    'processed' => 0,
                ];
            }

            $processed = 0;
            $results = [];
            $latestTimestamp = $since;

            foreach ($entries as $entry) {
                $result = $this->processEntry($entry);
                $results[] = $result;
                
                if ($result['success']) {
                    $processed++;
                }
                
                // Track latest timestamp
                $entryTime = $entry['published_at'] ?? null;
                if ($entryTime && $entryTime > $latestTimestamp) {
                    $latestTimestamp = $entryTime;
                }
            }

            // Save last processed timestamp
            if ($latestTimestamp !== $since) {
                $this->saveLastProcessedTimestamp($seriesName, $latestTimestamp);
            }

            $this->logger->info('Eve feed poll completed', [
                'series' => $seriesName,
                'fetched' => count($entries),
                'processed' => $processed,
            ]);

            return [
                'success' => true,
                'fetched' => count($entries),
                'processed' => $processed,
                'results' => $results,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Eve feed poll failed', [
                'series' => $seriesName,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a single feed entry
     */
    private function processEntry(array $entry): array
    {
        $entryType = $entry['entry_type'] ?? null;
        $content = $entry['content'] ?? [];
        
        if (is_string($content)) {
            $content = $this->serializer->unserialize($content);
        }

        $this->logger->info('Processing Eve feed entry', [
            'id' => $entry['id'] ?? null,
            'type' => $entryType,
            'title' => $entry['title'] ?? null,
        ]);

        return match ($entryType) {
            'bad_user' => $this->handleBadUser($entry, $content),
            'bad_event' => $this->handleBadEvent($entry, $content),
            'training_started' => $this->handleTrainingStarted($entry, $content),
            'training_completed' => $this->handleTrainingCompleted($entry, $content),
            'training_failed' => $this->handleTrainingFailed($entry, $content),
            default => [
                'success' => true,
                'message' => "Unhandled entry type: {$entryType}",
            ],
        };
    }

    /**
     * Handle bad_user entry - potentially block the customer
     */
    private function handleBadUser(array $entry, array $content): array
    {
        $userId = $content['user_id'] ?? null;
        $score = (float) ($content['score'] ?? 0);

        if ($userId === null) {
            return ['success' => false, 'error' => 'Missing user_id'];
        }

        // Extract customer ID from user_id format (customer_123 or guest_xxx)
        $customerId = $this->extractCustomerId($userId);

        if ($customerId === null) {
            $this->logger->info('Cannot block non-customer user', ['user_id' => $userId]);
            return [
                'success' => true,
                'message' => 'User is not a registered customer, skipping block',
            ];
        }

        // Check blocking conditions
        if (!$this->config->isAutoBlockEnabled()) {
            return [
                'success' => true,
                'message' => 'Auto-blocking disabled',
            ];
        }

        if (!$this->config->isProductionMode()) {
            return [
                'success' => true,
                'message' => 'Training mode active, customer not blocked',
            ];
        }

        $threshold = $this->config->getBlockScoreThreshold();
        if ($score < $threshold) {
            return [
                'success' => true,
                'message' => "Score {$score} below threshold {$threshold}",
            ];
        }

        // Block the customer
        $blocked = $this->customerBlockService->blockCustomer(
            (int) $customerId,
            "Blocked by Eve fraud detection. Score: {$score}",
            $content
        );

        if ($blocked) {
            $this->logger->info('Customer blocked via Eve feed', [
                'customer_id' => $customerId,
                'score' => $score,
            ]);
            return [
                'success' => true,
                'action' => 'customer_blocked',
                'customer_id' => $customerId,
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to block customer',
            'customer_id' => $customerId,
        ];
    }

    /**
     * Handle bad_event entry - log anomalous event
     */
    private function handleBadEvent(array $entry, array $content): array
    {
        $this->logger->info('Bad event detected', [
            'event_id' => $content['event_id'] ?? null,
            'user_id' => $content['user_id'] ?? null,
            'score' => $content['score'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => 'Bad event logged',
        ];
    }

    /**
     * Handle training_started entry
     */
    private function handleTrainingStarted(array $entry, array $content): array
    {
        $this->logger->info('Eve model training started', [
            'job_id' => $content['job_id'] ?? null,
            'series' => $content['series_name'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => 'Training started notification received',
        ];
    }

    /**
     * Handle training_completed entry
     */
    private function handleTrainingCompleted(array $entry, array $content): array
    {
        $this->logger->info('Eve model training completed', [
            'job_id' => $content['job_id'] ?? null,
            'series' => $content['series_name'] ?? null,
            'version' => $content['version'] ?? null,
            'metrics' => $content['metrics'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => 'Training completed notification received',
        ];
    }

    /**
     * Handle training_failed entry
     */
    private function handleTrainingFailed(array $entry, array $content): array
    {
        $this->logger->warning('Eve model training failed', [
            'job_id' => $content['job_id'] ?? null,
            'series' => $content['series_name'] ?? null,
            'error' => $content['error'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => 'Training failed notification received',
        ];
    }

    /**
     * Extract numeric customer ID from user_id string
     */
    private function extractCustomerId(string $userId): ?int
    {
        if (preg_match('/^customer_(\d+)$/', $userId, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Get last processed entry timestamp from cache
     */
    private function getLastProcessedTimestamp(string $seriesName): ?string
    {
        $cached = $this->cache->load(self::LAST_ENTRY_CACHE_KEY . $seriesName);
        return $cached ?: null;
    }

    /**
     * Save last processed entry timestamp to cache
     */
    private function saveLastProcessedTimestamp(string $seriesName, string $timestamp): void
    {
        $this->cache->save(
            $timestamp,
            self::LAST_ENTRY_CACHE_KEY . $seriesName,
            [],
            86400 * 30 // 30 days
        );
    }
}
