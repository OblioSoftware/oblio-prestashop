<?php

class AdminOblioInvoiceController extends ModuleAdminController
{
    /**
     * @var Oblio
     */
    public $module;

    public function init()
    {
        parent::init();
        $this->ajax = true;
    }
    
    public function displayAjax()
    {
        $id_order = (int) Tools::getValue('id_order');
        if (!$id_order) {
            die;
        }
        $options = [
            'useStock'              => (int) Tools::getValue('useStock'),
            'issuer_name'           => Tools::getValue('oblio_issuer_name', null),
            'issuer_id'             => Tools::getValue('oblio_issuer_id', null),
            'deputy_name'           => Tools::getValue('oblio_deputy_name', null),
            'deputy_identity_card'  => Tools::getValue('oblio_deputy_identity_card', null),
            'deputy_auto'           => Tools::getValue('oblio_deputy_auto', null),
            'seles_agent'           => Tools::getValue('oblio_seles_agent', null),
            'mentions'              => Tools::getValue('oblio_mentions', null),
            'collect'               => Tools::getValue('collect', null),
            'docType'               => Tools::getValue('oblio_doc_type', 'invoice'),
        ];
        $order = new Order($id_order);
        switch (Tools::getValue('delete-doc')) {
            case 'proforma':
            case 'invoice':
                $result = $this->module->deleteDoc($order, $options);
                break;
            default:
                $result = $this->module->generateInvoice($order, $options);
                if (Tools::getValue('redirect')) {
                    if (isset($result['link'])) {
                        Tools::redirect($result['link']);
                    } else if ($result['error']) {
                        $this->module->displayErrorApi($result['error']);
                    }
                    die;
                }
        }
        echo json_encode($result);
    }
    
    public function viewAccess($disable = false)
    {
        return true;
    }
}