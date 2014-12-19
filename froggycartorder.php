<?php
/**
 * 2013-2014 Froggy Commerce
 *
 * NOTICE OF LICENSE
 *
 * You should have received a licence with this module.
 * If you didn't buy this module on Froggy-Commerce.com, ThemeForest.net
 * or Addons.PrestaShop.com, please contact us immediately : contact@froggy-commerce.com
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to benefit the updates
 * for newer PrestaShop versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Froggy Commerce <contact@froggy-commerce.com>
 * @copyright 2013-2014 Froggy Commerce
 * @license   Unauthorized copying of this file, via any medium is strictly prohibited
 */

/*
 * Security
 */
defined('_PS_VERSION_') || require dirname(__FILE__).'/index.php';

/*
 * Include Froggy Library
 */
if (!class_exists('FroggyModule', false)) require_once _PS_MODULE_DIR_.'/froggycartorder/froggy/FroggyModule.php';
require_once dirname(__FILE__).'/classes/FroggyCartOrderObject.php';

class FroggyCartOrder extends FroggyModule
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->name = 'froggycartorder';
		$this->author = 'Froggy Commerce';
		$this->version = '1.0.2';
		$this->tab = 'administration';

		parent::__construct();

		$this->display_cart_limit = 50;
		$this->displayName = $this->l('Froggy Cart to Order');
		$this->description = $this->l('Allow you to convert a cart into order');
		$this->module_key = '13dc91b72a0de608f8befcdf22dd460e';
	}

	public function getContent()
	{
		$this->getCartByEmailAjax();
		$convert_result = $this->convertCart();

		$assign = array(
			'module_dir' => $this->_path,
			'cart_limit' => $this->display_cart_limit,
			'last_carts' => $this->getLastCartsNotAssociatedToOrder($this->display_cart_limit),
			'available_payment_methods' => $this->getAvailablePaymentMethods(),
			'order_state_list' => OrderState::getOrderStates($this->context->language->id),
			'convert_result' => $convert_result,
			'current_url' => Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']),
			'ps_version' => Tools::substr(_PS_VERSION_, 0, 3),
		);

		$this->smarty->assign($this->name, $assign);
		return $this->fcdisplay(__FILE__, 'getContent.tpl');
	}

	public function getCartByEmailAjax()
	{
		if (Tools::getValue('ajax_mode') == '' || Tools::getValue('get_cart_by_email') == '')
			return false;

		ob_end_clean();
		$carts_list = $this->getLastCartsNotAssociatedToOrder($this->display_cart_limit, Tools::getValue('get_cart_by_email'));
		die(Tools::jsonEncode($carts_list));
	}

	public function convertCart()
	{
		// Retrieve cart id, manual cart id is prioritary
		$id_cart = Tools::getValue('id_cart_manual');
		if ($id_cart < 1)
			$id_cart = Tools::getValue('id_cart');

		// Retrieve payment methods, manual payment is prioritary
		$payment_method = Tools::getValue('payment_method_manual');
		if ($payment_method == '')
			$payment_method = Tools::getValue('payment_method');

		// Retrieve order state
		$id_order_state = Tools::getValue('id_order_state');

		// If no cart id is selected, we stop
		if ($id_cart < 1 || $payment_method == '' || $id_order_state < 1)
			return '';

		// Context
		$this->context->cart = new Cart($id_cart);
		$this->context->currency = new Currency((int)$this->context->cart->id_currency);
		$this->context->customer = new Customer((int)$this->context->cart->id_customer);
		$amount_paid = $this->context->cart->getOrderTotal();

		// Load payment class
		$payment_module = new FroggyCartOrderPaymentModule();
		$payment_module->name = 'froggycartorderpaymentmodule';
		$result = $payment_module->validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method, $this->l('Order generated by Froggy Cart to Order'), array(), $this->context->currency->id, false, $this->context->cart->secure_key);
		if ($result)
		{
			$fco = new FroggyCartOrderObject();
			$fco->id_customer = $this->context->cart->id_customer;
			$fco->id_order = OrderCore::getOrderByCartId($id_cart);
			$fco->id_employee = $this->context->cookie->id_employee;
			$fco->payment = $payment_method;
			$fco->add();
			return 'OK';
		}
		return 'KO';
	}

	public function getLastCartsNotAssociatedToOrder($limit = 50, $email = '')
	{
		$carts_list = Db::getInstance()->executeS('
		SELECT a.*, CONCAT(c.`firstname`, \' \', c.`lastname`) `customer`,
			   ca.name carrier, o.`id_order`, IF(co.id_guest, 1, 0) id_guest
		FROM `'._DB_PREFIX_.'cart` a
		LEFT JOIN `'._DB_PREFIX_.'customer` c ON (c.`id_customer` = a.`id_customer`)
		LEFT JOIN `'._DB_PREFIX_.'currency` cu ON (cu.`id_currency` = a.`id_currency`)
		LEFT JOIN `'._DB_PREFIX_.'carrier` ca ON (ca.`id_carrier` = a.`id_carrier`)
		LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_cart` = a.`id_cart`)
		LEFT JOIN `'._DB_PREFIX_.'connections` co ON (a.`id_guest` = co.`id_guest` AND TIME_TO_SEC(TIMEDIFF(NOW(), co.`date_add`)) < 1800)
		WHERE a.`id_customer` > 0 AND o.`id_order` IS NULL
		AND a.`id_address_invoice` > 0
		'.($email != '' ? 'AND c.`email` LIKE \'%'.pSQL($email).'%\'' : '').'
		ORDER BY a.`date_add` DESC
		LIMIT '.(int)$limit);

		foreach ($carts_list as $kc => $vc)
			$carts_list[$kc]['total'] = Cart::getOrderTotalUsingTaxCalculationMethod($vc['id_cart']);

		return $carts_list;
	}

	public function getAvailablePaymentMethods()
	{
		return Db::getInstance()->executeS('
		SELECT DISTINCT(`payment`)
		FROM `'._DB_PREFIX_.'orders`');
	}
}



class FroggyCartOrderPaymentModule extends PaymentModule
{
	/*
	 * Fix for validateOrder
	 */
	public $active = 1;
}