<?php
/** Brand central-config tests. */

section('brand');

require_once __DIR__ . '/../brand.php';

ok(brand('name') === 'MODASR BOT', 'brand name is MODASR BOT');
ok(brand('name_fa') === 'مدثر بات', 'brand Persian name');
ok(brand('channels.telegram') === 'https://t.me/modasrpanel', 'nested key channels.telegram');
ok(brand('colors.accent') === '#35bfae', 'nested key colors.accent');
ok(brand('does.not.exist', 'fallback') === 'fallback', 'missing key returns default');
ok(str_starts_with((string) brand('donation'), 'https://nowpayments.io/donation/'), 'donation link is a NowPayments URL');
ok(strpos((string) brand('donation'), 'mahdi') === false, 'donation link must not point to a third party');
