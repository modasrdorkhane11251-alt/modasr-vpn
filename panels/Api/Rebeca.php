<?php
#-----------------------------#
function getuser_rebecca($username_account, $location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $url = $panel['url_panel'] . '/api/user/' . $username_account;
    $req = new CurlRequest($url);
    $req->setHeaders(array('accept: application/json'));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->get();
    return $response;
}
#-----------------------------#
function ResetUserDataUsage_rebecca($username_account, $location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $url = $panel['url_panel'] . '/api/user/' . $username_account . '/reset';
    $req = new CurlRequest($url);
    $req->setHeaders(array('accept: application/json'));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post(array());
    return $response;
}
function revoke_sub_rebecca($username_account, $location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $url = $panel['url_panel'] . '/api/user/' . $username_account . '/revoke_sub';
    $req = new CurlRequest($url);
    $req->setHeaders(array('accept: application/json'));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post(array());
    return $response;
}
#-----------------------------#
function adduser_rebecca($location, $data_limit, $username_ac, $timestamp, $name_product, $note = '', $data_limit_reset = 'no_reset', $limitip = null)
{
    $product = select('product', "*", "name_product", $name_product, "select");
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if ($product['inbounds'] != null) {
        $panel['proxies'] = $product['inbounds'];
    }
    $service_ids = json_decode($panel['proxies'], true);
    $service_id = is_array($service_ids) ? (int) reset($service_ids) : (int) $service_ids;
    $data = array(
        'username' => $username_ac,
        'service_id' => $service_id,
        'data_limit' => (int) $data_limit,
        'note' => $note,
        'data_limit_reset_strategy' => $data_limit_reset,
    );
    if ($limitip != null && $panel['limit_in_panel'] == "1") {
        $data['ip_limit'] = intval($limitip);
    }
    if ($name_product == "usertest") {
        $on_hold = $panel['on_hold_test'] != "0";
    } else {
        $on_hold = $panel['conecton'] != "offconecton";
    }
    if ($on_hold && $timestamp != 0) {
        $data['expire'] = null;
        $data['status'] = 'on_hold';
        $data['on_hold_expire_duration'] = $timestamp - time();
    } else {
        $data['expire'] = $timestamp == 0 ? null : $timestamp;
        $data['status'] = 'active';
    }
    $payload = json_encode($data);
    $url = $panel['url_panel'] . '/api/user';
    $req = new CurlRequest($url);
    $req->setHeaders(array(
        'accept: application/json',
        'Content-Type: application/json'
    ));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post($payload);
    return $response;
}
//----------------------------------
function Get_System_Stats_rebecca($location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $url = $panel['url_panel'] . '/api/system';
    $req = new CurlRequest($url);
    $req->setHeaders(array('accept: application/json'));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->get();
    return $response;
}
//----------------------------------
function removeuser_rebecca($location, $username_account)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $url = $panel['url_panel'] . '/api/user/' . $username_account;
    $req = new CurlRequest($url);
    $req->setHeaders(array('accept: application/json'));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->delete();
    return $response;
}
//----------------------------------
function Modifyuser_rebecca($location, $username_account, array $data)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    $payload = json_encode($data);
    $url = $panel['url_panel'] . '/api/user/' . $username_account;
    $req = new CurlRequest($url);
    $req->setHeaders(array(
        'accept: application/json',
        'Content-Type: application/json'
    ));
    $req->setBearerToken($panel['password_panel']);
    $response = $req->put($payload);
    return $response;
}
