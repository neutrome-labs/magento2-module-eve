<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Cron;

use NeutromeLabs\Eve\Model\Config;
use NeutromeLabs\Eve\Service\FeedProcessor;
use Psr\Log\LoggerInterface;

/**
 * Cron job to poll Eve feed periodically
 */
class PollFeed
{
    public function __construct(
        private readonly Config $config,
        private readonly FeedProcessor $feedProcessor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute feed poll via cron
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$this->config->isFeedPollingEnabled()) {
            return;
        }

        try {
            $result = $this->feedProcessor->poll();
            
            if ($this->config->isLoggingEnabled()) {
                $this->logger->info('Eve feed cron poll completed', $result);
            }
        } catch (\Exception $e) {
            $this->logger->error('Eve feed cron poll failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
