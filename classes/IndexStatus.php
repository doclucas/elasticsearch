<?php
/**
 * Copyright (C) 2017 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2017 thirty bees
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace ElasticsearchModule;

use Configuration;
use Context;
use Db;
use DbQuery;
use Dispatcher;
use Language;
use Product;

if (!defined('_TB_VERSION_')) {
    return;
}

/**
 * Class Indexer
 *
 * @package ElasticsearchModule
 */
class IndexStatus extends \ObjectModel
{
    /**
     * Indicates whether the dispatcher has been prepared to handle
     * inactive languages
     *
     * @var bool
     */
    protected static $dispatcherPrepared = false;

    /**
     * @var array
     */
    public static $definition = [
        'primary' => 'id_elasticsearch_index_status',
        'table' => 'elasticsearch_index_status',
        'fields'  => [
            'id_product' => ['type' => self::TYPE_INT,  'validate' => 'isUnsignedInt', 'required' => true],
            'id_lang'    => ['type' => self::TYPE_INT,  'validate' => 'isUnsignedInt', 'required' => true],
            'id_shop'    => ['type' => self::TYPE_INT,  'validate' => 'isUnsignedInt', 'required' => true],
            'date_upd'   => ['type' => self::TYPE_DATE, 'validate' => 'isDate',        'required' => true],
        ],
    ];

    /**
     * Get amount of products indexed for the given lang and shop
     *
     * @param int $idLang
     * @param int $idShop
     *
     * @return int
     */
    public static function getIndexed($idLang = null, $idShop = null)
    {
        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from(bqSQL(static::$definition['table']), 'eis')
                ->innerJoin(bqSQL(Product::$definition['table']).'_shop', 'p', 'p.`id_product` = eis.`id_product` AND p.`id_shop` = eis.`id_shop` AND p.`date_upd` = eis.`date_upd`')
                ->where($idLang ?'eis.`id_lang` = '.(int) $idLang : '')
                ->where($idShop ? 'eis.`id_shop` = '.(int) $idShop : '')
        );
    }

    /**
     * Get amount of products for the given shop and lang
     *
     * @param int|null $idLang
     * @param int|null $idShop
     *
     * @return int
     */
    public static function countProducts($idLang = null, $idShop = null)
    {
        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from(bqSQL(Product::$definition['table']).'_shop', 'ps')
                ->rightJoin(bqSQL(Product::$definition['table']).'_lang', 'pl', 'ps.`id_product` = pl.`id_product`')
                ->where($idLang ? 'pl.`id_lang` = '.(int) $idLang : '')
                ->where($idShop ? 'ps.`id_shop` = '.(int) $idShop : '')
        );
    }

    /**
     * Get amount of languages for the given shop
     *
     * @param int|null $idShop
     *
     * @return int
     */
    public static function countLanguages($idShop = null)
    {
        if (!$idShop) {
            $idShop = Context::getContext()->shop->id;
        }

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from(bqSQL(Language::$definition['table']).'_shop')
                ->where('`id_shop` = '.(int) $idShop)
        );
    }

    /**
     * Reset index
     *
     * @param int|null $idShop
     *
     * @return bool
     */
    public static function erase($idShop = null)
    {
        return Db::getInstance()->delete(bqSQL(static::$definition['table']), $idShop ? '`id_shop` = '.(int) $idShop : '');
    }

    /**
     * @param int      $limit
     * @param int      $offset
     * @param int|null $idLang
     * @param int|null $idShop
     *
     * @return array
     */
    public static function getProductsToIndex($limit = 0, $offset = 0, $idLang = null, $idShop = null)
    {
        // We have to prepare the back office dispatcher, otherwise it will not generate friendly URLs for languages
        // other than the current language
        static::prepareDispatcher();

        $results = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('ps.`id_product`, ps.`id_shop`, pl.`id_lang`, ps.`date_upd` AS `product_updated`, eis.`date_upd` AS `product_indexed`')
                ->from(bqSQL(Product::$definition['table']).'_shop', 'ps')
                ->leftJoin(bqSQL(Product::$definition['table']).'_lang', 'pl', 'pl.`id_product` = ps.`id_product`')
                ->leftJoin(bqSQL(IndexStatus::$definition['table']), 'eis', 'ps.`id_product` = eis.`id_product` AND ps.`id_shop` = eis.`id_shop` AND eis.`id_lang` = pl.`id_lang`')
                ->where($idLang ?'eis.`id_lang` = '.(int) $idLang : '')
                ->where($idShop ? 'eis.`id_shop` = '.(int) $idShop : '')
                ->where('ps.`date_upd` != eis.`date_upd` OR eis.`date_upd` IS NULL')
                ->limit($limit, $offset)
        );

        $products = [];
        foreach ($results as &$result) {
            $product = Fetcher::initProduct($result['id_product'], $result['id_lang']);
            $product->id_lang = $result['id_lang'];
            $product->id_shop = $result['id_shop'];

            $products[] = $product;
        }

        return $products;
    }

    /**
     * By default the dispatcher does not load the default routes for languages that have been deactivated.
     * This is a problem, because we also want to index languages that are not currently active.
     * By inserting the routes from inactive languages we can still generate friendly URLs for inactive languages.
     *
     * @return void
     */
    protected static function prepareDispatcher()
    {
        if (static::$dispatcherPrepared) {
            return;
        }

        // Set new routes
        $prodroutes = 'PS_ROUTE_product_rule';
        $catroutes = 'PS_ROUTE_category_rule';
        $supproutes = 'PS_ROUTE_supplier_rule';
        $manuroutes = 'PS_ROUTE_manufacturer_rule';
        $layeredroutes = 'PS_ROUTE_layered_rule';
        $cmsroutes = 'PS_ROUTE_cms_rule';
        $cmscatroutes = 'PS_ROUTE_cms_category_rule';
        $moduleroutes = 'PS_ROUTE_module';
        foreach (Language::getLanguages(false) as $lang) {
            foreach (Dispatcher::getInstance()->default_routes as $id => $route) {
                switch ($id) {
                    case 'product_rule':
                        $rule = Configuration::get($prodroutes, (int) $lang['id_lang']);
                        break;
                    case 'category_rule':
                        $rule = Configuration::get($catroutes, (int) $lang['id_lang']);
                        break;
                    case 'supplier_rule':
                        $rule = Configuration::get($supproutes, (int) $lang['id_lang']);
                        break;
                    case 'manufacturer_rule':
                        $rule = Configuration::get($manuroutes, (int) $lang['id_lang']);
                        break;
                    case 'layered_rule':
                        $rule = Configuration::get($layeredroutes, (int) $lang['id_lang']);
                        break;
                    case 'cms_rule':
                        $rule = Configuration::get($cmsroutes, (int) $lang['id_lang']);
                        break;
                    case 'cms_category_rule':
                        $rule = Configuration::get($cmscatroutes, (int) $lang['id_lang']);
                        break;
                    case 'module':
                        $rule = Configuration::get($moduleroutes, (int) $lang['id_lang']);
                        break;
                    default:
                        $rule = $route['rule'];
                        break;
                }

                Dispatcher::getInstance()->addRoute(
                    $id,
                    $rule,
                    $route['controller'],
                    $lang['id_lang'],
                    $route['keywords'],
                    isset($route['params']) ? $route['params'] : [],
                    Context::getContext()->shop->id
                );
            }
        }

        static::$dispatcherPrepared = true;
    }
}
