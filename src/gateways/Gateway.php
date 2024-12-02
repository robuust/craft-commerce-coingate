<?php

namespace robuust\coingate\gateways;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\App;
use craft\web\Response;
use Omnipay\CoinGate\Gateway as OmnipayGateway;
use Omnipay\CoinGate\Message\PurchaseStatusRequest;
use Omnipay\CoinGate\Message\PurchaseStatusResponse;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\RequestInterface;

/**
 * Coingate gateway.
 */
class Gateway extends OffsiteGateway
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $apiKey;

    /**
     * @var bool
     */
    public $testMode;

    // Public Methods
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public static function displayName(): string
    {
        return \Craft::t('commerce', 'Coingate');
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => \Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSettingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate('commerce-coingate/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * {@inheritdoc}
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $request = $this->createRequest($transaction);
        $request['transactionReference'] = $transaction->reference;
        $completeRequest = $this->prepareCompletePurchaseRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * @return Response
     *
     * @throws \Throwable
     * @throws CurrencyException
     * @throws OrderStatusException
     * @throws TransactionException
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function processWebHook(): Response
    {
        $response = \Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            \Craft::warning('Transaction with the hash “'.$transactionHash.'“ not found.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction) {
            \Craft::warning('Successful child transaction for “'.$transactionHash.'“ already exists.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $id = \Craft::$app->getRequest()->getBodyParam('id');
        /** @var OmnipayGateway */
        $gateway = $this->createGateway();
        /** @var PurchaseStatusRequest $request */
        $request = $gateway->getPurchaseStatus(['transactionReference' => $id]);
        /** @var PurchaseStatusResponse $res */
        $res = $request->send();

        if (!$res->isSuccessful()) {
            \Craft::warning('CoinGate request was unsuccessful.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        if ($res->isPaid()) {
            $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
        } elseif ($res->isCancelled()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } elseif ('failed' === $res->getOrderStatus()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else {
            $response->data = 'ok';

            return $response;
        }

        $childTransaction->response = $res->getData();
        $childTransaction->code = $res->getTransactionId();
        $childTransaction->reference = $res->getTransactionReference();
        $childTransaction->message = $res->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->data = 'ok';

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionHashFromWebhook(): ?string
    {
        return \Craft::$app->getRequest()->getParam('commerceTransactionHash');
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['paymentType', 'compare', 'compareValue' => 'purchase'];

        return $rules;
    }

    // Protected Methods
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiKey(App::parseEnv($this->apiKey));
        $gateway->setTestMode(App::parseBooleanEnv($this->testMode));

        // Receive as primary payment currency
        $currency = Commerce::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso();
        $gateway->setReceiveCurrency($currency);

        return $gateway;
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\'.OmnipayGateway::class;
    }

    /**
     * Prepare a complete purchase request from request data.
     *
     * @param mixed $request
     *
     * @return RequestInterface
     */
    protected function prepareCompletePurchaseRequest(mixed $request): RequestInterface
    {
        /** @var OmnipayGateway */
        $gateway = $this->gateway();

        return $gateway->getPurchaseStatus($request);
    }
}
