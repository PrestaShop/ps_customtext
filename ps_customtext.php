<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\ObjectPresenter;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

require_once _PS_MODULE_DIR_ . 'ps_customtext/classes/CustomText.php';
class Ps_Customtext extends Module implements WidgetInterface
{
    // Equivalent module on PrestaShop 1.6, sharing the same data
    const MODULE_16 = 'blockcmsinfo';

    /**
     * @var string Template used by widget
     */
    private $templateFile;

    public function __construct()
    {
        $this->name = 'ps_customtext';
        $this->author = 'PrestaShop';
        $this->version = '4.2.0';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        Shop::addTableAssociation('info', ['type' => 'shop']);

        $this->displayName = $this->trans('Custom text block', [], 'Modules.Customtext.Admin');
        $this->description = $this->trans('Give your visitors extra information, display a customized block of content on your homepage.', [], 'Modules.Customtext.Admin');

        $this->ps_versions_compliancy = ['min' => '1.7.4.0', 'max' => _PS_VERSION_];

        $this->templateFile = 'module:ps_customtext/ps_customtext.tpl';
    }

    /**
     * @return bool
     */
    public function install()
    {
        // Remove 1.6 equivalent module to avoid DB issues
        if (Module::isInstalled(self::MODULE_16)) {
            return $this->installFrom16Version();
        }

        return $this->runInstallSteps()
            && $this->installFixtures();
    }

    /**
     * @return bool
     */
    public function runInstallSteps()
    {
        return parent::install()
            && $this->installDB()
            && $this->registerHook('displayHome')
            && $this->registerHook('actionShopDataDuplication');
    }

    /**
     * @return bool
     */
    public function installFrom16Version()
    {
        require_once _PS_MODULE_DIR_ . $this->name . '/classes/MigrateData.php';
        $migration = new MigrateData();
        $migration->retrieveOldData();

        $oldModule = Module::getInstanceByName(self::MODULE_16);
        if ($oldModule) {
            $oldModule->uninstall();
        }

        return $this->uninstallDB()
            && $this->runInstallSteps()
            && $migration->insertData();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallDB();
    }

