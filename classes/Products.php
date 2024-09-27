<?php

class Oblio_Products {
    /**
     *  Finds product if it exists
     *  @param array data
     *  @return object post
     */
    public function find($data) {
        $id_product = 0;
        if (strlen($data['code']) > 0) {
            $sql = "SELECT id_product FROM `" . _DB_PREFIX_ . "product`
                    WHERE `reference`='" . pSQL($data['code']) . "'";
            $id_product = (int) Db::getInstance()->getValue($sql);
            
            if ($id_product === 0) {
                $sql = "SELECT id_product FROM `" . _DB_PREFIX_ . "product_attribute`
                        WHERE `reference`='" . pSQL($data['code']) . "'";
                $id_product = (int) Db::getInstance()->getValue($sql);
            }
        }
        if ($id_product === 0) {
            $sql = "SELECT id_product FROM `" . _DB_PREFIX_ . "product_lang`
                    WHERE `name`='" . pSQL($data['name']) . "'";
            $id_product = (int) Db::getInstance()->getValue($sql);
        }
        return $this->get($id_product);
    }
    
    /**
     *  Finds product by id
     *  @param int id_product
     *  @return object post
     */
    public function get($id_product) {
        if (!$id_product) {
            return null;
        }
        return new Product($id_product);
    }
    
    /**
     *  Insert product
     *  @param array data
     *  @return bool
     */
    public function insert($data) {
        if (empty($data['price'])) {
            return false;
        }
        $langs = language::getLanguages();
        
        $product = new Product();
        $product->name         = [];
        $product->description  = [];
        $product->description_short = [];
        $product->link_rewrite = [];
        foreach ($langs as $lang) {
            $product->name[$lang['id_lang']]              = $data['name'];
            $product->description[$lang['id_lang']]       = $data['description'];
            $product->description_short[$lang['id_lang']] = $data['description'];
            $product->link_rewrite[$lang['id_lang']]      = Tools::link_rewrite($data['name']);
        }
        // $product->price                 = $this->getPrice($data);
        // $product->id_tax_rules_group    = $this->getTaxRulesGroupId($data['vatPercentage']);
        $product->unity                 = $data['measuringUnit'];
        $product->reference             = $data['code'];
        $product->quantity              = isset($data['quantity']) ? $data['quantity'] : 99999;
        $product->out_of_stock          = isset($data['quantity']) ? 0 : 1;
        
        if ($product->add()) {
            StockAvailable::setQuantity((int)$product->id, 0, $product->quantity, Context::getContext()->shop->id);
        }
        return true;
    }
    
    /**
     *  Update product
     *  @param int id_product
     *  @param array data
     *  @return bool
     */
    public function update($id_product, $data, $ordersQty = []) {
        if (empty($data['price'])) {
            return false;
        }
        $langs = language::getLanguages();
        
        $product = new Product($id_product);
        // $product->id_tax_rules_group = $this->getTaxRulesGroupId($data['vatPercentage']);
        $combinations = $product->getAttributeCombinations(Context::getContext()->language->id);
        $combinationId = 0;
        $quantity = 0;
        if (empty($combinations)) {
            // $product->price        = $this->getPrice($data);
            // $product->reference    = $data['code'];
            $product->quantity     = isset($data['quantity']) ? $data['quantity'] : 0;
            $product->out_of_stock = isset($data['quantity']) ? 0 : 1;
            $quantity = $product->quantity;
        } else {
            foreach ($combinations as $combination) {
                if ($combination['reference'] === $data['code']) {
                    $combinationId = (int) $combination['id_product_attribute'];
                    $obj = new Combination($combinationId);
                    
                    $obj->setFieldsToUpdate(array(
                        // 'price'     => true,
                        'quantity'  => true,
                    ));
                    
                    // $obj->price    = $this->getPrice($data);
                    $obj->quantity = isset($data['quantity']) ? $data['quantity'] : 0;
                    
                    $quantity = $obj->quantity;
                    
                    $obj->save();
                    break;
                }
            }
        }
        if (isset($ordersQty[$id_product])) {
            $quantity -= $ordersQty[$id_product];
        }
        // if ($product->update()) {
            StockAvailable::setQuantity((int)$product->id, $combinationId, $quantity, Context::getContext()->shop->id);
        // }
        return true;
    }
    
    /**
     *  Get product price
     *  @param array data
     *  @return float
     */
    public function getPrice($data) {
        $price = $data['price'];
        if ((int) $data['vatIncluded'] === 1) {
            $price /= 1 + $data['vatPercentage'] / 100;
        }
        return round($price, 6);
    }
    
