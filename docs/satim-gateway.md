# SATIM Gateway Reference

What the SATIM integration guide specifies, kept here so the package's behavior can be checked
against it without hunting for the original document.

Credentials, merchant data, and the certification test cards are deliberately **not** reproduced
here. Those come from SATIM with your merchant registration.

## Payment Flow

1. The customer places an order on the merchant site.
2. The merchant registers it with the gateway (`register.do`).
3. The gateway answers with a unique order identifier and the URL of the payment form.
4. The merchant site redirects the customer to that URL.
5. The customer enters their CIB card details.
6. The gateway redirects the customer to the return URL given at registration.
7. The merchant asks the gateway for the outcome (`acknowledgeTransaction.do`).
8. The merchant shows the result.

The customer has 20 minutes on the payment page to submit their card details before the session
expires.

## Endpoints

Base URL for the test gateway: `https://test2.satim.dz/payment/rest`

| Action | Path | Parameters |
| --- | --- | --- |
| Register an order | `register.do` | `userName`, `password`, `orderNumber`, `amount`, `currency`, `returnUrl`, `failUrl`, `description`, `language`, `jsonParams` |
| Confirm an order | `public/acknowledgeTransaction.do` | `userName`, `password`, `mdOrder`, `language` |
| Refund an order | `refund.do` | `userName`, `password`, `orderId`, `language`, `amount`, `currency` |

The guide documents these as reachable over `GET`, and then strongly recommends `POST` for every
API request, so credentials and transaction details never land in URLs, logs, or browser history.
This package always uses `POST`, form encoded.

## Register

### Request

| Name | Type | Mandatory | Notes |
| --- | --- | --- | --- |
| `userName` | AN..30 | yes | Merchant login from registration. |
| `password` | AN..30 | yes | Merchant password from registration. |
| `orderNumber` | AN..10 | yes | Order identifier in the merchant system, unique per transaction. |
| `amount` | N 1..12 | yes | In centimes. Minimum 50 DA, so 5000. 5000 DA is sent as `500000`, 806.5 DA as `80650`. |
| `currency` | N3 | yes | ISO 4217 numeric. `012` for DZD. |
| `returnUrl` | AN..512 | yes | Where the customer goes after a successful payment. |
| `failUrl` | AN..512 | yes | Where the customer goes if the payment failed. |
| `description` | AN..512 | no | Free text. |
| `language` | A2 | yes | ISO 639-1: AR, FR, or EN. |
| `jsonParams` | AN..1024 | yes | Additional fields, see below. |

`jsonParams` contents:

| Field | Format | Mandatory | Notes |
| --- | --- | --- | --- |
| `force_terminal_id` | AN..16 | yes | Terminal id assigned by the bank. One merchant may have several. |
| `udf1` | AN..20 | yes | Merchant reference: invoice number, order number, and so on. |
| `udf2`–`udf5` | AN..20 | no | Up to four further merchant values. |
| `fundingTypeIndicator` | string | no | Transaction type indicator, currently `CP` or `698` (bill payment). |

### Response

| Name | Type | Notes |
| --- | --- | --- |
| `errorCode` | N3 | `0` when the order was registered. |
| `orderId` | ANS20 | Unique gateway identifier, the same value as `mdOrder` later. Absent when registration failed. |
| `formUrl` | AN..512 | Payment page to redirect the customer to. Absent when registration failed. |

### Error codes

| Code | Meaning |
| --- | --- |
| 0 | No system error. |
| 1 | Order number already processed, or the `childId` is incorrect; or the order number was registered but not paid; or the submerchant is blocked or deleted. |
| 3 | Unknown currency. |
| 4 | A mandatory parameter is missing: order number, merchant user name, amount, return URL, or password. |
| 5 | Incorrect value of a request parameter; incorrect language; access denied; the merchant must change the password; invalid `jsonParams[]`. |
| 7 | System error. |
| 14 | Payment way is invalid. |

## Acknowledge

### Request