    /**
     * @return bool
     */
    public function installDB()
    {
        $return = Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'info` (
                `id_info` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id_info`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;'
        );

        $return = $return && Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'info_shop` (
                `id_info` INT(10) UNSIGNED NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (`id_info`, `id_shop`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;'
        );

        $return = $return && Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'info_lang` (
                `id_info` INT UNSIGNED NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL,
                `id_lang` INT(10) UNSIGNED NOT NULL ,
                `text` text NOT NULL,
                PRIMARY KEY (`id_info`, `id_lang`, `id_shop`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;'
        );

        return $return;
    }

    /**
     * @param bool $drop_table
     *
     * @return bool
     */
    public function uninstallDB($drop_table = true)
    {
        if (!$drop_table) {
            return true;
        }

        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'info`, `' . _DB_PREFIX_ . 'info_shop`, `' . _DB_PREFIX_ . 'info_lang`');
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('saveps_customtext')) {
            if (!Tools::getValue('text_' . (int) Configuration::get('PS_LANG_DEFAULT'), false)) {
                $output = $this->displayError($this->trans('Please fill out all fields.', [], 'Admin.Notifications.Error'));
            } else {
                $update = $this->processSaveCustomText();

                if (!$update) {
                    $output = '<div class="alert alert-danger conf error">'
                        . $this->trans('An error occurred on saving.', [], 'Admin.Notifications.Error')
                        . '</div>';
                }

                $this->_clearCache($this->templateFile);

                if ($update) {
                    Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name . '&conf=4');
                }
            }
        }

        return $output . $this->renderForm();
    }

    /**
     * @return bool
     */
    public function processSaveCustomText()
    {
        $shops = Tools::getValue('checkBoxShopAsso_configuration', [$this->context->shop->id]);
        $text = [];
        $languages = Language::getLanguages(false);

        foreach ($languages as $lang) {
            $text[$lang['id_lang']] = (string) Tools::getValue('text_' . $lang['id_lang']);
        }

        $saved = true;
        foreach ($shops as $shop) {
            Shop::setContext(Shop::CONTEXT_SHOP, $shop);
            $info = new CustomText(Tools::getValue('id_info', 1));
            $info->text = $text;
            $saved = $saved && $info->save();
        }

        return $saved;
    }

    /**
     * @return string
     */
    protected function renderForm()
    {
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form = [
            'tinymce' => true,
            'legend' => [
                'title' => $this->trans('Custom text block', [], 'Modules.Customtext.Admin'),
            ],
            'input' => [
                'id_info' => [
                    'type' => 'hidden',
                    'name' => 'id_info',
                ],
                'content' => [
                    'type' => 'textarea',
                    'label' => $this->trans('Text block', [], 'Modules.Customtext.Admin'),
                    'lang' => true,
                    'name' => 'text',
                    'cols' => 40,
                    'rows' => 10,
                    'class' => 'rte',
                    'autoload_rte' => true,
                ],
            ],
            'submit' => [
                'title' => $this->trans('Save', [], 'Admin.Actions'),
            ],
        ];

        if (Shop::isFeatureActive() && Tools::getValue('id_info') == false) {
            $fields_form['input'][] = [
                'type' => 'shop',
                'label' => $this->trans('Shop association', [], 'Admin.Global'),
                'name' => 'checkBoxShopAsso_theme',
            ];
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = 'ps_customtext';
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        foreach (Language::getLanguages(false) as $lang) {
            $helper->languages[] = [
                'id_lang' => $lang['id_lang'],
                'iso_code' => $lang['iso_code'],
                'name' => $lang['name'],
                'is_default' => ($default_lang == $lang['id_lang'] ? 1 : 0),
            ];
        }

        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->toolbar_scroll = true;
        $helper->title = $this->displayName;
        $helper->submit_action = 'saveps_customtext';

        $helper->fields_value = $this->getFormValues();

        return $helper->generateForm([['form' => $fields_form]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormValues()
    {
        $fields_value = [];
        $idShop = $this->context->shop->id;
        $idInfo = CustomText::getCustomTextIdByShop($idShop);

        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        $info = new CustomText((int) $idInfo);

        $fields_value['text'] = $info->text;
        $fields_value['id_info'] = $idInfo;

        return $fields_value;
    }

    /**
     * @param string|null $hookName
     * @param array $configuration
     *
     * @return string
     */
    public function renderWidget($hookName = null, array $configuration = [])
    {
        if (!$this->isCached($this->templateFile, $this->getCacheId('ps_customtext'))) {
            $this->smarty->assign($this->getWidgetVariables($hookName, $configuration));
        }

        return $this->fetch($this->templateFile, $this->getCacheId('ps_customtext'));
    }

    /**
     * @param string|null $hookName
     * @param array $configuration
     *
     * @return array<string, mixed>
     */
    public function getWidgetVariables($hookName = null, array $configuration = [])
    {
        $customText = new CustomText(1, (int) $this->context->language->id, (int) $this->context->shop->id);
        $objectPresenter = new ObjectPresenter();
        $data = $objectPresenter->present($customText);
        $data['id_lang'] = $this->context->language->id;
        $data['id_shop'] = $this->context->shop->id;
        unset($data['id']);

        return ['cms_infos' => $data];
    }

    /**
     * @return bool
     */
    public function installFixtures()
    {
        $return = true;
        $tabTexts = [
            [
                'text' => '<h2>Custom Text Block</h2>
<p><strong class="dark">Lorem ipsum dolor sit amet conse ctetu</strong></p>
<p>Sit amet conse ctetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit.</p>',
            ],
        ];

        $shopsIds = Shop::getShops(true, null, true);
        $languages = Language::getLanguages(false);
        $text = [];

        foreach ($tabTexts as $tab) {
            $info = new CustomText();
            foreach ($languages as $lang) {
                $text[$lang['id_lang']] = $tab['text'];
            }
            $info->text = $text;
            $return = $return && $info->add();
        }

        if ($return && count($shopsIds) > 1) {
            foreach ($shopsIds as $idShop) {
                Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
                $info->text = $text;
                $return = $return && $info->save();
            }
        }

        return $return;
    }

    /**
     * Add CustomText when adding a new Shop
     *
     * @param array{cookie: Cookie, cart: Cart, altern: int, old_id_shop: int, new_id_shop: int} $params
     */
    public function hookActionShopDataDuplication(array $params)
    {
        if ($infoId = CustomText::getCustomTextIdByShop($params['old_id_shop'])) {
            Shop::setContext(Shop::CONTEXT_SHOP, $params['old_id_shop']);
            $oldInfo = new CustomText($infoId);

            Shop::setContext(Shop::CONTEXT_SHOP, $params['new_id_shop']);
            $newInfo = new CustomText($infoId, null, $params['new_id_shop']);
            $newInfo->text = $oldInfo->text;

            $newInfo->save();
        }
    }
}
