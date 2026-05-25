<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Drivers;

use Yakupeyisan\CodeIgniter4\Sms\Config\Sms as SmsConfig;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\SmsException;

class SanalSantralDriver extends BaseDriver
{
    protected string $username;
    protected string $password;
    protected string $originator;
    protected string $url;

    public function __construct(SmsConfig $config)
    {
        parent::__construct($config);
        
        $this->username = $this->config->sanalsantral['username'];
        $this->password = $this->config->sanalsantral['password'];
        $this->originator = $this->config->sanalsantral['originator'];
        $this->url = $this->config->sanalsantral['url'];
        
        $this->validateConfiguration();
    }

    /**
     * Yapılandırma doğrulaması
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->username)) {
            throw new ConfigurationException("SanalSantral username belirtilmelidir");
        }
        
        if (empty($this->password)) {
            throw new ConfigurationException("SanalSantral password belirtilmelidir");
        }
        
        if (empty($this->originator)) {
            throw new ConfigurationException("SanalSantral originator belirtilmelidir");
        }

        if ($this->url === '' || filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('SanalSantral URL geçersiz: ' . $this->url);
        }
    }

    /**
     * SMS gönder
     */
    public function send($to, string $message, array $options = []): array
    {
        try {
            // Telefon numaralarını formatla (eski sistem formatı)
            $phones = is_array($to) ? $to : [$to];
            $formattedPhones = array_map(function($phone) {
                // Eski sistem formatı: substr(str_replace(["(","+","-","(",")"," "],["","","","","",""],$number),-10)
                $number = str_replace(["(","+","-","(",")"," "], ["","","","","",""], $phone);
                return substr($number, -10);
            }, $phones);
            $number = $formattedPhones[0]; // SanalSantral tek numara alıyor
            
            // Mesajı temizle
            $message = trim($message);
            
            if (empty($message)) {
                throw new SmsException("SMS mesajı boş olamaz");
            }
            
            // SanalSantral XML formatı
            $postData = "<sms>" .
                "<apikey>" . htmlspecialchars($this->password, ENT_XML1, 'UTF-8') . "</apikey>" .
                "<header>" . htmlspecialchars($this->username, ENT_XML1, 'UTF-8') . "</header>" .
                "<type></type>" .
                "<validity>2880</validity>" .
                "<message>" .
                    "<gsm>" .
                        "<no>" . htmlspecialchars($number, ENT_XML1, 'UTF-8') . "</no>" .
                    "</gsm>" .
                    "<msg>" . htmlspecialchars($message, ENT_XML1, 'UTF-8') . "</msg>" .
                "</message>" .
                "</sms>";
            
            $verifySsl = true;
            $caBundle = env('CURL_CA_BUNDLE', '');
            if ($caBundle !== '' && is_file($caBundle)) {
                $verifySsl = $caBundle;
            }

            try {
                $httpResponse = \Config\Services::curlrequest()->post($this->url, [
                    'body' => $postData,
                    'headers' => ['Content-Type' => 'text/xml; charset=UTF-8'],
                    'http_errors' => false,
                    'verify' => $verifySsl,
                    'timeout' => $this->config->timeout,
                ]);
                $response = (string) $httpResponse->getBody();
                $httpCode = $httpResponse->getStatusCode();
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => 'HTTP isteği başarısız: ' . $e->getMessage(),
                    'data' => ['error' => $e->getMessage()],
                ];
            }

            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'message' => "HTTP {$httpCode}: {$response}",
                    'data' => ['http_code' => $httpCode, 'body' => $response],
                ];
            }
            
            // Yanıtı parse et
            return $this->parseResponse($response);
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * API yanıtını parse et
     */
    protected function parseResponse(string $response): array
    {
        $response = trim($response);
        $responseParts = explode(" ", $response);
        
        // Eski sistem kontrolü: explode(" ",$response)[0]!="00"
        if (!isset($responseParts[0]) || $responseParts[0] != "00") {
            return [
                'success' => false,
                'message' => "Bilinmeyen hata",
                'data' => ['Result' => "Bilinmeyen hata", 'response' => $response],
            ];
        }
        
        // Başarılı
        $result = isset($responseParts[1]) ? $responseParts[1] : $response;
        return [
            'success' => true,
            'message' => 'Mesaj gönderildi.',
            'data' => ['Result' => $result],
        ];
    }
}

