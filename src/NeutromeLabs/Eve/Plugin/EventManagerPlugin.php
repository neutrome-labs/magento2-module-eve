<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Plugin;

use Magento\Customer\Model\Session\Proxy as CustomerSessionProxy;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeutromeLabs\Eve\Model\Config;
use NeutromeLabs\Eve\Service\ApiClient;
use Psr\Log\LoggerInterface;

/**
 * Plugin on Magento Event Manager
 * 
 * Intercepts ALL Magento events BEFORE observers are called and sends them to Eve API.
 * Sends: series (const), customer_id, event_name, payload with __allow_schema_updates
 * 
 * NOTE: Uses CustomerSession\Proxy to avoid circular dependency - this plugin runs
 * during early bootstrap when CustomerSession may not yet be available.
 */
class EventManagerPlugin
{
    /**
     * Events to track - configurable whitelist
     * If empty, tracks ALL events (not recommended for production)
     */
    private const DEFAULT_TRACKED_EVENTS = [
        // Generic events
        'controller_front_send_response_before',
        
        // Customer events
        'customer_login',
        'customer_logout',
        'customer_register_success',
        'customer_save_after',
        'customer_address_save_after',
        
        // Order events
        'sales_order_place_after',
        'sales_order_place_before',
        'sales_order_save_after',
        'sales_order_payment_place_end',
        'checkout_submit_all_after',
        
        // Cart events
        'checkout_cart_add_product_complete',
        'checkout_cart_update_items_after',
        'checkout_cart_save_after',
        'sales_quote_save_after',
        
        // Catalog events
        'catalog_product_view',
        'catalog_category_view',
        'catalog_product_compare_add_product',
        
        // Wishlist events
        'wishlist_add_product',
        'wishlist_share',
        
        // Review events
        'review_save_after',
        
        // Search events
        'catalogsearch_query_save_after',
        
        // Newsletter events
        'newsletter_subscriber_save_after',
        
        // Contact events
        'controller_action_postdispatch_contact_index_post',
    ];

    private const NOISY_PREFIXES = [
        'core_',
        'view_',
        'layout_',
        'adminhtml_',
        'backend_',
    ];

    /**
     * Events to always skip (internal/noisy events)
     */
    private const SKIP_EVENTS = [
        // 'controller_front_send_response_before',
        'model_load_before',
        // 'model_load_after',
        'model_save_before',
        // 'model_save_after',
        'clean_cache_by_tags',
        'application_router_match_before',
    ];

