<?php

class RebecaPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $usernameC = $vars['usernameC'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $data_limit = $vars['data_limit'];
        $expire = $vars['expire'];
        $Get_Data_Product = $vars['Get_Data_Product'];
        $note = $vars['note'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        //create user
        $order_get = select("invoice", "*", "username", $usernameC, "select");
        $ConnectToPanel = adduser_rebecca($Get_Data_Panel['name_panel'], $data_limit, $usernameC, $expire, $Get_Data_Product['name_product'], $note, $Get_Data_Product['data_limit_reset'], $order_get['limit_user']);
        if (!empty($ConnectToPanel['status']) && $ConnectToPanel['status'] == 500) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $ConnectToPanel['status']
            );
        }
        if (!empty($ConnectToPanel['error'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $ConnectToPanel['error']
            );
        }
        $data_Output = json_decode($ConnectToPanel['body'], true);
        if (!empty($ConnectToPanel['status']) && $ConnectToPanel['status'] >= 400) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $data_Output['detail'] ?? 'Unsuccessful';
        } else {
            $sub_url = $data_Output['subscription_url'];
            $sub_url = absoluteSubscriptionUrl($sub_url, $Get_Data_Panel['url_panel']);
            if ($invoice != false) {
                $sub_url = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
            $Output['status'] = 'successful';
            $Output['username'] = $data_Output['username'];
            $Output['subscription_url'] = $sub_url;
            $Output['configs'] = $data_Output['links'];
        }
        return $Output;
    }

    public function read(array $vars)
    {
        $username = $vars['username'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        $UsernameData = getuser_rebecca($username, $Get_Data_Panel['name_panel']);
        if (!empty($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        } elseif (!empty($UsernameData['status']) && $UsernameData['status'] >= 400) {
            $body = json_decode($UsernameData['body'], true);
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $body['detail'] ?? ('error code : ' . $UsernameData['status'])
            );
        } else {
            $UsernameData = json_decode($UsernameData['body'], true);
            if (!is_array($UsernameData) || !isset($UsernameData['username'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => 'Unsuccessful'
                );
            } else {
                $sub_url = $UsernameData['subscription_url'];
                $sub_url = absoluteSubscriptionUrl($sub_url, $Get_Data_Panel['url_panel']);
                if ($invoice != false) {
                    $sub_url = "https://$domainhosts/sub/" . $invoice['id_invoice'];
                }
                if ($UsernameData['online_at']) {
                    $dateTime = new DateTime($UsernameData['online_at'], new DateTimeZone('UTC'));
                    $dateTime->setTimezone(new DateTimeZone('Asia/Tehran'));
                    $online_at = date('Y/m/d H:i:s', $dateTime->getTimestamp());
                } else {
                    $online_at = null;
                }
                $Output = array(
                    'status' => $UsernameData['status'],
                    'username' => $UsernameData['username'],
                    'data_limit' => $UsernameData['data_limit'] ?? 0,
                    'expire' => $UsernameData['expire'] ? $UsernameData['expire'] : 0,
                    'online_at' => $online_at,
                    'used_traffic' => $UsernameData['used_traffic'],
                    'links' => $UsernameData['links'],
                    'subscription_url' => $sub_url,
                    'sub_updated_at' => $UsernameData['sub_updated_at'],
                    'sub_last_user_agent' => $UsernameData['sub_last_user_agent'],
                    'uuid' => null,
                    'data_limit_reset' => $UsernameData['data_limit_reset_strategy']
                );
            }
        }
        return $Output;
    }

    public function revokeSub(array $vars)
    {
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $Output = $vars['Output'];
        $revoke_sub = revoke_sub_rebecca($username, $name_panel);
        if (!empty($revoke_sub['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $revoke_sub['error']
            );
        } elseif (!empty($revoke_sub['status']) && $revoke_sub['status'] >= 400) {
            $body = json_decode($revoke_sub['body'], true);
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $body['detail'] ?? ('error code : ' . $revoke_sub['status'])
            );
        } else {
            $Data_User = $this->DataUser($name_panel, $username);
            $Output = array(
                'status' => 'successful',
                'configs' => $Data_User['links'],
                'subscription_url' => $Data_User['subscription_url']
            );
        }
        return $Output;
    }

    public function remove(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $Output = $vars['Output'];
        $UsernameData = removeuser_rebecca($Get_Data_Panel['name_panel'], $username);
        if (!empty($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        } elseif (!empty($UsernameData['status']) && $UsernameData['status'] >= 400) {
            $body = json_decode($UsernameData['body'], true);
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $body['detail'] ?? ('error code : ' . $UsernameData['status'])
            );
        } else {
            $Output = array(
                'status' => 'successful',
                'username' => $username,
            );
        }
        return $Output;
    }

    public function modify(array $vars)
    {
        $name_panel = $vars['name_panel'];
        $username = $vars['username'];
        $config = $vars['config'];
        $modify = Modifyuser_rebecca($name_panel, $username, $config);
        if (!empty($modify['error'])) {
            return array(
                'status' => false,
                'msg' => $modify['error']
            );
        } elseif (!empty($modify['status']) && $modify['status'] >= 400) {
            $modifycheck = json_decode($modify['body'], true);
            return array(
                'status' => false,
                'msg' => $modifycheck['detail'] ?? ('error code : ' . $modify['status'])
            );
        }
        $modifycheck = json_decode($modify['body'], true);
        return array(
            'status' => true,
            'data' => $modifycheck
        );
    }

    public function changeStatus(array $vars)
    {
        $DataUserOut = $vars['DataUserOut'];
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        if ($DataUserOut['status'] == "active") {
            $status = "disabled";
        } else {
            $status = "active";
        }
        $configs = array("status" => $status);
        $this->Modifyuser($username, $name_panel, $configs);
        $Output = array(
            'status' => 'successful',
            'msg' => null
        );
        return $Output;
    }

    public function resetUsage(array $vars)
    {
        $username = $vars['username'];
        $panel = $vars['panel'];
        $reset = ResetUserDataUsage_rebecca($username, $panel['name_panel']);
        if (!empty($reset['status']) && $reset['status'] >= 400) {
            $body = json_decode($reset['body'], true);
            return array(
                'status' => false,
                'msg' => $body['detail'] ?? ('error code : ' . $reset['status'])
            );
        } elseif (!empty($reset['error'])) {
            return array(
                'status' => false,
                'msg' => 'error  : ' . $reset['error']
            );
        }
        return array(
            'status' => true,
            'msg' => 'successful'
        );
    }

    public function extendService(array $vars)
    {
        $data_limit_new = $vars['data_limit_new'];
        $time_new = $vars['time_new'];
        $username = $vars['username'];
        $panel = $vars['panel'];
        $data = array(
            'data_limit' => $data_limit_new,
            'expire' => $time_new == 0 ? null : $time_new,
        );
        return $this->applyModify($username, $panel['name_panel'], $data);
    }

    public function extraVolume(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $data = array(
            'data_limit' => $new_limit,
        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }

    public function extraTime(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $data = array(
            'expire' => $new_limit == 0 ? null : $new_limit,
        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }
}
