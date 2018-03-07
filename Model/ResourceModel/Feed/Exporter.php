<?php

namespace ShoppingFeed\Manager\Model\ResourceModel\Feed;

use Magento\Framework\DB\Select as DbSelect;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Model\ResourceModel\IteratorFactory;
use ShoppingFeed\Manager\Model\Feed\ExportableProductFactory;
use ShoppingFeed\Manager\Model\ResourceModel\AbstractDb;
use ShoppingFeed\Manager\Model\ResourceModel\Feed\Product\Filter\Applier as ProductFilterApplier;
use ShoppingFeed\Manager\Model\ResourceModel\Feed\Product\Section as FeedSectionResource;
use ShoppingFeed\Manager\Model\ResourceModel\Feed\Product\SectionFactory as FeedSectionResourceFactory;
use ShoppingFeed\Manager\Model\ResourceModel\Feed\Product\Section\Filter\Applier as SectionFilterApplier;
use ShoppingFeed\Manager\Model\Time\Helper as TimeHelper;


class Exporter extends AbstractDb
{
    const BASE_SECTION_DATA_KEY = 'section_%d';

    /**
     * @var IteratorFactory
     */
    private $iteratorFactory;

    /**
     * @var FeedSectionResource
     */
    private $feedSectionResource;

    /**
     * @var ExportableProductFactory
     */
    private $exportableProductFactory;

    /**
     * @param DbContext $context
     * @param TimeHelper $timeHelper
     * @param ProductFilterApplier $productFilterApplier
     * @param SectionFilterApplier $sectionFilterApplier
     * @param IteratorFactory $iteratorFactory
     * @param FeedSectionResourceFactory $feedSectionResourceFactory
     * @param ExportableProductFactory $exportableProductFactory
     * @param string|null $connectionName
     */
    public function __construct(
        DbContext $context,
        TimeHelper $timeHelper,
        ProductFilterApplier $productFilterApplier,
        SectionFilterApplier $sectionFilterApplier,
        IteratorFactory $iteratorFactory,
        FeedSectionResourceFactory $feedSectionResourceFactory,
        ExportableProductFactory $exportableProductFactory,
        $connectionName = null
    ) {
        $this->iteratorFactory = $iteratorFactory;
        $this->feedSectionResource = $feedSectionResourceFactory->create();
        $this->exportableProductFactory = $exportableProductFactory;
        parent::__construct($context, $timeHelper, $productFilterApplier, $sectionFilterApplier, $connectionName);
    }

    protected function _construct()
    {
        $this->_init('sfm_feed_product', 'product_id');
    }

    /**
     * @return \Zend_Db_Expr
     */
    private function getConfigurableParentIdsQuery()
    {
        return new \Zend_Db_Expr(
            $this->getConnection()
                ->select()
                ->from($this->getConfigurableProductLinkTable(), [ 'parent_id' ])
        );
    }

    /**
     * @return \Zend_Db_Expr
     */
    private function getConfigurableChildrenIdsQuery()
    {
        return new \Zend_Db_Expr(
            $this->getConnection()
                ->select()
                ->from($this->getConfigurableProductLinkTable(), [ 'product_id' ])
        );
    }

    /**
     * @param int $storeId
     * @param int[] $exportStates
     * @param bool $isChildrenSelect
     * @return DbSelect
     */
    private function getExportableProductBaseSelect($storeId, $exportStates, $isChildrenSelect = false)
    {
        $baseSelect = $this->getConnection()
            ->select()
            ->from([ 'product_table' => $this->getFeedProductTable() ], [ 'product_id' ])
            ->where('product_table.store_id = ?', $storeId)
            ->where('export_state_refreshed_at IS NOT NULL');

        if ($isChildrenSelect) {
            $baseSelect->columns([ 'export_state' => 'child_export_state' ]);
            $baseSelect->where('child_export_state IN (?)', $exportStates);
        } else {
            $baseSelect->columns([ 'export_state' ]);
            $baseSelect->where('export_state IN (?)', $exportStates);
        }

        return $baseSelect;
    }

    /**
     * @param DbSelect $productSelect
     * @param int[] $sectionTypeIds
     */
    private function joinSectionTablesToProductSelect(DbSelect $productSelect, array $sectionTypeIds)
    {
        $feedSectionTable = $this->getFeedProductSectionTable();
        $connection = $this->getConnection();

        foreach ($sectionTypeIds as $sectionTypeId) {
            $sectionTableAlias = sprintf('section_%d_table', $sectionTypeId);
            $sectionDataKey = sprintf(self::BASE_SECTION_DATA_KEY, $sectionTypeId);

            $productSelect->joinInner(
                [ $sectionTableAlias => $feedSectionTable ],
                implode(
                    ' AND ',
                    [
                        'product_table.product_id = ' . $sectionTableAlias . '.product_id',
                        'product_table.store_id = ' . $sectionTableAlias . '.store_id',
                        $sectionTableAlias . '.refreshed_at IS NOT NULL',
                        $connection->quoteInto($sectionTableAlias . '.type_id = ?', $sectionTypeId),
                    ]
                ),
                [ $sectionDataKey => 'data' ]
            );
        }
    }

