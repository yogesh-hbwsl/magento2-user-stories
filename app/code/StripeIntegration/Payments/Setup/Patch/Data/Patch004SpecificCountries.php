<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class Patch004SpecificCountries
    implements DataPatchInterface,
    PatchRevertableInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    private $areaCode;
    private $resourceConnection;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \StripeIntegration\Payments\Helper\AreaCode $areaCode
    ) {
        /**
         * If before, we pass $setup as argument in install/upgrade function, from now we start
         * inject it with DI. If you want to use setup, you can inject it, with the same way as here
         */
        $this->moduleDataSetup = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
        $this->areaCode = $areaCode;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->areaCode->setAreaCode();

        $connection = $this->resourceConnection->getConnection();

        // Remove payment method filtering settings, remnant from v2.x of the module
        $tableName = $this->resourceConnection->getTableName('core_config_data');
        $connection->delete(
            $tableName,
            ["path LIKE ?" => '%stripe_payments%specific%']
        );

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
