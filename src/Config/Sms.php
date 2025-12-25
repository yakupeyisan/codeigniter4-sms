<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Config;

use CodeIgniter\Config\BaseConfig;

class Sms extends BaseConfig
{
    /**
     * Varsayılan SMS driver
     * Seçenekler: mutlucell, sanalsantral, netgsm
     */
    public string $defaultDriver = 'mutlucell';

    /**
     * MutluCell Ayarları
     */
    public array $mutlucell = [
        'username' => '',
        'password' => '',
        'originator' => '',
        'url' => 'https://smsgw.mutlucell.com/smsgw-ws/sndblkex',
    ];

    /**
     * SanalSantral Ayarları
     */
    public array $sanalsantral = [
        'username' => '',
        'password' => '',
        'originator' => '',
        'url' => 'https://api.sanalsantral.com/sms/send',
    ];

    /**
     * NetGsm Ayarları
     */
    public array $netgsm = [
        'username' => '',
        'password' => '',
        'originator' => '',
        'url' => 'http://soap.netgsm.com.tr:8080/Sms_webservis/SMS?wsdl/',
    ];

    /**
     * SMS logging aktif mi?
     */
    public bool $logging = true;

    /**
     * Log dosyası yolu
     */
    public string $logPath = WRITEPATH . 'logs/sms/';

    /**
     * Timeout (saniye)
     */
    public int $timeout = 30;

    /**
     * Constructor - .env dosyasından değerleri yükler
     */
    public function __construct()
    {
        parent::__construct();

        // Driver
        $this->defaultDriver = env('SMS_DRIVER', $this->defaultDriver);

        // MutluCell
        $this->mutlucell['username'] = env('SMS_MUTLUCELL_USERNAME', $this->mutlucell['username']);
        $this->mutlucell['password'] = env('SMS_MUTLUCELL_PASSWORD', $this->mutlucell['password']);
        $this->mutlucell['originator'] = env('SMS_MUTLUCELL_ORIGINATOR', $this->mutlucell['originator']);
        $this->mutlucell['url'] = env('SMS_MUTLUCELL_URL', $this->mutlucell['url']);

        // SanalSantral
        $this->sanalsantral['username'] = env('SMS_SANALSANTRAL_USERNAME', $this->sanalsantral['username']);
        $this->sanalsantral['password'] = env('SMS_SANALSANTRAL_PASSWORD', $this->sanalsantral['password']);
        $this->sanalsantral['originator'] = env('SMS_SANALSANTRAL_ORIGINATOR', $this->sanalsantral['originator']);
        $this->sanalsantral['url'] = env('SMS_SANALSANTRAL_URL', $this->sanalsantral['url']);

        // NetGsm
        $this->netgsm['username'] = env('SMS_NETGSM_USERNAME', $this->netgsm['username']);
        $this->netgsm['password'] = env('SMS_NETGSM_PASSWORD', $this->netgsm['password']);
        $this->netgsm['originator'] = env('SMS_NETGSM_ORIGINATOR', $this->netgsm['originator']);
        $this->netgsm['url'] = env('SMS_NETGSM_URL', $this->netgsm['url']);

        // Diğer ayarlar
        $this->logging = (bool)env('SMS_LOGGING', $this->logging);
        $this->logPath = env('SMS_LOG_PATH', $this->logPath);
        $this->timeout = (int)env('SMS_TIMEOUT', $this->timeout);
    }
}

