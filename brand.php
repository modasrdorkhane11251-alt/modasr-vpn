<?php

/**
 * MODASR BOT — central brand identity.
 *
 * Single source of truth for the product name, developer identity, official
 * channels and visual identity. Code must read these values through brand()
 * instead of hard-coding product strings, so a rebrand touches one file.
 *
 * NOTE: legal/copyright notices and the LICENSE file are governed by the
 * project license (AGPL-3.0) and are NOT controlled from here.
 */
$GLOBALS['MODASR_BRAND'] = [
    'name'          => 'MODASR BOT',
    'name_short'    => 'modasr-bot',
    'name_fa'       => 'مدثر بات',
    'developer'     => 'MODASR',
    'website'       => 'https://bot.modasr.site',
    'repository'    => 'https://github.com/modasrdorkhane11251-alt/modasr-vpn',
    'channels'      => [
        'telegram' => 'https://t.me/modasrpanel',
        'bale'     => 'https://ble.ir/modasrpanel',
        'rubika'   => 'https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ',
        'splus'    => 'https://splus.ir/MODASRbot',
    ],
    'donation'      => 'https://nowpayments.io/donation/Modasrbot',
    'colors'        => [
        'primary' => '#0b1117', // deep navy/black base
        'surface' => '#16212c',
        'accent'  => '#35bfae', // MODASR teal
        'purple'  => '#7c5cff', // limited accent
    ],
];

/**
 * Read a brand value: brand('name'), brand('channels.telegram'),
 * brand('colors.accent'). Dotted keys walk nested arrays.
 */
function brand(string $key, $default = null)
{
    $value = $GLOBALS['MODASR_BRAND'] ?? [];
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}
