<?php
namespace PHPCommerce\Vendor\RatePAY\Service\Payment;

use PHPCommerce\Vendor\RatePAY\Service\Payment\Exception\RatePAYException;
use PHPCommerce\Vendor\RatePAY\Service\Payment\Exception\RejectionException;
use PHPCommerce\Vendor\RatePAY\Service\Payment\Exception\TechnicalErrorException;
use PHPCommerce\Vendor\RatePAY\Service\Payment\Exception\WarningException;
use PHPCommerce\Vendor\RatePAY\Service\Payment\Type\OperationType;
use PHPCommerce\Vendor\RatePAY\Service\Payment\Type\Request\RequestType;
use PHPCommerce\Vendor\RatePAY\Service\Payment\Type\Response\ResponseType;

class RatepayBrokerImpl implements RatepayBrokerInterface {

    /**
     * @var RatepayConfiguration
     */
    protected $ratepayConfiguration;

    /**
     * @var GatewayClientInterface
     */
    protected $gatewayClient;

    /**
     * @var RequestBuilder
     */
    protected $requestBuilder;

    public function __construct(RatepayConfiguration $ratepayConfiguration, GatewayClientInterface $gatewayClient) {
        $this->ratepayConfiguration     = $ratepayConfiguration;
        $this->gatewayClient            = $gatewayClient;
        $this->requestBuilder           = new RequestBuilder($ratepayConfiguration);
    }

    /**
     * Scan the response for the expected success & error codes. Throw an exception if an error is detected.
     * @param ResponseType $res
     * @param $successCode
     * @param $rejectionCode
     * @param $technicalErrorCode
     * @param $warningCode
     * @throws RejectionException
     * @throws TechnicalErrorException
     * @throws WarningException
     * @throws RatePAYException
     */
    protected function validateResponse(ResponseType $res, $successCode, $rejectionCode, $technicalErrorCode, $warningCode) {
        $processing             = $res->getHead()->getProcessing();
        $resultDescription      = $processing->getResult()->getDescription();
        $resultCode             = $processing->getResult()->getCode();

        $reasonDescription      = $processing->getReason()->getDescription();
        $reasonCode             = $processing->getReason()->getCode();

        $customerMessage        = $processing->getCustomerMessage();

        $exception              = null;

        if(null !== $rejectionCode && (int)$resultCode === (int)$rejectionCode) {
            $exception = new RejectionException($resultDescription, $resultCode);

        } elseif(null !== $technicalErrorCode && (int)$resultCode === (int)$technicalErrorCode) {
            $exception = new TechnicalErrorException($resultDescription, $resultCode);

        } elseif(null !== $warningCode && (int)$resultCode === (int)$warningCode) {
            $exception = new WarningException($resultDescription, $resultCode);

        } elseif(null !== $successCode && (int)$resultCode !== (int)$successCode) {
            $exception = new RatePAYException($resultDescription, $resultCode);

        }

        if(null !== $exception) {
            $exception
                ->setReasonCode($reasonCode)
                ->setReasonDescription($reasonDescription)
                ->setCustomerMessage($customerMessage);

            throw $exception;
        }
    }

    public function paymentInit()
    {
        $req = $this->requestBuilder
            ->operation(OperationType::OPERATION_PAYMENT_INIT)
            ->build();

        $res = $this->gatewayClient->postRequest($req);

        $this->validateResponse($res, 350, NULL, 150, NULL);

        return $res->getHead()->getTransactionId();
    }

    public function paymentRequest($transactionId, RequestType $req)
    {
        $req->getHead()->setTransactionId($transactionId);
        $req->getHead()->getOperation()->setValue(OperationType::OPERATION_PAYMENT_REQUEST);

        $res = $this->gatewayClient->postRequest($req);

        $this->validateResponse($res, 402, 401, 150, 405);

        return $res;
    }

    public function paymentConfirm($transactionId)
    {
        $req = $this->requestBuilder
            ->operation(OperationType::OPERATION_PAYMENT_CONFIRM)
            ->transactionId($transactionId)
            ->build();

        $res = $this->gatewayClient->postRequest($req);

        $this->validateResponse($res, 400, 401, 150, 405);
    }

    public function paymentChange($transactionId, $subtype, RequestType $req) {
        $allowedSubTypes = [
            OperationType::OPERATION_SUBTYPE_CANCELLATION,
            OperationType::OPERATION_SUBTYPE_RETURN,
            OperationType::OPERATION_SUBTYPE_CREDIT,
            OperationType::OPERATION_SUBTYPE_CHANGE_ORDER
        ];

        if(!in_array($subtype, $allowedSubTypes)) {
            throw new \RuntimeException("type must be one of ".implode(",", $allowedSubTypes));
        }

        $req->getHead()->setTransactionId($transactionId);
        $req->getHead()->getOperation()->setValue(OperationType::OPERATION_PAYMENT_CHANGE);
        $req->getHead()->getOperation()->setSubtype($subtype);

        $res = $this->gatewayClient->postRequest($req);

        $this->validateResponse($res, 403, 401, 150, 405);

        return $res;

    }

    public function configurationRequest() {
        $req = $this->requestBuilder
            ->operation(OperationType::OPERATION_CONFIGURATION_REQUEST)
            ->build();

        $res = $this->gatewayClient->postRequest($req);

        $this->validateResponse($res, 500, null, 150, null);

        return $res;
    }

    /**
     * @return RequestBuilder
     */
    public function getRequestBuilder()
    {
        return $this->requestBuilder;
    }

    /**
     * @param $transactionId
     * @param $subType
     * @param RequestType $req
     * @throws RatePAYException
     * @throws TechnicalErrorException
     * @throws WarningException
     * @throws RejectionException
     * @return ResponseType
     */
    public function confirmationDeliver($transactionId, RequestType $req)
    {
        $req->getHead()->setTransactionId($transactionId);
        $req->getHead()->getOperation()->setValue(OperationType::OPERATION_CONFIRMATION_DELIVER);

        $res = $this->gatewayClient->postRequest($req);

        $this->validateResponse($res, 404, 401, 150, 405);

        return $res;
    }
}