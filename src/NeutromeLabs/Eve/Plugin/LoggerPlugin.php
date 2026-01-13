<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Plugin;

use Magento\Customer\Model\Session\Proxy as CustomerSessionProxy;
use Magento\Store\Model\StoreManagerInterface;
use NeutromeLabs\Eve\Model\Config;
use NeutromeLabs\Eve\Service\ApiClient;
use Psr\Log\LoggerInterface;

/**
 * Plugin on Magento Logger to collect log entries as events
 * 
 * Intercepts log calls and sends them to Eve for anomaly detection.
 * Useful for detecting unusual error patterns, security warnings, etc.
 */
class LoggerPlugin
{
    /**
     * Log levels to track (PSR-3 levels)
     */
    private const TRACKED_LEVELS = [
        'emergency',
        'alert', 
        'critical',
        'error',
        'warning',
    ];

    /**
     * Patterns to skip (internal/noisy logs)
     */
    private const SKIP_PATTERNS = [
        '/^Eve event/',
        '/^Eve log/',
        '/neutrome_eve/',
        '/^Cache/',
        '/^Session/',
    ];

    private bool $isProcessing = false;

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly CustomerSessionProxy $customerSession,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * After emergency log
     */
    public function afterEmergency(LoggerInterface $subject, $result, $message, array $context = []): void
    {
        $this->processLog('emergency', $message, $context);
    }

    /**
     * After alert log
     */
    public function afterAlert(LoggerInterface $subject, $result, $message, array $context = []): void
    {
        $this->processLog('alert', $message, $context);
    }

    /**
     * After critical log
     */
    public function afterCritical(LoggerInterface $subject, $result, $message, array $context = []): void
    {
        $this->processLog('critical', $message, $context);
    }

    /**
     * After error log
     */
    public function afterError(LoggerInterface $subject, $result, $message, array $context = []): void
    {
        $this->processLog('error', $message, $context);
    }

    /**
     * After warning log
     */
    public function afterWarning(LoggerInterface $subject, $result, $message, array $context = []): void
    {
        $this->processLog('warning', $message, $context);
    }

    /**
     * Process and send log entry to Eve
     */
    private function processLog(string $level, string|\Stringable $message, array $context): void
    {
        // Prevent recursive logging
        if ($this->isProcessing) {
            return;
        }

        try {
            $this->isProcessing = true;
            
            // Check if module and logs collector are enabled
            $storeId = $this->getStoreId();
            if (!$this->config->isEnabled($storeId) || !$this->config->isLogsCollectorEnabled($storeId)) {
                return;
            }

            $messageStr = (string) $message;

            // Skip internal/noisy logs
            if ($this->shouldSkipLog($messageStr)) {
                return;
            }

            // Get user ID
            $userId = $this->getUserId();

            // Build log event payload
            $payload = [
                'log_level' => $level,
                'message' => $this->truncateMessage($messageStr),
                'context' => $this->sanitizeContext($context),
                'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            ];

            // Get input score based on customer group
            $inputScore = $this->config->getInputScoreForUser($storeId);

            // Send to Eve
            $this->apiClient->sendEvent(
                $this->config->getSeriesName($storeId),
                $userId,
                [
                    'event_name' => 'system_log_' . $level,
                    'event_type' => 'log',
                    'payload' => $payload,
                    'store_id' => $storeId,
                ],
                $inputScore
            );
        } catch (\Throwable $e) {
            // Never break logging - silently fail
        } finally {
            $this->isProcessing = false;
        }
    }

    /**
     * Check if log should be skipped
     */
    private function shouldSkipLog(string $message): bool
    {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Truncate long messages
     */
    private function truncateMessage(string $message, int $maxLength = 1000): string
    {
        if (strlen($message) > $maxLength) {
            return substr($message, 0, $maxLength) . '... [truncated]';
        }
        return $message;
    }

    /**
     * Sanitize context data (remove sensitive info, limit depth)
     */
    private function sanitizeContext(array $context, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['[max depth]'];
        }

        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'auth', 'credential'];
        $result = [];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            
            // Skip sensitive keys
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitizeContext($value, $depth + 1);
            } elseif (is_object($value)) {
                $result[$key] = get_class($value);
            } elseif (is_scalar($value)) {
                $result[$key] = $value;
            } else {
                $result[$key] = '[unserializable]';
            }
        }

        return $result;
    }

    /**
     * Get current user ID
     */
    private function getUserId(): string
    {
        try {
            if ($this->customerSession->isLoggedIn()) {
                return 'customer_' . $this->customerSession->getCustomerId();
            }
        } catch (\Throwable $e) {
            // Session might not be available
        }

        return 'system_' . (php_sapi_name() === 'cli' ? 'cli' : 'web');
    }

    /**
     * Get current store ID
     */
    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
