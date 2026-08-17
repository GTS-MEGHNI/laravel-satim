<?php

declare(strict_types=1);

return [
    /*
     * Wording required by the SATIM integration guide when an authorisation is
     * reversed: the gateway reports no error of its own for that case.
     */
    'transaction_rejected' => 'Your transaction was rejected',
    /*
     * Hotline text required by SATIM. It must always be shown together with the
     * SATIM logo, never on its own.
     */
    'support_message' => 'If you have a payment issue, please contact the SATIM toll-free number 3020',
];
