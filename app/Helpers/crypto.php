<?php

declare(strict_types=1);


function app_encryption_key(): string
{
    $key = getenv('APP_ENCRYPTION_KEY');

    if (!$key) {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY is missing from your .env file. ' .
            'Visit public/generate_key.php once to create one.'
        );
    }

    
    $binary = base64_decode($key, true);

    if ($binary === false || strlen($binary) < 32) {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY looks invalid. Generate a new one with ' .
            'public/generate_key.php and update your .env file.'
        );
    }

    return $binary;
}


function encrypt_secret(string $plainText): string
{
    $key = app_encryption_key();

    // A fresh random "nonce" every time we encrypt, so encrypting the
    // same value twice never produces the same result twice.
    $nonce = random_bytes(12);

    $tag = '';
    $cipherText = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($cipherText === false) {
        throw new RuntimeException('Encryption failed.');
    }

    // Store nonce + tag + ciphertext together, base64-encoded, so it's
    // one simple string to save in a TEXT column.
    return base64_encode($nonce . $tag . $cipherText);
}


function decrypt_secret(string $encoded): string
{
    $key = app_encryption_key();

    $raw = base64_decode($encoded, true);

    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Stored secret is corrupted or unreadable.');
    }

    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipherText = substr($raw, 28);

    $plainText = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($plainText === false) {
        throw new RuntimeException(
            'Could not decrypt secret -- your APP_ENCRYPTION_KEY may have changed.'
        );
    }

    return $plainText;
}
