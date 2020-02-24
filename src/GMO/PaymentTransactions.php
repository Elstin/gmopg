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

namespace GMO;

use GMO\API\Call\AlterTran;
use GMO\API\Call\EntryTran;
use GMO\API\Call\ExecTran;
use GMO\API\Call\Magic;
use GMO\API\Errors;
use GMO\API\MethodsSandbox;
use GMO\API\Response\AlterTranResponse;
use GMO\API\Response\EntryTranResponse;
use GMO\API\Response\ErrorResponse;
use GMO\API\Response\ExecTranResponse;

class PaymentTransactions
{
    /**
     * @var string
     */
    public $accessId;

    /**
     * @var string
     */
    public $accessPass;

    /**
     * Unique ID for the payment.
     *
     * @var int
     */
    public $paymentId;

    /**
     * Payment amount (Japanese yen only).
     *
     * @var int
     */
    public $amount;

    /**
     * Payment token.
     *
     * @var string
     */
    public $token;

    /**
     * Job Code
     * @var string
     */
    public $jobCode;

    private $errorShortCode;
    private $errorCode;

    /** @var EntryTran */
    private $entryTran;

    /** @var EntryTranResponse */
    private $entryTranResponse;

    /** @var ExecTran */
    private $execTran;

    /** @var ExecTranResponse */
    private $execTranResponse;

    /** @var AlterTran */
    private $alterTran;

    /** @var AlterTranResponse */
    private $alterTranResponse;

    // Test details
    public $testShopId;
    public $testShopPassword;
    public $testShopName;

    public function cancel()
    {
        $this->checkRequiredVars([
            'accessId',
            'accessPass',
            'jobCd'
        ]);

        $this->alterTran = new AlterTran();

        if ($this->testShopId) {
            // Use sandbox methods if requested
            $this->entryTran->setMethods(new MethodsSandbox());
            $this->entryTran->setShop($this->testShopId, $this->testShopPassword, $this->testShopName);
        }

        $this->alterTran->AccessID = $this->accessId;
        $this->alterTran->AccessPass = $this->accessPass;
        $this->alterTran->JobCd = $this->jobCode;
        $this->alterTranResponse = $this->alterTran->dispatch();

        if (!$this->verifyResponse($this->alterTranResponse)) {
            return false;
        }

        return true;
    }
    public function authorization()
    {
        $this->checkRequiredVars([
            'paymentId',
            'amount',
        ]);

        if (isset($this->token)) {
            $this->checkRequiredVars(['token']);
        } else {
            return false;
        }

        // Setup transaction details (password etc)
        $this->entryTran = new EntryTran();

        if ($this->testShopId) {
            // Use sandbox methods if requested
            $this->entryTran->setMethods(new MethodsSandbox());
            $this->entryTran->setShop($this->testShopId, $this->testShopPassword, $this->testShopName);
        }

        $this->entryTran->OrderID = $this->paymentId;
        $this->entryTran->Amount = $this->amount;
        $this->entryTranResponse = $this->entryTran->dispatch();

        if (!$this->verifyResponse($this->entryTranResponse)) {
            return false;
        }

        $this->execTran = new ExecTran();
        // configure this request using earlier request's data
        $this->entryTran->setupOther($this->execTran);
        // payment ID must be the same as before
        $this->execTran->OrderID = $this->paymentId;
        // copy the access keys for the transaction
        $this->execTran->setAccessID($this->entryTranResponse);
        // set payment token for the transaction
        $this->execTran->setToken($this->token);

        $this->execTranResponse = $this->execTran->dispatch();

        if (!$this->verifyResponse($this->execTranResponse)) {
            // @codeCoverageIgnoreStart
            return false; // this should never happen under normal circumstances
            // @codeCoverageIgnoreEnd
        }

        // verify the checksum
        if (!$this->execTran->verifyResponse($this->execTranResponse)) {
            // @codeCoverageIgnoreStart
            return false; // this should never happen under normal circumstances
            // @codeCoverageIgnoreEnd
        }

        return $this->execTranResponse;
    }

    /**
     * Returns payment details. These should be stored in the database for the future use.
     *
     * @return \GMO\API\Response\ExecTranResponse
     */
    public function getResponse()
    {
        return $this->execTranResponse;
    }

    /**
     * Array of error codes.
     *
     * @return array
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * Returns an array of maching error codes and descriptions.
     */
    public function getErrors()
    {
        $result = [];
        foreach ($this->errorCode as $code) {
            $result[$code] = Errors::getDescription($code);
        }

        return $result;
    }

    public function setupOther(Magic $method)
    {
        return $this->entryTran->setupOther($method);
    }

    private function verifyResponse($response)
    {
        if ($response instanceof ErrorResponse) {
            $this->errorShortCode = $response->ErrCode;
            $this->errorCode = $response->ErrInfo;

            return false;
        }

        return true;
    }

    private function checkRequiredVars($vars)
    {
        foreach ($vars as $requiredVar) {
            if (empty($this->{$requiredVar})) {
                throw new Exception("Missing $requiredVar");
            }
        }
    }
}
