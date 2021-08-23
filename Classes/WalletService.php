<?php

namespace Classes;

class WalletService
{
    public $url;
    public $token;
    public $refreshToken;
    public $expiresIn;
    public $clientId;
    public $clientSecret;

    public function __construct($url, $clientId, $clientSecret)
    {
        $this->url          = $url;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
    }

    private function _post($url, $params, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($ch);
        if ($output === FALSE) {
            echo "CURL Error:" . curl_error($ch) . " " . $url . "\n";
            return $output;
        }
        curl_close($ch);

        return $output;
    }

    private function _get($url, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($ch);
        if ($output === FALSE) {
            echo "CURL Error:" . curl_error($ch) . " " . $url . "\n";
            return $output;
        }
        curl_close($ch);

        return $output;
    }

    public function getAccessToken(string $scopes)
    {
        $response = $this->_post($this->url . '/api/v1/oauth/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
            'scopes'        => $scopes
        ]);

        return json_decode($response);
    }

    public function refreshToken(string $refreshToken)
    {
        $response = $this->_post($this->url . '/api/v1/oauth/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken
        ]);

        return json_decode($response);
    }

    public function getBalance(string $token, string $uuid, string $currency = null)
    {
        try {
            $params = 'uuid=' . $uuid;
            if (!empty($currency)) {
                $params .= '&currency=' . $currency;
            }
            $response = $this->_get($this->url . '/api/v1/wallet/balance?' . $params, [
                'Authorization: Bearer ' . $token,
                'X-Requested-With: XMLHttpRequest'
            ]);

            $response = json_decode($response);
        } catch (Exception $e) {
            $response = $e;
        }
        return $response;
    }

    public function addBalance(string $token, string $uuid, string $currency, float $amount, string $reason)
    {
        try {
            $response = $this->_post($this->url . '/api/v1/wallet/credit', [
                'uuid'     => $uuid,
                'amount'   => $amount,
                'currency' => $currency,
                'reason'   => $reason
            ], [
                'Authorization: Bearer ' . $token,
                'X-Requested-With: XMLHttpRequest'
            ]);
            $response = json_decode($response);
        } catch (Exception $e) {
            $response = $e;
        }
        return $response;
    }

    public function subtractBalance(string $token, string $uuid, string $currency, float $amount, string $reason)
    {
        try {
            $response = $this->_post($this->url . '/api/v1/wallet/debit', [
                'uuid'     => $uuid,
                'amount'   => $amount,
                'currency' => $currency,
                'reason'   => $reason
            ], [
                'Authorization: Bearer ' . $token,
                'X-Requested-With: XMLHttpRequest'
            ]);
            $response = json_decode($response);
        } catch (Exception $e) {
            $response = $e;
        }
        return $response;
    }
}