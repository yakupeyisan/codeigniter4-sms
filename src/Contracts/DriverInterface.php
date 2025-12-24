<?php

namespace Yakupeyisan\CodeIgniter4\Sms\Contracts;

interface DriverInterface
{
    /**
     * SMS gönderir
     *
     * @param string|array $to Telefon numarası veya numaralar dizisi
     * @param string $message SMS mesajı
     * @param array $options Ek seçenekler
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function send($to, string $message, array $options = []): array;

    /**
     * Test bağlantısı yapar
     *
     * @return bool
     */
    public function test(): bool;
}

