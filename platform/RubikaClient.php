<?php
/**
 * RubikaClient — adapter between the Telegram Bot API surface used by the
 * Modasr bot core and the Rubika messenger Bot API (botapi.rubika.ir/v3).
 *
 * Rubika's schema differs from Telegram's (inline_keypad vs inline_keyboard,
 * status/data vs ok/result, different update types), so this class translates
 * both outgoing calls and incoming webhook updates.
 *
 * Limitations of the Rubika Bot API (no equivalent feature):
 *   - inline queries, native payments (pre_checkout_query), URL buttons,
 *     chat actions, pin/unpin. These degrade gracefully (see below).
 */
class RubikaClient
{
    private string $token;
    private string $base;

    public function __construct(string $token)
    {
        $this->token = $token;
        $this->base  = 'https://botapi.rubika.ir/v3/' . $token . '/';
    }

    /* ------------------------------------------------------------------ */
    /*  Public entry point: accept a Telegram-style method + payload       */
    /* ------------------------------------------------------------------ */

    public function call(string $method, array $datas): array
    {
        switch ($method) {
            case 'sendMessage':
                return $this->sendMessage($datas);
            case 'sendPhoto':
            case 'sendVideo':
            case 'sendDocument':
            case 'sendAudio':
                return $this->sendFileMessage($method, $datas);
            case 'editMessageText':
                return $this->request('editMessageText', [
                    'chat_id'    => $datas['chat_id'] ?? '',
                    'message_id' => $datas['message_id'] ?? 0,
                    'text'       => $this->cleanText($datas['text'] ?? ''),
                ]);
            case 'deleteMessage':
                return $this->request('deleteMessage', [
                    'chat_id'    => $datas['chat_id'] ?? '',
                    'message_id' => $datas['message_id'] ?? 0,
                ]);
            case 'forwardMessage':
                return $this->request('forwardMessage', [
                    'from_chat_id' => $datas['from_chat_id'] ?? '',
                    'message_id'   => $datas['message_id'] ?? 0,
                    'to_chat_id'   => $datas['chat_id'] ?? '',
                ]);
            case 'getMe':
                return $this->request('getMe', []);
            case 'getFile':
                // Rubika files are already downloadable by id on the CDN.
                return [
                    'ok'     => true,
                    'result' => ['file_id' => $datas['file_id'] ?? '', 'file_path' => $datas['file_id'] ?? ''],
                ];
            case 'setWebhook':
                // Webhooks are registered from the Rubika bot settings panel,
                // not through the API — report success so install flows pass.
                return ['ok' => true, 'result' => true, 'description' => 'Configure the webhook in the Rubika bot panel.'];
            case 'answerCallbackQuery':
            case 'sendChatAction':
            case 'pinChatMessage':
            case 'unpinChatMessage':
            case 'editMessageReplyMarkup':
            case 'deleteMyCommands':
            case 'setMyCommands':
                // No Rubika equivalent — harmless no-op.
                return ['ok' => true, 'result' => true];
            case 'answerInlineQuery':
            case 'answerPreCheckoutQuery':
            case 'sendInvoice':
                return [
                    'ok'          => false,
                    'error_code'  => 501,
                    'description' => $method . ' is not supported by the Rubika Bot API.',
                ];
            default:
                return [
                    'ok'          => false,
                    'error_code'  => 501,
                    'description' => 'Method ' . $method . ' has no Rubika mapping.',
                ];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Message sending                                                    */
    /* ------------------------------------------------------------------ */

    private function sendMessage(array $datas): array
    {
        $text = $this->cleanText($datas['text'] ?? '');
        $payload = [
            'chat_id' => $datas['chat_id'] ?? '',
            'text'    => $text,
        ];
        $this->applyReplyMarkup($payload, $datas['reply_markup'] ?? null, $text);
        if (!empty($datas['reply_to_message_id'])) {
            $payload['reply_to_message_id'] = $datas['reply_to_message_id'];
        }
        return $this->request('sendMessage', $payload);
    }

    private function sendFileMessage(string $method, array $datas): array
    {
        $field = strtolower(substr($method, 4)); // photo|video|document|audio
        $file  = $datas[$field] ?? null;
        if (!$file) {
            return ['ok' => false, 'error_code' => 400, 'description' => 'No file provided.'];
        }

        // Upload the file through Rubika's two-step upload flow.
        $typeMap = ['photo' => 'Image', 'video' => 'File', 'document' => 'File', 'audio' => 'Music'];
        $upload  = $this->uploadFile(is_string($file) ? $file : '', $typeMap[$field] ?? 'File');
        if (!$upload['ok']) {
            // Fall back to sending a caption + link as a plain message.
            $fallback = trim(($datas['caption'] ?? '') . "\n" . (is_string($file) ? $file : ''));
            return $this->sendMessage(['chat_id' => $datas['chat_id'] ?? '', 'text' => $fallback]);
        }

        return $this->request('sendFile', [
            'chat_id' => $datas['chat_id'] ?? '',
            'file_id' => $upload['file_id'],
            'text'    => $this->cleanText($datas['caption'] ?? ''),
            'type'    => $typeMap[$field] ?? 'File',
        ]);
    }

    private function uploadFile(string $source, string $type): array
    {
        if ($source === '') {
            return ['ok' => false];
        }
        // Resolve a local copy of the file.
        $tmp = null;
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $data = @file_get_contents($source);
            if ($data === false) {
                return ['ok' => false];
            }
            $tmp = tempnam(sys_get_temp_dir(), 'modasr_rubika_');
            file_put_contents($tmp, $data);
        } elseif (is_file($source)) {
            $tmp = $source;
        } else {
            // Assume it is already a Rubika file_id.
            return ['ok' => true, 'file_id' => $source];
        }

        $slot = $this->rawRequest('requestSendFile', ['type' => $type]);
        $url  = $slot['data']['upload_url'] ?? ($slot['upload_url'] ?? null);
        if (!$url) {
            return ['ok' => false];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($tmp)]);
        $res  = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode((string) $res, true);
        $fileId  = $decoded['data']['file_id'] ?? ($decoded['file_id'] ?? null);

        return $fileId ? ['ok' => true, 'file_id' => $fileId] : ['ok' => false];
    }

