<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\Rule;

use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

/**
 * @deprecated tag:v6.7.0 - reason:becomes-final - Will become final in v6.7.0
 */
#[Package('fundamentals@after-sales')]
class CustomerCreatedByAdminRule extends Rule
{
    final public const RULE_NAME = 'customerCreatedByAdmin';

    /**
     * @internal
     */
    public function __construct(protected bool $shouldCustomerBeCreatedByAdmin = true)
    {
        parent::__construct();
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CheckoutRuleScope) {
            return false;
        }

        if (!$customer = $scope->getSalesChannelContext()->getCustomer()) {
            return false;
        }

        return $this->shouldCustomerBeCreatedByAdmin === (bool) $customer->getCreatedById();
    }

    public function getConstraints(): array
    {
        return [
            'shouldCustomerBeCreatedByAdmin' => RuleConstraints::bool(true),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->booleanField('shouldCustomerBeCreatedByAdmin');
    }
}
