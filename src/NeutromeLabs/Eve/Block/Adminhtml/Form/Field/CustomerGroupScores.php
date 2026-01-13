<?php
declare(strict_types=1);

namespace NeutromeLabs\Eve\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Admin config field for customer group scores table
 * 
 * Allows setting input_score per customer group:
 * - Empty value = null (production mode, ML will score)
 * - 0.0 = good/normal (training baseline)
 * - 1.0 = bad/anomaly
 * - Any value 0.0-1.0
 */
class CustomerGroupScores extends AbstractFieldArray
{
    private ?CustomerGroupColumn $customerGroupRenderer = null;

    /**
     * Prepare to render
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('customer_group_id', [
            'label' => __('Customer Group'),
            'renderer' => $this->getCustomerGroupRenderer(),
        ]);
        
        $this->addColumn('input_score', [
            'label' => __('Input Score'),
            'class' => 'validate-number-range number-range-0-1',
            'style' => 'width: 100px',
            'comment' => __('Leave empty for ML scoring, 0.0 for good, 1.0 for bad'),
        ]);
        
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Customer Group Score');
    }

    /**
     * Prepare existing row data object
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $customerGroupId = $row->getData('customer_group_id');
        
        if ($customerGroupId !== null) {
            $key = 'option_' . $this->getCustomerGroupRenderer()->calcOptionHash($customerGroupId);
            $options[$key] = 'selected="selected"';
        }
        
        $row->setData('option_extra_attrs', $options);
    }

    /**
     * Get customer group column renderer
     */
    private function getCustomerGroupRenderer(): CustomerGroupColumn
    {
        if ($this->customerGroupRenderer === null) {
            $this->customerGroupRenderer = $this->getLayout()->createBlock(
                CustomerGroupColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->customerGroupRenderer;
    }
}
