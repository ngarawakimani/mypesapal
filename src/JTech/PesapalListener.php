<?php

namespace Jchegenye\MyPesaPal\JTech;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class PesapalListener {

    private $consumer_key;
    private $consumer_secret;
    private $statusrequestAPI;
    private $pesapal_transaction_tracking_id;
    private $pesapal_merchant_reference;
    private $pesapalNotification="CHANGE";
    private $pesapal_notification_type;

    public function __construct() {
        $this->consumer_key = Config::get('pesapal.auth.consumer_key');
        $this->consumer_secret = Config::get('pesapal.auth.consumer_secret');

        if (\App::environment('local')) {
            $this->statusrequestAPI = Config::get('pesapal.auth.demo_statusrequestAPI');
        } elseif (\App::environment('production')) {
            $this->statusrequestAPI = Config::get('pesapal.auth.live_statusrequestAPI');
       }
    }

    public function response(Request $request){
        //$pesapal_notification_type        = $request->get('pesapal_notification_type');
        $this->pesapal_transaction_tracking_id  = $request->get('pesapal_transaction_tracking_id');
        $this->pesapal_merchant_reference       = $request->get('pesapal_merchant_reference');
    }

    public function statusChecker($data){
        //$pesapal_notification_type        = $data['pesapal_notification_type'];
        $this->pesapal_transaction_tracking_id  = $data['pesapal_transaction_tracking_id'];
        $this->pesapal_merchant_reference       = $data['pesapal_merchant_reference'];

        return $this->sendRequest();
    }

    private function sendRequest(){

        if ($this->pesapal_transaction_tracking_id != '') {

            $token = $params = NULL;
            $consumer = new \OAuthConsumer($this->consumer_key, $this->consumer_secret);

            // Get transaction status
            $signature_method = new \OAuthSignatureMethod_HMAC_SHA1();
            $request_status = \OAuthRequest::from_consumer_and_token($consumer, $token, 'GET', $this->statusrequestAPI, $params);
            $request_status -> set_parameter('pesapal_merchant_reference', $this->pesapal_merchant_reference);
            $request_status -> set_parameter('pesapal_transaction_tracking_id',$this->pesapal_transaction_tracking_id);
            $request_status -> sign_request($signature_method, $consumer, $token);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $request_status);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            if (defined('CURL_PROXY_REQUIRED')) {
                if (CURL_PROXY_REQUIRED == 'True') {

                    $proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
                    curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
                    curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                    curl_setopt ($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
                }
            }

           $response = curl_exec($ch);

           $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
           $raw_header  = substr($response, 0, $header_size - 4);
           $headerArray = explode('\r\n\r\n', $raw_header);
           $header      = $headerArray[count($headerArray) - 1];

           // Transaction status
           $elements = preg_split('/=/',substr($response, $header_size));
           $responseData = explode(',', $elements[1]);

           curl_close ($ch);

            // At this point $status may have value of PENDING, COMPLETED or FAILED.
            // Please note that (as mentioned above) PesaPal will call the URL you
            // entered above with the 3 query parameters. You must respond to the HTTP
            // request with the same data that you received from PesaPal. PesaPal will
            // retry a number of times, if they don't receive the correct response (for
            // example due to network failure). So if successful, we update our DB ...
            // if it FAILED, we can cancel out transaction in our DB and notify user

            return [
                'pesapal_transaction_tracking_id' => $this->pesapal_transaction_tracking_id,
                'pesapal_merchant_reference' => $this->pesapal_merchant_reference,
                'status' => $responseData[2],
                'method' => $responseData[1]
            ];
        }
    }

}
