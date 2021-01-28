<?php

class OblioApiPrestashopAccessTokenHandler implements OblioApiAccessTokenHandlerInterface {
    private $_key = 'oblio_api_access_token';
    
    public function get() {
        $accessTokenJson = Configuration::get($this->_key);
        if ($accessTokenJson) {
            $accessToken = json_decode($accessTokenJson);
            if ($accessToken && $accessToken->request_time + $accessToken->expires_in > time()) {
                return $accessToken;
            }
        }
        return false;
    }
    
    public function set($accessToken) {
        if (!is_string($accessToken)) {
            $accessToken = json_encode($accessToken);
        }
        Configuration::updateValue($this->_key, $accessToken);
    }
    
    public function clear() {
        Configuration::updateValue($this->_key, '');
    }
}