<?php

abstract class CheckoutApi_ChargePayment_Model_Method_Abstract extends Mage_Payment_Model_Method_Cc
{
	protected $_canAuthorize = true;
	protected $_canCapture = true;
	protected $_canRefund = true;
	protected $_canVoid = true;
	protected $_canOrder = true;
    protected $_isInitializeNeeded    = false;


	protected function _placeOrder ( Varien_Object $payment , $amount , $messageSuccess , $extraConfig )
	{
		/** @var CheckoutApi_Lib_RespondObj $respondCharge */

		$respondCharge = $this->_createCharge ( $payment , $amount , $extraConfig );
        
		$this->_debug ( $respondCharge );
        /** @var Mage_Sales_Model_Order_Payment_Transaction $payment */
		/** @var Mage_Sales_Model_Order $order */
		$order = $payment->getOrder ();
        $quote = Mage::getModel('checkout/session')->getQuote();
        $Api = CheckoutApi_Api::getApi(array('mode'=>$this->getConfigData('mode')));
        $toValidate = array(
          'currency' => $order->getOrderCurrencyCode(),
          'value'    => $Api->valueToDecimal($quote->getGrandTotal(), $order->getOrderCurrencyCode()),
        );
        
        $validateRequest = $Api::validateRequest($toValidate,$respondCharge);
		if ( $respondCharge->isValid () ) {

			if ( preg_match ( '/^1[0-9]+$/' , $respondCharge->getResponseCode () ) ) {

				$payment->setTransactionId ( $respondCharge->getId () );
				$rawInfo = $respondCharge->toArray ();
				$payment->setAdditionalInformation ( 'rawrespond' , $rawInfo );
				$payment->setTransactionAdditionalInfo ( Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS , $rawInfo );
                $paymentMethod = $respondCharge->getCard()->getPaymentMethod();
                $cctype = $this->_getCcCodeType($paymentMethod);
                $payment->setCcType($cctype);
                $payment->setCcLast4($respondCharge->getCard()->getLast4());
				$orderStatus = $this->getConfigData ( 'order_status' );
				$order->setStatus ( $orderStatus , false );

				$order->addStatusToHistory ( $orderStatus , $messageSuccess . $respondCharge->getId ()
					. ' and respond code ' . $respondCharge->getResponseCode () , false );
                
                if(!$validateRequest['status']){  
                      foreach($validateRequest['message'] as $errormessage){
                        $order->addStatusToHistory ( $orderStatus , $errormessage , false );
                      }
                }
				$order->save ();


				$chargeUpdated = $Api->updateTrackId($respondCharge, $order->getIncrementId());

				if($respondCharge->getCaptured()){
					$payment->capture ( null );
				}
				$payment->save ();
				return $respondCharge;
			} else {
				$errorDetails = '';
				if ( $this->getDebugFlag () ) {
					$errorDetails = $respondCharge->getResponseMessage () . '---' . $respondCharge->getId ();
				}
				Mage::throwException ( Mage::helper ( 'payment' )->__ ( 'An error has occured. Please check you card
                details and try again. Thank you.' ) . ' ( ' . $errorDetails . ')' );
				return false;
			}

		} else {
			
            $errorDetails = $respondCharge->getMessage ();
			if ( $this->getDebugFlag () ) {
                Mage::log( $respondCharge->getExceptionState ()->getErrorMessage (),Zend_Log::DEBUG,'cko.log');
			}

			Mage::throwException ( Mage::helper ( 'payment' )->__ ( $respondCharge->getExceptionState ()->getErrorMessage () .
				' ( ' . $errorDetails . ')'
			) );
		}
		return false;
	}

	/**
	 * @param Varien_Object $payment
	 * @param $amount
	 * @param array $extraConfig
	 * @return mixed
	 */


	protected function _createCharge ( Varien_Object $payment , $amount , $extraConfig = array () )
	{
		$config = array ();
		$scretKey = $this->getConfigData ( 'privatekey' );
		$order = $payment->getOrder ();
		$billingAddress = $order->getBillingAddress ();
		$shippingAddress = $order->getBillingAddress ();
		$orderedItems = $order->getAllItems ();
		$currencyDesc = $order->getBaseCurrencyCode ();
		$orderId = $order->getIncrementId ();
        $Api = CheckoutApi_Api::getApi(array('mode'=>$this->getConfigData('mode')));
		$amountCents = $Api->valueToDecimal($amount, $currencyDesc);
		$street = Mage::helper ( 'customer/address' )
			->convertStreetLines ( $billingAddress->getStreet () , 2 );
		$billingAddressConfig = array (
			'addressLine1' => $street[ 0 ] ,
			'addressLine2' => $street[ 1 ] ,
			'postcode'     => $billingAddress->getPostcode () ,
			'country'      => $billingAddress->getCountry () ,
			'city'         => $billingAddress->getCity () ,
			'phone'        => array('number' => $billingAddress->getTelephone()) ,

		);

		$street = Mage::helper ( 'customer/address' )
			->convertStreetLines ( $shippingAddress->getStreet () , 2 );
		$shippingAddressConfig = array (
			'addressLine1'  => $street[ 0 ] ,
			'addressLine2'  => $street[ 1 ] ,
			'postcode'      => $shippingAddress->getPostcode () ,
			'country'       => $shippingAddress->getCountry () ,
			'city'          => $shippingAddress->getCity () ,
			'phone'         => array('number' => $shippingAddress->getTelephone()) ,
			'recipientName' => $shippingAddress->getFirstname () . ' ' . $shippingAddress->getLastname ()

		);

		$products = array ();
		foreach ( $orderedItems as $item ) {
			$product = Mage::getModel ( 'catalog/product' )->load ( $item->getProductId () );
			$products[ ] = array (
				'name'     => $item->getName () ,
				'sku'      => $item->getSku () ,
				'price'    => $item->getPrice () ,
				'quantity' => $item->getQtyOrdered () ,
				'image'    => Mage::helper ( 'catalog/image' )->init ( $product , 'image' )->__toString ()
			);
		}

		$config = array ();
		$config[ 'authorization' ] = $scretKey;
		$config[ 'mode' ] = $this->getConfigData ( 'mode' );
		$config[ 'timeout' ] = $this->getConfigData ( 'timeout' );

		$config[ 'postedParam' ] = array (
			'value'           => $amountCents ,
			'currency'        => $currencyDesc ,
			'shippingDetails' => $shippingAddressConfig ,
			'products'        => $products ,
			'description'     => "Order number::$orderId" ,
			'metadata'        => array ( "trackId" => $orderId ) ,
			'card'            => array (
				'billingDetails' => $billingAddressConfig
			)
		);
		$config[ 'postedParam' ] = array_merge ( $config[ 'postedParam' ] , $extraConfig );
		return $config;

	}

	protected function _capture ( Varien_Object $payment , $amount )
	{
		$extraConfig = array (
			'autoCapture' => CheckoutApi_Client_Constant::AUTOCAPUTURE_CAPTURE ,
			'autoCapTime' => $this->getConfigData ( 'auto_capture_time' )
		);

		$this->_placeOrder ( $payment , $amount , "Payment has been successfully captured for Transaction " , $extraConfig );
	}

	/**
	 * Capture payment abstract method
	 *
	 * @param Varien_Object $payment
	 * @param float $amount
	 *
	 * @return CheckoutApi_ChargePayment_Model_Method_Creditcard
	 */
	public function capture ( Varien_Object $payment , $amount )
	{
		if ( !$this->canCapture () ) {
			Mage::throwException ( Mage::helper ( 'payment' )->__ ( 'Capture action is not available.' ) );
		} else {
			if ( !$payment->getLastTransId () ) {
				$this->_capture ( $payment , $amount );
			}
		}
		return $this;
	}

	/**
	 * Authorize payment abstract method
	 *
	 * @param Varien_Object $payment
	 * @param float $amount
	 *
	 * @return CheckoutApi_ChargePayment_Model_Method_Creditcard
	 */

	public function authorize ( Varien_Object $payment , $amount )
	{
		if ( !$this->canAuthorize () ) {
			Mage::throwException ( Mage::helper ( 'payment' )->__ ( 'Authorize action is not available.' ) );
		} else {
			$extraConfig = array (
				'autoCapture' => CheckoutApi_Client_Constant::AUTOCAPUTURE_AUTH ,
				'autoCapTime' => 0
			);
			$this->_placeOrder ( $payment , $amount , "Payment has been successfully authorize for Transaction " , $extraConfig );
		}

		return $this;
	}


	public function order ( Varien_Object $payment , $amount )
	{
		if ( !$this->canOrder () ) {
			parent::order ( $payment , $amount );

		} else {
			$this->_capture ( $payment , $amount );
			$order = $payment->getOrder ();
			$payment->setAmountAuthorized ( $order->getTotalDue () );
			$payment->setBaseAmountAuthorized ( $order->getBaseTotalDue () );
		}
		return $this;
	}

    /**
     * Instantiate state and set it to state object
     * @param string $paymentAction
     * @param Varien_Object
     */
    public function setPendingState( $payment)
    {
        $order = $payment->getOrder ();
        $order->setStatus ( 'pending_payment' , false );
    }
}