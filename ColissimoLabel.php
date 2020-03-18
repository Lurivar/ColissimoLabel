<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace ColissimoLabel;

use ColissimoLabel\Request\Helper\OutputFormat;
use ColissimoWs\ColissimoWs;
use Propel\Runtime\Connection\ConnectionInterface;
use SoColissimo\SoColissimo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Module\BaseModule;
use Thelia\Install\Database;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class ColissimoLabel extends BaseModule
{
    /** @var string */
    const DOMAIN_NAME = 'colissimolabel';

    const LABEL_FOLDER = THELIA_LOCAL_DIR . 'colissimo-label';

    const BORDEREAU_FOLDER = self::LABEL_FOLDER . DIRECTORY_SEPARATOR . 'bordereau';

    const AUTHORIZED_MODULES = ['ColissimoWs', 'SoColissimo'];

    const CONFIG_KEY_DEFAULT_LABEL_FORMAT = 'default-label-format';

    const CONFIG_KEY_CONTRACT_NUMBER = 'contract-number';

    const CONFIG_KEY_PASSWORD = 'password';

    const CONFIG_KEY_AUTO_SENT_STATUS = 'auto-sent-status';

    const CONFIG_DEFAULT_AUTO_SENT_STATUS = 1;

    const CONFIG_KEY_SENT_STATUS_ID = 'sent-status-id';

    const CONFIG_DEFAULT_SENT_STATUS_ID = 4;

    const CONFIG_KEY_PRE_FILL_INPUT_WEIGHT = 'pre-fill-input-weight';

    const CONFIG_DEFAULT_PRE_FILL_INPUT_WEIGHT = 1;

    const CONFIG_KEY_LAST_BORDEREAU_DATE = 'last-bordereau-date';

    const CONFIG_DEFAULT_KEY_LAST_BORDEREAU_DATE = 1970;

    const CONFIG_KEY_DEFAULT_SIGNED = 'default-signed';

    const CONFIG_DEFAULT_KEY_DEFAULT_SIGNED = true;

    /**
     * @param ConnectionInterface $con
     */
    public function postActivation(ConnectionInterface $con = null)
    {
        static::checkLabelFolder();

        if (!$this->getConfigValue('is_initialized', false)) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__ . "/Config/thelia.sql"]);
            $this->setConfigValue('is_initialized', true);
        }

        $this->checkConfigurationsValues();
    }

    public function update($currentVersion, $newVersion, ConnectionInterface $con = null)
    {
        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in(__DIR__ . DS . 'Config' . DS . 'update');

        $database = new Database($con);

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    /**
     * Check if config values exist in the module config table exists. Creates them with a default value otherwise
     */
    protected function checkConfigurationsValues()
    {
        /** Check if the default label format config value exists, and sets it to PDF_10x15_300dpi is it doesn't exists */
        if (null === self::getConfigValue(self::CONFIG_KEY_DEFAULT_LABEL_FORMAT)) {
            self::setConfigValue(
                self::CONFIG_KEY_DEFAULT_LABEL_FORMAT,
                OutputFormat::OUTPUT_PRINTING_TYPE_DEFAULT
            );
        }

        /**
         * Check if the contract number config value exists, and sets it to either of the following :
         * The contract number of the ColissimoWS config, if the module is activated
         * Otherwise : the contract number of the SoColissimo config, if the module is activated
         * Otherwise : a blanck string : ""
         */
        if (null === self::getConfigValue(self::CONFIG_KEY_CONTRACT_NUMBER)) {

            $contractNumber = '';
            if (ModuleQuery::create()->findOneByCode(self::AUTHORIZED_MODULES[1])) {
                $contractNumber = SoColissimo::getConfigValue('socolissimo_username');
            }
            if (ModuleQuery::create()->findOneByCode(self::AUTHORIZED_MODULES[0])) {
                $contractNumber = ColissimoWs::getConfigValue('colissimo_username');
            }

            self::setConfigValue(
                self::CONFIG_KEY_CONTRACT_NUMBER,
                $contractNumber
            );
        }

        /**
         * Check if the contract password config value exists, and sets it to either of the following :
         * The contract password of the ColissimoWS config, if the module is activated
         * Otherwise : the contract password of the SoColissimo config, if the module is activated
         * Otherwise : a blanck string : ""
         */
        if (null === self::getConfigValue(self::CONFIG_KEY_PASSWORD)) {

            $contractPassword = '';
            if (ModuleQuery::create()->findOneByCode(self::AUTHORIZED_MODULES[1])) {
                $contractPassword = SoColissimo::getConfigValue('socolissimo_password');
            }
            if (ModuleQuery::create()->findOneByCode(self::AUTHORIZED_MODULES[0])) {
                $contractPassword = ColissimoWs::getConfigValue('colissimo_password');
            }

            self::setConfigValue(
                self::CONFIG_KEY_PASSWORD,
                $contractPassword
            );
        }

        /** TODO : Find out what this does */
        if (null === self::getConfigValue(self::CONFIG_KEY_AUTO_SENT_STATUS)) {
            self::setConfigValue(
                self::CONFIG_KEY_AUTO_SENT_STATUS,
                self::CONFIG_DEFAULT_AUTO_SENT_STATUS
            );
        }

        /** TODO : Verify that this isn't useless as you can already find sent status id without it */
        if (null === self::getConfigValue(self::CONFIG_KEY_SENT_STATUS_ID)) {
            self::setConfigValue(
                self::CONFIG_KEY_SENT_STATUS_ID,
                self::CONFIG_DEFAULT_SENT_STATUS_ID
            );
        }

        /** TODO : Verify that this isn't a double with what's above x2 */
        if (null === self::getConfigValue(self::CONFIG_KEY_AUTO_SENT_STATUS)) {
            self::setConfigValue(
                self::CONFIG_KEY_AUTO_SENT_STATUS,
                self::CONFIG_DEFAULT_AUTO_SENT_STATUS
            );
        }

        /** TODO : Verify that this isn't useless as we can get the order weight. Maybe in case not order wieght is found ? */
        if (null === self::getConfigValue(self::CONFIG_KEY_PRE_FILL_INPUT_WEIGHT)) {
            self::setConfigValue(
                self::CONFIG_KEY_PRE_FILL_INPUT_WEIGHT,
                self::CONFIG_DEFAULT_PRE_FILL_INPUT_WEIGHT
            );
        }

        /** Check if the config value for the last bordereau date exists, creates it with a default value of 1970 otherwise */
        if (null === self::getConfigValue(self::CONFIG_KEY_LAST_BORDEREAU_DATE)) {
            self::setConfigValue(
                self::CONFIG_KEY_LAST_BORDEREAU_DATE,
                self::CONFIG_DEFAULT_KEY_LAST_BORDEREAU_DATE
            );
        }

        /** Check if the config value for the default signed state for labels exists, creates it with a value of true otherwise */
        if (null === self::getConfigValue(self::CONFIG_KEY_DEFAULT_SIGNED)) {
            self::setConfigValue(
                self::CONFIG_KEY_DEFAULT_SIGNED,
                self::CONFIG_DEFAULT_KEY_DEFAULT_SIGNED
            );
        }

    }

    /**
     * Check if the label and bordereau folders exists. Creates them otherwise.
     */
    public static function checkLabelFolder()
    {
        $fileSystem = new Filesystem();

        if (!$fileSystem->exists(self::LABEL_FOLDER)) {
            $fileSystem->mkdir(self::LABEL_FOLDER);
        }
        if (!$fileSystem->exists(self::BORDEREAU_FOLDER)) {
            $fileSystem->mkdir(self::BORDEREAU_FOLDER);
        }
    }

    /** Get the path of a given label file, according to its number */
    public static function getLabelPath($number, $extension)
    {
        return self::LABEL_FOLDER . DIRECTORY_SEPARATOR . $number . '.' . $extension;
    }

    /** TODO : Find out what this does */
    public static function getLabelCN23Path($number, $extension)
    {
        return self::LABEL_FOLDER . DIRECTORY_SEPARATOR . $number . '.' . $extension;
    }

    /** Get the path of a bordereau file, according to a date */
    public static function getBordereauPath($date)
    {
        return self::BORDEREAU_FOLDER . DIRECTORY_SEPARATOR . $date . '.pdf';
    }

    /** Get the label files extension according to the file type indicated in the module config */
    public static function getExtensionFile()
    {
        return strtolower(substr(self::getConfigValue(self::CONFIG_KEY_DEFAULT_LABEL_FORMAT), 0, 3));
    }

    /**
     * Check if order has to be signed or if it is optionnal (aka if its in Europe or not)
     *
     * @param Order $order
     * @return bool
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public static function canOrderBeNotSigned(Order $order)
    {
        $areas = $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getCountry()->getAreas();

        $areas_id = [];

        foreach ($areas as $area){
            $areas_id[] = $area->getId();
        }

        if (in_array(4, $areas_id) || in_array(5, $areas_id)) // If order's country isn't in Europe or in DOM-TOM so order has to be signed
            return false;
        else
            return true;
    }
}