| Name | Type | Mandatory | Notes |
| --- | --- | --- | --- |
| `userName` | AN..30 | yes | Merchant login. |
| `password` | AN..30 | yes | Merchant password. |
| `mdOrder` | ANS20 | yes | The `orderId` register returned. |
| `language` | A2 | yes | AR, FR, or EN. |

### Response

| Name | Type | Notes |
| --- | --- | --- |
| `ErrorCode` | N3 | Error code, see below. |
| `ErrorMessage` | AN..512 | Description in the requested language. |
| `OrderStatus` | N1 | Order state, see below. Absent when no order matches the id. |
| `OrderNumber` | AN..20 | The merchant order number from registration. |
| `Amount` | N..20 | In centimes, as at registration. |
| `depositAmount` | N5 | Amount debited in the order currency. |
| `currency` | N3 | ISO 4217 numeric. |
| `actionCode` | N3 | Processing system authorization code. |
| `actionCodeDescription` | AN..512 | Its description, in the requested language. |
| `approvalCode` | AN6 | IPS authorization code, six characters. |
| `authorizationResponseId` | AN..6 | Payment network authorization code. Replaces the deprecated `approvalCode` name. |
| `Pan` | N..19 | Masked card number. Paid orders only. |
| `expiration` | N6 | Card expiry as YYYYMM. Paid orders only. |
| `cardholderName` | AN..20 | Latin letters, digits, `$ ) ( - .` and space; starts with a letter; 2 to 26 characters; may be null. |
| `Ip` | AN..20 | Customer IP address. |
| `Description` | ANS..512 | Order description, omitted when it was null. |
| `clientId`, `bindingId`, `paymentAccountReference` | | Present only for merchants using bindings or when the host is configured to send them. |

The response also carries a nested `params` object holding `respCode`, `respCode_desc`, and the
`udf` values sent at registration.

### Error codes

| Code | Meaning |
| --- | --- |
| 0 | Success. |
| 2 | The order was declined because of an error in the payment credentials. |
| 5 | Access denied; the user must change the password; `orderId` is empty. |
| 6 | Unregistered order id. |
| 7 | System error. |

### Order status values

| Status | Meaning |
| --- | --- |
| -1 | Replaces the decline status when none of the listed statuses fits the transaction. |
| 0 | Order registered, but not paid. |
| 1 | Transaction approved (one-phase), or preauthorization amount held (two-phase). |
| 2 | Amount deposited successfully. |
| 3 | Authorization reversed. |
| 4 | Transaction refunded. |
| 6 | Authorization declined. |

## Refund

### Request

| Name | Type | Mandatory | Notes |
| --- | --- | --- | --- |
| `userName` | AN..30 | yes | Merchant login. |
| `password` | AN..30 | yes | Merchant password. |
| `orderId` | ANS20 | yes | The `orderId` register returned, also called `mdOrder`. |
| `amount` | N..20 | yes | In centimes, same rule as register. |

### Response

| Name | Type | Notes |
| --- | --- | --- |
| `errorCode` | N3 | `0` when the money was returned. |
| `errorMessage` | AN..512 | Success message or error description. |

### Error codes

| Code | Meaning |
| --- | --- |
| 0 | No system error. |
| 5 | Access denied; the user must change the password; invalid amount; the deposit amount must be zero or more than one currency unit; a refund with this `externalRefundId` already exists for the merchant. |
| 6 | Unregistered order id. |
| 7 | System error; the payment must be in a correct state. |

## Reading These Codes in the Package

`SatimErrorCode` names every code above, and `description()` takes the operation, because the same
number means different things on different endpoints. `RegisterResult::error()`,
`AcknowledgeResult::error()`, and `RefundResult::error()` return it, or null for a code outside this
list, with the raw value still on `errorCode`.

A code of `0` on acknowledge does not mean the customer paid. That verdict needs `respCode` `00`,
`ErrorCode` `0`, and `OrderStatus` `2` together, which is what `AcknowledgeResult::paid()` checks.
