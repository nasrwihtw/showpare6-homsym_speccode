<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Rule\Container;

use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Cart\Rule\LineItemScope;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\RuleScope;
use Symfony\Component\Validator\Constraints\Type;

/**
 * @deprecated tag:v6.7.0 - reason:becomes-final - Will become final in v6.7.0
 *
 * MatchAllLineItemsRule returns true, if all rules are true for all line items
 */
#[Package('fundamentals@after-sales')]
class MatchAllLineItemsRule extends Container
{
    final public const RULE_NAME = 'allLineItemsContainer';

    /**
     * @internal
     */
    public function __construct(
        array $rules = [],
        protected ?int $minimumShouldMatch = null,
        protected ?string $type = null
    ) {
        parent::__construct($rules);
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CartRuleScope && !$scope instanceof LineItemScope) {
            return false;
        }

        $lineItems = $scope instanceof LineItemScope ? new LineItemCollection([$scope->getLineItem()]) : $scope->getCart()->getLineItems();

        if ($this->type !== null) {
            $lineItems = $lineItems->filterFlatByType($this->type);
        }

        if (\is_array($lineItems) && \count($lineItems) === 0) {
            return false;
        }

        if (!\is_array($lineItems) && $lineItems->count() === 0) {
            return false;
        }

        $context = $scope->getSalesChannelContext();

        foreach ($this->rules as $rule) {
            $matched = 0;

            foreach ($lineItems as $lineItem) {
                $scope = new LineItemScope($lineItem, $context);
                $match = $rule->match($scope);

                if (!$this->minimumShouldMatch && !$match) {
                    return false;
                }

                if ($match) {
                    ++$matched;
                }
            }

            if ($this->minimumShouldMatch && $matched < $this->minimumShouldMatch) {
                return false;
            }
        }

        return true;
    }

    public function getConstraints(): array
    {
        $rules = parent::getConstraints();

        $rules['minimumShouldMatch'] = [new Type('int')];
        $rules['type'] = [new Type('string')];

        return $rules;
    }
}
