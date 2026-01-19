<?php
/*
1.  Create new stripe transaction, it will return "payID" & "payUrl":

    require_once(app_path('Libs/payment/StripeAPI.php'));
    $stripeAPI = new \StripeAPI($stripe_clientSecret);
    $stripeAPI->setReturnUrl(xxxx);
    $stripeAPI->setCancelUrl(xxxx);
    $stripe_checkout = $stripeAPI->doCheckout($items)
    redirect($stripe_checkout['payment_url']);
 

5.  Webhooks checking, determine whether the transaction is completed (if paid, it will return "payment_id", otherwise return "false):

    require_once(app_path('Libs/payment/StripeAPI.php'));
    $stripeAPI = new \StripeAPI($stripe_clientSecret);
    $stripeResponse = $stripeAPI->webHookResult();

6.  Return link, do loop checking:
 
    $checkout_temp_token = $this->getParamValue('session_id');
    if(!empty($checkout_temp_token)) {
        $target_temp = $eshop_order_model->getTempOrderByPayID($checkout_temp_token);
        if(!empty($target_temp)) {
            $stripe_clientSecret = $this->_setting_model->getByName('stripe_clientSecret');
            require_once(app_path('Libs/payment/StripeAPI.php'));
            $stripeAPI = new \StripeAPI($stripe_clientSecret);

            // try to get payment result
            $max_loop = 1;
            $payment_status = '';
            do {
                if($max_loop > 1) {
                    sleep(5);
                }
                $payment_status = $stripeAPI->fetchResult($checkout_temp_token, true);
                $max_loop++;
                if($payment_status == 'paid' || $payment_status == 'succeeded') {
                    $paid_transaction_id = $stripeAPI->transactionID();
                    ...
                }
                $max_loop++;
            } while(($payment_status != 'paid' && $payment_status != 'succeeded') && $max_loop < 10);
        }
    }
*/
class StripeAPI {
    private $_secret_key = '';
    private $_currency = 'hkd';
    private $_discount = 0;
    private $_shipping_fee = 0;
    private $_return_url = '';
    private $_cancel_url = '';
    private $_response = null;
    private $_transaction_id = '';
    private $_endpoint_secret = 'whsec_';

    public function __construct($secret_key = '', $endpoint_secret = '') {
        if(!empty($secret_key)) {
            $this->_secret_key = $secret_key;
        }
        if(!empty($endpoint_secret)) {
            $this->_endpoint_secret = $endpoint_secret;
        }
    }
    
    public function setCurrency($currency = 'hkd') {
        if(!empty($currency)) {
            $this->_currency = $currency;
        }
    }
    
    public function setDiscount($value = 0) {
        if(!empty($value)) {
            $this->_discount = max(0, $value);
        }
    }
    
    public function setShippingFee($value = 0) {
        if(!empty($value)) {
            $this->_shipping_fee = max(0, $value);
        }
    }
    
    public function setReturnUrl($url = '') {
        if(!empty($url)) {
            $this->_return_url = $url;
        }
    }
    
    public function setCancelUrl($url = '') {
        if(!empty($url)) {
            $this->_cancel_url = $url;
        }
    }
    
    public function getResponse() {
        return $this->_response;
    }
   