    /* ------------------------------------------------------------------ */
    /*  Keyboard translation                                               */
    /* ------------------------------------------------------------------ */

    private function applyReplyMarkup(array &$payload, $replyMarkup, string &$text): void
    {
        if (!$replyMarkup) {
            return;
        }
        $markup = is_string($replyMarkup) ? json_decode($replyMarkup, true) : $replyMarkup;
        if (!is_array($markup)) {
            return;
        }

        if (!empty($markup['inline_keyboard'])) {
            $rows = [];
            foreach ($markup['inline_keyboard'] as $i => $row) {
                $buttons = [];
                foreach ((array) $row as $j => $btn) {
                    if (!empty($btn['url'])) {
                        // Rubika has no URL buttons — surface the link in the text.
                        $text .= "\n" . ($btn['text'] ?? 'Link') . ': ' . $btn['url'];
                        continue;
                    }
                    // Rubika echoes the button id back in aux_data.button_id,
                    // so we embed the Telegram callback_data directly in the id
                    // (truncated + hashed when too long) to survive across requests.
                    $cb = (string) ($btn['callback_data'] ?? ($btn['text'] ?? ($i . '_' . $j)));
                    $id = strlen($cb) <= 60 ? $cb : 'h_' . md5($cb);
                    $buttons[] = [
                        'id'          => $id,
                        'type'        => 'Simple',
                        'button_text' => stripCustomEmojiText((string) ($btn['text'] ?? '')),
                    ];
                }
                if ($buttons) {
                    $rows[] = ['buttons' => $buttons];
                }
            }
            if ($rows) {
                $payload['inline_keypad'] = [
                    'rows'             => $rows,
                    'resize_keyboard'  => true,
                    'on_time_keyboard' => false,
                ];
            }
            $payload['text'] = $text;
            return;
        }

        if (!empty($markup['keyboard'])) {
            $rows = [];
            foreach ($markup['keyboard'] as $i => $row) {
                $buttons = [];
                foreach ((array) $row as $j => $btn) {
                    $label = is_array($btn) ? ($btn['text'] ?? '') : (string) $btn;
                    $buttons[] = [
                        'id'          => (string) ($i . '_' . $j),
                        'type'        => 'Simple',
                        'button_text' => stripCustomEmojiText($label),
                    ];
                }
                $rows[] = ['buttons' => $buttons];
            }
            $payload['chat_keypad'] = [
                'rows'             => $rows,
                'resize_keyboard'  => $markup['resize_keyboard'] ?? true,
                'on_time_keyboard' => $markup['one_time_keyboard'] ?? false,
            ];
            $payload['chat_keypad_type'] = 'New';
        }
    }

