<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Backend model for customer group scores configuration
 * 
 * Handles serialization of the dynamic rows table data
 */
class CustomerGroupScores extends ConfigValue
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SerializerInterface $serializer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Process data before save
     */
    public function beforeSave(): self
    {
        $value = $this->getValue();
        
        if (is_array($value)) {
            // Remove empty rows and the __empty placeholder
            unset($value['__empty']);
            
            // Clean up the data - keep only customer_group_id and input_score
            $cleanData = [];
            foreach ($value as $row) {
                if (!empty($row['customer_group_id']) || $row['customer_group_id'] === '0') {
                    $cleanData[] = [
                        'customer_group_id' => $row['customer_group_id'],
                        'input_score' => $row['input_score'] ?? '', // Empty string means null
                    ];
                }
            }
            
            $this->setValue($this->serializer->serialize($cleanData));
        }
        
        return parent::beforeSave();
    }

    /**
     * Process data after load
     */
    protected function _afterLoad(): self
    {
        $value = $this->getValue();
        
        if ($value && is_string($value)) {
            try {
                $this->setValue($this->serializer->unserialize($value));
            } catch (\Exception $e) {
                $this->setValue([]);
            }
        } elseif (!is_array($value)) {
            $this->setValue([]);
        }
        
        return parent::_afterLoad();
    }
}
