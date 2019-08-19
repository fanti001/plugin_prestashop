<?php

/**
 * OpenPayU
 *
 * @author    PayU
 * @copyright Copyright (c) 2016 PayU
 * @license   http://opensource.org/licenses/LGPL-3.0  Open Software License (LGPL 3.0)
 *
 * http://www.payu.com
 */

class PayUPaymentModuleFrontController extends ModuleFrontController
{
    /** @var PayU */
    private $payu;

    public $display_column_left = false;

    private $hasRetryPayment;

    private $order = null;

    public function postProcess()
    {
        $this->checkHasModuleActive();
        $this->checkHasRetryPayment();

        $this->payu = new PayU();

        if ($this->hasRetryPayment) {
            $this->postProcessRetryPayment();
        } else {
            $this->postProcessPayment();
        }
    }

    public function initContent()
    {
        parent::initContent();
        SimplePayuLogger::addLog('order', __FUNCTION__, 'payment.php entrance. PHP version:  ' . phpversion(), '');

        if (Configuration::get('PAYU_RETRIEVE')) {
            if (Tools::getValue('payuPay')) {
                $payMethod = Tools::getValue('payMethod');
                $payuConditions = Tools::getValue('payuConditions');
                $errors = array();
                $payError = array();

                if (!$payMethod) {
                    $errors[] = $this->module->l('Please select a method of payment', 'payment');
                }

                if (!$payuConditions) {
                    $errors[] = $this->module->l('Please accept "Terms of single PayU payment transaction"', 'payment');
                }

                if (count($errors) == 0) {
                    $payError = $this->pay($payMethod);
                    if (!array_key_exists('firstPayment', $payError)) {
                        $errors[] = $payError['message'];
                    }
                }
                if (array_key_exists('firstPayment', $payError)) {
                    $this->showPaymentError();
                } else {
                    $this->showPayMethod($payMethod, $payuConditions, $errors);
                }
            } else {
                $this->showPayMethod();
            }
        } else {
            $this->pay();
            $this->showPaymentError();
        }
    }

    private function showPayMethod($payMethod = '', $payuConditions = 1, $errors = array())
    {
        $this->context->smarty->assign(array(
            'payMethod' => $payMethod,
            'image' => $this->payu->getPayuLogo(),
            'conditionUrl' => $this->payu->getPayConditionUrl(),
            'payuConditions' => $payuConditions,
            'payuErrors' => $errors
        ));

        $this->context->smarty->assign($this->getShowPayMethodsParameters());

        $this->setTemplate($this->payu->buildTemplatePath('payMethods'));
    }

    private function showPaymentError()
    {
        $this->context->smarty->assign(
            array(
                'image' => $this->payu->getPayuLogo(),
                'total' => Tools::displayPrice($this->order->total_paid, (int)$this->order->id_currency),
                'orderCurrency' => (int)$this->order->id_currency,
                'buttonAction' => $this->context->link->getModuleLink('payu', 'payment', array('id_order' => $this->order->id, 'order_reference' => $this->order->reference)),
                'payuOrderInfo' => $this->module->l('Pay for your order', 'payment') .  ' ' . $this->order->reference,
                'payuError' => $this->module->l('An error occurred while processing your payment.', 'payment')
            )
        );

        $this->setTemplate($this->payu->buildTemplatePath('error'));
    }

    private function pay($payMethod = null)
    {
        if (!$this->hasRetryPayment) {
            $this->payu->validateOrder(
                $this->context->cart->id, (int)Configuration::get('PAYU_PAYMENT_STATUS_PENDING'),
                $this->context->cart->getOrderTotal(true, Cart::BOTH), $this->payu->displayName,
                null, array(), (int)$this->context->cart->id_currency, false, $this->context->cart->secure_key,
                Context::getContext()->shop->id ? new Shop((int)Context::getContext()->shop->id) : null
            );

            $this->order = new Order($this->payu->currentOrder);
        }

        $this->payu->generateExtOrderId($this->order->id);
        $this->payu->order = $this->order;

        try {
            $result = $this->payu->orderCreateRequestByOrder($payMethod);

            $this->payu->payu_order_id = $result['orderId'];
            $this->postOCR();

            SimplePayuLogger::addLog('order', __FUNCTION__, 'Process redirect to ' . ($payMethod ? 'bank or card form' : 'summary') . '...', $result['orderId']);
            Tools::redirect($result['redirectUri']);

        } catch (\Exception $e) {
            SimplePayuLogger::addLog('order', __FUNCTION__, 'An error occurred while processing  OCR - ' . $e->getMessage(), '');

            if ($this->hasRetryPayment) {
                return array('message' => $this->module->l('An error occurred while processing your payment. Please try again or contact the store.', 'payment') . ' ' .$result['error']);
            }

            return array(
                'firstPayment' => true
            );
        }
    }

