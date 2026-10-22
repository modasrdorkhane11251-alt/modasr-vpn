<?php

class MikrotikPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $Get_Data_Product = $vars['Get_Data_Product'];
        $code_product = $vars['code_product'];
        $usernameC = $vars['usernameC'];
        $Output = $vars['Output'];
        $password = bin2hex(random_bytes(6));
        $name_group = $Get_Data_Panel['proxies'];
        if ($Get_Data_Product['inbounds'] != null) {
            $name_group = $Get_Data_Product['inbounds'];
        } elseif ($code_product == "usertest") {
            $name_group = "usertest";
        }
        $data_Output = addUser_mikrotik($Get_Data_Panel['name_panel'], $usernameC, $password, $name_group);
        if (isset($data_Output['error'])) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $data_Output['msg'];
        } else {
            $Output['status'] = 'successful';
            $Output['username'] = $usernameC;
            $Output['subscription_url'] = $password;
            $Output['configs'] = [];
        }
        return $Output;
    }

    public function read(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $Output = $vars['Output'];
        $UsernameResponse = GetUsermikrotik($Get_Data_Panel['name_panel'], $username);
        $UsernameData = $UsernameResponse[0] ?? null;
        if (!is_array($UsernameData)) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameResponse['message'] ?? 'user not found on panel'
            );
        } else {
            $invocie = select("invoice", "*", "username", $username, "select");
            $traffic_get = GetUsermikrotik_volume($Get_Data_Panel['name_panel'], $UsernameData['.id']);
            $used_traffic = $traffic_get['total-upload'] + $traffic_get['total-download'];
            $data_limit = $invocie['Volume'] * pow(1024, 3);
            $expire = $invocie['time_sell'] + ($invocie['Service_time'] * 86400);
            $UsernameData['enable'] = "active";
            $Output = array(
                'status' => $UsernameData['enable'],
                'username' => $invocie['username'],
                'data_limit' => $data_limit,
                'expire' => $expire,
                'online_at' => null,
                'used_traffic' => $used_traffic,
                'links' => [],
                'subscription_url' => $UsernameData['password'],
                'sub_updated_at' => null,
                'sub_last_user_agent' => null,
            );
        }
        return $Output;
    }

    public function remove(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $Output = $vars['Output'];
        $UsernameResponse = GetUsermikrotik($Get_Data_Panel['name_panel'], $username);
        $UsernameData = $UsernameResponse[0] ?? null;
        if (!is_array($UsernameData)) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameResponse['message'] ?? 'user not found on panel'
            );
        } else {
            $removeResponse = deleteUser_mikrotik($Get_Data_Panel['name_panel'], $UsernameData['.id']);
            if (isset($removeResponse['error'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $removeResponse['message'] ?? $removeResponse['error']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        }
        return $Output;
    }
}
