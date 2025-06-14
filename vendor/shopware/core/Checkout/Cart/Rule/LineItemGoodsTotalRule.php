<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Rule;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Container\FilterRule;
use Shopware\Core\Framework\Rule\Exception\UnsupportedOperatorException;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

/**
 * @deprecated tag:v6.7.0 - reason:becomes-final - Will become final in v6.7.0
 */
#[Package('fundamentals@after-sales')]
class LineItemGoodsTotalRule extends FilterRule
{
    final public const RULE_NAME = 'cartLineItemGoodsTotal';

    protected int $count;

    /**
     * @internal
     */
    public function __construct(
        protected string $operator = self::OPERATOR_EQ,
        ?int $count = null
    ) {
        parent::__construct();
        $this->count = (int) $count;
    }

    /**
     * @throws UnsupportedOperatorException
     */
    public function match(RuleScope $scope): bool
    {
        if ($scope instanceof LineItemScope) {
            $lineItem = $scope->getLineItem()->isGood() ? $scope->getLineItem() : null;

            if ($lineItem === null) {
                return false;
            }

            if ($this->filter !== null && !$this->filter->match($scope)) {
                return false;
            }

            $quantityDefinition = $lineItem->getPriceDefinition();
            $total = $quantityDefinition instanceof QuantityPriceDefinition ? $quantityDefinition->getQuantity() : $lineItem->getQuantity();

            return RuleComparison::numeric($total, $this->count, $this->operator);
        }

        if (!$scope instanceof CartRuleScope) {
            return false;
        }

        $goods = new LineItemCollection($scope->getCart()->getLineItems()->filterGoodsFlat());
        $filter = $this->filter;
        if ($filter !== null) {
            $context = $scope->getSalesChannelContext();

            $goods = $goods->filter(static function (LineItem $lineItem) use ($filter, $context) {
                $scope = new LineItemScope($lineItem, $context);

                return $filter->match($scope);
            });
        }

        return RuleComparison::numeric($goods->getTotalQuantity(), $this->count, $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'count' => RuleConstraints::int(),
            'operator' => RuleConstraints::numericOperators(false),
        ];
    }
}