    private function postProcessPayment()
    {
        if ($this->context->cart->id_customer == 0 || $this->context->cart->id_address_delivery == 0 || $this->context->cart->id_address_invoice == 0 || !count($this->context->cart->getProducts())) {
            Tools::redirectLink(__PS_BASE_URI__ . 'order.php?step=1');
        }

        $customer = new Customer($this->context->cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirectLink(__PS_BASE_URI__ . 'order.php?step=1');
        }
    }

    private function postProcessRetryPayment()
    {
        $id_order = (int)Tools::getValue('id_order');
        $order_reference = Tools::getValue('order_reference');

        if (!$id_order || !Validate::isUnsignedId($id_order)) {
            Tools::redirect('index.php?controller=history');
        }

        $this->order = new Order($id_order);

        if (!Validate::isLoadedObject($this->order) || $this->order->reference !== $order_reference) {
            Tools::redirect('index.php?controller=history');
        }

        if (!$this->payu->hasRetryPayment($this->order->id, $this->order->current_state)) {
            Tools::redirect('index.php?controller=history');
        }
    }

    private function checkHasModuleActive()
    {
        if (!Module::isEnabled($this->module->name)) {
            die($this->module->l('This payment method is not available.', 'payment'));
        }

        if (!$this->module->active) {
            die($this->module->l('PayU module isn\'t active.', 'payment'));
        }
    }

    private function checkHasRetryPayment()
    {
        $this->hasRetryPayment = Tools::getValue('id_order') !== false && Tools::getValue('order_reference') !== false;
    }

    private function getShowPayMethodsParameters()
    {
        if ($this->hasRetryPayment) {
            return array(
                'total' => Tools::displayPrice($this->order->total_paid, (int)$this->order->id_currency),
                'orderCurrency' => (int)$this->order->id_currency,
                'payMethods' => $this->payu->getPaymethods(Currency::getCurrency($this->order->id_currency)),
                'payuPayAction' => $this->context->link->getModuleLink('payu', 'payment', array('id_order' => $this->order->id, 'order_reference' => $this->order->reference)),
                'payuOrderInfo' => $this->module->l('Retry pay for your order', 'payment') . ' ' . $this->order->reference,
                'retryPayment' => $this->hasRetryPayment
            );
        } else {
            return array(
                'total' => Tools::displayPrice($this->context->cart->getOrderTotal(true, Cart::BOTH)),
                'orderCurrency' => (int)$this->context->cart->id_currency,
                'payMethods' => $this->payu->getPaymethods(Currency::getCurrency($this->context->cart->id_currency)),
                'payuPayAction' => $this->context->link->getModuleLink('payu', 'payment'),
                'payuOrderInfo' => $this->module->l('The total amount of your order is', 'payment'),
                'retryPayment' => $this->hasRetryPayment
            );
        }
    }

    private function postOCR()
    {
        if ($this->hasRetryPayment) {
            $history = new OrderHistory();
            $history->id_order = $this->order->id;
            $history->changeIdOrderState(Configuration::get('PAYU_PAYMENT_STATUS_PENDING'), $this->order->id);
            $history->addWithemail(true);
        }

        $this->payu->addOrderSessionId(OpenPayuOrderStatus::STATUS_NEW, $this->order->id, 0, $this->payu->payu_order_id, $this->payu->getExtOrderId());

    }
}
