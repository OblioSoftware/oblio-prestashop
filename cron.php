<?php
error_reporting(0);

$siteBasePath = dirname(__FILE__) . '/../../';
require_once $siteBasePath . 'config/config.inc.php';

if ($argc > 1) {
    $key = $argv[1];
} else {
    $key = Tools::getValue('key');
}

if ($key === Configuration::get('oblio_api_secret')) {
    $oblio = Module::getInstanceByName('oblio');
    
    $total = $oblio->syncStock($error);
    if ($error) {
        echo $error;
    } else {
        echo sprintf('Au fors updatate %d produse', $total);
    }
}
