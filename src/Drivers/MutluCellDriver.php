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

    public static array $errors = [
        '20' => 'Post edilen xml eksik veya hatalı.',
        '21' => 'Kullanılan originatöre sahip değilsiniz',
        '22' => 'Kontörünüz yetersiz',
        '23' => 'Kullanıcı adı ya da parolanız hatalı.',
        '24' => 'Şu anda size ait başka bir işlem aktif.',
        '25' => 'SMSC Stopped (Bu hatayı alırsanız, işlemi 1-2 dk sonra tekrar deneyin)',
        '30' => 'Hesap Aktivasyonu sağlanmamış'
    ];

    public function __construct(SmsConfig $config)
    {
        parent::__construct($config);
        
        $this->username = $this->config->mutlucell['username'];
        $this->password = $this->config->mutlucell['password'];
        $this->originator = $this->config->mutlucell['originator'];
        $this->url = $this->config->mutlucell['url'] ?: 'https://smsgw.mutlucell.com/smsgw-ws/sndblkex';
        
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

        if (filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('MutluCell URL geçersiz: ' . $this->url);
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
            $number = implode(',', $formattedPhones);
            
            // Mesajı temizle
            $message = trim($message);
            
            if (empty($message)) {
                throw new SmsException("SMS mesajı boş olamaz");
            }
            
            // MutluCell XML formatı
            $xml_data = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<smspack ka="' . htmlspecialchars($this->username, ENT_XML1, 'UTF-8') . '" pwd="' . htmlspecialchars($this->password, ENT_XML1, 'UTF-8') . '" org="' . htmlspecialchars($this->originator, ENT_XML1, 'UTF-8') . '" >' .
                '<mesaj>' .
                '<metin>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</metin>' .
                '<nums>' . htmlspecialchars($number, ENT_XML1, 'UTF-8') . '</nums>' .
                '</mesaj>' .
                '</smspack>';
            
            $verifySsl = true;
            $caBundle = env('CURL_CA_BUNDLE', '');
            if ($caBundle !== '' && is_file($caBundle)) {
                $verifySsl = $caBundle;
            }

            try {
                $httpResponse = \Config\Services::curlrequest()->post($this->url, [
                    'body' => $xml_data,
                    'headers' => ['Content-Type' => 'text/xml'],
                    'http_errors' => false,
                    'verify' => $verifySsl,
                    'timeout' => $this->config->timeout,
                ]);
                $output = (string) $httpResponse->getBody();
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
                    'message' => "HTTP {$httpCode}: {$output}",
                    'data' => ['http_code' => $httpCode, 'body' => $output],
                ];
            }
            
            // Yanıtı parse et
            return $this->parseResponse($output);
            
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
    protected function parseResponse(string $output): array
    {
        $output = trim($output);
        
        // Hata kodları kontrolü
        if (isset(self::$errors[$output])) {
            return [
                'success' => false,
                'message' => self::$errors[$output],
                'data' => ['Result' => self::$errors[$output], 'error_code' => $output],
            ];
        }
        
        // Başarılı
        return [
            'success' => true,
            'message' => 'Mesaj gönderildi.',
            'data' => ['Result' => $output],
        ];
    }
}

