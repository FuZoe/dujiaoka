<?php

namespace App\Jobs;

/**
 * Backwards-compatible job name for the optional email sent after a restock
 * import. The common implementation lives in EmailStockNotification.
 */
class EmailRestockNotification extends EmailStockNotification
{
    public function __construct(
        string $batchId,
        int $goodsId,
        string $recipient,
        string $title,
        string $content
    ) {
        parent::__construct(
            'restock:'.$batchId,
            $goodsId,
            $recipient,
            $title,
            $content,
            'is_open_email_restock',
            self::TYPE_RESTOCK
        );
    }

    public function batchId(): string
    {
        $prefix = 'restock:';

        return substr($this->eventKey(), strlen($prefix));
    }
}