    private bool $isDispatching = false;

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly CustomerSessionProxy $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Before plugin - fires BEFORE any observers are called
     *
     * @param EventManager $subject
     * @param string $eventName
     * @param array $data
     * @return array
     */
    public function beforeDispatch(
        EventManager $subject,
        string $eventName,
        array $data = []
    ): array {
        // Prevent recursive dispatching
        if ($this->isDispatching) {
            return [$eventName, $data];
        }

        try {
            $this->isDispatching = true;
            $this->processEvent($eventName, $data);
        } catch (\Throwable $e) {
            // Never break Magento events - log and continue
            $this->logger->error('Eve event processing failed', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->isDispatching = false;
        }

        return [$eventName, $data];
    }

    /**
     * Process and send event to Eve API
     */
    private function processEvent(string $eventName, array $data): void
    {
        // Check if module is enabled
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        // Check if events collector is enabled
        if (!$this->config->isEventsCollectorEnabled($storeId)) {
            return;
        }

        // Skip internal/noisy events
        if ($this->shouldSkipEvent($eventName)) {
            $this->logSkipped($eventName, 'internal/noisy event');
            return;
        }

        // Check if event should be tracked
        if (!$this->shouldTrackEvent($eventName, $storeId)) {
            $this->logSkipped($eventName, 'not in tracked events whitelist');
            return;
        }

        // Build and send payload
        $payload = $this->buildPayload($eventName, $data, $storeId);
        
        if ($payload !== null) {
            $this->sendToEve($payload, $storeId);
        }
    }

    /**
     * Check if event should be skipped entirely
     */
    private function shouldSkipEvent(string $eventName): bool
    {
        // Skip internal events
        if (in_array($eventName, self::SKIP_EVENTS, true)) {
            return true;
        }

        // Skip events that start with common noisy prefixes
        foreach (self::NOISY_PREFIXES as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if event should be tracked based on config
     */
    private function shouldTrackEvent(string $eventName, int $storeId): bool
    {
        // Get tracked events from config or use defaults
        $trackedEvents = $this->getTrackedEvents($storeId);
        
        // If no whitelist configured, track all non-skipped events
        if (empty($trackedEvents)) {
            return true;
        }

        // Check exact match
        if (in_array($eventName, $trackedEvents, true)) {
            return true;
        }

        // Check wildcard patterns (e.g., "sales_order_*")
        foreach ($trackedEvents as $pattern) {
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $eventName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get list of tracked events from config
     */
    private function getTrackedEvents(int $storeId): array
    {
        // Could be extended to read from admin config
        // For now, use default whitelist
        return self::DEFAULT_TRACKED_EVENTS;
    }

    /**
     * Build payload for Eve API
     */
    private function buildPayload(string $eventName, array $data, int $storeId): ?array
    {
        // Get customer ID
        $customerId = $this->getCustomerId($data);
        
        // Extract relevant data from event
        $eventData = $this->extractEventData($eventName, $data);

        // Build the Eve event payload
        return [
            'series' => $this->config->getSeriesName($storeId),
            'user_id' => $customerId,
            'data' => [
                'event_name' => $eventName,
                'payload' => $eventData,
                'store_id' => $storeId,
                'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            ],
            'happened_at' => (new \DateTime())->format(\DateTime::ATOM),
            '__allow_schema_updates' => true,  // Always allow schema evolution
        ];
    }

    /**
     * Get customer ID from event data or session
     */
    private function getCustomerId(array $data): string
    {
        // Try to extract from event data
        if (isset($data['customer'])) {
            $customer = $data['customer'];
            if (is_object($customer) && method_exists($customer, 'getId')) {
                return 'customer_' . $customer->getId();
            }
        }

        if (isset($data['customer_id'])) {
            return 'customer_' . $data['customer_id'];
        }

        // Try from order
        if (isset($data['order'])) {
            $order = $data['order'];
            if (is_object($order)) {
                if (method_exists($order, 'getCustomerId') && $order->getCustomerId()) {
                    return 'customer_' . $order->getCustomerId();
                }
                if (method_exists($order, 'getCustomerEmail')) {
                    return 'guest_' . md5($order->getCustomerEmail());
                }
            }
        }

        // Try from quote
        if (isset($data['quote'])) {
            $quote = $data['quote'];
            if (is_object($quote) && method_exists($quote, 'getCustomerId') && $quote->getCustomerId()) {
                return 'customer_' . $quote->getCustomerId();
            }
        }

        // Fallback to session
        try {
            if ($this->customerSession->isLoggedIn()) {
                return 'customer_' . $this->customerSession->getCustomerId();
            }
        } catch (\Throwable $e) {
            // Session might not be available in all contexts
        }

        // Anonymous user - use session ID or generate unique ID
        return 'anonymous_' . (session_id() ?: uniqid('anon_', true));
    }

    /**
     * Extract relevant data from event objects
     */
    private function extractEventData(string $eventName, array $data): array
    {
        $extracted = [];

        foreach ($data as $key => $value) {
            $extracted[$key] = $this->serializeValue($value);
        }

        return $extracted;
    }

    /**
     * Serialize a value for JSON payload
     */
    private function serializeValue(mixed $value, int $depth = 0): mixed
    {
        // Prevent deep recursion
        if ($depth > 5) {
            return '[max depth]';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->serializeValue($v, $depth + 1);
            }
            return $result;
        }

        if (is_object($value)) {
            return $this->serializeObject($value, $depth);
        }

        return '[unserializable]';
    }

    /**
     * Serialize Magento objects to array
     */
    private function serializeObject(object $value, int $depth): array
    {
        $className = get_class($value);
        $data = ['__class' => $className];

        // Handle common Magento objects
        if (method_exists($value, 'getData')) {
            try {
                $objectData = $value->getData();
                if (is_array($objectData)) {
                    // Filter out sensitive and large fields
                    $filtered = $this->filterSensitiveData($objectData);
                    $data['data'] = $this->serializeValue($filtered, $depth + 1);
                }
            } catch (\Throwable $e) {
                $data['error'] = 'getData() failed';
            }
        } elseif (method_exists($value, 'toArray')) {
            try {
                $data['data'] = $this->serializeValue($value->toArray(), $depth + 1);
            } catch (\Throwable $e) {
                $data['error'] = 'toArray() failed';
            }
        } elseif (method_exists($value, 'getId')) {
            try {
                $data['id'] = $value->getId();
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        return $data;
    }

    /**
     * Filter sensitive data from payload
     */
    private function filterSensitiveData(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'password_hash',
            'password_confirmation',
            'cc_number',
            'cc_cid',
            'cc_exp_month',
            'cc_exp_year',
            'card_number',
            'cvv',
            'secret',
            'token',
            'api_key',
            'private_key',
        ];

        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            
            // Skip sensitive fields
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->filterSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Send payload to Eve API
     */
    private function sendToEve(array $payload, int $storeId): void
    {
        // Get input score based on current user's customer group
        $inputScore = $this->config->getInputScoreForUser($storeId);

        // Send event via Vector (always async)
        $this->apiClient->sendEvent(
            $payload['series'],
            $payload['user_id'],
            $payload['data'],
            $inputScore
        );

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('Eve event sent', [
                'event' => $payload['data']['event_name'] ?? 'unknown',
                'user_id' => $payload['user_id'],
                'series' => $payload['series'],
                'input_score' => $inputScore,
            ]);
        }
    }

    /**
     * Log skipped event (only when debug logging is enabled)
     */
    private function logSkipped(string $eventName, string $reason): void
    {
        if ($this->config->isLoggingEnabled()) {
            $this->logger->debug('Eve event skipped', [
                'event' => $eventName,
                'reason' => $reason,
            ]);
        }
    }
}
