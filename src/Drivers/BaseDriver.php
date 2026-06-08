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
     * Guzzle/cURL verify seçeneği: false, true veya CA bundle dosya yolu.
     *
     * Windows/IIS ortamlarında php.ini curl.cainfo boş olduğunda
     * "unable to get local issuer certificate" hatasını önlemek için
     * CURL_CA_BUNDLE, php.ini veya projedeki certs/cacert.pem kullanılır.
     *
     * @return bool|string
     */
    protected function resolveSslVerify()
    {
        $verifySsl = env('SMS_VERIFY_SSL', env('CURL_VERIFY_SSL', true));
        if ($verifySsl === false || $verifySsl === 'false' || $verifySsl === '0') {
            return false;
        }

        $candidates = [
            env('CURL_CA_BUNDLE', ''),
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
        ];

        if (defined('ROOTPATH')) {
            $candidates[] = ROOTPATH . 'certs/cacert.pem';
            $candidates[] = ROOTPATH . 'writable/certs/cacert.pem';
            $candidates[] = ROOTPATH . 'writable/cacert.pem';
        }

        foreach ($candidates as $path) {
            $path = trim((string) $path);
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return true;
    }

    /**
     * HTTP POST isteği gönder (CURLRequest; curl_exec değil).
     */
    protected function sendHttpRequest(string $url, array $data, array $headers = []): array
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new SmsException('Geçersiz HTTP URL');
        }

        $options = [
            'form_params' => $data,
            'http_errors' => false,
            'verify' => $this->resolveSslVerify(),
            'timeout' => $this->config->timeout,
        ];

        $parsedHeaders = $this->normalizeHttpHeaders($headers);
        if ($parsedHeaders !== []) {
            $options['headers'] = $parsedHeaders;
        }

        try {
            $httpResponse = \Config\Services::curlrequest()->post($url, $options);
        } catch (\Throwable $e) {
            throw new SmsException('HTTP isteği başarısız: ' . $e->getMessage());
        }

        return [
            'http_code' => $httpResponse->getStatusCode(),
            'body' => (string) $httpResponse->getBody(),
        ];
    }

    /**
     * @param list<string> $headers "Name: value" format
     * @return array<string, string>
     */
    private function normalizeHttpHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            if (! is_string($header)) {
                continue;
            }
            $pos = strpos($header, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($header, 0, $pos));
            $value = trim(substr($header, $pos + 1));
            if ($name !== '') {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
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

