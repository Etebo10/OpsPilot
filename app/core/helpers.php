<?php

function uuid(): string
{
    $data = random_bytes(16);

    $data[6] = chr(
        ord($data[6]) & 0x0f | 0x40
    );

    $data[8] = chr(
        ord($data[8]) & 0x3f | 0x80
    );

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($data), 4)
    );
}


function generate_reference(string $prefix): string
{
    return $prefix .
        '-' .
        date('ymd') .
        '-' .
        strtoupper(
            substr(
                bin2hex(random_bytes(5)),
                0,
                8
            )
        );
}


function currency_symbol(string $currency): string
{
    return match (strtoupper($currency)) {

        'NGN' => '₦',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => 'CA$',
        'AUD' => 'A$',

        default => strtoupper($currency) . ' '
    };
}