    /*
    $items = 
    [
        [
            'name'      =>  'xxxxx',
            'price'     =>  100,
            'quantity'  =>  1
        ],
        [
            'name'      =>  'yyyyy',
            'price'     =>  300,
            'quantity'  =>  3
        ]
    ];
    */
    public function doCheckout($items = [], $platform = 'web') {
        require_once(app_path('Libs/payment/stripe-php/init.php')); 
        \Stripe\Stripe::setApiKey($this->_secret_key);
        
        // custom identification ID, used for comparison with Stripe.
        $security_checkout_id = 'CS-'.md5(date('YmdHis').$platform.uniqid(rand()));
        
        // start processsing
        if(is_array($items) && !empty($items)) {
            if(strtolower($platform) == 'app') {
                $items_details = [];
                $totalAmount = 0;
                $k = 1;
                foreach ($items as $item) {
                    $totalAmount += $item['price'] * $item['quantity'];
                    $items_details['Item'.$k] = $item['name'].' x '.$item['quantity'].' | $'.number_format(round($item['price'], 2), 2);
                    $k++;
                }
                
                if(!empty($this->_discount)) {
                    $totalAmount -= $this->_discount;
                    $items_details['Discount'] = '$'.number_format(round($this->_discount, 2), 2);
                }
                
                if(!empty($this->_shipping_fee)) {
                    $totalAmount += $this->_shipping_fee;
                    $items_details['ShippingFee'] = '$'.number_format(round($this->_shipping_fee, 2), 2);
                }
                $items_details['securityCheckoutID'] = $security_checkout_id;
  
                $paymentIntent = \Stripe\PaymentIntent::create([
                    'amount'    =>  round($totalAmount * 100),
                    'currency'  =>  $this->_currency,
                    'metadata'  =>  $items_details,
                    'automatic_payment_methods' =>  ['enabled' => true],
                ]);

                return [
                    'payID'                 =>  $paymentIntent->id,
                    'clientSecret'          =>  $paymentIntent->client_secret,
                    'securityCheckoutID'    =>  $security_checkout_id
                ];
            }
            else if(!empty($this->_return_url) && !empty($this->_cancel_url)) {
                $line_items = [];
                foreach ($items as $key => $value) {
                    $line_items[] = [
                        'price_data' => [
                            'product_data' => [
                                'name' => $value['name'],
                            ],
                            'currency' => $this->_currency,
                            'unit_amount' => round($value['price']*100),
                        ],
                        'quantity' => $value['quantity']
                    ];
                }

                $options =
                [
                    'line_items'    =>  $line_items,
                    'discounts'     =>  null,
                    'mode'          =>  'payment',
                    'success_url'   =>  $this->_return_url.((strpos($this->_return_url, '?') === false)?'?':'&').'session_id='.$security_checkout_id,
                    'cancel_url'    =>  $this->_cancel_url.((strpos($this->_return_url, '?') === false)?'?':'&').'session_id='.$security_checkout_id,
                    'metadata'      => 
                    [
                        'securityCheckoutID' => $security_checkout_id
                    ]
                ];

                if(!empty($this->_discount)) {
                    $coupon = \Stripe\Coupon::create(
                    [
                        'currency'      =>  $this->_currency,
                        'amount_off'    =>  round($this->_discount*100),
                        'duration'      =>  'once'
                    ]);
                    $options['discounts'] = 
                    [
                        ['coupon' => $coupon->id]
                    ];
                }

                if(!empty($this->_shipping_fee)) {
                    $options['shipping_options'] = 
                    [
                        [
                            'shipping_rate_data' => 
                            [
                                'type' => 'fixed_amount',
                                'fixed_amount' => 
                                [
                                    'currency'  => $this->_currency,
                                    'amount'    => round($this->_shipping_fee*100)
                                ],
                                'display_name' => 'Shipping Fee'
                            ]
                        ]
                    ];
                }

                $checkout_session = \Stripe\Checkout\Session::create($options);
                
                return 
                [
                    'payID'                 =>  $checkout_session->id,  // e.g., cs_test_b1dcM72EiT6klc7zMCqmJB8hFusgVgseqHmWmGzHKcFh3cuju97oRs2uaG,
                    'payUrl'                =>  $checkout_session->url,
                    'securityCheckoutID'    =>  $security_checkout_id
                ];
            }
        }
        
        return false;
    }
    
    public function fetchResult($session_id = '', $payment_status_only = false, $platform = 'web') {
        if(!empty($session_id)) {
            require_once(app_path('Libs/payment/stripe-php/init.php'));
            \Stripe\Stripe::setApiKey($this->_secret_key);
            
            try {
                if(strtolower($platform) == 'app') {
                    $intent = \Stripe\PaymentIntent::retrieve($session_id);
                    $this->_transaction_id = $session_id;
                    return ($payment_status_only && !empty($intent['status']))?strtolower($intent['status']):$intent;
                }
                else {
                    $session = \Stripe\Checkout\Session::retrieve($session_id);
                    $this->_transaction_id = ((!empty($session['payment_intent']))?$session['payment_intent']:'');
                    return ($payment_status_only && !empty($session['payment_status']))?strtolower($session['payment_status']):$session;
                }
            } catch (\Exception $e) {
                throw $e;
                exit();
            }
        }
            
        return false;
    }

    public function webHookResult($overwrite_payload = '') {
        $payload = @file_get_contents('php://input');
        if(!empty($overwrite_payload)) {
            $payload = $overwrite_payload;
        }
        
        if(!empty($payload)) {
            require_once(app_path('Libs/payment/stripe-php/init.php'));
            \Stripe\Stripe::setApiKey($this->_secret_key);
            try {
                if (!empty($this->_endpoint_secret) && ($this->_endpoint_secret != 'whsec_')) {
                    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
                    if(empty($sig_header)) {
                        http_response_code(400);
                        return false;
                    }
                    $this->_response = \Stripe\Webhook::constructEvent(
                        $payload, $sig_header, $this->_endpoint_secret
                    );
                }
                else {
                    $this->_response = \Stripe\Event::constructFrom(
                        json_decode($payload, true)
                    );
                }
            } catch(\UnexpectedValueException $e) {
                http_response_code(400);
                return false;
            }
            
            if (strtolower($this->_response['type']) == 'checkout.session.completed') {
                if(strtolower($this->_response['data']['object']['payment_status']) == 'paid') {
                    return $this->_response['data']['object'];
                }
            }
            else if (strtolower($this->_response['type']) == 'payment_intent.succeeded') {
                if(strtolower($this->_response['data']['object']['status']) == 'succeeded') {
                    $related_checkout_session = \Stripe\Checkout\Session::all([
                        'payment_intent' => $this->_response['data']['object']['id'],
                        'limit' => 1
                    ]);
                    
                    return  $related_checkout_session->data[0] ?? null;
                }
            }
        }
        
        return false;
    }
    
    public function transactionID($session_id = '') {
        if(!empty($session_id)) {
            $this->fetchResult($session_id);
        }
        
        return $this->_transaction_id;
    }
}
