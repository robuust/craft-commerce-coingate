<?php

namespace robuust\coingate\gateways;

use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\helpers\App;
use Omnipay\CoinGate\Gateway as OmnipayGateway;
use Omnipay\Common\AbstractGateway;

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
    public function populateRequest(array &$request, ?BasePaymentForm $paymentForm = null): void
    {
        parent::populateRequest($request, $paymentForm);
        $request['callback_url'] = $request['notifyUrl'];
        $request['success_url'] = $request['returnUrl'];
        $request['cancel_url'] = $request['cancelUrl'];
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
     * {@inheritdoc}
     */
    protected function createRequest(Transaction $transaction, ?BasePaymentForm $form = null): mixed
    {
        $request = parent::createRequest($transaction, $form);
        $request['code'] = $transaction->code;

        return $request;
    }
}