    /**
     *  Get Tax Rules Group Id
     *  @param float vat
     *  @return int
     */
    public function getTaxRulesGroupId($vat) {
        $countryId = (int) Context::getContext()->country->id;
        $sql = "SELECT tr.id_tax_rules_group FROM `" . _DB_PREFIX_ . "tax_rule` tr
                JOIN `" . _DB_PREFIX_ . "tax` t ON(t.`id_tax`=tr.`id_tax`)
                JOIN `" . _DB_PREFIX_ . "tax_rules_group` trg ON(trg.`id_tax_rules_group`=tr.`id_tax_rules_group`)
                WHERE tr.id_country='" . $countryId . "' AND t.`rate`='" . pSQL($vat) . "' AND t.`deleted`=0 AND trg.`deleted`=0";
        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     *  Get all products
     *  @return array
     */
    public function getAll() {
        $sql = 'SELECT DISTINCT pl.`name`, p.`id_product`, p.`reference`, pl.`id_shop`
                FROM `'._DB_PREFIX_.'product` p
                LEFT JOIN `'._DB_PREFIX_.'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop ='.Context::getContext()->shop->id.')
                LEFT JOIN `'._DB_PREFIX_.'product_lang` pl
                    ON (pl.`id_product` = p.`id_product` AND pl.id_shop ='.Context::getContext()->shop->id.' AND pl.`id_lang` = '.Context::getContext()->language->id.')
                GROUP BY pl.`id_product`';
        return Db::getInstance()->executeS($sql);
    }

    public function getCombinations($product_id = null, $id_lang = null) {
        if ($id_lang === null) {
            $id_lang = Context::getContext()->language->id;
        }
        $sql = "SELECT
                    pac.id_product_attribute, (SELECT SUM(quantity) FROM " . _DB_PREFIX_ . "stock_available WHERE id_product_attribute = pac.id_product_attribute) as quantity,
                    GROUP_CONCAT(' - ', agl.name, ' : ', al.name ORDER BY agl.id_attribute_group SEPARATOR '') as attribute_designation, pa.reference
                FROM " . _DB_PREFIX_ . "product_attribute_combination pac
                LEFT JOIN " . _DB_PREFIX_ . "attribute a ON a.id_attribute = pac.id_attribute
                LEFT JOIN " . _DB_PREFIX_ . "attribute_group ag ON ag.id_attribute_group = a.id_attribute_group
                LEFT JOIN " . _DB_PREFIX_ . "attribute_lang al ON (a.id_attribute = al.id_attribute AND al.id_lang = " . (int) $id_lang . ")
                LEFT JOIN " . _DB_PREFIX_ . "attribute_group_lang agl ON (ag.id_attribute_group = agl.id_attribute_group AND agl.id_lang =  " . (int) $id_lang . ")
                LEFT JOIN " . _DB_PREFIX_ . "product_attribute pa ON (pa.id_product_attribute = pac.id_product_attribute)
                WHERE pac.id_product_attribute IN (
                    SELECT pa.id_product_attribute
                    FROM " . _DB_PREFIX_ . "product_attribute pa
                    WHERE pa.id_product = " . (int) $product_id . "
                    GROUP BY pa.id_product_attribute
                )
                GROUP BY pac.id_product_attribute ";
        return Db::getInstance()->executeS($sql);
    }

    public function regerateReferenceIfNeeded() {
        $items = $this->getAll();
        $id_lang = Context::getContext()->language->id;
        foreach ($items as $item) {
            $product = new Product($item['id_product']);
            $combinations = $product->getAttributeCombinations($id_lang);
            if (empty($combinations)) {
                // update product
                $reference = trim($product->reference);
                if (strlen($reference) === 0) {
                    $product->reference = $this->generateCode($product->id);
                    $product->update();
                }
            } else {
                // update combinations
                foreach ($combinations as $combination) {
                    $reference = trim($combination['reference']);
                    if (strlen($reference) === 0) {
                        $combinationId = (int) $combination['id_product_attribute'];
                        $obj = new Combination($combinationId);
                        $obj->reference = $this->generateCode($combinationId);
                        $obj->save();
                    }
                }
            }
        }
        return 0;
    }

    public function exportProducts() {
        $items = $this->getAll();

        $context = Context::getContext();
        $id_shop = $context->shop->id;
        $id_lang = $context->language->id;

        // retrieve address informations
        $address = new Address();
        $address->id_country = $context->country->id;
        $address->id_state = 0;
        $address->postcode = 0;

        $filename = 'export_stoc_initial_oblio.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Denumire produs',
            'Tip',
            'Cod produs',
            'Stoc',
            'U.M.',
            'Cost achizitie fara TVA',
            'Pret vanzare',
            'Cota TVA',
            'TVA inclus',
        ]);
        foreach ($items as $item) {
            $product = new Product($item['id_product'], false, $id_lang);
            $tax_manager = TaxManagerFactory::getManager($address, Product::getIdTaxRulesGroupByIdProduct((int)$item['id_product'], $context));
            $product_tax_calculator = $tax_manager->getTaxCalculator();

            $line = [
                $product->name,
                'Marfa',
                $product->reference,
                0,
                'buc',
                $product->wholesale_price,
                Product::getPriceStatic($product->id, true, null, 2), // $product->price,
                $product_tax_calculator->getTotalRate(),
                'DA',
            ];
            
            $combinations = $this->getCombinations($product->id, $id_lang);
            if (empty($combinations)) {
                $line[3] = StockAvailable::getQuantityAvailableByProduct($product->id, null, $id_shop);
                fputcsv($out, $line);
            } else {
                foreach ($combinations as $combination) {
                    $line[0] = $product->name . $combination['attribute_designation'];
                    $line[2] = $combination['reference'];
                    $line[3] = $combination['quantity'];
                    fputcsv($out, $line);
                }
            }
        }
        fclose($out);
    }

    public function generateCode($id) {
        $code = '_' . sha1(microtime(true) + $id);
        return substr($code, 0, 10);
    }
    
    public function getOrdersQty() {
        $db = Db::getInstance();

        $sql = "SELECT od.product_quantity AS qty, od.product_id
                FROM `" . _DB_PREFIX_ . "orders` o
                JOIN `" . _DB_PREFIX_ . "order_detail` od ON (od.id_order = o.id_order)
                LEFT JOIN `" . _DB_PREFIX_ . "oblio_invoice` ob ON (ob.id_order = o.id_order AND ob.type = 1)
                WHERE o.date_add > DATE_SUB(NOW(), INTERVAL 30 DAY) 
                AND ob.id_order IS NULL
                GROUP BY o.id_order";

        $result = $db->executeS($sql);
        $products = [];
        foreach ($result as $item) {
            $item_id = $item['product_id'];
            if (!isset($products[$item_id])) {
                $products[$item_id] = 0;
            }
            $products[$item_id] += (int) $item['qty'];
        }
        return $products;
    }
}
