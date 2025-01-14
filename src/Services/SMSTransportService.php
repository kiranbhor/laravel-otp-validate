<?php

namespace Ferdous\OtpValidator\Services;

use Exception;
use Ferdous\OtpValidator\Exceptions\InvalidMethodException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;

class SMSTransportService implements TransportServiceInterface
{
    private $number;
    private $otp;
    private $service;
    private $company;

    private static $client = null;
    private $response = '';
    private $responseCode = '';

    /**
     * SMSService constructor.
     * @param $number
     * @param $otp
     */
    public function __construct($number, $otp)
    {
        $this->number = $number;
        $this->otp = $otp;
        $this->service = config('otp.service-name');
        $this->company = config('otp.company-name');
        $this->createClient();
    }

    /**
     * @return $this
     */
    protected function createClient()
    {
        if (!self::$client) {
            self::$client = new Client;
        }
        return $this;
    }

    /**
     * @return GuzzleHttp\Client | null
     */
    public function getClient()
    {
        return self::$client;
    }

    /**
     * @param string $otp
     * @param string $service
     * @param string $company
     * @return array|string
     */
    private function replaceOtpInTheTemplate(string $otp, string $service, string $company)
    {
        try {
            return view('vendor.template-otp.sms')
                ->with(['otp' => $otp, 'company' => $company, 'service' => $service])
                ->render();
        } catch (\Throwable $e) {
            echo $e->getMessage();
        }
    }

    /**
     *
     */
    public function send(): void
    {
        try {
            $this->sendMessage(
                $this->number,
                $this->replaceOtpInTheTemplate($this->otp, $this->service, $this->company));
        } catch (Exception $e) {
            dd($e->getMessage());
        }
    }

    /**
     * @param $to
     * @param $message
     * @param null $extra_params
     * @param array $extra_headers
     * @return $this
     * @throws InvalidMethodException
     */
    public function sendMessage($to, $message, $extra_params = null, $extra_headers = [])
    {
        $config = config('otp.smsc');
        $headers = $config['headers'] ?? [];
        $number = isset($config['add_code']) ? $config['add_code'] . $to : $to;
        $send_to_param_name = $config['params']['send_to_param_name'];
        $msg_param_name = $config['params']['msg_param_name'];
        $params = $config['params']['others'];

        if ($extra_params) {
            $params = array_merge($params, $extra_params);
        }

        if ($extra_headers) {
            $headers = array_merge($headers, $extra_headers);
        }

        // wrapper
        $wrapper = $config['wrapper'] ?? null;
        $wrapperParams = $config['wrapperParams'] ?? [];
        $send_vars = [];

        if ($wrapper) {
            $send_vars[$send_to_param_name] = $number;
            $send_vars[$msg_param_name] = $message;
        } else {
            $params[$send_to_param_name] = $number;
            $params[$msg_param_name] = $message;
        }

        if ($wrapper && $wrapperParams) {
            $send_vars = array_merge($send_vars, $wrapperParams);
        }

        try {
            //Build Request
            $request = new Request($config['method'], $config['url']);
            if ($config['method'] == "GET") {
                $promise = $this->getClient()->sendAsync(
                    $request,
                    [
                        'query' => $params,
                        'headers' => $headers,
                    ]
                );
            } elseif ($config['method'] == "POST") {
                $payload = $wrapper ? array_merge(array($wrapper => array($send_vars)), $params) : $params;
                if ((isset($config['json']) && $config['json'])) {
                    $promise = $this->getClient()->sendAsync(
                        $request,
                        [
                            'json' => $payload,
                            'headers' => $headers,
                        ]
                    );
                } else {
                    $promise = $this->getClient()->sendAsync(
                        $request,
                        [
                            'query' => $params,
                            'headers' => $headers,
                        ]
                    );
                }
            } else {
                throw new InvalidMethodException(
                    sprintf("Only GET and POST methods allowed.")
                );
            }

            $response = $promise->wait();
            $this->response = $response->getBody()->getContents();
            $this->responseCode = $response->getStatusCode();
            Log::info("OTP Validator: Number: {$number} SMS Gateway Response Code: {$this->responseCode}");
            Log::info("OTP Validator: Number: {$number} SMS Gateway Response Body: \n {$this->response}");
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                //dd($e);
                $response = $e->getResponse();
                $this->response = $e->getResponseBodySummary($response);
                $this->responseCode = $response->getStatusCode();

                Log::error("OTP Validator: Number:{$number} SMS Gateway Response Code: {$this->responseCode}");
                Log::error("OTP Validator: Number:{$number} SMS Gateway Response Body: \n { $this->response}");

                // $this->response = $e->getResponseBodySummary($e->getResponse());
            }
        }
        return $this;

    }

}
