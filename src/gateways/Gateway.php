<?php

namespace robuust\coingate\gateways;

use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin;
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
    public function supportsWebhooks(): bool
    {
        return true;
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
        $currency = Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso();
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
}