    private function cleanText(string $text): string
    {
        $text = stripCustomEmojiText($text);
        // Rubika renders plain text only — drop HTML tags.
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    }

    /* ------------------------------------------------------------------ */
    /*  Incoming update normalisation (Rubika → Telegram shape)            */
    /* ------------------------------------------------------------------ */

    public static function normalizeUpdate(array $update): array
    {
        $inner = $update['update'] ?? $update;
        $type  = $inner['type'] ?? ($update['type'] ?? '');

        $chatTypeMap = ['User' => 'private', 'Bot' => 'private', 'Group' => 'group', 'Channel' => 'channel'];

        switch ($type) {
            case 'NewMessage':
                $msg = $inner['new_message'] ?? [];
                return [
                    'update_id' => crc32(json_encode($inner)),
                    'message'   => [
                        'message_id' => $msg['message_id'] ?? 0,
                        'from'       => [
                            'id'         => $msg['sender_id'] ?? ($inner['chat_id'] ?? 0),
                            'is_bot'     => false,
                            'first_name' => $inner['sender_name'] ?? '',
                            'username'   => $inner['username'] ?? null,
                        ],
                        'chat'       => [
                            'id'   => $inner['chat_id'] ?? ($msg['chat_id'] ?? 0),
                            'type' => $chatTypeMap[$inner['chat_type'] ?? 'User'] ?? 'private',
                        ],
                        'text'       => $msg['text'] ?? '',
                    ],
                ];

            case 'InlineMessage': // tap on an inline keypad button
                $msg      = $inner['inline_message'] ?? [];
                // The original Telegram callback_data was embedded as the button id.
                $data = (string) ($inner['aux_data']['button_id'] ?? ($msg['aux_data']['button_id'] ?? ''));
                return [
                    'update_id'      => crc32(json_encode($inner)),
                    'callback_query' => [
                        'id'      => (string) ($msg['message_id'] ?? $buttonId),
                        'from'    => [
                            'id'         => $inner['sender_id'] ?? ($msg['sender_id'] ?? ($inner['chat_id'] ?? 0)),
                            'first_name' => $inner['sender_name'] ?? '',
                            'username'   => $inner['username'] ?? null,
                        ],
                        'message' => [
                            'message_id' => $msg['message_id'] ?? 0,
                            'chat'       => [
                                'id'   => $inner['chat_id'] ?? ($msg['chat_id'] ?? 0),
                                'type' => 'private',
                            ],
                            'text' => $msg['text'] ?? '',
                        ],
                        'data'    => (string) $data,
                    ],
                ];

            case 'Started': // user pressed the bot's start button
                return [
                    'update_id' => crc32(json_encode($inner)),
                    'message'   => [
                        'message_id' => 0,
                        'from'       => ['id' => $inner['chat_id'] ?? 0, 'is_bot' => false, 'first_name' => ''],
                        'chat'       => ['id' => $inner['chat_id'] ?? 0, 'type' => 'private'],
                        'text'       => '/start',
                    ],
                ];

            default:
                // MessageRemoved / Stopped / ChatUpdated / … — ignore safely.
                return ['update_id' => crc32(json_encode($inner))];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Transport                                                          */
    /* ------------------------------------------------------------------ */

    private function request(string $method, array $payload): array
    {
        $decoded = $this->rawRequest($method, $payload);

        $ok = (($decoded['status'] ?? '') === 'OK');
        if (!$ok) {
            return [
                'ok'          => false,
                'error_code'  => 400,
                'description' => 'Rubika error: ' . ($decoded['status'] ?? 'unknown')
                    . ' ' . json_encode($decoded['client_meta'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
        }
        return ['ok' => true, 'result' => $decoded['data'] ?? true];
    }

    protected function rawRequest(string $method, array $payload): array
    {
        $ch = curl_init($this->base . $method);
        if ($ch === false) {
            return ['status' => 'ERROR', 'client_meta' => ['curl' => 'init failed']];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 'ERROR', 'client_meta' => ['curl' => $err]];
        }
        curl_close($ch);

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => 'ERROR', 'client_meta' => ['raw' => substr($raw, 0, 200)]];
    }
}

/**
 * Remove <tg-emoji ...>…</tg-emoji> premium-emoji tags (Telegram-only).
 */
function stripCustomEmojiText(string $text): string
{
    return preg_replace('/<tg-emoji[^>]*>(.*?)<\/tg-emoji>/u', '$1', $text) ?? $text;
}
