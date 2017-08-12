<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_k2store
 * @subpackage 	Trangell_Zarinpal
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 20016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die('Restricted access');

require_once (JPATH_ADMINISTRATOR.'/components/com_k2store/library/plugins/payment.php');
if (!class_exists ('checkHack')) {
	require_once( JPATH_PLUGINS . '/k2store/payment_zarinpal/trangell_inputcheck.php');
}

class plgK2StorePayment_zarinpal extends K2StorePaymentPlugin
{
    var $_element    = 'payment_zarinpal';

	function plgK2StorePayment_zarinpal(& $subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage( 'com_k2store', JPATH_ADMINISTRATOR );
	}
	
	function _beforePayment($order) {
		$surcharge = 0;
		$surcharge_percent = $this->params->get('surcharge_percent', 0);
		$surcharge_fixed = $this->params->get('surcharge_fixed', 0);
		if((float) $surcharge_percent > 0 || (float) $surcharge_fixed > 0) {
			if((float) $surcharge_percent > 0) {
				$surcharge += ($order->order_total * (float) $surcharge_percent) / 100;
			}
	
			if((float) $surcharge_fixed > 0) {
				$surcharge += (float) $surcharge_fixed;
			}
			
			$order->order_surcharge = round($surcharge, 2);
			$order->calculateTotals();
		}
	
	}
	
