<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class Patch001WebhooksConfiguration
    implements DataPatchInterface,
    PatchRevertableInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    private $webhooksSetupFactory;
    private $areaCode;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \StripeIntegration\Payments\Helper\WebhooksSetupFactory $webhooksSetupFactory,
        \StripeIntegration\Payments\Helper\AreaCode $areaCode
    ) {
        /**
         * If before, we pass $setup as argument in install/upgrade function, from now we start
         * inject it with DI. If you want to use setup, you can inject it, with the same way as here
         */
        $this->moduleDataSetup = $moduleDataSetup;
        $this->webhooksSetupFactory = $webhooksSetupFactory;
        $this->areaCode = $areaCode;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->areaCode->setAreaCode();
        $webhooksSetup = $this->webhooksSetupFactory->create();

        try
        {
            if ($webhooksSetup->isConfigureNeeded())
                $webhooksSetup->configure();
        }
        catch (\Exception $e)
        {
            // The Stripe PHP library may have not been installed yet, do nothing
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        /**
         * This is dependency to another patch. Dependency should be applied first
         * One patch can have few dependencies
         * Patches do not have versions, so if in old approach with Install/Ugrade data scripts you used
         * versions, right now you need to point from patch with higher version to patch with lower version
         * But please, note, that some of your patches can be independent and can be installed in any sequence
         * So use dependencies only if this important for you
         */
        return [
            \StripeIntegration\Payments\Setup\Patch\Data\InitialInstall::class
        ];
    }

    public function revert()
    {

    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        /**
         * This internal Magento method, that means that some patches with time can change their names,
         * but changing name should not affect installation process, that's why if we will change name of the patch
         * we will add alias here
         */
        return [];
    }
}
