<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Block\Adminhtml\Form\Field;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

/**
 * Customer group dropdown column for dynamic rows
 */
class CustomerGroupColumn extends Select
{
    private bool $optionsLoaded = false;

    public function __construct(
        Context $context,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set input name
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    /**
     * Set input id
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    /**
     * Render block HTML
     */
    public function _toHtml(): string
    {
        if (!$this->optionsLoaded) {
            $this->loadOptions();
            $this->optionsLoaded = true;
        }
        return parent::_toHtml();
    }

    /**
     * Load customer group options
     */
    private function loadOptions(): void
    {
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $groups = $this->groupRepository->getList($searchCriteria)->getItems();
        
        $this->addOption('', __('-- Select Group --'));
        
        foreach ($groups as $group) {
            $this->addOption($group->getId(), $group->getCode());
        }
    }

    /**
     * Calculate option hash for selected state
     */
    public function calcOptionHash($optionValue): string
    {
        return sprintf('%u', crc32((string) $optionValue));
    }
}
