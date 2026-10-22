<?php
function request_nexora($location, $method, $path, $body = null)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $req = new CurlRequest(rtrim($panel['url_panel'], '/') . '/api/v1' . $path);
    $req->setHeaders(array(
        'Accept: application/json',
        'Content-Type: application/json'
    ));
    $req->setBearerToken($panel['password_panel']);
    $payload = $body === null ? null : json_encode($body);
    if ($method == "GET") {
        return $req->get();
    } elseif ($method == "POST") {
        return $req->post($payload);
    } elseif ($method == "PUT") {
        return $req->put($payload);
    }
    return $req->delete($payload);
}
function getuser_nexora($username_account, $location)
{
    $response = request_nexora($location, "GET", '/users?limit=1000&q=' . urlencode($username_account));
    $response['user'] = null;
    $list = json_decode($response['body'] ?? '', true);
    foreach ($list['items'] ?? [] as $item) {
        if (strcasecmp($item['name'], $username_account) == 0) {
            $response['user'] = $item;
            break;
        }
    }
    if ($response['user'] === null && empty($response['error']) && $response['status'] < 400) {
        $response['status'] = 404;
        $response['body'] = json_encode(array('error' => 'User not found'));
    }
    return $response;
}
function links_nexora($sub_url)
{
    $links = (string) outputlink($sub_url);
    if (isBase64($links)) {
        $links = base64_decode($links);
    }
    return array_values(array_filter(array_map('trim', explode("\n", $links))));
}
function status_nexora(array $user)
{
    if (!$user['enable']) {
        return "disabled";
    }
    if ($user['duration'] > 0 && $user['activatedAt'] == 0) {
        return "on_hold";
    }
    if ($user['expiry'] > 0 && $user['expiry'] < time()) {
        return "expired";
    }
    if ($user['volume'] > 0 && $user['up'] + $user['down'] >= $user['volume']) {
        return "limited";
    }
    return "active";
}
function adduser_nexora($location, $data_limit, $username_ac, $timestamp, $name_product, $note = '', $limitip = null)
{
    $product = select('product', "*", "name_product", $name_product, "select");
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $templates = json_decode($product['inbounds'] ?? $panel['proxies'] ?? '', true);
    $data = array(
        'name' => $username_ac,
        'desc' => $note,
        'enable' => true,
        'volume' => (int) $data_limit,
        'expiry' => (int) $timestamp,
        'duration' => 0,
    );
    if (is_array($templates)) {
        $data['templateIds'] = $templates['templateIds'] ?? [];
        $data['allTemplates'] = $templates['allTemplates'] ?? false;
        $data['group'] = $templates['group'] ?? '';
    }
    if ($limitip != null && $panel['limit_in_panel'] == "1") {
        $data['ipLimit'] = intval($limitip);
    }
    $on_hold = $name_product == "usertest" ? $panel['on_hold_test'] != "0" : $panel['conecton'] != "offconecton";
    if ($on_hold && $timestamp != 0) {
        $data['duration'] = $timestamp - time();
        $data['expiry'] = 0;
    }
    return request_nexora($location, "POST", '/users', $data);
}
function Modifyuser_nexora($location, $username_account, array $data)
{
    $response = getuser_nexora($username_account, $location);
    if ($response['user'] === null) {
        return $response;
    }
    return request_nexora($location, "PUT", '/users/' . $response['user']['id'], array_merge($response['user'], $data));
}
function removeuser_nexora($location, $username_account)
{
    $response = getuser_nexora($username_account, $location);
    if ($response['user'] === null) {
        return $response;
    }
    return request_nexora($location, "DELETE", '/users/' . $response['user']['id']);
}
function ResetUserDataUsage_nexora($username_account, $location)
{
    $response = getuser_nexora($username_account, $location);
    if ($response['user'] === null) {
        return $response;
    }
    return request_nexora($location, "POST", '/users/bulk/reset-traffic', array('ids' => [$response['user']['id']]));
}
function revoke_sub_nexora($username_account, $location)
{
    return Modifyuser_nexora($location, $username_account, array('subId' => bin2hex(random_bytes(12))));
}
