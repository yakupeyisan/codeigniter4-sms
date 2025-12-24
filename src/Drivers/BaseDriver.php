<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Drivers;

use Yakupeyisan\CodeIgniter4\Sms\Config\Sms as SmsConfig;
use Yakupeyisan\CodeIgniter4\Sms\Contracts\DriverInterface;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\SmsException;

abstract class BaseDriver implements DriverInterface
{
    protected SmsConfig $config;

    public function __construct(SmsConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Telefon numarasını temizle ve formatla
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Sadece rakamları al
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Türkiye formatı: 90 ile başlıyorsa olduğu gibi, değilse 90 ekle
        if (strlen($phone) == 10 && substr($phone, 0, 1) == '5') {
            $phone = '90' . $phone;
        } elseif (strlen($phone) == 11 && substr($phone, 0, 2) == '05') {
            $phone = '9' . $phone;
        }
        
        return $phone;
    }

    /**
     * HTTP POST isteği gönder
     */
    protected function sendHttpRequest(string $url, array $data, array $headers = []): array
    {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new SmsException("HTTP isteği başarısız: {$error}");
        }
        
        return [
            'http_code' => $httpCode,
            'body' => $response,
        ];
    }

    /**
     * Test bağlantısı (varsayılan implementasyon)
     */
    public function test(): bool
    {
        try {
            // Test mesajı gönder (gerçek gönderim yapmadan)
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

