<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Model;

use Magento\Customer\Model\Session\Proxy as CustomerSessionProxy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration provider for Eve module
 */
class Config
{
    private const XML_PATH_ENABLED = 'neutrome_eve/general/enabled';
    private const XML_PATH_EVENTS_COLLECTOR = 'neutrome_eve/collectors/events_enabled';
    private const XML_PATH_LOGS_COLLECTOR = 'neutrome_eve/collectors/logs_enabled';
    private const XML_PATH_CUSTOMER_GROUP_SCORES = 'neutrome_eve/collectors/customer_group_scores';
    private const XML_PATH_VECTOR_ENDPOINT = 'neutrome_eve/api/vector_endpoint';
    private const XML_PATH_GRAPHQL_ENDPOINT = 'neutrome_eve/api/graphql_endpoint';
    private const XML_PATH_HASURA_SECRET = 'neutrome_eve/api/hasura_secret';
    private const XML_PATH_SERIES_NAME = 'neutrome_eve/api/series_name';
    private const XML_PATH_TIMEOUT = 'neutrome_eve/api/timeout';
    private const XML_PATH_FEED_POLLING_ENABLED = 'neutrome_eve/feed/polling_enabled';
    private const XML_PATH_AUTO_BLOCK = 'neutrome_eve/feed/auto_block_enabled';
    private const XML_PATH_BLOCK_THRESHOLD = 'neutrome_eve/feed/block_score_threshold';
    private const XML_PATH_NOTIFY_ADMIN = 'neutrome_eve/feed/notify_admin';
    private const XML_PATH_LOGGING = 'neutrome_eve/debug/logging_enabled';

    private ?array $customerGroupScoresCache = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SerializerInterface $serializer,
        private readonly CustomerSessionProxy $customerSession
    ) {
    }

    /**
     * Check if module is enabled
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if events collector is enabled
     */
    public function isEventsCollectorEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EVENTS_COLLECTOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if logs collector is enabled
     */
    public function isLogsCollectorEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LOGS_COLLECTOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get customer group scores configuration
     * 
     * @return array<int, float|null> Map of customer_group_id => input_score (null means ML scoring)
     */
    public function getCustomerGroupScores(?int $storeId = null): array
    {
        if ($this->customerGroupScoresCache !== null) {
            return $this->customerGroupScoresCache;
        }

        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CUSTOMER_GROUP_SCORES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $scores = [];
        
        if ($value && is_string($value)) {
            try {
                $data = $this->serializer->unserialize($value);
                foreach ($data as $row) {
                    $groupId = (int) $row['customer_group_id'];
                    $inputScore = $row['input_score'] ?? '';
                    
                    // Empty string means null (ML scoring), otherwise parse as float
                    $scores[$groupId] = $inputScore === '' ? null : (float) $inputScore;
                }
            } catch (\Exception $e) {
                // Invalid data, return empty
            }
        }

        $this->customerGroupScoresCache = $scores;
        return $scores;
    }

    /**
     * Get input score for current user based on their customer group
     * 
     * @return float|null Input score (null means ML scoring mode)
     */
    public function getInputScoreForUser(?int $storeId = null): ?float
    {
        $scores = $this->getCustomerGroupScores($storeId);
        
        if (empty($scores)) {
            // No configuration - default to null (ML scoring)
            return null;
        }

        // Get current customer's group ID
        $groupId = $this->getCurrentCustomerGroupId();
        
        // Return configured score for this group, or null if not configured
        return $scores[$groupId] ?? null;
    }

    /**
     * Get input score for a specific customer group
     */
    public function getInputScoreForGroup(int $groupId, ?int $storeId = null): ?float
    {
        $scores = $this->getCustomerGroupScores($storeId);
        return $scores[$groupId] ?? null;
    }

    /**
     * Get current customer's group ID
     */
    private function getCurrentCustomerGroupId(): int
    {
        try {
            if ($this->customerSession->isLoggedIn()) {
                return (int) $this->customerSession->getCustomerGroupId();
            }
        } catch (\Throwable $e) {
            // Session not available
        }

        // Default: NOT LOGGED IN group (0)
        return 0;
    }

    /**
     * Get Vector endpoint URL (for event ingestion)
     */
    public function getVectorEndpoint(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_VECTOR_ENDPOINT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get GraphQL endpoint URL (for queries/scoring)
     */
    public function getGraphQLEndpoint(?int $storeId = null): string
    {
        return rtrim((string) $this->scopeConfig->getValue(
            self::XML_PATH_GRAPHQL_ENDPOINT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ), '/');
    }

    /**
     * Get Hasura admin secret
     */
    public function getHasuraSecret(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_HASURA_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get series name
     */
    public function getSeriesName(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SERIES_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get API timeout in seconds
     */
    public function getTimeout(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_TIMEOUT) ?: 5;
    }

    /**
     * Check if feed polling is enabled
     */
    public function isFeedPollingEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FEED_POLLING_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if auto-blocking is enabled
     */
    public function isAutoBlockEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_BLOCK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get block score threshold
     */
    public function getBlockScoreThreshold(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_BLOCK_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.8;
    }

    /**
     * Check if admin notifications are enabled
     */
    public function isAdminNotifyEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_NOTIFY_ADMIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if debug logging is enabled
     */
    public function isLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOGGING);
    }
}

