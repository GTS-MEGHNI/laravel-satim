<?php

declare(strict_types=1);

use GtsMeghni\Satim\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

// RefreshDatabase everywhere, not only in the audit tests: the package migration
// is loaded for every test, and against a real server a table left behind would
// break the next run.
uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);

const REGISTER_URL = 'https://test2.satim.dz/payment/rest/register.do';

const ACKNOWLEDGE_URL = 'https://test2.satim.dz/payment/rest/public/acknowledgeTransaction.do';

const REFUND_URL = 'https://test2.satim.dz/payment/rest/refund.do';

const ORDER_ID = '6bbnSxXAZJ4eDAAAABHE';

/**
 * A full accepted acknowledge response, as documented by SATIM.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function acknowledgeResponse(array $overrides = []): array
{
    return array_merge([
        'expiration' => '202701',
        'cardholderName' => 'cardholder Name',
        'depositAmount' => 100320,
        'currency' => '012',
        'authorizationResponseId' => '913180',
        'approvalCode' => '913180',
        'actionCode' => 0,
        'actionCodeDescription' => 'Votre paiement a été accepté',
        'ErrorCode' => '0',
        'ErrorMessage' => 'Success',
        'OrderStatus' => 2,
        'OrderNumber' => 'CMD0000004',
        'Pan' => '6280****7215',
        'Amount' => 100320,
        'Ip' => '10.12.12.14',
        'params' => [
            'respCode_desc' => 'Votre paiement a été accepté',
            'udf1' => 'Bill00001',
            'respCode' => '00',
        ],
        'SvfeResponse' => '00',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $response
 */
function fakeAcknowledge(array $response): void
{
    Http::fake([ACKNOWLEDGE_URL => Http::response($response)]);
}
