<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Drivers;

use Yakupeyisan\CodeIgniter4\Sms\Config\Sms as SmsConfig;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\ConfigurationException;
use Yakupeyisan\CodeIgniter4\Sms\Exceptions\SmsException;

class MutluCellDriver extends BaseDriver
{
    protected string $username;
    protected string $password;
    protected string $aboneid;
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
        $this->aboneid = (string) ($this->config->mutlucell['aboneid'] ?? '');
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
            
            $result = $this->dispatch($number, $message, $this->originator, $this->aboneid);
            if (($result['data']['error_code'] ?? '') !== '21') {
                return $result;
            }

            // Abone no, başlığı bu hesaba ait olmaktan çıkarır. Aynı başlıkla abonesiz dene.
            if ($this->aboneid !== '') {
                $withoutSubscriber = $this->dispatch($number, $message, $this->originator, '');
                if (($withoutSubscriber['data']['error_code'] ?? '') !== '21') {
                    return $withoutSubscriber;
                }
                $result = $withoutSubscriber;
            }

            $owned = $this->fetchOwnedOriginators();
            $picked = $this->pickOwnedOriginator($this->originator, $owned);
            if ($picked === null || $picked === $this->originator) {
                return $result;
            }

            log_message(
                'error',
                'MutluCell originatör "' . $this->originator . '" reddedildi; "' . $picked . '" ile yeniden deneniyor.'
            );

            return $this->dispatch($number, $message, $picked, '');
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * @return array{success:bool,message:string,data:array<string,mixed>}
     */
    private function dispatch(string $number, string $message, string $originator, string $aboneid): array
    {
        $ka = htmlspecialchars($this->username, ENT_XML1, 'UTF-8');
        $pwd = htmlspecialchars($this->password, ENT_XML1, 'UTF-8');
        $attrs = 'ka="' . $ka . '" pwd="' . $pwd . '"';
        if ($aboneid !== '') {
            $attrs .= ' aboneid="' . htmlspecialchars($aboneid, ENT_XML1, 'UTF-8') . '"';
        }
        if ($originator !== '') {
            $attrs .= ' org="' . htmlspecialchars($originator, ENT_XML1, 'UTF-8') . '"';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<smspack ' . $attrs . '>' .
            '<mesaj>' .
            '<metin>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</metin>' .
            '<nums>' . htmlspecialchars($number, ENT_XML1, 'UTF-8') . '</nums>' .
            '</mesaj>' .
            '</smspack>';

        $posted = $this->postXml($this->url, $xml);
        if (isset($posted['error'])) {
            return [
                'success' => false,
                'message' => $posted['error'],
                'data' => ['error' => $posted['error']],
            ];
        }
        if ($posted['httpCode'] !== 200) {
            return [
                'success' => false,
                'message' => 'HTTP ' . $posted['httpCode'] . ': ' . $posted['body'],
                'data' => ['http_code' => $posted['httpCode'], 'body' => $posted['body']],
            ];
        }

        return $this->parseResponse($posted['body']);
    }

    /**
     * Hesaba tanımlı gönderen başlıkları. Mutlucell gtorgex.
     *
     * @return list<string>
     */
    private function fetchOwnedOriginators(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<smsorig ka="' . htmlspecialchars($this->username, ENT_XML1, 'UTF-8') . '"'
            . ' pwd="' . htmlspecialchars($this->password, ENT_XML1, 'UTF-8') . '"/>';

        $posted = $this->postXml($this->originatorListUrl(), $xml);
        if (isset($posted['error']) || $posted['httpCode'] !== 200) {
            return [];
        }

        return $this->parseOriginatorList($posted['body']);
    }

    private function originatorListUrl(): string
    {
        $parts = parse_url($this->url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'smsgw.mutlucell.com';
        if (! str_contains($host, 'mutlucell.com')) {
            $host = 'smsgw.mutlucell.com';
            $scheme = 'https';
        }

        return $scheme . '://' . $host . '/smsgw-ws/gtorgex';
    }

    /**
     * @param list<string> $owned
     */
    private function pickOwnedOriginator(string $wanted, array $owned): ?string
    {
        if ($owned === []) {
            return null;
        }

        $wantedKey = $this->originatorKey($wanted);
        foreach ($owned as $name) {
            if ($wantedKey !== '' && $this->originatorKey($name) === $wantedKey) {
                return $name;
            }
        }

        return $owned[0];
    }

    private function originatorKey(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['İ', 'I', 'ı'], ['i', 'i', 'i'], $value);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function parseOriginatorList(string $output): array
    {
        $output = trim($output);
        if ($output === '' || isset(self::$errors[$output])) {
            return [];
        }

        $names = [];
        if (str_contains($output, '<')
            && preg_match_all('/<(?:originator|org|baslik)>([^<]+)<\/(?:originator|org|baslik)>/i', $output, $matches)) {
            foreach ($matches[1] as $name) {
                $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        if ($names === []) {
            foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
                $line = trim($line, " \t\n\r\0\x0B#$*");
                if ($line === '' || isset(self::$errors[$line])) {
                    continue;
                }
                if (preg_match('/^[\p{L}\p{N} ._-]{1,16}$/u', $line) === 1) {
                    $names[] = $line;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{httpCode:int,body:string,error?:string}
     */
    private function postXml(string $url, string $xml): array
    {
        try {
            $httpResponse = \Config\Services::curlrequest()->post($url, [
                'body' => $xml,
                'headers' => ['Content-Type' => 'text/xml'],
                'http_errors' => false,
                'verify' => $this->resolveSslVerify(),
                'timeout' => $this->config->timeout,
            ]);

            return [
                'httpCode' => $httpResponse->getStatusCode(),
                'body' => (string) $httpResponse->getBody(),
            ];
        } catch (\Throwable $e) {
            return [
                'httpCode' => 0,
                'body' => '',
                'error' => 'HTTP isteği başarısız: ' . $e->getMessage(),
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

