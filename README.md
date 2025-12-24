# CodeIgniter 4 SMS Package

CodeIgniter 4 için eksiksiz ve güçlü SMS paketi. MutluCell, SanalSantral ve NetGsm SMS servislerini destekler.

## Kurulum

```bash
composer require yakupeyisan/codeigniter4-sms
```

## Yapılandırma

`app/Config/Sms.php` dosyasını oluşturun veya `php spark sms:publish` komutu ile yayınlayın.

## Kullanım

```php
use Yakupeyisan\CodeIgniter4\Sms\Sms;

$sms = new Sms('mutlucell'); // veya 'sanalsantral', 'netgsm'

$result = $sms->send('905551234567', 'Merhaba, bu bir test mesajıdır.');

if ($result['success']) {
    echo "SMS gönderildi!";
} else {
    echo "Hata: " . $result['message'];
}
```

## Desteklenen Servisler

- **MutluCell**: Türkiye'nin önde gelen SMS servis sağlayıcılarından biri
- **SanalSantral**: Sanal santral ve SMS çözümleri
- **NetGsm**: Güvenilir SMS gönderim servisi

## Lisans

MIT

