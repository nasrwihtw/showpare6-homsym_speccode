<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Rule;

use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Content\Flow\Rule\FlowRuleScope;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

/**
 * @deprecated tag:v6.7.0 - reason:becomes-final - Will become final in v6.7.0
 */
#[Package('fundamentals@after-sales')]
class AffiliateCodeOfOrderRule extends Rule
{
    final public const RULE_NAME = 'orderAffiliateCode';

    /**
     * @internal
     */
    public function __construct(
        protected string $operator = self::OPERATOR_EQ,
        protected ?string $affiliateCode = null
    ) {
        parent::__construct();
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof FlowRuleScope) {
            return false;
        }
        if (!$this->affiliateCode && $this->operator !== self::OPERATOR_EMPTY) {
            throw CartException::unsupportedValue(\gettype($this->affiliateCode), self::class);
        }

        if (!$affiliateCode = $scope->getOrder()->getAffiliateCode()) {
            return RuleComparison::isNegativeOperator($this->operator);
        }

        return RuleComparison::string($affiliateCode, $this->affiliateCode ?? '', $this->operator);
    }

    public function getConstraints(): array
    {
        $constraints = [
            'operator' => RuleConstraints::stringOperators(true),
        ];

        if ($this->operator === self::OPERATOR_EMPTY) {
            return $constraints;
        }

        $constraints['affiliateCode'] = RuleConstraints::string();

        return $constraints;
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_STRING, true)
            ->stringField('affiliateCode');
    }
}
