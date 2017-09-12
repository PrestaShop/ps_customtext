<?php
/*
* 2007-2017 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* https://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2017 PrestaShop SA
*  @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade the Ps_Customtext module to V3.0.0
 *
 * @param Ps_Customtext $module
 * @return bool
 */
function upgrade_module_3_0_0($module)
{
    $return = true;

    $data = getOldData();

    /** Delete the column id_shop from info table */
    $return &= Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'info` DROP `id_shop`');

    /** Add the column id_shop and define as primary key in the table info_lang */
    $return &= Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'info_lang` ADD `id_shop` INT(10) UNSIGNED NOT NULL');
    $return &= Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'info_lang` DROP PRIMARY KEY, ADD PRIMARY KEY(`id_info`, `id_lang`, `id_shop`)');

    /** Create the info_shop table */
    $return &= Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'info_shop` (
                `id_info` INT(10) UNSIGNED NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (`id_info`, `id_shop`)
                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;'
    );

    /** Register the hook responsible for adding custom text when adding a new store */
    $return &= $module->registerHook('actionShopDataDuplication');

    /** Truncate all DB table */
    $return &= Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . 'info`');
    $return &= Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . 'info_shop`');
    $return &= Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . 'info_lang`');

    /** Reset DB data */
    $return &= insertData($data);

    return $return;
}

/**
 * Retrieves the old data of CustomText
 *
 * @return array
 */
function getOldData()
{
    $data = array();
    $texts = Db::getInstance()->executeS('SELECT i.`id_shop`, il.`id_lang`, il.`text` FROM `' . _DB_PREFIX_ . 'info` i
    INNER JOIN `' . _DB_PREFIX_ . 'info_lang` il ON il.`id_info` = i.`id_info`'
    );

    if (is_array($texts) && !empty($texts)) {
        foreach ($texts as $text) {
            $data[(int)$text['id_shop']][(int)$text['id_lang']] = $text['text'];
        }
    }

    return $data;
}

/**
 * Inserting the old CustomText data
 *
 * @param $text
 * @return bool
 */
function insertData($texts)
{
    $return = true;

    if (is_array($texts) && !empty($texts)) {
        $shopsIds = Shop::getShops(true, null, true);
        $customTexts = array_intersect_key($texts, $shopsIds);

        $info = new CustomText();
        $info->text = reset($customTexts);
        $return &= $info->add();

        if (sizeof($customTexts) > 1) {
            foreach ($customTexts as $key => $text) {
                Shop::setContext(Shop::CONTEXT_SHOP, (int) $key);
                $info->text = $text;
                $return &= $info->save();
            }
        }
    }

    return $return;
}
