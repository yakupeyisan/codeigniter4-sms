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

    public static array $errors = [
        '20' => 'Mesaj metninde ki problemden dolayı gönderilemediğini veya standart maksimum mesaj karakter sayısını geçtiğini ifade eder.(Standart maksimum karakter sayısı 917 dir. Eğer mesajınız türkçe karakter içeriyorsa Türkçe Karakter Hesaplama menüsunden karakter sayılarının hesaplanış şeklini görebilirsiniz.).',
        '30' => 'Geçersiz kullanıcı adı , şifre veya kullanıcınızın API erişim izninin olmadığını gösterir. Ayrıca eğer API erişiminizde IP sınırlaması yaptıysanız ve sınırladığınız ip dışında gönderim sağlıyorsanız 30 hata kodunu alırsınız. API erişim izninizi veya IP sınırlamanızı , web arayüzden; sağ üst köşede bulunan ayarlar> API işlemleri menüsunden kontrol edebilirsiniz.',
        '40' => 'Mesaj başlığınızın (gönderici adınızın) sistemde tanımlı olmadığını ifade eder. Gönderici adlarınızı API ile sorgulayarak kontrol edebilirsiniz.',
        '50' => 'Abone hesabınız ile İYS kontrollü gönderimler yapılamamaktadır.',
        '51' => 'Aboneliğinize tanımlı İYS Marka bilgisi bulunamadığını ifade eder.',
        '70' => 'Hatalı sorgulama. Gönderdiğiniz parametrelerden birisi hatalı veya zorunlu alanlardan birinin eksik olduğunu ifade eder.',
        '80' => 'Gönderim sınır aşımı',
        '85' => 'Mükerrer Gönderim sınır aşımı. Aynı numaraya 1 dakika içerisinde 20\'den fazla görev oluşturulamaz.',
        '100' => 'Sistem hatası.',
        '101' => 'Sistem hatası.',
        '-1' => 'Entegrasyon hatası.'
    ];

    public function __construct(SmsConfig $config)
    {
        parent::__construct($config);
        
        $this->username = $this->config->netgsm['username'];
        $this->password = $this->config->netgsm['password'];
        $this->originator = $this->config->netgsm['originator'];
        $this->url = $this->config->netgsm['url'] ?: 'http://soap.netgsm.com.tr:8080/Sms_webservis/SMS?wsdl/';
        
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

        if (filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('NetGsm URL geçersiz: ' . $this->url);
        }
    }

    /**
     * @return array{output: string, httpCode: int, error: string}
     */
    private function postSoapRequest(string $soapXml): array
    {
        $verifySsl = true;
        $caBundle = env('CURL_CA_BUNDLE', '');
        if ($caBundle !== '' && is_file($caBundle)) {
            $verifySsl = $caBundle;
        }

        try {
            $httpResponse = \Config\Services::curlrequest()->post($this->url, [
                'body' => $soapXml,
                'headers' => ['Content-Type' => 'text/xml'],
                'http_errors' => false,
                'verify' => $verifySsl,
                'timeout' => $this->config->timeout,
                'allow_redirects' => ['max' => 10],
            ]);

            return [
                'output' => (string) $httpResponse->getBody(),
                'httpCode' => $httpResponse->getStatusCode(),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'output' => '',
                'httpCode' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Gönderici adlarını kontrol et
     */
    public function checkSender(): array
    {
        try {
            $soapXml = '<?xml version="1.0"?>
                <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
                            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                    <SOAP-ENV:Body>
                        <ns3:gondericiadlari xmlns:ns3="http://sms/">
                        <username>' . htmlspecialchars($this->username, ENT_XML1, 'UTF-8') . '</username>
                        <password>' . htmlspecialchars($this->password, ENT_XML1, 'UTF-8') . '</password>
                        <header>' . htmlspecialchars($this->originator, ENT_XML1, 'UTF-8') . '</header>
                    </ns3:gondericiadlari>
                    </SOAP-ENV:Body>
                </SOAP-ENV:Envelope>';

            $result = $this->postSoapRequest($soapXml);

            if ($result['error'] !== '') {
                return [
                    'success' => false,
                    'message' => 'HTTP isteği başarısız: ' . $result['error'],
                    'data' => ['error' => $result['error']],
                ];
            }

            return [
                'success' => true,
                'message' => 'Başarılı',
                'data' => ['Result' => $result['output']],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
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
                // Eski sistem formatı: substr(str_replace(["(","+","-","(",")"],["","","","",""],$number),-10)
                $number = str_replace(["(","+","-","(",")"," "], ["","","","","",""], $phone);
                return substr($number, -10);
            }, $phones);
            $number = $formattedPhones[0]; // NetGsm tek numara alıyor
            
            // Mesajı temizle
            $message = trim($message);
            
            if (empty($message)) {
                throw new SmsException("SMS mesajı boş olamaz");
            }
            
            // NetGsm SOAP XML formatı
            $soapXml = '<?xml version="1.0"?>
            <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                <SOAP-ENV:Body>
                    <ns3:smsGonder1NV2 xmlns:ns3="http://sms/">
                        <username>' . htmlspecialchars($this->username, ENT_XML1, 'UTF-8') . '</username>
                        <password>' . htmlspecialchars($this->password, ENT_XML1, 'UTF-8') . '</password>
                        <header>' . htmlspecialchars($this->originator, ENT_XML1, 'UTF-8') . '</header>
                        <msg>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</msg>
                        <gsm>' . htmlspecialchars($number, ENT_XML1, 'UTF-8') . '</gsm>
                        <encoding>TR</encoding>
                    </ns3:smsGonder1NV2>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>';
            
            $result = $this->postSoapRequest($soapXml);
            $output = $result['output'];
            $httpCode = $result['httpCode'];

            if ($result['error'] !== '') {
                return [
                    'success' => false,
                    'message' => 'HTTP isteği başarısız: ' . $result['error'],
                    'data' => ['error' => $result['error']],
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
        try {
            $resultDocument = new \DOMDocument();
            $resultDocument->loadXML(str_replace("''", '"', $output));
            $returnNode = $resultDocument->getElementsByTagName("return")->item(0);
            $returnCode = "-1";
            
            if ($returnNode != null) {
                $returnCode = $returnNode->nodeValue;
            }
            
            // Hata kodları kontrolü
            if (isset(self::$errors[$returnCode])) {
                return [
                    'success' => false,
                    'message' => self::$errors[$returnCode],
                    'data' => ['Result' => self::$errors[$returnCode], 'error_code' => $returnCode],
                ];
            }
            
            // Başarılı
            return [
                'success' => true,
                'message' => 'Mesaj gönderildi.',
                'data' => ['Result' => $returnCode],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Yanıt parse edilemedi: ' . $e->getMessage(),
                'data' => ['body' => $output],
            ];
        }
    }
}

