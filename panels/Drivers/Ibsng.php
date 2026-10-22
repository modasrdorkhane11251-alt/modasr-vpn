<?php

class IbsngPanelDriver extends BasePanelDriver
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
        $data_Output = addUserIBsng($Get_Data_Panel['name_panel'], $usernameC, $password, $name_group);
        if (empty($data_Output['status'])) {
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
        $UsernameData = GetUserIBsng($Get_Data_Panel['name_panel'], $username);
        if (!$UsernameData['status']) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['msg']
            );
        } else {
            $UsernameData = $UsernameData['data'];
            $data_limit = $UsernameData['data_limit'];
            $expire = strtotime($UsernameData['absolute_expire_date']);
            $UsernameData['enable'] = "active";
            $Output = array(
                'status' => $UsernameData['enable'],
                'username' => $UsernameData['username'],
                'data_limit' => $data_limit,
                'expire' => $expire,
                'online_at' => strtolower($UsernameData['status']),
                'used_traffic' => $UsernameData['used_traffic'],
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
        $UsernameData = deleteUserIBSng($Get_Data_Panel['name_panel'], $username);
        $ibsngDeleted = $UsernameData === true || (is_array($UsernameData) && !empty($UsernameData['status']));
        if (!$ibsngDeleted) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => is_array($UsernameData) ? ($UsernameData['msg'] ?? 'delete failed') : 'delete failed'
            );
        } else {
            $Output = array(
                'status' => 'successful',
                'username' => $username,
            );
        }
        return $Output;
    }
}
