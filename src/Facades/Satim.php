<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \GtsMeghni\Satim\Data\RegisterResult register(string $orderNumber, int|float|string $amount, ?string $description = null, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null, ?string $udf1 = null, ?string $udf2 = null, ?string $udf3 = null, ?string $udf4 = null, ?string $udf5 = null, ?string $fundingTypeIndicator = null)
 * @method static \GtsMeghni\Satim\Data\AcknowledgeResult acknowledge(string $orderId, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 * @method static \GtsMeghni\Satim\Data\RefundResult refund(string $orderId, int|float|string $amount, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 * @method static \GtsMeghni\Satim\Data\Receipt receipt(\GtsMeghni\Satim\Data\AcknowledgeResult $result, ?\DateTimeInterface $paidAt = null, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 *
 * @see \GtsMeghni\Satim\Satim
 */
class Satim extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \GtsMeghni\Satim\Satim::class;
    }
}
