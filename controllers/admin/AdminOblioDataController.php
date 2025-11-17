<?php

class AdminOblioDataController extends ModuleAdminController
{
    /**
     * @var Oblio
     */
    public $module;

    public function init()
    {
        parent::init();
        $this->bootstrap = true;
        if (Tools::getValue('action')) {
            $this->ajax = true;
            $this->json = true;
        }
    }
    
    public function initContent()
    {
        parent::initContent();
        srand((int) substr(preg_replace('/[a-z]/i', '', md5(_PS_BASE_URL_)), 0, 8));
        $minute = rand(0, 59); // fixed for this domain
        srand(time());
		$this->context->smarty->assign(array(
			'btnName'       => 'Sincronizare',
			'secret'        => Configuration::get('oblio_api_secret'),
			'cron_minute'   => $minute,
		));
		$this->setTemplate('view.tpl');
    }
    
    public function displayAjax()
    {
        $type = Tools::getValue('type');
        switch ($type) {
            case 'series_name':
            case 'workstation':
            case 'management':
                echo $this->module->ajaxHandler([
                    'type' => $type,
                    'cui'  => Tools::getValue('cui'),
                    'name' => Tools::getValue('name')
                ]);
                break;
            case 'generate_reference':
                echo $this->module->regerateReferenceIfNeeded();
                break;
            case 'export':
                $this->module->exportProducts();
                break;
            default:
                $total = $this->module->syncStock($error);
                echo json_encode([$total, $error]);
        }
    }
}