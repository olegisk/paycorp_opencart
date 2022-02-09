<?php

$include_dir = DIR_SYSTEM . '../vendors/paycorp/';
require_once $include_dir . 'au.com.gateway.client.utils/IJsonHelper.php';
require_once $include_dir . 'au.com.gateway.client/GatewayClient.php';
require_once $include_dir . 'au.com.gateway.client.config/ClientConfig.php';
require_once $include_dir . 'au.com.gateway.client.component/RequestHeader.php';
require_once $include_dir . 'au.com.gateway.client.component/CreditCard.php';
require_once $include_dir . 'au.com.gateway.client.component/TransactionAmount.php';
require_once $include_dir . 'au.com.gateway.client.component/Redirect.php';
require_once $include_dir . 'au.com.gateway.client.facade/BaseFacade.php';
require_once $include_dir . 'au.com.gateway.client.payment/PaymentCompleteResponse.php';
require_once $include_dir . 'au.com.gateway.client.facade/Payment.php';
require_once $include_dir . 'au.com.gateway.client.payment/PaymentInitRequest.php';
require_once $include_dir . 'au.com.gateway.client.payment/PaymentInitResponse.php';
require_once $include_dir . 'au.com.gateway.client.helpers/PaymentCompleteJsonHelper.php';
require_once $include_dir . 'au.com.gateway.client.payment/PaymentCompleteRequest.php';
require_once $include_dir . 'au.com.gateway.client.root/PaycorpRequest.php';
require_once $include_dir . 'au.com.gateway.client.helpers/PaymentInitJsonHelper.php';
require_once $include_dir . 'au.com.gateway.client.utils/HmacUtils.php';
require_once $include_dir . 'au.com.gateway.client.utils/CommonUtils.php';
require_once $include_dir . 'au.com.gateway.client.utils/RestClient.php';
require_once $include_dir . 'au.com.gateway.client.enums/TransactionType.php';
require_once $include_dir . 'au.com.gateway.client.enums/Version.php';
require_once $include_dir . 'au.com.gateway.client.enums/Operation.php';
require_once $include_dir . 'au.com.gateway.client.facade/Vault.php';
require_once $include_dir . 'au.com.gateway.client.facade/Report.php';
require_once $include_dir . 'au.com.gateway.client.facade/AmexWallet.php';


class ControllerExtensionPaymentPaycorp extends Controller {
    public function index() {
        $this->load->language('extension/payment/paycorp');

        $data = array();
        $data['form_submit'] = $this->url->link('extension/payment/paycorp/confirm', '', true);

        return $this->load->view('extension/payment/paycorp', $data);
    }

    public function confirm() {
        $this->load->language('extension/payment/paycorp');
        $this->load->model('checkout/order');
        $this->load->model('localisation/country');
        $this->load->model('extension/payment/paycorp');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $country_info = $this->model_localisation_country->getCountry($order_info['payment_country_id']);

        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

        $currency = $order_info['currency_code'];
        $msisdn = $order_info['telephone'];
        $sessionId = '';
        $comment = '';

        $clientRef = $this->session->data['order_id'];

        try {
            $clientConfig = new ClientConfig();
            //$clientConfig->setServiceEndpoint( "https://sampath.paycorp.com.au/rest/service/proxy/" );
            //$clientConfig->setAuthToken( "e21727d6-9998-4148-bc78-589e8dedf723" );
            //$clientConfig->setHmacSecret( "W3Uh7vekQTK1zbkS" );
            $clientConfig->setServiceEndpoint($this->config->get('payment_paycorp_pg_domain'));
            $clientConfig->setAuthToken($this->config->get('payment_paycorp_auth_token'));
            $clientConfig->setHmacSecret($this->config->get('payment_paycorp_hmac_secret'));
            $clientConfig->setValidateOnly(false);

            $client = new GatewayClient($clientConfig);

            $initRequest = new PaymentInitRequest();
            $initRequest->setClientId($this->config->get('payment_paycorp_client_id'));
            $initRequest->setTransactionType($this->config->get('payment_paycorp_transaction_type'));
            $initRequest->setClientRef($clientRef);
            $initRequest->setComment($comment);
            $initRequest->setTokenize(false);
            $initRequest->setExtraData(array('msisdn' => $msisdn, 'sessionId' => $sessionId));

            $transactionAmount = new TransactionAmount(intval($amount * 100));
            //$transactionAmount->setTotalAmount(intval($amount * 100));
            $transactionAmount->setServiceFeeAmount(0);
            $transactionAmount->setPaymentAmount(intval($amount * 100));
            $transactionAmount->setCurrency($currency);
            $initRequest->setTransactionAmount($transactionAmount);

            $redirect = new Redirect();
            $redirect->setReturnUrl($this->url->link('extension/payment/paycorp/complete', '', true));
            $redirect->setReturnMethod('GET');
            $initRequest->setRedirect($redirect);

            //$initResponse = $client->getPayment()->init( $initRequest );
            $initResponse = $client->payment()->init($initRequest);
        } catch (Exception $e) {
            //echo 'Error :' . $e->getMessage();
            $this->session->data['error'] = sprintf('Error: %s', $e->getMessage());
            $this->response->redirect($this->url->link('checkout/cart', '', true));
        }

        $this->response->redirect($initResponse->getPaymentPageUrl());
    }

    public function complete() {
        $this->load->model('checkout/order');

        $clientConfig = new ClientConfig();
        $clientConfig->setServiceEndpoint($this->config->get('payment_paycorp_pg_domain'));
        $clientConfig->setAuthToken($this->config->get('payment_paycorp_auth_token'));
        $clientConfig->setHmacSecret($this->config->get('payment_paycorp_hmac_secret'));

        $client = new GatewayClient($clientConfig);

        $completeRequest = new PaymentCompleteRequest();
        $completeRequest->setClientId($this->config->get('payment_paycorp_client_id'));
        $completeRequest->setReqid($_GET['reqid']);

        $completeResponse = $client->payment()->complete($completeRequest);
        $order_id = $completeResponse->getClientRef();
        $response_code = $completeResponse->getResponseCode();
        $transaction_id = $completeResponse->getTxnReference();

        switch ($response_code) {
            case '00':
                $order_status_id = $this->config->get('payment_paycorp_order_status_id');
                if (empty($order_status_id)) {
                    $order_status_id = $this->config->get('order_status_id');
                }

                $comment = sprintf('Transaction success. Transaction ID: %s, ', $transaction_id);
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $order_status_id, $comment, true);
                $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            default:
                $this->session->data['error'] = sprintf('Transaction failed. Transaction ID: %s. Code: %s', $transaction_id, $response_code);
                $this->response->redirect($this->url->link('checkout/cart', '', true));
                break;
        }
    }
}
