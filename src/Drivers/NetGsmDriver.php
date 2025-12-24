<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Drivers;

use Yakupeyisan\CodeIgniter4\Sms\Config\Sms as SmsConfig;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\SmsException;

class NetGsmDriver extends BaseDriver
{
    protected string $username;
    protected string $password;
    protected string $originator;
    protected string $url;

    public function __construct(SmsConfig $config)
    {
        parent::__construct($config);
        
        $this->username = $this->config->netgsm['username'];
        $this->password = $this->config->netgsm['password'];
        $this->originator = $this->config->netgsm['originator'];
        $this->url = $this->config->netgsm['url'];
        
        $this->validateConfiguration();
    }

    /**
     * Yapılandırma doğrulaması
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->username)) {
            throw new ConfigurationException("NetGsm username belirtilmelidir");
        }
        
        if (empty($this->password)) {
            throw new ConfigurationException("NetGsm password belirtilmelidir");
        }
        
        if (empty($this->originator)) {
            throw new ConfigurationException("NetGsm originator belirtilmelidir");
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
            
            // NetGsm API parametreleri
            $data = [
                'usercode' => $this->username,
                'password' => $this->password,
                'gsmno' => implode(',', $formattedPhones),
                'message' => $message,
                'msgheader' => $this->originator,
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
        $body = trim($response['body']);
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'message' => "HTTP {$httpCode}: {$body}",
                'data' => ['http_code' => $httpCode, 'body' => $body],
            ];
        }
        
        // NetGsm genellikle sayısal kod döner
        // 00 = başarılı, diğerleri hata
        if ($body === '00' || strpos($body, '00') === 0) {
            return [
                'success' => true,
                'message' => 'SMS başarıyla gönderildi',
                'data' => ['body' => $body],
            ];
        }
        
        // Hata kodları
        $errorMessages = [
            '20' => 'Mesaj metninde hata var',
            '30' => 'Geçersiz kullanıcı adı, şifre veya hesabınızda SMS gönderebileceğiniz kadar kredi yok',
            '40' => 'Mesaj başlığı (sender ID) kayıtlı değil',
            '50' => 'Abone hesabında yeterli kredi yok',
            '51' => 'Kredi limiti aşıldı',
            '70' => 'Hatalı sorgu. Gönderdiğiniz parametrelerden birisi hatalı veya zorunlu alanlardan birisi eksik',
        ];
        
        $errorCode = substr($body, 0, 2);
        $errorMessage = $errorMessages[$errorCode] ?? 'Bilinmeyen hata: ' . $body;
        
        return [
            'success' => false,
            'message' => $errorMessage,
            'data' => ['body' => $body, 'error_code' => $errorCode],
        ];
    }
}

