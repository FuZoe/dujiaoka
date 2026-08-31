<?php

namespace App\Jobs;

/**
 * Sends the first sold-out alert for a product's current stock cycle.
 */
class EmailOutOfStockNotification extends EmailStockNotification
{
    public function __construct(
        int $goodsId,
        string $recipient,
        string $title,
        string $content
    ) {
        parent::__construct(
            'out-of-stock:goods:'.$goodsId,
            $goodsId,
            $recipient,
            $title,
            $content,
            'is_open_email_out_of_stock',
            self::TYPE_OUT_OF_STOCK
        );
    }
}
