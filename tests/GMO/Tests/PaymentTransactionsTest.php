<?php
/**
 * This code is licensed under the MIT License.
 *
 * Copyright (c) 2015-2017 Alexey Kopytko
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace GMO\Tests;

use GMO\API\Call\SearchTrade;
use GMO\API\Errors;
use GMO\API\Response\SearchTradeResponse;

class PaymentTransactionsTransaction extends TestCase
{
    /** @var \GMO\PaymentTransactions */
    protected $payment;

    protected function setUp()
    {
        $this->assertArrayHasKey('SANDBOX_SHOP_ID', $_SERVER, 'SANDBOX_SHOP_ID must be defined in the environment');
        $this->assertArrayHasKey('SANDBOX_PASSWORD', $_SERVER, 'SANDBOX_PASSWORD must be defined in the environment');
        $this->assertArrayHasKey('SANDBOX_SHOP_NAME', $_SERVER, 'SANDBOX_SHOP_NAME must be defined in the environment');
    }

    /** @return \GMO\PaymentTransactions */
    private function getPayment()
    {
        // A wrapper object that does everything for you.
        $payment = new \GMO\PaymentTransactions();
        $payment->testShopId = $_SERVER['SANDBOX_SHOP_ID'];
        $payment->testShopPassword = $_SERVER['SANDBOX_PASSWORD'];
        $payment->testShopName = $_SERVER['SANDBOX_SHOP_NAME'];

        return $payment;
    }

    public function testExecuteWithError()
    {
        $payment = $this->getPayment();

        $payment->paymentId = 1; // ID is non-unique here on purpose
        $payment->amount = 4999;
        $payment->cardNumber = '4111111111111112';
        $payment->cardYear = '2020';
        $payment->cardMonth = '7';
        $payment->cardCode = '123';

        // Should fail because of non-unique payment ID.
        $this->assertFalse($payment->execute());

        $this->assertGreaterThan(0, $payment->getErrorCode());

        // Fetch error codes with descriptions.
        $errors = $payment->getErrors();
        $this->assertArrayHasKey(Errors::DUPLICATE_ORDER_ID, $errors);
        $this->assertEquals('This order ID was used previously.', $errors[Errors::DUPLICATE_ORDER_ID]);
    }

    public function testExecuteWithSuccess()
    {
        $payment = $this->getPayment();

        $payment->paymentId = time();
        $payment->amount = 4999;
        $payment->cardNumber = '4111111111111111';
        $payment->cardYear = date('Y') + 1;
        $payment->cardMonth = '7';
        $payment->cardCode = '123';

        if (!$result = $payment->execute()) {
            $this->assertArrayHasKey(Errors::NO_FULL_CARD_NUMBERS_ALLOWED, $payment->getErrors());
            $this->markTestIncomplete('Payment with a complete card number is not enabled for the test environment');
        }

        $this->assertTrue($result);
        $this->assertInstanceOf(\GMO\API\Response\ExecTranResponse::class, $payment->getResponse());

        // Now let's try to load transaction details.
        $searchMethod = new SearchTrade();
        $searchMethod->OrderID = $payment->getResponse()->OrderID;
        $payment->setupOther($searchMethod);

        $response = $searchMethod->dispatch();

        $this->assertInstanceOf(SearchTradeResponse::class, $response);
        $this->assertFalse($response->hasError());
    }

    public function testFailWithUnknownToken()
    {
        $payment = $this->getPayment();

        $payment->paymentId = time() + 1;
        $payment->amount = 4999;
        $payment->token = '123456';

        // Should fail because of invalid token.
        $this->assertFalse($payment->execute());

        $this->assertGreaterThan(0, $payment->getErrorCode());

        // Fetch error codes with descriptions.
        $errors = $payment->getErrors();

        $this->assertArrayHasKey('EX1000301', $errors);
        $this->assertEquals('An unknown error occurred.', $errors['EX1000301']);
    }
}
