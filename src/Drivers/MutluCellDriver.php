<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Drivers;

use Yakupeyisan\CodeIgniter4\Sms\Config\Sms as SmsConfig;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\SmsException;

class MutluCellDriver extends BaseDriver
{
    protected string $username;
    protected string $password;
    protected string $originator;
    protected string $url;

    public function __construct(SmsConfig $config)
    {
        parent::__construct($config);
        
        $this->username = $this->config->mutlucell['username'];
        $this->password = $this->config->mutlucell['password'];
        $this->originator = $this->config->mutlucell['originator'];
        $this->url = $this->config->mutlucell['url'];
        
        $this->validateConfiguration();
    }

    /**
     * Yapılandırma doğrulaması
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->username)) {
            throw new ConfigurationException("MutluCell username belirtilmelidir");
        }
        
        if (empty($this->password)) {
            throw new ConfigurationException("MutluCell password belirtilmelidir");
        }
        
        if (empty($this->originator)) {
            throw new ConfigurationException("MutluCell originator belirtilmelidir");
        }
    }

    /**
     * SMS gönder
     */
    public function send($to, string $message, array $options = []): array
    {
        try {
            // Telefon numaralarını formatla
            $phones = is_array($to) ? $to : [$to];
            $formattedPhones = array_map([$this, 'formatPhoneNumber'], $phones);
            
            // Mesajı temizle
            $message = trim($message);
            
            if (empty($message)) {
                throw new SmsException("SMS mesajı boş olamaz");
            }
            
            // MutluCell API parametreleri
            $data = [
                'username' => $this->username,
                'password' => $this->password,
                'originator' => $this->originator,
                'message' => $message,
                'numbers' => implode(',', $formattedPhones),
            ];
            
            // HTTP isteği gönder
            $response = $this->sendHttpRequest($this->url, $data);
            
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
    protected function parseResponse(array $response): array
    {
        $httpCode = $response['http_code'];
        $body = $response['body'];
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => "HTTP {$httpCode}: {$body}",
                'data' => ['http_code' => $httpCode, 'body' => $body],
            ];
        }
        
        // MutluCell genellikle XML veya JSON döner
        // Basit bir kontrol yapalım
        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // JSON yanıt
            if (isset($json['status']) && $json['status'] === 'success') {
                return [
                    'success' => true,
                    'message' => 'SMS başarıyla gönderildi',
                    'data' => $json,
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $json['message'] ?? 'SMS gönderilemedi',
                    'data' => $json,
                ];
            }
        }
        
        // XML veya text yanıt
        // MutluCell'in yanıt formatına göre parse edilebilir
        // Şimdilik başarılı kabul edelim
        if (strpos($body, 'OK') !== false || strpos($body, 'success') !== false) {
            return [
                'success' => true,
                'message' => 'SMS başarıyla gönderildi',
                'data' => ['body' => $body],
            ];
        }
        
        return [
            'success' => false,
            'message' => 'SMS gönderilemedi: ' . $body,
            'data' => ['body' => $body],
        ];
    }
}