    function _prePayment( $data ) {

		$vars = new JObject();
		$vars->order_id = $data['order_id'];
		$vars->orderpayment_id = $data['orderpayment_id'];
		$vars->orderpayment_amount = $data['orderpayment_amount'];
		$vars->orderpayment_type = $this->_element;
		$vars->onbeforepayment_text = $this->params->get('onbeforepayment', '');
		$vars->button_text = $this->params->get('button_text', 'K2STORE_PLACE_ORDER');
		//==========================================================
		$vars->merchant_id = $this->params->get('merchant_id', '');
		if ($vars->merchant_id == null || $vars->merchant_id == ''){
			$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
			$app->redirect($link, '<h2>لطفا تنظیمات درگاه زرین پال را بررسی کنید</h2>', $msgType='Error'); 
		}

		$app	= JFactory::getApplication();
		$Amount = round($vars->orderpayment_amount,0)/10; // Toman 
		$Description = 'خرید محصول از فروشگاه   '; 
		$Email = ''; 
		$Mobile = ''; 
		$CallbackURL = JRoute::_(JURI::root(). "index.php?option=com_k2store&view=checkout" ) .'&orderpayment_id='.$vars->orderpayment_id . '&orderpayment_type=' . $vars->orderpayment_type .'&task=confirmPayment' ;
			
		try {
			$client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 	
			//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local
			$result = $client->PaymentRequest(
				[
				'MerchantID' => $vars->merchant_id,
				'Amount' => $Amount,
				'Description' => $Description,
				'Email' => $Email,
				'Mobile' => '',
				'CallbackURL' => $CallbackURL,
				]
			);
			
			$resultStatus = abs($result->Status); 
			if ($resultStatus == 100) {
				$vars->zarinpal= 'https://www.zarinpal.com/pg/StartPay/'.$result->Authority;
				$html = $this->_getLayout('prepayment', $vars);
				return $html;
			// Header('Location: https://sandbox.zarinpal.com/pg/StartPay/'.$result->Authority); 
		
			} else {
				$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
				$app->redirect($link, '<h2>ERR: '. $resultStatus .'</h2>', $msgType='Error'); 
			}
		}
		catch(\SoapFault $e) {
			$msg= $this->getGateMsg('error'); 
			$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
    }

    function _postPayment( $data ) {
        $app = JFactory::getApplication(); 
        $html = '';
		$jinput = $app->input;
		$orderpayment_id = $jinput->get->get('orderpayment_id', '0', 'INT');
        JTable::addIncludePath( JPATH_ADMINISTRATOR.'/components/com_k2store/tables' );
        $orderpayment = JTable::getInstance('Orders', 'Table');
        require_once (JPATH_SITE.'/components/com_k2store/models/address.php');
    	$address_model = new K2StoreModelAddress();
		//$address_model->getShippingAddress()->phone_2
		//==========================================================================
		$Authority = $jinput->get->get('Authority', '0', 'INT');
		$status = $jinput->get->get('Status', '', 'STRING');

	    if ($orderpayment->load( $orderpayment_id )){
			$customer_note = $orderpayment->customer_note;
			if($orderpayment->id == $orderpayment_id) {
				if (checkHack::checkString($status)){
					if ($status == 'OK') {
						try {
							$client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 
							//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local
							$result = $client->PaymentVerification(
								[
									'MerchantID' =>  $this->params->get('merchant_id', ''),
									'Authority' => $Authority,
									'Amount' => round($orderpayment->order_total,0)/10,
								]
							);
							$resultStatus = abs($result->Status); 
							if ($resultStatus == 100) {
								$msg= $this->getGateMsg($resultStatus); 
								$this->saveStatus($msg,1,$customer_note,'ok',$result->RefID,$orderpayment);
								$app->enqueueMessage($result->RefID . ' کد پیگیری شما', 'message');	
							} 
							else {
								$msg= $this->getGateMsg($resultStatus); 
								$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);
								$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
								$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
							}
						}
						catch(\SoapFault $e) {
							$msg= $this->getGateMsg('error'); 
							$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);
							$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
							$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
						}
					}
					else {
						$msg= $this->getGateMsg(intval(17)); 
						$this->saveStatus($msg,4,$customer_note,'nonok',null,$orderpayment);
						$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
					}
				}
				else {
					$msg= $this->getGateMsg('hck2'); 
					$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);
					$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
					$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
				}
			}
			else {
				$msg= $this->getGateMsg('notff'); 
				$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
				$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
			}
	    }
		else {
			$msg= $this->getGateMsg('notff'); 
			$link = JRoute::_(JURI::root(). "index.php?option=com_k2store" );
			$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
		}	
	}

    function _renderForm( $data )
    {
    	$user = JFactory::getUser();
        $vars = new JObject();
        $vars->onselection_text = $this->params->get('onselection', '');
        $html = $this->_getLayout('form', $vars);
        return $html;
    }

    function getPaymentStatus($payment_status) {
    	$status = '';
    	switch($payment_status) {
			case '1': $status = JText::_('K2STORE_CONFIRMED'); break;
			case '2': $status = JText::_('K2STORE_PROCESSED'); break;
			case '3': $status = JText::_('K2STORE_FAILED'); break;
			case '4': $status = JText::_('K2STORE_PENDING'); break;
			case '5': $status = JText::_('K2STORE_INCOMPLETE'); break;
			default: $status = JText::_('K2STORE_PENDING'); break;	
    	}
    	return $status;
    }

	function saveStatus($msg,$statCode,$customer_note,$emptyCart,$trackingCode,$orderpayment){
		$html ='<br />';
		$html .='<strong>'.JText::_('K2STORE_BANK_TRANSFER_INSTRUCTIONS').'</strong>';
		$html .='<br />';
		if (isset($trackingCode)){
			$html .= '<br />';
			$html .= $trackingCode .'شماره پیگری ';
			$html .= '<br />';
		}
		$html .='<br />' . $msg;
		$orderpayment->customer_note =$customer_note.$html;
		$payment_status = $this->getPaymentStatus($statCode); 
		$orderpayment->transaction_status = $payment_status;
		$orderpayment->order_state = $payment_status;
		$orderpayment->order_state_id = $this->params->get('payment_status', $statCode); 
		
		if ($orderpayment->save()) {
			if ($emptyCart == 'ok'){
				JLoader::register( 'K2StoreHelperCart', JPATH_SITE.'/components/com_k2store/helpers/cart.php');
				K2StoreHelperCart::removeOrderItems( $orderpayment->id );
			}
		}
		else
		{
			$errors[] = $orderpayment->getError();
		}
		if ($statCode == 1){
			require_once (JPATH_SITE.'/components/com_k2store/helpers/orders.php');
			K2StoreOrdersHelper::sendUserEmail($orderpayment->user_id, $orderpayment->order_id, $orderpayment->transaction_status, $orderpayment->order_state, $orderpayment->order_state_id);
		}

 		$vars = new JObject();
		$vars->onafterpayment_text = $msg;
		$html = $this->_getLayout('postpayment', $vars);
		$html .= $this->_displayArticle();
		return $html;
	}

    function getGateMsg ($msgId) {
		switch($msgId){
			case	11: $out =  'شماره کارت نامعتبر است';break;
			case	12: $out =  'موجودي کافي نيست';break;
			case	13: $out =  'رمز نادرست است';break;
			case	14: $out =  'تعداد دفعات وارد کردن رمز بيش از حد مجاز است';break;
			case	15: $out =   'کارت نامعتبر است';break;
			case	17: $out =   'کاربر از انجام تراکنش منصرف شده است';break;
			case	18: $out =   'تاريخ انقضاي کارت گذشته است';break;
			case	21: $out =   'پذيرنده نامعتبر است';break;
			case	22: $out =   'ترمينال مجوز ارايه سرويس درخواستي را ندارد';break;
			case	23: $out =   'خطاي امنيتي رخ داده است';break;
			case	24: $out =   'اطلاعات کاربري پذيرنده نامعتبر است';break;
			case	25: $out =   'مبلغ نامعتبر است';break;
			case	31: $out =  'پاسخ نامعتبر است';break;
			case	32: $out =   'فرمت اطلاعات وارد شده صحيح نمي باشد';break;
			case	33: $out =   'حساب نامعتبر است';break;
			case	34: $out =   'خطاي سيستمي';break;
			case	35: $out =   'تاريخ نامعتبر است';break;
			case	41: $out =   'شماره درخواست تکراري است';break;
			case	42: $out =   'تراکنش Sale يافت نشد';break;
			case	43: $out =   'قبلا درخواست Verify داده شده است';break;
			case	44: $out =   'درخواست Verify يافت نشد';break;
			case	45: $out =   'تراکنش Settle شده است';break;
			case	46: $out =   'تراکنش Settle نشده است';break;
			case	47: $out =   'تراکنش Settle يافت نشد';break;
			case	48: $out =   'تراکنش Reverse شده است';break;
			case	49: $out =   'تراکنش Refund يافت نشد';break;
			case	51: $out =   'تراکنش تکراري است';break;
			case	52: $out =   'سرويس درخواستي موجود نمي باشد';break;
			case	54: $out =   'تراکنش مرجع موجود نيست';break;
			case	55: $out =   'تراکنش نامعتبر است';break;
			case	61: $out =   'خطا در واريز';break;
			case	100: $out =   'تراکنش با موفقيت انجام شد.';break;
			case	111: $out =   'صادر کننده کارت نامعتبر است';break;
			case	112: $out =   'خطاي سوئيچ صادر کننده کارت';break;
			case	113: $out =   'پاسخي از صادر کننده کارت دريافت نشد';break;
			case	114: $out =   'دارنده کارت مجاز به انجام اين تراکنش نيست';break;
			case	412: $out =   'شناسه قبض نادرست است';break;
			case	413: $out =   'شناسه پرداخت نادرست است';break;
			case	414: $out =   'سازمان صادر کننده قبض نامعتبر است';break;
			case	415: $out =   'زمان جلسه کاري به پايان رسيده است';break;
			case	416: $out =   'خطا در ثبت اطلاعات';break;
			case	417: $out =   'شناسه پرداخت کننده نامعتبر است';break;
			case	418: $out =   'اشکال در تعريف اطلاعات مشتري';break;
			case	419: $out =   'تعداد دفعات ورود اطلاعات از حد مجاز گذشته است';break;
			case	421: $out =   'IP نامعتبر است';break;
			case	500: $out =   'کاربر به صفحه زرین پال رفته ولي هنوز بر نگشته است';break;
			case	'1':
			case	'error': $out ='خطا غیر منتظره رخ داده است';break;
			case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
			case	'notff': $out = 'سفارش پیدا نشد';break;
			default: $out ='خطا غیر منتظره رخ داده است';break;
		}
		return $out;
	}
}
