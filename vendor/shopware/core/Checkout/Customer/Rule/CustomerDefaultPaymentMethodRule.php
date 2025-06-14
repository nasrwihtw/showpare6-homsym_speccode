<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\Rule;

use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Checkout\Payment\PaymentMethodDefinition;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

/**
 * @deprecated tag:v6.7.0 - will be removed
 */
/**
 * @deprecated tag:v6.7.0 - reason:becomes-final - Will become final in v6.7.0
 */
#[Package('fundamentals@after-sales')]
class CustomerDefaultPaymentMethodRule extends Rule
{
    public const RULE_NAME = 'customerDefaultPaymentMethod';

    /**
     * @internal
     *
     * @param list<string> $methodIds
     */
    public function __construct(
        public string $operator = Rule::OPERATOR_EQ,
        public ?array $methodIds = null
    ) {
        parent::__construct();
    }

    public function getConstraints(): array
    {
        Feature::triggerDeprecationOrThrow('v6.7.0.0', 'The default payment method of a customer will be removed.');

        return [
            'operator' => RuleConstraints::uuidOperators(false),
            'methodIds' => RuleConstraints::uuids(),
        ];
    }

    public function match(RuleScope $scope): bool
    {
        Feature::triggerDeprecationOrThrow('v6.7.0.0', 'The default payment method of a customer will be removed.');

        if (!$scope instanceof CheckoutRuleScope) {
            return false;
        }

        if (!$customer = $scope->getSalesChannelContext()->getCustomer()) {
            return RuleComparison::isNegativeOperator($this->operator);
        }

        return RuleComparison::uuids([$customer->getDefaultPaymentMethodId()], $this->methodIds, $this->operator);
    }

    public function getConfig(): RuleConfig
    {
        Feature::triggerDeprecationOrThrow('v6.7.0.0', 'The default payment method of a customer will be removed.');

        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_STRING, false, true)
            ->entitySelectField('methodIds', PaymentMethodDefinition::ENTITY_NAME, true);
    }
}
