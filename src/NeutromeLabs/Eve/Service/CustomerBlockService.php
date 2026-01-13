<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Service;

use Magento\Customer\Model\CustomerAuthUpdate;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\ResourceModel\CustomerRepository;
use Psr\Log\LoggerInterface;

/**
 * Service for blocking customer accounts using Magento's native lock
 */
class CustomerBlockService
{
    public function __construct(
        private readonly CustomerAuthUpdate $customerAuthUpdate,
        private readonly CustomerRegistry $customerRegistry,
        private readonly CustomerRepository $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Block a customer using Magento's native lock mechanism
     */
    public function blockCustomer(int $customerId, string $reason = '', array $webhookData = []): bool
    {
        try {
            // Use Magento's native lock - sets lock_expires to far future
            $this->customerAuthUpdate->lockCustomer($customerId);
            
            $this->logger->info('Customer locked by Eve', [
                'customer_id' => $customerId,
                'reason' => $reason,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to lock customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Unblock a customer
     */
    public function unblockCustomer(int $customerId): bool
    {
        try {
            $customerSecure = $this->customerRegistry->retrieveSecureData($customerId);
            $customerSecure->setLockExpires(null);
            $customerSecure->setFailuresNum(0);
            
            // Save changes to database
            $this->customerRepository->save(
                $this->customerRepository->getById($customerId)
            );
            
            // Clear registry cache to reflect changes
            $this->customerRegistry->remove($customerId);
            
            $this->logger->info('Customer unlocked', ['customer_id' => $customerId]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to unlock customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if customer is locked
     */
    public function isCustomerIdBlocked(int $customerId): bool
    {
        try {
            $customerSecure = $this->customerRegistry->retrieveSecureData($customerId);
            $lockExpires = $customerSecure->getLockExpires();
            
            return $lockExpires && strtotime($lockExpires) > time();
        } catch (\Exception $e) {
            return false;
        }
    }
}