    /**
     * @param DbSelect $productSelect
     */
    private function joinChildParentIdToProductSelect(DbSelect $productSelect)
    {
        $productSelect->joinInner(
            [ 'configurable_link_table' => $this->getConfigurableProductLinkTable() ],
            'product_table.product_id = configurable_link_table.product_id',
            [ 'parent_id' ]
        );
    }

    /**
     * @param array $row
     * @param int[] $sectionTypeIds
     * @return array
     */
    private function prepareRowSectionsData(array $row, array $sectionTypeIds)
    {
        $sectionsData = [];

        foreach ($sectionTypeIds as $sectionTypeId) {
            $sectionDataKey = sprintf(self::BASE_SECTION_DATA_KEY, $sectionTypeId);
            $sectionData = $this->feedSectionResource->unserializeSectionData((string) $row[$sectionDataKey]);
            $sectionsData[$sectionTypeId] = $sectionData;
        }

        return $sectionsData;
    }

    /**
     * @param callable $callback
     * @param int $storeId
     * @param int[] $sectionTypeIds
     * @param int[] $exportStates
     * @param bool $includeParentProducts
     * @param bool $includeChildProducts
     * @return $this
     */
    public function iterateExportableProducts(
        callable $callback,
        $storeId,
        array $sectionTypeIds,
        array $exportStates,
        $includeParentProducts,
        $includeChildProducts
    ) {
        $productSelect = $this->getExportableProductBaseSelect($storeId, $exportStates);
        $this->joinSectionTablesToProductSelect($productSelect, $sectionTypeIds);

        if (!$includeParentProducts) {
            $productSelect->where('product_table.product_id NOT IN (?)', $this->getConfigurableParentIdsQuery());
        }

        if (!$includeChildProducts) {
            $productSelect->where('product_table.product_id NOT IN (?)', $this->getConfigurableChildrenIdsQuery());
        }

        $this->iteratorFactory->create()
            ->walk(
                $productSelect,
                [
                    function (array $args) use ($callback, $sectionTypeIds) {
                        $row = $args['row'];

                        $exportableProduct = $this->exportableProductFactory->create()
                            ->setId((int) $row['product_id'])
                            ->setExportState((int) $row['export_state'])
                            ->setSectionsData($this->prepareRowSectionsData($row, $sectionTypeIds));

                        call_user_func($callback, $exportableProduct);
                    },
                ]
            );

        return $this;
    }

    /**
     * @param callable $callback
     * @param int $storeId
     * @param int[] $sectionTypeIds
     * @param int[] $parentExportStates
     * @param int[] $childExportStates
     * @return $this
     * @throws \Zend_Db_Statement_Exception
     */
    public function iterateExportableParentProducts(
        callable $callback,
        $storeId,
        array $sectionTypeIds,
        array $parentExportStates,
        array $childExportStates
    ) {
        $connection = $this->getConnection();

        $parentSelect = $this->getExportableProductBaseSelect($storeId, $parentExportStates);
        $parentSelect->where('product_table.product_id IN (?)', $this->getConfigurableParentIdsQuery());
        $this->joinSectionTablesToProductSelect($parentSelect, $sectionTypeIds);
        $parentSelect->order('product_id ASC');

        $childrenSelect = $this->getExportableProductBaseSelect($storeId, $childExportStates, true);
        $this->joinChildParentIdToProductSelect($childrenSelect);
        $this->joinSectionTablesToProductSelect($childrenSelect, $sectionTypeIds);
        $childrenSelect->order('parent_id ASC');

        $parentQuery = $connection->query($parentSelect);
        $childrenQuery = $connection->query($childrenSelect);
        $previousChildRow = null;

        while (is_array($parentRow = $parentQuery->fetch())) {
            $parentId = (int) $parentRow['product_id'];
            $childRows = [];

            if (null !== $previousChildRow) {
                $childRows[] = $previousChildRow;
            }

            while (is_array($childRow = $childrenQuery->fetch())) {
                $childParentId = (int) $childRow['parent_id'];

                if ($childParentId !== $parentId) {
                    $previousChildRow = $childRow;
                    break;
                } else {
                    $childRows[] = $childRow;
                }
            }

            $children = [];

            foreach ($childRows as $childRow) {
                $children[] = $this->exportableProductFactory->create()
                    ->setId((int) $childRow['product_id'])
                    ->setExportState((int) $childRow['export_state'])
                    ->setSectionsData($this->prepareRowSectionsData($childRow, $sectionTypeIds));
            }

            $parent = $this->exportableProductFactory->create()
                ->setChildren($children)
                ->setId((int) $parentRow['product_id'])
                ->setExportState((int) $parentRow['export_state'])
                ->setSectionsData($this->prepareRowSectionsData($parentRow, $sectionTypeIds));

            call_user_func($callback, $parent);
        }

        return $this;
    }
}