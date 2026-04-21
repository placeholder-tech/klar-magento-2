<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Api\Data;

interface ApiInterface
{
    public const ORDERS_STATUS_PATH = '/orders/status';
    public const ORDERS_VALIDATE_PATH = '/orders/validate';
    public const ORDERS_JSON_PATH = '/orders/json';
    public const ORDER_STATUS_VALID = 'VALID';
    public const ORDER_STATUS_INVALID = 'INVALID';
    public const ORDER_STATUS_PARTIALLY_VALID = 'PARTIALLY_VALID';
    public const BATCH_SIZE = 250;

    /**
     * Get Klar orders status.
     *
     * @return array
     */
    public function getStatus(): array;

    /**
     * Sends to Klar.
     *
     * @param int[] $salesOrders
     *
     * @return int
     */
    public function send(array $ids): int;
}
