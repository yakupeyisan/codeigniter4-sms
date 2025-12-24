<?php

namespace Yakupeyisan\CodeIgniter4\Sms;

use Yakupeyisan\CodeIgniter4\Sms\Config\Sms as SmsConfig;
use Yakupeyisan\CodeIgniter4\Sms\Contracts\SmsInterface;
use Yakupeyisan\CodeIgniter4\Sms\Contracts\DriverInterface;
use Yakupeyisan\CodeIgniter4\Sms\Drivers\MutluCellDriver;
use Yakupeyisan\CodeIgniter4\Sms\Drivers\SanalSantralDriver;
use Yakupeyisan\CodeIgniter4\Sms\Drivers\NetGsmDriver;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\SmsException;

class Sms implements SmsInterface
{
    protected SmsConfig $config;
    protected DriverInterface $driver;
    protected ?string $driverName = null;

    public function __construct(?string $driver = null)
    {
        $this->config = config('Sms');
        $this->driverName = $driver ?? $this->config->defaultDriver;
        $this->driver = $this->createDriver($this->driverName);
    }

    /**
     * Driver oluştur
     */
    protected function createDriver(string $driver): DriverInterface
    {
        return match ($driver) {
            'mutlucell' => new MutluCellDriver($this->config),
            'sanalsantral' => new SanalSantralDriver($this->config),
            'netgsm' => new NetGsmDriver($this->config),
            default => throw new ConfigurationException("Bilinmeyen driver: {$driver}"),
        };
    }

    /**
     * SMS gönder
     */
    public function send($to, string $message, array $options = []): array
    {
        try {
            // Validation
            $this->validateSms($to, $message);
            
            // Logging
            if ($this->config->logging) {
                $this->logSms($to, $message, 'sending');
            }
            
            // Send
            $result = $this->driver->send($to, $message, $options);
            
            // Logging
            if ($this->config->logging) {
                $this->logSms($to, $message, $result['success'] ? 'sent' : 'failed', $result['message'] ?? null);
            }
            
            return $result;
        } catch (\Exception $e) {
            if ($this->config->logging) {
                $this->logSms($to, $message, 'error', $e->getMessage());
            }
            throw new SmsException("SMS gönderilemedi: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * SMS validation
     */
    protected function validateSms($to, string $message): void
    {
        if (empty($to)) {
            throw new SmsException("Alıcı (to) belirtilmelidir");
        }
        
        if (empty(trim($message))) {
            throw new SmsException("SMS mesajı belirtilmelidir");
        }
    }

    /**
     * SMS logla
     */
    protected function logSms($to, string $message, string $status, ?string $errorMessage = null): void
    {
        $logPath = $this->config->logPath;
        
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
        
        $logFile = $logPath . 'sms-' . date('Y-m-d') . '.log';
        
        $phones = is_array($to) ? $to : [$to];
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $status,
            'driver' => $this->driverName,
            'to' => $phones,
            'message' => substr($message, 0, 100), // İlk 100 karakter
            'error' => $errorMessage,
        ];
        
        $logLine = json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    /**
     * Test bağlantısı
     */
    public function test(): bool
    {
        return $this->driver->test();
    }

    /**
     * Driver'ı değiştir
     */
    public function driver(string $driver): self
    {
        $this->driverName = $driver;
        $this->driver = $this->createDriver($driver);
        return $this;
    }

    /**
     * Mevcut driver'ı döndür
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }
}

