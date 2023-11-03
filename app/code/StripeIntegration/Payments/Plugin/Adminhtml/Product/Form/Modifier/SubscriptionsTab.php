<?php
namespace StripeIntegration\Payments\Plugin\Adminhtml\Product\Form\Modifier;

use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;

class SubscriptionsTab extends AbstractModifier
{
    /**
     * @var LocatorInterface
     */
    protected $locator;

    private $subscriptionOptionsFactory;

    /**
     * @param LocatorInterface $locator
     */
    public function __construct(
        LocatorInterface $locator,
        \StripeIntegration\Payments\Model\SubscriptionOptionsFactory $subscriptionOptionsFactory
    ) {
        $this->locator = $locator;
        $this->subscriptionOptionsFactory = $subscriptionOptionsFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function modifyMeta(array $meta)
    {
        $visibleSubscriptionOptions = 0;
        $visibleParentSubscriptionOptions = 0;
        $product = $this->locator->getProduct();

        if ($product && !empty($product->getTypeId()))
        {
            $typeId = $product->getTypeId();
            if ($typeId == 'simple' || $typeId == 'virtual')
            {
                $visibleSubscriptionOptions = 1;
            }

            if ($typeId == 'simple' || $typeId == 'virtual' || $typeId == 'configurable' || $typeId == 'bundle')
            {
                $visibleParentSubscriptionOptions = 1;
            }
        }

        $meta = array_replace_recursive(
            $meta,
            [
                'subscriptions-by-stripe' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'visible' => ($visibleSubscriptionOptions || $visibleParentSubscriptionOptions),
                            ]
                        ]
                    ],
                    'children' => [
                        'sub_enabled' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'sub_interval' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'sub_interval_count' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'sub_trial' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'sub_initial_fee' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'start_on_specific_date' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'upgrades_downgrades' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleParentSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'upgrades_downgrades_use_config' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleParentSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'prorate_upgrades' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleParentSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'prorate_upgrades_use_config' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleParentSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'prorate_downgrades' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleParentSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                        'prorate_downgrades_use_config' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'visible' => $visibleParentSubscriptionOptions,
                                    ]
                                ]
                            ]
                        ],
                    ]
                ],
            ]
        );

        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function modifyData(array $data)
    {
        $productId = $this->locator->getProduct()->getId();
        $model = $this->subscriptionOptionsFactory->create()->load($productId);

        if ($model->getId())
        {
            $data = array_replace_recursive(
                $data,
                [
                    $productId => [
                        self::DATA_SOURCE_DEFAULT => [
                            'subscription_options' => [
                                'sub_enabled' => (bool)$model->getSubEnabled(),
                                'sub_interval' => $model->getSubInterval(),
                                'sub_interval_count' => $model->getSubIntervalCount(),
                                'sub_trial' => $model->getSubTrial(),
                                'sub_initial_fee' => $model->getSubInitialFee(),
                                'start_on_specific_date' => (bool)$model->getStartOnSpecificDate(),
                                'start_date' => $model->getStartDate(),
                                'first_payment' => $model->getFirstPayment(),
                                'prorate_first_payment' => (bool)$model->getProrateFirstPayment(),
                                'upgrades_downgrades' => (bool)$model->getUpgradesDowngrades(),
                                'upgrades_downgrades_use_config' => $model->getUpgradesDowngradesUseConfig(),
                                'prorate_upgrades' => (bool)$model->getProrateUpgrades(),
                                'prorate_upgrades_use_config' => $model->getProrateUpgradesUseConfig(),
                                'prorate_downgrades' => (bool)$model->getProrateDowngrades(),
                                'prorate_downgrades_use_config' => $model->getProrateDowngradesUseConfig(),
                            ]
                        ],
                    ]
                ]
            );
        }

        return $data;
    }
}
