<?php

class MarzneshinPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $data_limit = $vars['data_limit'];
        $usernameC = $vars['usernameC'];
        $expire = $vars['expire'];
        $Get_Data_Product = $vars['Get_Data_Product'];
        $note = $vars['note'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        //create user
        $ConnectToPanel = adduserm($Get_Data_Panel['name_panel'], $data_limit, $usernameC, $expire, $Get_Data_Product['name_product'], $note, $Get_Data_Product['data_limit_reset']);
        if (!empty($ConnectToPanel['status']) && $ConnectToPanel['status'] >= 400) {
            $errBody = json_decode($ConnectToPanel['body'] ?? '', true);
            return array(
                'status' => 'Unsuccessful',
                'msg' => is_array($errBody) && !empty($errBody['detail']) ? $errBody['detail'] : $ConnectToPanel['status']
            );
        }
        if (!empty($ConnectToPanel['error'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $ConnectToPanel['error']
            );
        }
        $data_Output = json_decode($ConnectToPanel['body'], true);
        if (isset($data_Output['detail']) && $data_Output['detail']) {
            $Output['status'] = 'Unsuccessful';
            if ($data_Output['detail']) {
                $Output['msg'] = $data_Output['detail'];
            } else {
                $Output['msg'] = '';
            }
        } else {
            $data_Output['subscription_url'] = absoluteSubscriptionUrl($data_Output['subscription_url'], $Get_Data_Panel['url_panel']);
            $data_Output['links'] = outputlink($data_Output['subscription_url']);
            if (isBase64($data_Output['links'])) {
                $data_Output['links'] = base64_decode($data_Output['links']);
            }
            $links_user = explode("\n", trim($data_Output['links']));
            $date = new DateTime($data_Output['expire']);
            if ($invoice != false) {
                $data_Output['subscription_url'] = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
            $data_Output['expire'] = $date->getTimestamp();
            $Output['status'] = 'successful';
            $Output['username'] = $data_Output['username'];
            $Output['subscription_url'] = $data_Output['subscription_url'];
            $Output['configs'] = $links_user;
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
        $UsernameData = getuserm($username, $Get_Data_Panel['name_panel']);
        if (!empty($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        } elseif (!empty($UsernameData['status']) && $UsernameData['status'] == 500) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['status']
            );
        } else {
            $UsernameData = json_decode($UsernameData['body'], true);
            if (isset($UsernameData['detail']) && $UsernameData['detail']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['detail']
                );
            } elseif (!isset($UsernameData['username'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => "Unsuccessful"
                );
            } else {
                $UsernameData['subscription_url'] = absoluteSubscriptionUrl($UsernameData['subscription_url'], $Get_Data_Panel['url_panel']);
                $UsernameData['status'] = "active";
                if (!$UsernameData['enabled']) {
                    $UsernameData['status'] = "disabled";
                }
                if ($UsernameData['expire_strategy'] == "start_on_first_use") {
                    $UsernameData['status'] = "on_hold";
                }
                if ($UsernameData['expired']) {
                    $UsernameData['status'] = "expired";
                }
                if (($UsernameData['data_limit'] - $UsernameData['used_traffic'] <= 0) and $UsernameData['data_limit'] != null) {
                    $UsernameData['status'] = "limtied";
                }
                $UsernameData['links'] = outputlink($UsernameData['subscription_url']);
                if (isBase64($UsernameData['links'])) {
                    $UsernameData['links'] = base64_decode($UsernameData['links']);
                }
                $links_user = explode("\n", trim($UsernameData['links']));
                if ($UsernameData['data_limit'] == null) {
                    $UsernameData['data_limit'] = 0;
                }
                if (isset($UsernameData['expire_date'])) {
                    $expiretime = strtotime(($UsernameData['expire_date']));
                } else {
                    $expiretime = 0;
                }
                if ($invoice != false) {
                    $UsernameData['subscription_url'] = "https://$domainhosts/sub/" . $invoice['id_invoice'];
                }
                $Output = array(
                    'status' => $UsernameData['status'],
                    'username' => $UsernameData['username'],
                    'data_limit' => $UsernameData['data_limit'],
                    'expire' => $expiretime,
                    'online_at' => $UsernameData['online_at'],
                    'used_traffic' => $UsernameData['used_traffic'],
                    'links' => $links_user,
                    'subscription_url' => $UsernameData['subscription_url'],
                    'sub_updated_at' => $UsernameData['sub_updated_at'],
                    'sub_last_user_agent' => $UsernameData['sub_last_user_agent'],
                    'uuid' => null
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
        $revoke_sub = revoke_subm($username, $name_panel);
        if (isset($revoke_sub['detail']) && $revoke_sub['detail']) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $revoke_sub['detail']
            );
        } else {
            $Data_User = $this->DataUser($name_panel, $username);
            $Data_User['links'] = [base64_decode(outputlink($Data_User['subscription_url']))];
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
        $UsernameData = removeuserm($Get_Data_Panel['name_panel'], $username);
        if (isset($UsernameData['detail']) && $UsernameData['detail']) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['detail']
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
        $config = $vars['config'];
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $config['username'] = $username;
        $modify = Modifyuserm($name_panel, $username, $config);
        if (!empty($modify['error'])) {
            return array(
                'status' => false,
                'msg' => $modify['error']
            );
        } elseif (!empty($modify['status']) && $modify['status'] == 500) {
            return array(
                'status' => false,
                'msg' => 'error code : ' . $modify['status']
            );
        }
        $modifycheck = json_decode($modify['body'], true);
        if (!empty($modifycheck['detail'])) {
            return array(
                'status' => false,
                'msg' => $modifycheck['detail']
            );
        }
        return array(
            'status' => true,
            'data' => $modify
        );
    }

    public function changeStatus(array $vars)
    {
        $DataUserOut = $vars['DataUserOut'];
        $name_panel = $vars['name_panel'];
        $username = $vars['username'];
        if ($DataUserOut['status'] == "active") {
            disableduser($name_panel, $username);
        } else {
            enableuser($name_panel, $username);
        }
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
        $reset = ResetUserDataUsagem($username, $panel['name_panel']);
        if (!empty($reset['status']) && $reset['status'] != 200) {
            return array(
                'status' => false,
                'msg' => 'error code : ' . $reset['status']
            );
        } elseif (!empty($reset['error'])) {
            return array(
                'status' => false,
                'msg' => 'error  : ' . $reset['error']
            );
        }
        $reset = json_decode($reset['body'], true);
        if (!empty($reset['detail'])) {
            return array(
                'status' => false,
                'msg' => $reset['detail']
            );
        }
        return array(
            'status' => true,
            'msg' => 'successful'
        );
    }

    public function extendService(array $vars)
    {
        $time_new = $vars['time_new'];
        $username = $vars['username'];
        $data_limit_new = $vars['data_limit_new'];
        $panel = $vars['panel'];
        $expire_strotegy = $time_new == 0 ? "never" : "fixed_date";
        $time_new = date('c', $time_new);
        $data = array(
            'username' => $username,
            'expire_date' => $time_new,
            'expire_strategy' => $expire_strotegy,
            'data_limit' => $data_limit_new
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
            'expire_date' => $new_limit,
            'expire_strategy' => "fixed_date",

        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }
}
