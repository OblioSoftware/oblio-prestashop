<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Oblio extends Module
{
    private $_table_invoice             = 'oblio_invoice';
    private $_table_product_attributes  = 'oblio_product_attributes';
    
    private $_status_name               = 'Facturat cu Oblio';
    private $_ps_new_style              = false;
    
    const INVOICE   = 1;
    const PROFORMA  = 2;
    
    const PS_OS_NAME = 'PS_OS_OBLIO';

    private $_tags = [
        'id'            => 'id',
        'reference'     => 'reference',
        'date_add'      => 'date',
        'payment'       => 'payment',
    ];
    private $_product_types = [
        ['name' => 'Marfa'],
        ['name' => 'Semifabricate'],
        ['name' => 'Produs finit'],
        ['name' => 'Produs rezidual'],
        ['name' => 'Produse agricole'],
        ['name' => 'Animale si pasari'],
        ['name' => 'Ambalaje'],
        ['name' => 'Serviciu'],
    ];
    private $_invoice_options = [];
    
    public function __construct()
    {
        $this->name                     = 'oblio';
        $this->tab                      = 'billing_invoicing';
        $this->version                  = '1.1.8';
        $this->author                   = 'Oblio Software';
        $this->need_instance            = 1;
        $this->controllers              = [];
        $this->ps_versions_compliancy   = [
            'min' => '1.6',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap                = true;
        $this->_ps_new_style            = version_compare(_PS_VERSION_, '1.7.7') === 1;

        parent::__construct();

        $this->displayName = $this->l('Oblio');
        $this->description = $this->l('Genereaza facturi cu Oblio');
        $this->_initInvoiceOptions();
    }
    
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }
        $orderDetailsHookName = $this->_ps_new_style
            ? 'displayAdminOrderMainBottom'
            : 'displayAdminOrderLeft';

        return 
            parent::install() && 
            $this->installDb() &&
            $this->installTab() &&
            $this->addOrderState() &&
            (
                version_compare(_PS_VERSION_, '8.1') > 0
                    ? $this->registerHook('displayAdminProductsExtra')
                    : $this->registerHook('displayAdminProductsCombinationBottom') &&
                        $this->registerHook('displayAdminProductsMainStepRightColumnBottom')
            ) &&
            $this->registerHook('actionProductSave') &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionPDFInvoiceRender') &&
            $this->registerHook('actionOrderHistoryAddAfter') &&
            // $this->registerHook('actionOrderStatusPostUpdate') &&
            // $this->registerHook('displayBackOfficeOrderActions') &&
            $this->registerHook($orderDetailsHookName);
    }
    
    private function installDb()
    {
        $createSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . $this->_table_invoice . '` (
        `id_order` int(10) unsigned NOT NULL,
        `type` tinyint(1) NOT NULL DEFAULT "1",
        `invoice_series` varchar(20) NOT NULL,
        `invoice_number` int(10) unsigned NOT NULL,
        PRIMARY KEY (`id_order`, `type`)
        ) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8;';
        $result_table_invoice = Db::getInstance()->execute($createSql);
        
        $checkInvoiceTable = 'SHOW COLUMNS FROM `' . _DB_PREFIX_ . $this->_table_invoice . '`';
        $columns = Db::getInstance()->ExecuteS($checkInvoiceTable);
        $found = [];
        foreach ($columns as $column) {
            $found[$column['Field']] = $column['Type'];
        }
        if (empty($found['type'])) {
            $sql = 'ALTER TABLE `' . _DB_PREFIX_ . $this->_table_invoice . '` CHANGE `id_order` `id_order` INT(10) UNSIGNED NOT NULL;';
            Db::getInstance()->execute($sql);
            
            $sql = 'ALTER TABLE `' . _DB_PREFIX_ . $this->_table_invoice . '` DROP PRIMARY KEY;';
            Db::getInstance()->execute($sql);
            
            $sql = 'ALTER TABLE `' . _DB_PREFIX_ . $this->_table_invoice . '`  ADD `type` tinyint(1) NOT NULL DEFAULT "1" AFTER `id_order`;';
            Db::getInstance()->execute($sql);
            
            $sql = 'ALTER TABLE `' . _DB_PREFIX_ . $this->_table_invoice . '` ADD PRIMARY KEY(`id_order`, `type`);';
            Db::getInstance()->execute($sql);
        }

        $createSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . $this->_table_product_attributes . '` (
            `id_product` int(11) NOT NULL,
            `attribute` varchar(100) NOT NULL,
            `value` varchar(100) NOT NULL,
            PRIMARY KEY (`id_product`,`attribute`)
        ) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8;';
        $result_table_product_attributes = Db::getInstance()->execute($createSql);

        return $result_table_invoice && $result_table_product_attributes;
    }
    
    private function installTab()
    {
        $tabs = [
            [
                'name' => $this->name,
                'class_name' => 'AdminOblioInvoice',
                'parent' => -1
            ],
            [
                'name' => 'Sincronizare Oblio',
                'class_name' => 'AdminOblioData',
                'parent' => 9
            ],
        ];
        $langs = language::getLanguages();
        foreach ($tabs as $_tab) {
            $tab = new Tab();
            $tab->name = [];
            foreach ($langs as $lang) {
                $tab->name[$lang['id_lang']] = $_tab['name'];
            }
            $tab->module = $this->name;
            $tab->id_parent = $_tab['parent'];
            $tab->class_name = $_tab['class_name'];
            $tab->save();
        }
        return true;
    }
    
    public function addOrderState()
    {
        $state_exist = false;
        $states = OrderState::getOrderStates((int)$this->context->language->id);
 
        // check if order state exist
        foreach ($states as $state) {
            if (in_array($this->_status_name, $state)) {
                $state_exist = true;
                break;
            }
        }
 
        // If the state does not exist, we create it.
        if (!$state_exist) {
            // create new order state
            $order_state = new OrderState();
            $order_state->color = '#623394';
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->template = '';
            $order_state->name = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                $order_state->name[$language['id_lang']] = $this->_status_name;
            }
 
            // Update object
            $order_state->add();
            Configuration::updateValue(self::PS_OS_NAME, $order_state->id);
            
            $logo = _PS_MODULE_DIR_ . $this->name . '/logo.gif';
            $copy = _PS_IMG_DIR_ . sprintf('os/%d.gif', $order_state->id);
            copy($logo, $copy);
        }
 
        return true;
    }
    
    public function uninstall()
    {
        Configuration::updateValue('oblio_api_email', null);
        Configuration::updateValue('oblio_api_secret', null);
        Configuration::updateValue('oblio_company_cui', null);
        Configuration::updateValue('oblio_company_series_name', null);
        Configuration::updateValue('oblio_company_series_name_proforma', null);
        Configuration::updateValue('oblio_company_workstation', null);
        Configuration::updateValue('oblio_company_management', null);
        Configuration::updateValue('oblio_api_access_token', null);
        return $this->uninstallTab() && parent::uninstall();
    }
    
    private function uninstallTab()
    {
        $tabs = ['AdminOblioInvoice', 'AdminOblioData'];
        foreach ($tabs as $tab) {
            $id_tab = Tab::getIdFromClassName($tab);
            if ($id_tab) {
                $tab = new Tab($id_tab);
                $tab->delete();
            }
        }
        return true;
    }
    
    public function displayForm()
    {
        // $this->context->controller->addJQuery();
        $this->context->controller->addJS($this->_path . 'views/js/script.js');
        
        // < load helperForm >
        $helper = new HelperForm();

        // < module, token and currentIndex >
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // < title and toolbar >
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                    '&token='.Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // < load current value >
        $helper->fields_value['oblio_api_email']                    = Configuration::get('oblio_api_email');
        $helper->fields_value['oblio_api_secret']                   = Configuration::get('oblio_api_secret');
        $helper->fields_value['oblio_company_cui']                  = Configuration::get('oblio_company_cui');
        $helper->fields_value['oblio_company_series_name']          = Configuration::get('oblio_company_series_name');
        $helper->fields_value['oblio_company_series_name_proforma'] = Configuration::get('oblio_company_series_name_proforma');
        $helper->fields_value['oblio_company_workstation']          = Configuration::get('oblio_company_workstation');
        $helper->fields_value['oblio_company_management']           = Configuration::get('oblio_company_management');
        
        $helper->fields_value['oblio_issuer_name']                  = Configuration::get('oblio_issuer_name');
        $helper->fields_value['oblio_issuer_id']                    = Configuration::get('oblio_issuer_id');
        $helper->fields_value['oblio_deputy_name']                  = Configuration::get('oblio_deputy_name');
        $helper->fields_value['oblio_deputy_identity_card']         = Configuration::get('oblio_deputy_identity_card');
        $helper->fields_value['oblio_deputy_auto']                  = Configuration::get('oblio_deputy_auto');
        $helper->fields_value['oblio_seles_agent']                  = Configuration::get('oblio_seles_agent');
        $helper->fields_value['oblio_mentions']                     = Configuration::get('oblio_mentions');
        
        $helper->fields_value['oblio_exclude_reference']                = Configuration::get('oblio_exclude_reference');
        $helper->fields_value['oblio_product_category_on_invoice']      = Configuration::get('oblio_product_category_on_invoice');
        $helper->fields_value['oblio_product_discount_included']        = Configuration::get('oblio_product_discount_included');
        $helper->fields_value['oblio_company_products_type']            = Configuration::get('oblio_company_products_type');
        $helper->fields_value['oblio_generate_invoice_status_change']   = Configuration::get('oblio_generate_invoice_status_change');
        $helper->fields_value['oblio_generate_invoice_use_stock']       = Configuration::get('oblio_generate_invoice_use_stock');
        $helper->fields_value['oblio_generate_proforma_new_order']      = Configuration::get('oblio_generate_proforma_new_order');
        $helper->fields_value['oblio_generate_change_state']            = Configuration::get('oblio_generate_change_state');
        $helper->fields_value['oblio_generate_email_state']             = Configuration::get('oblio_generate_email_state');
        $helper->fields_value['oblio_stock_adjusments']                 = Configuration::get('oblio_stock_adjusments');
    

        // < init fields for form array >
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Logare Oblio'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Email cont Oblio'),
                    'name' => 'oblio_api_email',
                    //'lang' => true,
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('API secret'),
                    'desc' => $this->l('Se gaseste in: Oblio > Setari > Date cont'),
                    'name' => 'oblio_api_secret',
                    //'lang' => true,
                    'size' => 20,
                    'required' => true
                ),
            ),
            'submit' => array(
                'title' => $this->l('Salveaza'),
                'class' => 'btn btn-default pull-right'
            )
        );
        
        if ($helper->fields_value['oblio_api_email'] && $helper->fields_value['oblio_api_secret']) {
            require_once 'classes/OblioApi.php';
            require_once 'classes/OblioApiPrestashopAccessTokenHandler.php';
            
            $accessTokenHandler = new OblioApiPrestashopAccessTokenHandler();
            try {
                $api = new OblioApi($helper->fields_value['oblio_api_email'], $helper->fields_value['oblio_api_secret'], $accessTokenHandler);
                // companies
                $response = $api->nomenclature('companies');
                
                if ((int) $response['status'] === 200 && count($response['data']) > 0) {
                    $cui        = '';
                    $useStock   = false;
                    $companies  = [];
                    foreach ($response['data'] as $key=>$company) {
                        $companies[$key] = [
                            'cif'     => $company['cif'] . '" data-use-stock="' . $company['useStock'], // ¯\_(ツ)_/¯
                            'company' => $company['company'],
                        ];
                        if ($company['cif'] === $helper->fields_value['oblio_company_cui']) {
                            $cui        = $company['cif'];
                            $useStock   = $company['useStock'];
                            $helper->fields_value['oblio_company_cui'] = $companies[$key]['cif'];
                        }
                    }
                    $fields = array(
                        array(
                            'type' => 'select',
                            'label' => $this->l('Companie'),
                            'name' => 'oblio_company_cui',
                            'options' => [
                                'query' => array_merge([['cif' => '', 'company' => 'Selecteaza']], $companies),
                                'id'    => 'cif',
                                'name'  => 'company',
                            ],
                            'class' => 'chosen',
                            //'lang' => true,
                            'required' => true
                        ),
                    );
                    
                    $invoiceSeries  = [];
                    $proformaSeries = [];
                    $workStations   = [];
                    $management     = [];
                    if ($cui) {
                        $api->setCif($cui);
                        
                        // series
                        usleep(500000); // 0.5s sleep
                        $response = $api->nomenclature('series', '');
                        $series = $response['data'];
                        foreach ($response['data'] as $series) {
                            switch ($series['type']) {
                                case 'Factura': $invoiceSeries[] = $series; break;
                                case 'Proforma': $proformaSeries[] = $series; break;
                            }
                        }
                        
                        // management
                        if ($useStock) {
                            usleep(500000); // 0.5s sleep
                            $response = $api->nomenclature('management', '');
                            foreach ($response['data'] as $item) {
                                if ($helper->fields_value['oblio_company_workstation'] === $item['workStation']) {
                                    $management[] = ['name' => $item['management']];
                                }
                                $workStations[$item['workStation']] = ['name' => $item['workStation']];
                            }
                        }
                    }
                    
                    $fields[] = array(
                        'type' => 'select',
                        'label' => $this->l('Serie factura'),
                        'name' => 'oblio_company_series_name',
                        'options' => [
                            'query' => array_merge([['name' => 'Selecteaza']], $invoiceSeries),
                            'id'    => 'name',
                            'name'  => 'name',
                        ],
                        'class' => 'chosen',
                        //'lang' => true,
                        'required' => true
                    );
                    $fields[] = array(
                        'type' => 'select',
                        'label' => $this->l('Serie proforma'),
                        'name' => 'oblio_company_series_name_proforma',
                        'options' => [
                            'query' => array_merge([['name' => 'Selecteaza']], $proformaSeries),
                            'id'    => 'name',
                            'name'  => 'name',
                        ],
                        'class' => 'chosen',
                        //'lang' => true,
                        'required' => true
                    );
                    $fields[] = array(
                        'type' => 'select',
                        'label' => $this->l('Punct de lucru'),
                        'name' => 'oblio_company_workstation',
                        'options' => [
                            'query' => array_merge([['name' => 'Selecteaza']], $workStations),
                            'id'    => 'name',
                            'name'  => 'name',
                        ],
                        'class' => 'chosen',
                        //'lang' => true,
                        //'required' => true
                    );
                    $fields[] = array(
                        'type' => 'select',
                        'label' => $this->l('Gestiune'),
                        'name' => 'oblio_company_management',
                        'options' => [
                            'query' => array_merge([['name' => 'Selecteaza']], $management),
                            'id'    => 'name',
                            'name'  => 'name',
                        ],
                        'class' => 'chosen',
                        //'lang' => true,
                        //'required' => true
                    );
                    
                    $fields_form[1]['form'] = array(
                        'legend' => array(
                            'title' => $this->l('Setari Oblio'),
                        ),
                        'input' => $fields,
                        'submit' => array(
                            'title' => $this->l('Salveaza'),
                            'class' => 'btn btn-default pull-right'
                        )
                    );
                    
                    $fields_form[2]['form'] = array(
                        'legend' => array(
                            'title' => $this->l('Setari Oblio'),
                        ),
                        'input' => $this->_invoice_options,
                        'submit' => array(
                            'title' => $this->l('Salveaza'),
                            'class' => 'btn btn-default pull-right'
                        )
                    );
                    
                    $fields_form[3]['form'] = array(
                        'legend' => array(
                            'title' => $this->l('Setari Oblio'),
                        ),
                        'input' => array(
                            array(
                                'type' => 'text',
                                'label' => $this->l('Coduri produse excluse din facturi'),
                                'desc' => $this->l('separate prin virgula (",")'),
                                'name' => 'oblio_exclude_reference',
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l('Numele categoriei pe factura'),
                                'desc' => $this->l('Inlocuieste numele produsului cu numele categoriei din care face produsul respectiv'),
                                'name' => 'oblio_product_category',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'on_invoice',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l('Adauga discountul in pretul produsului'),
                                'desc' => $this->l('Produsele cu discount ocupa pe factura un singur rand cu pretul cu discount inclus'),
                                'name' => 'oblio_product_discount',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'included',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                            array(
                                'type' => 'select',
                                'label' => $this->l('Tip produse'),
                                'desc' => $this->l('Seteaza tipul contabil general al produselor'),
                                'name' => 'oblio_company_products_type',
                                'options' => [
                                    'query' => $this->_product_types,
                                    'id'    => 'name',
                                    'name'  => 'name',
                                ],
                                'class' => 'chosen',
                                //'lang' => true,
                                'required' => true
                            ),
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l('Genereaza factura automat'),
                                'desc' => $this->l('Genereaza factura automat la schimbarea statusului comenzii (Payment accepted, Delivered)'),
                                'name' => 'oblio_generate_invoice',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'status_change',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l('Descarcare de stoc la factura automata'),
                                'desc' => $this->l('Cand se genereaza factura automat produsele sunt descarcate din stoc'),
                                'name' => 'oblio_generate_invoice',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'use_stock',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
			                array(
                                'type' => 'checkbox',
                                'label' => $this->l('Rezerva stoc comenzi'),
                                'desc' => "Stocul din magazin va fi echivalentul stocului din Oblio, minus comenzile din ultimele 30 de zile care au un status diferit de 'finalizat'",
                                'name' => 'oblio_stock',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'adjusments',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),	
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l('Genereaza proforma automat'),
                                'desc' => $this->l('Genereaza proforma automat la plasarea comenzii'),
                                'name' => 'oblio_generate_proforma',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'new_order',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l(sprintf('Schimba statusul in "%s"', $this->_status_name)),
                                'desc' => $this->l(sprintf('Schimba statusul comenzii in "%s" la generarea facturii', $this->_status_name)),
                                'name' => 'oblio_generate_change',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'state',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                            array(
                                'type' => 'checkbox',
                                'label' => $this->l('Trimite email la generare factura'),
                                'desc' => $this->l('Trimite email catre client imediat dupa generarea unei facturi'),
                                'name' => 'oblio_generate_email',
                                'values' => array(
                                    'query' => array(
                                        array(
                                            'id'   => 'state',
                                            'name' => '',
                                            'val'  => '1'
                                        ),
                                    ),
                                    'id' => 'id',
                                    'name' => 'name'
                                ),
                                //'lang' => true,
                                'size' => 20,
                                'required' => false
                            ),
                        ),
                        'submit' => array(
                            'title' => $this->l('Salveaza'),
                            'class' => 'btn btn-default pull-right'
                        )
                    );
                }
            } catch (Exception $e) {
                $accessTokenHandler->clear();
            }
        }
        
        $getAjaxLink = function() {
            return sprintf('<script> var ajaxurl = "%s&action=ajax"; </script>',
                $this->context->link->getAdminLink('AdminOblioData'));
        };

        return $getAjaxLink() . $helper->generateForm($fields_form);
    }
    
    public function getContent()
    {
        $output = '';

        // < here we check if the form is submited for this module >
        if (Tools::isSubmit('submit' . $this->name)) {
            $oblio_api_email                    = strval(Tools::getValue('oblio_api_email'));
            $oblio_api_secret                   = strval(Tools::getValue('oblio_api_secret'));
            $oblio_company_cui                  = strval(Tools::getValue('oblio_company_cui'));
            $oblio_company_series_name          = strval(Tools::getValue('oblio_company_series_name'));
            $oblio_company_series_name_proforma = strval(Tools::getValue('oblio_company_series_name_proforma'));
            $oblio_company_workstation          = strval(Tools::getValue('oblio_company_workstation'));
            $oblio_company_management           = strval(Tools::getValue('oblio_company_management'));
            
            $oblio_issuer_name                  = strval(Tools::getValue('oblio_issuer_name'));
            $oblio_issuer_id                    = strval(Tools::getValue('oblio_issuer_id'));
            $oblio_deputy_name                  = strval(Tools::getValue('oblio_deputy_name'));
            $oblio_deputy_identity_card         = strval(Tools::getValue('oblio_deputy_identity_card'));
            $oblio_deputy_auto                  = strval(Tools::getValue('oblio_deputy_auto'));
            $oblio_seles_agent                  = strval(Tools::getValue('oblio_seles_agent'));
            $oblio_mentions                     = strval(Tools::getValue('oblio_mentions'));
            
            $oblio_exclude_reference                = strval(Tools::getValue('oblio_exclude_reference'));
            $oblio_product_category_on_invoice      = strval(Tools::getValue('oblio_product_category_on_invoice'));
            $oblio_product_discount_included        = strval(Tools::getValue('oblio_product_discount_included'));
            $oblio_company_products_type            = strval(Tools::getValue('oblio_company_products_type'));
            $oblio_generate_invoice_status_change   = strval(Tools::getValue('oblio_generate_invoice_status_change'));
            $oblio_generate_invoice_use_stock       = strval(Tools::getValue('oblio_generate_invoice_use_stock'));
            $oblio_generate_proforma_new_order      = strval(Tools::getValue('oblio_generate_proforma_new_order'));
            $oblio_generate_change_state            = strval(Tools::getValue('oblio_generate_change_state'));
            $oblio_generate_email_state             = strval(Tools::getValue('oblio_generate_email_state'));
            $oblio_stock_adjusments                 = strval(Tools::getValue('oblio_stock_adjusments'));	

            // < this will update the value of the Configuration variable >
            Configuration::updateValue('oblio_api_email', $oblio_api_email);
            Configuration::updateValue('oblio_api_secret', $oblio_api_secret);
            Configuration::updateValue('oblio_company_cui', $oblio_company_cui);
            Configuration::updateValue('oblio_company_series_name', $oblio_company_series_name);
            Configuration::updateValue('oblio_company_series_name_proforma', $oblio_company_series_name_proforma);
            Configuration::updateValue('oblio_company_workstation', $oblio_company_workstation);
            Configuration::updateValue('oblio_company_management', $oblio_company_management);
            
            Configuration::updateValue('oblio_issuer_name', $oblio_issuer_name);
            Configuration::updateValue('oblio_issuer_id', $oblio_issuer_id);
            Configuration::updateValue('oblio_deputy_name', $oblio_deputy_name);
            Configuration::updateValue('oblio_deputy_identity_card', $oblio_deputy_identity_card);
            Configuration::updateValue('oblio_deputy_auto', $oblio_deputy_auto);
            Configuration::updateValue('oblio_seles_agent', $oblio_seles_agent);
            Configuration::updateValue('oblio_mentions', $oblio_mentions);
            
            Configuration::updateValue('oblio_exclude_reference', $oblio_exclude_reference);
            Configuration::updateValue('oblio_product_category_on_invoice', $oblio_product_category_on_invoice);
            Configuration::updateValue('oblio_product_discount_included', $oblio_product_discount_included);
            Configuration::updateValue('oblio_company_products_type', $oblio_company_products_type);
            Configuration::updateValue('oblio_generate_invoice_status_change', $oblio_generate_invoice_status_change);
            Configuration::updateValue('oblio_generate_invoice_use_stock', $oblio_generate_invoice_use_stock);
            Configuration::updateValue('oblio_generate_proforma_new_order', $oblio_generate_proforma_new_order);
            Configuration::updateValue('oblio_generate_change_state', $oblio_generate_change_state);
            Configuration::updateValue('oblio_generate_email_state', $oblio_generate_email_state);
            Configuration::updateValue('oblio_stock_adjusments', $oblio_stock_adjusments);

            // < this will display the confirmation message >
            $output .= $this->displayConfirmation($this->l('Modificarea a fost facuta'));
        }
        return $output . $this->displayForm();
    }
    
    public function hookDisplayAdminOrderMainBottom(array $data)
    {
        return $this->hookDisplayAdminOrderLeft($data);
    }
    
    public function hookDisplayAdminOrderLeft(array $data)
    {
        if (!$this->active) {
            return '';
        }
        
        $invoice = $this->getInvoice($data['id_order']);
        $proforma = $this->getInvoice($data['id_order'], ['docType' => 'proforma']);
        $this->smarty->assign([
            'id_order'      => $data['id_order'],
            '_ps_new_style' => $this->_ps_new_style,
            'oblio'         => [
                'invoice_series'    => $invoice ? $invoice['invoice_series'] : '',
                'invoice_number'    => $invoice ? $invoice['invoice_number'] : '',
                'proforma_series'   => $proforma ? $proforma['invoice_series'] : '',
                'proforma_number'   => $proforma ? $proforma['invoice_number'] : '',
                'invoice_is_last'   => $invoice ? $invoice['invoice_is_last'] : 0,
                'proforma_is_last'  => $proforma ? $proforma['invoice_is_last'] : 0,
                'has_stock_active'  => strval(Configuration::get('oblio_company_management')) !== '',

                'options' => $this->getOptions([
                    [
                        'type' => 'select',
                        'label' => $this->l('Incaseaza'),
                        'name' => 'collect',
                        'options' => [
                            'query' => [
                                [
                                    'id'    => '',
                                    'name'  => 'Neincasat',
                                ],
                                [
                                    'id'    => 'Alta incasare numerar',
                                    'name'  => 'Alta incasare numerar',
                                ],
                                [
                                    'id'    => 'Ramburs',
                                    'name'  => 'Ramburs',
                                ],
                                [
                                    'id'    => 'Ordin de plata',
                                    'name'  => 'Ordin de plata',
                                ],
                                [
                                    'id'    => 'Mandat postal',
                                    'name'  => 'Mandat postal',
                                ],
                                [
                                    'id'    => 'Card',
                                    'name'  => 'Card',
                                ],
                                [
                                    'id'    => 'Alta incasare banca',
                                    'name'  => 'Alta incasare banca',
                                ],
                            ],
                            'id'    => 'id',
                            'name'  => 'name',
                        ],
                        'class' => $this->_ps_new_style ? '' : 'chosen',
                        'value' => '',
                        //'lang' => true,
                        'required' => true
                    ]
                ])
            ]
        ]);
        return $this->display(__FILE__, 'views/admin/invoice/view.tpl');
    }
    
    public function hookActionProductSave(array $data)
    {
        if (!$this->active) {
            return '';
        }
        $oblio = Tools::getValue('oblio');
        $oldProductAttribute = $this->getProductAttribute($data['id_product'], 'type');
        if (!empty($oblio['product_type']) || $oldProductAttribute !== false) {
            $this->setProductAttribute($data['id_product'], 'type', $oblio['product_type']);
        }
        $oldPackageNumber = $this->getProductAttribute($data['id_product'], 'package_number');
        if (!empty($oblio['package_number']) || $oldPackageNumber !== false) {
            $this->setProductAttribute($data['id_product'], 'package_number', (int) $oblio['package_number']);
        }
        if (!empty($oblio['combinations'])) {
            foreach ($oblio['combinations'] as $key=>$combination) {
                $oldPackageNumber = $this->getProductAttribute($data['id_product'], $key);
                if (!empty($combination) || $oldPackageNumber !== false) {
                    $this->setProductAttribute($data['id_product'], $key, (int) $combination);
                }
            }
        }
    }
    
    public function hookActionValidateOrder(array $data)
    {
        if (!$this->active) {
            return '';
        }
        if (!Configuration::get('oblio_generate_proforma_new_order')) {
            return '';
        }
        $options = [
            'docType' => 'proforma',
        ];
        $result = $this->generateInvoice($data['order'], $options);
    }

    public function hookDisplayBackOfficeOrderActions(array $data)
    {
        if (!$this->active) {
            return '';
        }
        $invoice = $this->getInvoice($data['id_order']);
        $proforma = $this->getInvoice($data['id_order'], ['docType' => 'proforma']);
        $this->smarty->assign([
            'id_order'       => $data['id_order'],
            'invoice_series' => $invoice ? $invoice['invoice_series'] : '',
            'invoice_number' => $invoice ? $invoice['invoice_number'] : '',
            'proforma_series' => $proforma ? $proforma['invoice_series'] : '',
            'proforma_number' => $proforma ? $proforma['invoice_number'] : '',
        ]);
        return $this->display(__FILE__, 'views/admin/invoice/button.tpl');
    }
    
    public function hookDisplayAdminProductsCombinationBottom(array $data)
    {
        $package_number_key = 'package_number_' . $data['id_product_attribute'];
        $package_number = $this->getProductAttribute($data['id_product'], $package_number_key);
        $this->context->smarty->assign([
            'oblio_package_number_key'  => $package_number_key,
            'oblio_package_number'      => $package_number,
        ]);
        return $this->display(__FILE__, 'views/admin/catalog/product_combinations.tpl');
    }

    public function hookDisplayAdminProductsExtra(array $data)
    {
        return $this->hookDisplayAdminProductsMainStepRightColumnBottom($data);
    }

    public function hookDisplayAdminProductsMainStepRightColumnBottom(array $data)
    {
        if (!$this->active) {
            return '';
        }
        $product_types = [['name' => 'Valoarea implicita', 'value' => '', 'selected' => '']];

        $productAttribute = $this->getProductAttribute($data['id_product'], 'type');
        foreach ($this->_product_types as $product_type) {
            $product_type['value'] = $product_type['name'];
            $product_type['selected'] = $productAttribute === $product_type['name'];
            $product_types[] = $product_type;
        }
        $package_number = $this->getProductAttribute($data['id_product'], 'package_number');
        $this->context->smarty->assign(array(
            'oblio_package_number'  => $package_number,
            'oblio_product_types'   => $product_types,
            'oblio_message'         => 'Pentru a face o legatura intre Prestashop si Oblio, adauga in campul Reference, codul de produs din nomenclatorul Oblio.',
        ));
        return $this->display(__FILE__, 'views/admin/catalog/product.tpl');
    }
    
    public function hookActionPDFInvoiceRender(array $params)
    {
        if (!$this->active) {
            return '';
        }
        $invoice  = $params['order_invoice_list']->getFirst();
        $order    = $invoice->getOrder();
        $row      = $this->getInvoice($order->id, []);
        if (empty($row) || Tools::getValue('vieworder') === '') { // skip status update
            return;
        }
        
        $result   = $this->generateInvoice($order);
        if (isset($result['link'])) {
            Tools::redirect($result['link']);
        } else if ($result['error']) {
            $this->displayErrorApi($result['error']);
            die;
        }
    }
    
    // generate on payment/delivered
    public function hookActionOrderHistoryAddAfter(array $params)
    {
        if (!$this->active) {
            return '';
        }
        $id_order = (int) $params['order_history']->id_order;
        $generate_invoice_status_change = (bool) Configuration::get('oblio_generate_invoice_status_change');
        $oblio_generate_invoice_use_stock = (bool) Configuration::get('oblio_generate_invoice_use_stock');

        if (
            $generate_invoice_status_change &&
            in_array((int)$params['order_history']->id_order_state, [2, 5]) &&
            $id_order > 0
            ) {
            $order = new Order($id_order);
            $this->generateInvoice($order, [
                'useStock' => $oblio_generate_invoice_use_stock,
            ]);
        }
    }
    
    public function getInvoice($id_order, $options = [])
    {
        if (empty($options['docType'])) {
            $options['docType'] = 'invoice';
        }
        switch ($options['docType']) {
            case 'proforma':
                $type = self::PROFORMA;
                break;
            default:
                $type = self::INVOICE;
        }
        $sql = sprintf('SELECT * FROM ' . _DB_PREFIX_ . $this->_table_invoice . ' WHERE `id_order`=%d AND `type`=%d', $id_order, $type);
        $invoice = Db::getInstance()->getRow($sql);
        if ($invoice) {
            $sql = 'SELECT MAX(`invoice_number`) AS last_invoice_number FROM ' . _DB_PREFIX_ . $this->_table_invoice . ' WHERE `type`=' . $type;
            $last_number = Db::getInstance()->getRow($sql);
            $invoice['invoice_is_last'] = $last_number['last_invoice_number'] === $invoice['invoice_number'];
        }
        return $invoice;
    }

    public function getProductAttribute($id_product, $attribute)
    {
        $sql = sprintf('SELECT `value` FROM %s WHERE `id_product`=%d AND `attribute`="%s"',
            _DB_PREFIX_ . $this->_table_product_attributes, $id_product, pSQL($attribute));
        $result = Db::getInstance()->getValue($sql);
        return $result;
    }

    public function setProductAttribute($id_product, $attribute, $value)
    {
        $productAttribute = $this->getProductAttribute($id_product, $attribute);
        $data = [
            'id_product'    => (int) $id_product,
            'attribute'     => pSQL($attribute),
            'value'         => pSQL($value),
        ];
        if ($productAttribute === false) {
            Db::getInstance()->insert($this->_table_product_attributes, $data);
        } else {
            Db::getInstance()->update($this->_table_product_attributes, $data,
                sprintf('`id_product`=%d AND `attribute`="%s"', $id_product, pSQL($attribute)));
        }
    }
    
    public function generateInvoice($order, $options = [])
    {
        if (!$order) {
            return array();
        }
        $cui         = Configuration::get('oblio_company_cui');
        $email       = Configuration::get('oblio_api_email');
        $secret      = Configuration::get('oblio_api_secret');
        $workstation = Configuration::get('oblio_company_workstation');
        $management  = Configuration::get('oblio_company_management');
        
        $exclude_reference = array();
        $oblio_exclude_reference = Configuration::get('oblio_exclude_reference');
        if (trim($oblio_exclude_reference) !== '') {
            $exclude_reference  = array_map('trim', explode(',', $oblio_exclude_reference));
        }
        $oblio_product_category_on_invoice = (bool) Configuration::get('oblio_product_category_on_invoice');
        $oblio_product_discount_included   = (bool) Configuration::get('oblio_product_discount_included');
        $oblio_company_products_type       = strval(Configuration::get('oblio_company_products_type'));
        $oblio_generate_email_state        = strval(Configuration::get('oblio_generate_email_state'));
        
        $fields = ['issuer_name', 'issuer_id', 'deputy_name', 'deputy_identity_card', 'deputy_auto',
            'seles_agent', 'mentions'];
        foreach ($fields as $field) {
            if (isset($options[$field])) {
                ${$field} = $options[$field];
                // Configuration::updateValue('oblio_' . $field, ${$field});
            } else {
                ${$field} = Configuration::get('oblio_' . $field);
            }
        }

        // collect
        $collect = [];
        $collectType = isset($options['collect']) ? $options['collect'] : '';
        $collectTypes = [
            'Chitanta',
            'Bon fiscal',
            'Alta incasare numerar',
            'Ramburs',
            'Ordin de plata',
            'Mandat postal',
            'Card',
            'CEC',
            'Bilet ordin',
            'Alta incasare banca'
        ];
        if (in_array($collectType, $collectTypes)) {
            $collect = [
                'type'              => $collectType,
                'documentNumber'    => '#' . $order->id,
            ];
        }

        foreach ($this->_tags as $key=>$tag) {
            switch ($tag) {
                case 'payment':
                    $payments = $order->getOrderPayments();
                    if (empty($payments)) {
                        $payment = 'Neplatit';
                    } else {
                        $lastPayment = end($payments);
                        $payment = $lastPayment->payment_method;
                    }
                    $mentions = str_replace('#' . $tag . '#', $payment, $mentions);
                    break;
                default:
                    $mentions = str_replace('#' . $tag . '#', $order->{$key}, $mentions);
            }
        }
        
        if (empty($options['docType'])) {
            $options['docType'] = 'invoice';
        }
        switch ($options['docType']) {
            case 'proforma':
                $series_name = Configuration::get('oblio_company_series_name_proforma');
                break;
            default:
                $series_name = Configuration::get('oblio_company_series_name');
        }
        
        if (!$cui || !$email || !$secret || !$series_name) {
            return [
                'error' => 'Intra la module si configureaza folosind datele din contul Oblio'
            ];
        }
        
        require_once 'classes/OblioApi.php';
        require_once 'classes/OblioApiPrestashopAccessTokenHandler.php';
        
        $row = $this->getInvoice($order->id, $options);
        if (!empty($row['invoice_number'])) {
            try {
                $api = new OblioApi($email, $secret, new OblioApiPrestashopAccessTokenHandler());
                $api->setCif($cui);

                $result = $api->get($options['docType'], $row['invoice_series'], $row['invoice_number']);
                return $result['data'];
            } catch (Exception $e) {
                // delete old
                // Db::getInstance()->delete($this->_table_invoice, sprintf('`id_order`=%d', $order->id));
                $this->updateNumbers($order->id, [
                    $options['docType'] . '_series' => '',
                    $options['docType'] . '_number' => 0,
                ]);
            }
        }
        
        $address  = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency($order->id_currency);
        $products = $order->getCartProducts();
        try {
            $contact = $address->firstname . ' ' . $address->lastname;
            $cuiClient = empty($address->vat_number) ? $address->dni : $address->vat_number;
            if (in_array($cuiClient, ['dni', '0'])) {
                $cuiClient = '';
            }
            
            $rc = '';
            $iban = '';
            $bank = '';
            $invoiceAddress = $address->address1;
            if (property_exists($address, 'address_facturare')) {
                $rc = $address->address_reg_com;
                $iban = $address->address_account;
                $bank = $address->address_bank;
                if (strlen(trim($address->address_info)) > 0) {
                    $invoiceAddress = trim($address->address_info);
                }
            }
            
            $data = array(
                'cif'                => $cui,
                'client'             => [
                    'cif'           => $cuiClient,
                    'name'          => empty(trim($address->company)) ? $contact : $address->company,
                    'rc'            => $rc,
                    'code'          => '',
                    'address'       => $invoiceAddress,
                    'state'         => State::getNameById($address->id_state),
                    'city'          => $address->city,
                    'country'       => $address->country,
                    'iban'          => $iban,
                    'bank'          => $bank,
                    'email'         => $customer->email,
                    'phone'         => $address->phone == '' ? $address->phone_mobile : $address->phone,
                    'contact'       => $contact,
                    'vatPayer'      => preg_match('/RO/i', $cuiClient),
                    'save'          => true,
                ],
                'issueDate'          => date('Y-m-d'),
                'dueDate'            => '',
                'deliveryDate'       => '',
                'collectDate'        => '',
                'seriesName'         => $series_name,
                'collect'            => $collect,
                'referenceDocument'  => $this->_referenceDocument($order->id, $options),
                'language'           => 'RO',
                'precision'          => 2,
                'currency'           => $currency->iso_code,
                // 'exchangeRate'       => 1 / $currency->conversion_rate,
                'products'           => [],
                'issuerName'         => $issuer_name,
                'issuerId'           => $issuer_id,
                'noticeNumber'       => '',
                'internalNote'       => '',
                'deputyName'         => $deputy_name,
                'deputyIdentityCard' => $deputy_identity_card,
                'deputyAuto'         => $deputy_auto,
                'selesAgent'         => $seles_agent,
                'mentions'           => $mentions,
                'value'              => 0,
                'workStation'        => $workstation,
                'useStock'           => isset($options['useStock']) ? (int) $options['useStock'] : 0,
                'sendEmail'          => $oblio_generate_email_state,
            );
            
            if (empty($data['referenceDocument'])) {
                $hasDiscounts = false;
                $total = 0;
                foreach ($products as $item) {
                    if (!empty($exclude_reference) && in_array($item['product_reference'], $exclude_reference)) {
                        continue;
                    }
                    $name = $item['product_name'];
                    $code = $item['product_reference'];
                    $vatName = $item['tax_rate'] > 0 ? null : 'SDD';
                    $productType = $this->getProductAttribute($item['product_id'], 'type');
                    if (!$productType) {
                        $productType = $oblio_company_products_type ? $oblio_company_products_type : 'Marfa';
                    }
                    if ($oblio_product_category_on_invoice) {
                        $product = new Product($item['product_id']);
                        $category = new Category((int) $product->id_category_default, (int) $this->context->language->id);
                        $name = $category->name;
                        $code = '';
                        $productType = 'Serviciu';
                    }
                    $price     = self::getPrice($item, $currency, true);
                    $fullPrice = self::getPrice($item, $currency, false);
                    if ($oblio_product_discount_included) {
                        $fullPrice = $price;
                    }
                    $package_number = 1;
                    if ($item['id_product_attribute'] > 0) {
                        $package_number_key = 'package_number_' . $item['id_product_attribute'];
                        $package_number = (int) $this->getProductAttribute($item['id_product'], $package_number_key);
                    }
                    if ($package_number === 0) {
                        $package_number = (int) $this->getProductAttribute($item['id_product'], 'package_number');
                        if ($package_number === 0) {
                            $package_number = 1;
                        }
                    }
                    $data['products'][] = [
                        'name'          => $name,
                        'code'          => $code,
                        'description'   => '',
                        'price'         => $fullPrice / $package_number,
                        'measuringUnit' => 'buc',
                        'currency'      => $currency->iso_code,
                        'vatName'       => $vatName,
                        'vatPercentage' => $item['tax_rate'],
                        'vatIncluded'   => true,
                        'quantity'      => $item['product_quantity'] * $package_number,
                        'productType'   => $productType,
                        'management'    => $management,
                    ];
                    $total += $price * $item['product_quantity'];
                    if (!$oblio_product_discount_included && $price !== $fullPrice) {
                        $totalOriginalPrice = $fullPrice * $item['product_quantity'];
                        $difference = $totalOriginalPrice - $item['total_price_tax_incl'];

                        if ($difference > 0) {
                            $data['products'][] = [
                                'name'          => sprintf('Discount "%s"', $name),
                                'discount'      => round($difference, $data['precision']),
                                'discountType'  => 'valoric',
                            ];
                            $hasDiscounts = true;
                        } else {
                            $lastKey = array_key_last($data['products']);
                            $data['products'][$lastKey]['price'] = $item['unit_price_tax_incl'];
                        }
                    }
                }
                if ($order->total_shipping_tax_incl > 0) {
                    $data['products'][] = [
                        'name'          => 'Transport',
                        'code'          => '',
                        'description'   => '',
                        'price'         => $order->total_shipping_tax_incl,
                        'measuringUnit' => 'buc',
                        'currency'      => $currency->iso_code,
                        // 'vatName'       => 'Normala',
                        'vatPercentage' => round($order->total_shipping_tax_incl / $order->total_shipping_tax_excl * 100) - 100,
                        'vatIncluded'   => true,
                        'quantity'      => 1,
                        'productType'   => 'Serviciu',
                    ];
                    $total += $order->total_shipping_tax_incl;
                }
                if ($order->total_discounts_tax_incl > 0) {
                    if ($hasDiscounts) {
                        $data['products'][] = [
                            'name'          => 'Discount',
                            'code'          => '',
                            'description'   => '',
                            'price'         => $order->total_discounts_tax_incl,
                            'measuringUnit' => 'buc',
                            'currency'      => $currency->iso_code,
                            // 'vatName'       => 'Normala',
                            'vatPercentage' => round($order->total_discounts_tax_incl / $order->total_discounts_tax_excl * 100) - 100,
                            'vatIncluded'   => true,
                            'quantity'      => -1,
                            'productType'   => 'Serviciu',
                        ];
                    } else {
                        $data['products'][] = [
                            'name'              => 'Discount',
                            'discount'          => $order->total_discounts_tax_incl,
                            'discountType'      => 'valoric',
                            'discountAllAbove'  => 1
                        ];
                    }
                    $total -= $order->total_discounts_tax_incl;
                }
				
                if (number_format($total, 2, '.', '') !== number_format($order->total_paid_tax_incl, 2, '.', '')) {
                    $difference = number_format($order->total_paid_tax_incl, 2, '.', '') - number_format($total, 2, '.', '');
                    $data['products'][] = [
                        'name'          => $difference > 0 ? 'Alte taxe' : 'Discount',
                        'code'          => '',
                        'description'   => '',
                        'price'         => abs($difference),
                        'measuringUnit' => 'buc',
                        'currency'      => $currency->iso_code,
                        // 'vatName'       => 'Normala',
                        'vatPercentage' => round($order->total_paid_tax_incl / $order->total_paid_tax_excl * 100) - 100,
                        'vatIncluded'   => true,
                        'quantity'      => $difference > 0 ? 1 : -1,
                        'productType'   => 'Serviciu',
                    ];
                }
            }
            
            $api = new OblioApi($email, $secret, new OblioApiPrestashopAccessTokenHandler());
            switch ($options['docType']) {
                case 'proforma': $result = $api->createProforma($data); break;
                default:
                    $result = $api->createInvoice($data);

                    $changeState = Configuration::get('oblio_generate_change_state');
                    $state_id = (int) Configuration::get(self::PS_OS_NAME);
                    if ($changeState && $state_id) {
                        $history = new OrderHistory();
                        $history->id_order = (int)$order->id;
                        $history->id_employee = (int) $this->context->employee->id;

                        $use_existings_payment = !$order->hasInvoice();
                        $history->changeIdOrderState($state_id, $order, $use_existings_payment);
                        $history->addWithemail(true, []);
                    }
            }
            
            $this->updateNumbers($order->id, [
                $options['docType'] . '_series' => $result['data']['seriesName'],
                $options['docType'] . '_number' => $result['data']['number'],
            ]);
            return $result['data'];
        } catch (Exception $e) {
            return array(
                'error' => $e->getMessage()
            );
        }
    }
    
    public function updateNumbers($id_order, $options = [])
    {
        $invoice = [];
        if (isset($options['invoice_series']) && isset($options['invoice_number'])) {
            $invoice = [
                'id_order'       => (int) $id_order,
                'type'           => self::INVOICE,
                'invoice_series' => pSQL($options['invoice_series']),
                'invoice_number' => (int) $options['invoice_number'],
            ];
        } else if (isset($options['proforma_series']) && isset($options['proforma_number'])) {
            $invoice = [
                'id_order'       => (int) $id_order,
                'type'           => self::PROFORMA,
                'invoice_series' => pSQL($options['proforma_series']),
                'invoice_number' => (int) $options['proforma_number'],
            ];
        }
        
        if (empty($invoice)) {
            return false;
        }
        if ($invoice['invoice_number'] === 0) {
            $where = sprintf('`id_order`=%d AND `type`=%d', $invoice['id_order'], $invoice['type']);
            Db::getInstance()->delete($this->_table_invoice, $where);
        } else {
            Db::getInstance()->insert($this->_table_invoice, $invoice);
        }
        return true;
    }
    
    public function addTax($priceExcl, $taxRate) {
        return round($priceExcl * (1 + ($taxRate / 100)), 2);
    }
    
    public function deleteDoc($order, $options = [])
    {
        if (!$order) {
            return array();
        }
        if (empty($options['docType'])) {
            $options['docType'] = 'invoice';
        }
        $row = $this->getInvoice($order->id, $options);
        
        $result      = array(
            'type'    => 'error',
            'message' => '',
        );
        
        if ($row) {
            $email       = Configuration::get('oblio_api_email');
            $secret      = Configuration::get('oblio_api_secret');
            $cui         = Configuration::get('oblio_company_cui');
            
            $series_name = $row['invoice_series'];
            $number      = $row['invoice_number'];
            try {
                require_once 'classes/OblioApi.php';
                require_once 'classes/OblioApiPrestashopAccessTokenHandler.php';
                
                $accessTokenHandler = new OblioApiPrestashopAccessTokenHandler();
                $api = new OblioApi($email, $secret, $accessTokenHandler);
                $api->setCif($cui);
                $response = $api->delete($options['docType'], $series_name, $number);
                if ($response['status'] === 200) {
                    // delete old
                    // Db::getInstance()->delete($this->_table_invoice, sprintf('`id_order`=%d', $row['id_order']));
                    $this->updateNumbers($row['id_order'], [
                        $options['docType'] . '_series' => '',
                        $options['docType'] . '_number' => 0,
                    ]);
                    $result['type'] = 'success';
                    $result['message'] = 'Factura a fost stearsa';
                }
            } catch (Exception $e) {
                $result['message'] = $e->getMessage(); // 'A aparut o eroare, posibil factura pe care vreti sa o stergeti nu este ultima din serie';
            }
        }
        return $result;
    }
    
    public function syncStock(&$error = '')
    {
        global $argc, $argv;
        $cui         = Configuration::get('oblio_company_cui');
        $email       = Configuration::get('oblio_api_email');
        $secret      = Configuration::get('oblio_api_secret');
        $workstation = Configuration::get('oblio_company_workstation');
        $management  = Configuration::get('oblio_company_management');
        $products_type = strval(Configuration::get('oblio_company_products_type'));
        $oblio_stock_adjusments = (int) Configuration::get('oblio_stock_adjusments');
        
        if (!$email || !$secret || !$cui) {
            return 0;
        }
        
        $total = 0;
        try {
            require_once 'classes/Products.php';
            require_once 'classes/OblioApi.php';
            require_once 'classes/OblioApiPrestashopAccessTokenHandler.php';
            $accessTokenHandler = new OblioApiPrestashopAccessTokenHandler();
            $api = new OblioApi($email, $secret, $accessTokenHandler);
            $api->setCif($cui);
            $ordersQty = [];

            $offset = 0;
            $limitPerPage = 250;
            $model = new Oblio_Products();

            if ($oblio_stock_adjusments === 1) {
                $ordersQty = $model->getOrdersQty();
            }
            do {
                if ($offset > 0) {
                    usleep(200000);
                }
                $products = $api->nomenclature('products', null, [
                    'workStation' => $workstation,
                    'management'  => $management,
                    'offset'      => $offset,
                ]);
                $index = 0;
                foreach ($products['data'] as $product) {
                    $index++;
                    $post = $model->find($product);
                    $productType = $post ? $this->getProductAttribute($post->id, 'type') : null;
                    if (!$productType) {
                        $productType = $products_type ? $products_type : 'Marfa';
                    }
                    if ($post && $productType !== $product['productType']) {
                        continue;
                    }
                    if ($post) {
                        $model->update($post->id, $product, $ordersQty);
                    } else {
                        // $model->insert($product);
                    }
                    
                }
                $offset += $limitPerPage; // next page
            } while ($index === $limitPerPage);
            $total = $offset - $limitPerPage + $index;
        } catch (Exception $e) {
            $error = $e->getMessage();
            // $accessTokenHandler->clear();
        }
        return $total;
    }
    public function regerateReferenceIfNeeded()
    {
        require_once 'classes/Products.php';
        $model = new Oblio_Products();
        return $model->regerateReferenceIfNeeded();
    }

    public function exportProducts()
    {
        require_once 'classes/Products.php';
        $model = new Oblio_Products();
        return $model->exportProducts();
    }
    
    public function displayErrorApi($message)
    {
        echo sprintf('<div style="border:1px solid #800000;font-size:9pt;font-family:monospace;color:#800000;padding:1em;margin:8px;background:#eadddd">%s</div>', $message);
    }
    
    public function ajaxHandler($options)
    {
        $type       = isset($options['type']) ? $options['type'] : '';
        $cui        = isset($options['cui']) ? $options['cui'] : '';
        $name       = isset($options['name']) ? $options['name'] : '';
        $result     = array();
        
        $email      = Configuration::get('oblio_api_email');
        $secret     = Configuration::get('oblio_api_secret');
        
        if (!$email || !$secret) {
            return '[]';
        }
        
        try {
            require_once 'classes/OblioApi.php';
            require_once 'classes/OblioApiPrestashopAccessTokenHandler.php';
            
            $accessTokenHandler = new OblioApiPrestashopAccessTokenHandler();
            $api = new OblioApi($email, $secret, $accessTokenHandler);
            $api->setCif($cui);
            
            switch ($type) {
                case 'series_name':
                    $response = $api->nomenclature('series', '');
                    $result = $response['data'];
                    break;
                case 'workstation':
                case 'management':
                    $response = $api->nomenclature('management', '');
                    $workStations = array();
                    $management = array();
                    foreach ($response['data'] as $item) {
                        if ($name === $item['workStation']) {
                            $management[] = ['name' => $item['management']];
                        }
                        $workStations[$item['workStation']] = ['name' => $item['workStation']];
                    }
                    switch ($type) {
                        case 'workstation': $result = $workStations; break;
                        case 'management': $result = $management; break;
                    }
                    break;
            }
        } catch (Exception $e) {
            // do nothing
        }
        return json_encode($result);
    }

    public function getOptions($options = [])
    {
        foreach ($this->_invoice_options as $option) {
            $option['value'] = Configuration::get($option['name']);
            $options[] = $option;
        }
        return $options;
    }

    private function _initInvoiceOptions()
    {
        $this->_invoice_options = array(
            array(
                'type' => 'text',
                'label' => $this->l('Intocmit de'),
                'name' => 'oblio_issuer_name',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
            array(
                'type' => 'text',
                'label' => $this->l('CNP'),
                'name' => 'oblio_issuer_id',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
            
            array(
                'type' => 'text',
                'label' => $this->l('Delegat'),
                'name' => 'oblio_deputy_name',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Carte Identitate'),
                'name' => 'oblio_deputy_identity_card',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Auto'),
                'name' => 'oblio_deputy_auto',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Agent vanzari'),
                'name' => 'oblio_seles_agent',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
            
            array(
                'type' => 'textarea',
                'label' => $this->l('Mentiuni'),
                'desc' => sprintf($this->l('se pot adauga tagurile %s'), '#' . implode('#, #', $this->_tags) . '#'),
                'name' => 'oblio_mentions',
                //'lang' => true,
                'size' => 20,
                'required' => false
            ),
        );
    }
    
    private function _referenceDocument($id_order, $options = [])
    {
        if (empty($options['docType'])) {
            $options['docType'] = 'invoice';
        }
        switch ($options['docType']) {
            case 'invoice':
                $proforma = $this->getInvoice($id_order, ['docType' => 'proforma']);
                if ($proforma) {
                    return [
                        'type'          => 'Proforma',
                        'seriesName'    => $proforma['invoice_series'],
                        'number'        => $proforma['invoice_number']
                    ];
                }
                break;
        }
        return [];
    }
    
    public static function getPrice($item, $currencyTo, $usereduc = false)
    {
        if ($usereduc) {
            $price = $item['unit_price_tax_incl'];
        } else {
            $price = ($item['original_product_price'] * (1 + $item['tax_rate'] / 100));
        }
        return number_format($price, 4, '.', '');
    }
}

if (!function_exists('pr')) {
    function pr($array)
    {
        echo '<pre>', print_r($array, 1), '</pre>';
    }
}
