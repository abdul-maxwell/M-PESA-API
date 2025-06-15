<?php
header('content-type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $data = file_get_contents("php://input");
        if ($data === false) {
            throw new Exception("Failed to read input data");
        }
        
        $data = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON input: " . json_last_error_msg());
        }

        if (!isset($data['amount']) || !isset($data['phone-num'])) {
            throw new Exception("Missing required fields (amount or phone-num)");
        }

        Mpesa::stkSend($data);
        
        if (empty(Mpesa::$response)) {
            throw new Exception("Empty response from Mpesa API");
        }
        
        $response = json_decode(Mpesa::$response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Mpesa: " . json_last_error_msg());
        }
        
        echo Mpesa::$response;
        
    } catch (Exception $e) {
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
} else {
   echo json_encode(['code' => 0, 'method' => $_SERVER['REQUEST_METHOD']]);
}

class Mpesa
{
    public static $credentials, $token, $payload, $response, $url;

    public static function load() : void
    {
        self::$credentials = array(
            'consumer_key' => '5PlPThFg2TFbnULywCRJ2AJcCAZ5nhnxf7iulgKD2UKeXGQ0',
            'consumer_secret' => 'bAdVHfuBWyKmLSb0DVdzrwWsVksnYKcxKe0Xr7kd3cQLHIg75aQZLcAEdhI1GlmT',
        );
    }

    public static function stkSend(){
        $args = func_get_args();
        $data = $args[0];

        try {
            $Amount = $data['amount'];
            $AccountReference = 'test_payment';
            $CallBackURL = 'https://stk-maxwelll.up.railway.app/callback_url.php';
            $PhoneNumber = $PartyA = $data['phone-num'];

            $passkey = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919";
            $Timestamp = date('YmdHis');
            $TransactionType = 'CustomerPayBillOnline';
            $TransactionDesc = 'Test Payment';

            $PartyB = $BusinessShortCode = 174379;
            $Password = base64_encode($BusinessShortCode . $passkey . $Timestamp);

            self::$payload = compact(
                'Password',
                'BusinessShortCode',
                'PhoneNumber',
                'PartyB',
                'PartyA',
                'Timestamp',
                'AccountReference',
                'Amount',
                'TransactionDesc',
                'CallBackURL',
                'TransactionType'
            );

            self::$url = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";

            self::curlPost();
        } catch (Exception $e) {
            self::$response = json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }

    public static function curlPost()
    {
        self::AccessToken();
        
        $ch = curl_init();
        curl_setopt_array(
            $ch,
            array(
                CURLOPT_URL => self::$url,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_HTTPHEADER =>  array('Content-Type: application/json', 'Authorization:Bearer ' . self::$token),
                CURLOPT_POST =>  1,
                CURLOPT_POSTFIELDS =>  json_encode(self::$payload),
                CURLOPT_RETURNTRANSFER =>  true,
                CURLOPT_SSL_VERIFYPEER =>  false,
                CURLOPT_SSL_VERIFYHOST =>  false,
                CURLOPT_VERBOSE => true // For debugging
            )
        );
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            self::$response = json_encode([
                'error' => true,
                'message' => 'Curl error: ' . curl_error($ch)
            ]);
        } else {
            self::$response = $response;
        }
        
        curl_close($ch);
    }

    public static function AccessToken()
    {
        self::load();

        $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $curl = curl_init($url);
        
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_HTTPHEADER => ['Content-Type:application/json; charset=utf8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_USERPWD => self::$credentials['consumer_key'] . ':' . self::$credentials['consumer_secret'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            )
        );
        
        $result = curl_exec($curl);
        
        if (curl_errno($curl)) {
            throw new Exception('Curl error: ' . curl_error($curl));
        }
        
        $result = json_decode($result);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg());
        }
        
        if (!isset($result->access_token)) {
            throw new Exception('Failed to get access token. Response: ' . json_encode($result));
        }
        
        curl_close($curl);
        self::$token = $result->access_token;
    }
}
