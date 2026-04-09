<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTestData extends Command
{
    private OrderRepositoryInterface $orderRepository;
    private ProductRepositoryInterface $productRepository;
    private StoreManagerInterface $storeManager;
    private InvoiceOrderInterface $invoiceOrder;
    private ShipOrderInterface $shipOrder;
    private CreditmemoFactory $creditmemoFactory;
    private CreditmemoManagementInterface $creditmemoManagement;
    private AdapterInterface $connection;

    private array $scenarios = [];
    private int $created = 0;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        InvoiceOrderInterface $invoiceOrder,
        ShipOrderInterface $shipOrder,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoManagementInterface $creditmemoManagement,
        ResourceConnection $resourceConnection,
        string $name = null
    ) {
        parent::__construct($name);
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->invoiceOrder = $invoiceOrder;
        $this->shipOrder = $shipOrder;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoManagement = $creditmemoManagement;
        $this->connection = $resourceConnection->getConnection();
    }

    protected function configure()
    {
        $this->setName('klar:generate-test-data');
        $this->setDescription('[DEV ONLY] Generate diverse test orders for Klar integration testing');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Generating test orders for Klar integration testing...</info>');
        $output->writeln('');

        $this->buildScenarios();

        foreach ($this->scenarios as $scenario) {
            try {
                $this->createScenario($scenario, $output);
            } catch (\Exception $e) {
                $output->writeln('<error>  FAILED: ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln('');
        $output->writeln('<info>Done. Created ' . $this->created . ' test orders.</info>');
        $output->writeln('<comment>Run "bin/magento klar:order all" to sync them to Klar.</comment>');

        return self::SUCCESS;
    }

    private function buildScenarios(): void
    {
        // ============================================================
        // STORE 1 (Default) — DE address, 19% tax, USD currency
        // ============================================================

        // 1. Simple product, registered customer, no discount, pending
        $this->scenarios[] = [
            'label' => 'Simple product, registered customer, pending',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 29.99, 1, 19.0),
            ],
            'shipping' => 5.95,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'pending',
        ];

        // 2. Simple product, guest customer, with coupon discount, paid
        $this->scenarios[] = [
            'label' => 'Simple product, guest, 10% coupon, paid+invoiced',
            'store_id' => 1,
            'customer' => $this->guestCustomer('DE'),
            'items' => [
                $this->simpleItem('product_dynamic_2', 49.99, 2, 19.0),
            ],
            'shipping' => 5.95,
            'shipping_tax_pct' => 19.0,
            'discount_pct' => 10,
            'coupon_code' => 'CouponCode4',
            'coupon_rule_id' => 65,
            'lifecycle' => 'invoiced',
        ];

        // 3. Multiple simple products, high value, free shipping threshold, paid+shipped
        $this->scenarios[] = [
            'label' => 'Multi-item, high value, free shipping, shipped',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 199.99, 3, 19.0),
                $this->simpleItem('product_dynamic_2', 89.50, 2, 19.0),
            ],
            'shipping' => 0,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'shipped',
        ];

        // 4. Configurable product, paid+shipped+complete
        $this->scenarios[] = [
            'label' => 'Configurable product, complete',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->configurableItem(1185, 'Configurable Product 1', 709.60, 1, 19.0),
            ],
            'shipping' => 9.99,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'complete',
        ];

        // 5. Simple product, fully refunded
        $this->scenarios[] = [
            'label' => 'Simple product, fully refunded',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 39.99, 1, 19.0),
            ],
            'shipping' => 5.95,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'refunded',
        ];

        // 6. Simple product, partially refunded (2 of 3 qty)
        $this->scenarios[] = [
            'label' => 'Simple product, partially refunded (2 of 3)',
            'store_id' => 1,
            'customer' => $this->guestCustomer('DE'),
            'items' => [
                $this->simpleItem('product_dynamic_2', 24.99, 3, 19.0),
            ],
            'shipping' => 5.95,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'partially_refunded',
            'refund_qty' => [0 => 2],
        ];

        // 7. Cancelled order
        $this->scenarios[] = [
            'label' => 'Simple product, cancelled order',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 15.00, 1, 19.0),
            ],
            'shipping' => 5.95,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'cancelled',
        ];

        // 8. Reduced tax rate (7%)
        $this->scenarios[] = [
            'label' => 'Simple product, 7% reduced tax rate',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 12.99, 4, 7.0),
            ],
            'shipping' => 3.50,
            'shipping_tax_pct' => 7.0,
            'discount' => 0,
            'lifecycle' => 'invoiced',
            'tax_title' => 'DE - reduzierte USt.',
        ];

        // 9. Zero tax (export/B2B)
        $this->scenarios[] = [
            'label' => 'Simple product, 0% tax (export)',
            'store_id' => 1,
            'customer' => $this->guestCustomer('CH'),
            'items' => [
                $this->simpleItem('product_dynamic_2', 199.00, 1, 0.0),
            ],
            'shipping' => 15.00,
            'shipping_tax_pct' => 0.0,
            'discount' => 0,
            'lifecycle' => 'invoiced',
            'tax_title' => 'CH - ohne USt.',
        ];

        // 10. Mixed tax rates in one order
        $this->scenarios[] = [
            'label' => 'Mixed tax rates: 19% + 7% items',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 59.99, 1, 19.0),
                $this->simpleItem('product_dynamic_2', 9.99, 2, 7.0, 'DE - reduzierte USt.'),
            ],
            'shipping' => 4.99,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'invoiced',
        ];

        // 11. Large quantity order
        $this->scenarios[] = [
            'label' => 'Simple product, qty=50, with fixed discount',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->simpleItem('product_dynamic_1', 3.99, 50, 19.0),
            ],
            'shipping' => 0,
            'shipping_tax_pct' => 19.0,
            'discount' => 20.00,
            'lifecycle' => 'shipped',
        ];

        // 12. Configurable + simple mixed order with discount
        $this->scenarios[] = [
            'label' => 'Configurable + simple, 5% coupon, partial ship',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->configurableItem(1186, 'Configurable Product 2', 149.90, 2, 19.0),
                $this->simpleItem('product_dynamic_1', 29.99, 1, 19.0),
            ],
            'shipping' => 7.95,
            'shipping_tax_pct' => 19.0,
            'discount_pct' => 5,
            'coupon_code' => 'CouponCode8',
            'coupon_rule_id' => 69,
            'lifecycle' => 'shipped',
        ];

        // ============================================================
        // STORE 2 — different store view
        // ============================================================

        // 13. Store 2 order
        $this->scenarios[] = [
            'label' => 'Store 2: simple product, guest, shipped',
            'store_id' => 2,
            'customer' => $this->guestCustomer('FR'),
            'items' => [
                $this->simpleItem('product_dynamic_1201', 44.90, 1, 19.0, 'FR - USt.'),
            ],
            'shipping' => 8.50,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'shipped',
        ];

        // ============================================================
        // STORE 3 — different store view
        // ============================================================

        // 14. Store 3 order with refund
        $this->scenarios[] = [
            'label' => 'Store 3: simple product, refunded',
            'store_id' => 3,
            'customer' => $this->guestCustomer('SE'),
            'items' => [
                $this->simpleItem('product_dynamic_1201', 79.00, 2, 19.0, 'SE - USt.'),
            ],
            'shipping' => 0,
            'shipping_tax_pct' => 19.0,
            'discount' => 0,
            'lifecycle' => 'refunded',
        ];

        // 15. Very high value configurable product order (stress test)
        $this->scenarios[] = [
            'label' => 'High value configurable, 3 qty, coupon, complete',
            'store_id' => 1,
            'customer' => $this->deCustomer(),
            'items' => [
                $this->configurableItem(1187, 'Configurable Product 3', 1499.00, 3, 19.0),
            ],
            'shipping' => 0,
            'shipping_tax_pct' => 19.0,
            'discount_pct' => 10,
            'coupon_code' => 'CouponCode0',
            'coupon_rule_id' => 61,
            'lifecycle' => 'complete',
        ];
    }

    private function createScenario(array $scenario, OutputInterface $output): void
    {
        $label = $scenario['label'];
        $output->write("  Creating: <comment>{$label}</comment> ... ");

        $storeId = $scenario['store_id'];
        $customer = $scenario['customer'];
        $isGuest = $customer['is_guest'];

        // Calculate financial totals
        $subtotal = 0;
        $totalTax = 0;
        $totalDiscount = 0;
        $orderItems = [];

        foreach ($scenario['items'] as $idx => $itemData) {
            $qty = $itemData['qty'];
            $price = $itemData['price'];
            $taxPct = $itemData['tax_pct'];
            $taxTitle = $itemData['tax_title'] ?? 'DE - USt.';

            $rowTotal = $price * $qty;
            $taxAmount = round($rowTotal * $taxPct / 100, 2);
            $rowTotalInclTax = $rowTotal + $taxAmount;
            $priceInclTax = round($price * (1 + $taxPct / 100), 2);

            // Apply discount
            $itemDiscount = 0;
            if (isset($scenario['discount_pct']) && $scenario['discount_pct'] > 0) {
                $itemDiscount = round($rowTotal * $scenario['discount_pct'] / 100, 2);
            } elseif (isset($scenario['discount']) && $scenario['discount'] > 0 && $idx === 0) {
                $itemDiscount = $scenario['discount'];
            }

            $subtotal += $rowTotal;
            $totalTax += $taxAmount;
            $totalDiscount += $itemDiscount;

            $orderItem = new \Magento\Framework\DataObject([
                'product_id' => $itemData['product_id'],
                'product_type' => $itemData['type'],
                'name' => $itemData['name'],
                'sku' => $itemData['sku'],
                'qty_ordered' => $qty,
                'price' => $price,
                'base_price' => $price,
                'original_price' => $price,
                'price_incl_tax' => $priceInclTax,
                'row_total' => $rowTotal,
                'base_row_total' => $rowTotal,
                'row_total_incl_tax' => $rowTotalInclTax,
                'base_row_total_incl_tax' => $rowTotalInclTax,
                'tax_amount' => $taxAmount,
                'base_tax_amount' => $taxAmount,
                'tax_percent' => $taxPct,
                'discount_amount' => $itemDiscount,
                'base_discount_amount' => $itemDiscount,
                'weight' => 1.0,
                'applied_rule_ids' => isset($scenario['coupon_rule_id']) ? (string)$scenario['coupon_rule_id'] : '',
                'tax_title' => $taxTitle,
            ]);

            // For configurable, also store child info
            if ($itemData['type'] === 'configurable') {
                $orderItem->setData('configurable_child', $itemData['child'] ?? null);
            }

            $orderItems[] = $orderItem;
        }

        $shippingAmount = $scenario['shipping'];
        $shippingTaxPct = $scenario['shipping_tax_pct'];
        $shippingTax = round($shippingAmount * $shippingTaxPct / 100, 2);
        $shippingInclTax = $shippingAmount + $shippingTax;

        $grandTotal = $subtotal + $totalTax + $shippingInclTax - $totalDiscount;

        // Build Magento order object
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $billingAddress = $objectManager->create(OrderAddress::class, ['data' => $customer['address']]);
        $billingAddress->setAddressType('billing');

        $shippingAddress = clone $billingAddress;
        $shippingAddress->setId(null)->setAddressType('shipping');

        $payment = $objectManager->create(Payment::class);
        $payment->setMethod('checkmo')
            ->setAdditionalInformation('method_title', 'Check / Money Order');

        /** @var Order $order */
        $order = $objectManager->create(Order::class);
        $order->setStoreId($storeId)
            ->setSubtotal($subtotal)
            ->setBaseSubtotal($subtotal)
            ->setGrandTotal($grandTotal)
            ->setBaseGrandTotal($grandTotal)
            ->setTotalPaid(0)
            ->setTotalRefunded(0)
            ->setShippingAmount($shippingAmount)
            ->setBaseShippingAmount($shippingAmount)
            ->setShippingInclTax($shippingInclTax)
            ->setBaseShippingInclTax($shippingInclTax)
            ->setShippingTaxAmount($shippingTax)
            ->setBaseShippingTaxAmount($shippingTax)
            ->setShippingDescription('Flat Rate - Fixed')
            ->setShippingMethod('flatrate_flatrate')
            ->setTaxAmount($totalTax + $shippingTax)
            ->setBaseTaxAmount($totalTax + $shippingTax)
            ->setDiscountAmount(-$totalDiscount)
            ->setBaseDiscountAmount(-$totalDiscount)
            ->setOrderCurrencyCode('USD')
            ->setBaseCurrencyCode('USD')
            ->setGlobalCurrencyCode('USD')
            ->setStoreCurrencyCode('USD')
            ->setCustomerIsGuest($isGuest)
            ->setCustomerEmail($customer['email'])
            ->setCustomerFirstname($customer['address']['firstname'])
            ->setCustomerLastname($customer['address']['lastname'])
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPayment($payment)
            ->setState(Order::STATE_NEW)
            ->setStatus('pending');

        if (isset($scenario['coupon_code'])) {
            $order->setCouponCode($scenario['coupon_code']);
            $order->setAppliedRuleIds((string)$scenario['coupon_rule_id']);
        }

        // Add order items
        foreach ($orderItems as $itemDataObj) {
            /** @var OrderItem $magentoItem */
            $magentoItem = $objectManager->create(OrderItem::class);
            $magentoItem->setProductId($itemDataObj->getData('product_id'))
                ->setProductType($itemDataObj->getData('product_type'))
                ->setName($itemDataObj->getData('name'))
                ->setSku($itemDataObj->getData('sku'))
                ->setQtyOrdered($itemDataObj->getData('qty_ordered'))
                ->setPrice($itemDataObj->getData('price'))
                ->setBasePrice($itemDataObj->getData('base_price'))
                ->setOriginalPrice($itemDataObj->getData('original_price'))
                ->setPriceInclTax($itemDataObj->getData('price_incl_tax'))
                ->setRowTotal($itemDataObj->getData('row_total'))
                ->setBaseRowTotal($itemDataObj->getData('base_row_total'))
                ->setRowTotalInclTax($itemDataObj->getData('row_total_incl_tax'))
                ->setBaseRowTotalInclTax($itemDataObj->getData('base_row_total_incl_tax'))
                ->setTaxAmount($itemDataObj->getData('tax_amount'))
                ->setBaseTaxAmount($itemDataObj->getData('base_tax_amount'))
                ->setTaxPercent($itemDataObj->getData('tax_percent'))
                ->setDiscountAmount($itemDataObj->getData('discount_amount'))
                ->setBaseDiscountAmount($itemDataObj->getData('base_discount_amount'))
                ->setWeight($itemDataObj->getData('weight'))
                ->setAppliedRuleIds($itemDataObj->getData('applied_rule_ids'))
                ->setStoreId($storeId);

            $order->addItem($magentoItem);

            // Add child item for configurable products
            if ($itemDataObj->getData('product_type') === 'configurable' && $itemDataObj->getData('configurable_child')) {
                $child = $itemDataObj->getData('configurable_child');
                $childItem = $objectManager->create(OrderItem::class);
                $childItem->setProductId($child['product_id'])
                    ->setProductType('simple')
                    ->setName($child['name'])
                    ->setSku($child['sku'])
                    ->setQtyOrdered($itemDataObj->getData('qty_ordered'))
                    ->setPrice(0)
                    ->setBasePrice(0)
                    ->setRowTotal(0)
                    ->setBaseRowTotal(0)
                    ->setTaxAmount(0)
                    ->setBaseTaxAmount(0)
                    ->setWeight(1.0)
                    ->setStoreId($storeId)
                    ->setParentItem($magentoItem);
                $order->addItem($childItem);
            }
        }

        // Save order
        $this->orderRepository->save($order);
        $orderId = (int)$order->getEntityId();

        // Insert tax records
        foreach ($orderItems as $itemDataObj) {
            $this->insertTaxRecords($order, $itemDataObj);
        }
        if ($shippingTax > 0) {
            $this->insertShippingTaxRecord($order, $shippingTax, $shippingTaxPct);
        }

        // Apply lifecycle
        $this->applyLifecycle($order, $scenario, $output);

        $this->created++;
        $output->writeln('<info>OK</info> (order #' . $order->getIncrementId() . ', id:' . $orderId . ')');
    }

    private function applyLifecycle(Order $order, array $scenario, OutputInterface $output): void
    {
        $lifecycle = $scenario['lifecycle'];
        $orderId = (int)$order->getEntityId();

        if ($lifecycle === 'pending') {
            return;
        }

        if ($lifecycle === 'cancelled') {
            $order->setState(Order::STATE_CANCELED)
                ->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($order);
            return;
        }

        // Invoice (for all non-pending, non-cancelled states)
        try {
            $this->invoiceOrder->execute($orderId);
            // Reload order
            $order = $this->orderRepository->get($orderId);
            $order->setTotalPaid($order->getGrandTotal())
                ->setBaseTotalPaid($order->getBaseGrandTotal());
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            // Ignore invoice errors for test data
        }

        if ($lifecycle === 'invoiced') {
            return;
        }

        // Ship
        if (in_array($lifecycle, ['shipped', 'complete', 'refunded', 'partially_refunded'])) {
            try {
                $this->shipOrder->execute($orderId);
            } catch (\Exception $e) {
                // Ignore shipping errors
            }
        }

        if ($lifecycle === 'shipped') {
            return;
        }

        // Refund
        if ($lifecycle === 'refunded') {
            try {
                $order = $this->orderRepository->get($orderId);
                $creditmemo = $this->creditmemoFactory->createByOrder($order);
                $this->creditmemoManagement->refund($creditmemo);
            } catch (\Throwable $e) {
                // Mark as refunded via DB update as fallback
                $order = $this->orderRepository->get($orderId);
                $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED)
                    ->setTotalRefunded($order->getGrandTotal());
                $this->orderRepository->save($order);
            }
            return;
        }

        if ($lifecycle === 'partially_refunded') {
            try {
                $order = $this->orderRepository->get($orderId);
                $refundQtys = $scenario['refund_qty'] ?? [];
                $creditmemoItems = [];

                foreach ($order->getAllItems() as $idx => $item) {
                    if ($item->getParentItemId()) {
                        continue;
                    }
                    $creditmemoItems[$item->getId()] = ['qty' => $refundQtys[$idx] ?? 0];
                }

                $creditmemo = $this->creditmemoFactory->createByOrder($order, [
                    'qtys' => $creditmemoItems,
                    'shipping_amount' => 0,
                    'adjustment_positive' => 0,
                    'adjustment_negative' => 0,
                ]);
                $this->creditmemoManagement->refund($creditmemo);
            } catch (\Throwable $e) {
                // Mark as partially refunded via DB as fallback
                $order = $this->orderRepository->get($orderId);
                $refundAmount = $order->getGrandTotal() * 0.5;
                $order->setTotalRefunded($refundAmount);
                $this->orderRepository->save($order);
            }
            return;
        }

        // Complete
        if ($lifecycle === 'complete') {
            $order = $this->orderRepository->get($orderId);
            $order->setState(Order::STATE_COMPLETE)
                ->setStatus(Order::STATE_COMPLETE);
            $this->orderRepository->save($order);
        }
    }

    private function insertTaxRecords(Order $order, \Magento\Framework\DataObject $itemData): void
    {
        $taxPct = $itemData->getData('tax_percent');
        $taxAmount = $itemData->getData('tax_amount');
        $taxTitle = $itemData->getData('tax_title') ?? 'DE - USt.';

        if ($taxAmount <= 0) {
            return;
        }

        // Insert into sales_order_tax
        $taxTable = $this->connection->getTableName('sales_order_tax');
        $this->connection->insert($taxTable, [
            'order_id' => $order->getEntityId(),
            'code' => $taxTitle,
            'title' => $taxTitle,
            'percent' => $taxPct,
            'amount' => $taxAmount,
            'base_amount' => $taxAmount,
            'base_real_amount' => $taxAmount,
            'priority' => 0,
            'position' => 0,
            'process' => 0,
        ]);
        $taxId = (int)$this->connection->lastInsertId($taxTable);

        // Find the item ID
        $itemId = null;
        foreach ($order->getAllItems() as $orderItem) {
            if ($orderItem->getSku() === $itemData->getData('sku') && !$orderItem->getParentItemId()) {
                $itemId = $orderItem->getId();
                break;
            }
        }

        if ($itemId) {
            $taxItemTable = $this->connection->getTableName('sales_order_tax_item');
            $this->connection->insert($taxItemTable, [
                'tax_id' => $taxId,
                'item_id' => $itemId,
                'tax_percent' => $taxPct,
                'amount' => $taxAmount,
                'base_amount' => $taxAmount,
                'real_amount' => $taxAmount,
                'real_base_amount' => $taxAmount,
                'associated_item_id' => null,
                'taxable_item_type' => 'product',
            ]);
        }
    }

    private function insertShippingTaxRecord(Order $order, float $shippingTax, float $shippingTaxPct): void
    {
        $taxTable = $this->connection->getTableName('sales_order_tax');
        // Check if a tax record with same title already exists for this order
        $existingTaxId = $this->connection->fetchOne(
            $this->connection->select()
                ->from($taxTable, ['tax_id'])
                ->where('order_id = ?', $order->getEntityId())
                ->where('percent = ?', $shippingTaxPct)
                ->limit(1)
        );

        if ($existingTaxId) {
            // Update existing tax record to add shipping tax
            $this->connection->update($taxTable, [
                'amount' => new \Zend_Db_Expr('amount + ' . $shippingTax),
                'base_amount' => new \Zend_Db_Expr('base_amount + ' . $shippingTax),
                'base_real_amount' => new \Zend_Db_Expr('base_real_amount + ' . $shippingTax),
            ], ['tax_id = ?' => $existingTaxId]);
            $taxId = (int)$existingTaxId;
        } else {
            $this->connection->insert($taxTable, [
                'order_id' => $order->getEntityId(),
                'code' => 'Shipping Tax',
                'title' => 'Shipping Tax',
                'percent' => $shippingTaxPct,
                'amount' => $shippingTax,
                'base_amount' => $shippingTax,
                'base_real_amount' => $shippingTax,
                'priority' => 0,
                'position' => 0,
                'process' => 0,
            ]);
            $taxId = (int)$this->connection->lastInsertId($taxTable);
        }

        $taxItemTable = $this->connection->getTableName('sales_order_tax_item');
        $this->connection->insert($taxItemTable, [
            'tax_id' => $taxId,
            'item_id' => null,
            'tax_percent' => $shippingTaxPct,
            'amount' => $shippingTax,
            'base_amount' => $shippingTax,
            'real_amount' => $shippingTax,
            'real_base_amount' => $shippingTax,
            'associated_item_id' => null,
            'taxable_item_type' => 'shipping',
        ]);
    }

    // ================================================================
    // Helper methods for building item and customer data
    // ================================================================

    private function simpleItem(string $sku, float $price, int $qty, float $taxPct, string $taxTitle = 'DE - USt.'): array
    {
        // Look up the product by SKU to get its entity_id
        $productId = $this->connection->fetchOne(
            $this->connection->select()
                ->from($this->connection->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', $sku)
                ->limit(1)
        );

        return [
            'type' => 'simple',
            'product_id' => (int)$productId ?: 1,
            'name' => 'Test ' . $sku,
            'sku' => $sku,
            'price' => $price,
            'qty' => $qty,
            'tax_pct' => $taxPct,
            'tax_title' => $taxTitle,
        ];
    }

    private function configurableItem(int $configurableId, string $name, float $price, int $qty, float $taxPct): array
    {
        // Find a child simple product
        $childId = $this->connection->fetchOne(
            $this->connection->select()
                ->from($this->connection->getTableName('catalog_product_super_link'), ['product_id'])
                ->where('parent_id = ?', $configurableId)
                ->limit(1)
        );

        $childSku = '';
        $childName = '';
        if ($childId) {
            $childRow = $this->connection->fetchRow(
                $this->connection->select()
                    ->from($this->connection->getTableName('catalog_product_entity'), ['sku'])
                    ->where('entity_id = ?', $childId)
            );
            $childSku = $childRow['sku'] ?? $name . ' - variant';
            $childName = $name . ' - variant';
        }

        return [
            'type' => 'configurable',
            'product_id' => $configurableId,
            'name' => $name,
            'sku' => $childSku ?: $name,
            'price' => $price,
            'qty' => $qty,
            'tax_pct' => $taxPct,
            'tax_title' => 'DE - USt.',
            'child' => [
                'product_id' => (int)$childId ?: $configurableId + 1,
                'name' => $childName,
                'sku' => $childSku,
            ],
        ];
    }

    private function deCustomer(): array
    {
        return [
            'is_guest' => false,
            'email' => 'test-de-' . mt_rand(1000, 9999) . '@example.com',
            'address' => [
                'firstname' => 'Max',
                'lastname' => 'Mustermann',
                'street' => 'Musterstraße 42',
                'city' => 'Berlin',
                'postcode' => '10115',
                'country_id' => 'DE',
                'region' => 'Berlin',
                'telephone' => '+49301234567',
                'email' => 'test-de@example.com',
            ],
        ];
    }

    private function guestCustomer(string $countryCode): array
    {
        $configs = [
            'DE' => ['firstname' => 'Anna', 'lastname' => 'Gast', 'city' => 'München', 'postcode' => '80331', 'street' => 'Gästeweg 1'],
            'FR' => ['firstname' => 'Marie', 'lastname' => 'Dupont', 'city' => 'Paris', 'postcode' => '75001', 'street' => '12 Rue de Rivoli'],
            'SE' => ['firstname' => 'Erik', 'lastname' => 'Svensson', 'city' => 'Stockholm', 'postcode' => '11120', 'street' => 'Drottninggatan 5'],
            'CH' => ['firstname' => 'Hans', 'lastname' => 'Meier', 'city' => 'Zürich', 'postcode' => '8001', 'street' => 'Bahnhofstrasse 10'],
        ];

        $c = $configs[$countryCode] ?? $configs['DE'];

        return [
            'is_guest' => true,
            'email' => 'guest-' . strtolower($countryCode) . '-' . mt_rand(1000, 9999) . '@example.com',
            'address' => array_merge($c, [
                'country_id' => $countryCode,
                'region' => $c['city'],
                'telephone' => '+00123456789',
                'email' => 'guest-' . strtolower($countryCode) . '@example.com',
            ]),
        ];
    }
}
