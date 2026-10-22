<?php

class NexoraPanelDriver extends BasePanelDriver
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
        $order_get = select("invoice", "*", "username", $usernameC, "select");
        $ConnectToPanel = adduser_nexora($Get_Data_Panel['name_panel'], $data_limit, $usernameC, $expire, $Get_Data_Product['name_product'], $note, $order_get['limit_user'] ?? null);
        if (!empty($ConnectToPanel['error'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $ConnectToPanel['error']
            );
        }
        $data_Output = json_decode($ConnectToPanel['body'], true);
        if ($ConnectToPanel['status'] >= 400 || !isset($data_Output['name'])) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $data_Output['error'] ?? ('error code : ' . $ConnectToPanel['status']);
        } else {
            $Output['status'] = 'successful';
            $Output['username'] = $data_Output['name'];
            $Output['subscription_url'] = $invoice != false ? "https://$domainhosts/sub/" . $invoice['id_invoice'] : $data_Output['subUrl'];
            $Output['configs'] = links_nexora($data_Output['subUrl']);
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
        $UsernameData = getuser_nexora($username, $Get_Data_Panel['name_panel']);
        if (!empty($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        } elseif ($UsernameData['status'] >= 400) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => json_decode($UsernameData['body'], true)['error'] ?? ('error code : ' . $UsernameData['status'])
            );
        } else {
            $UsernameData = $UsernameData['user'];
            $Output = array(
                'status' => status_nexora($UsernameData),
                'username' => $UsernameData['name'],
                'data_limit' => $UsernameData['volume'],
                'expire' => $UsernameData['duration'] > 0 && $UsernameData['activatedAt'] == 0 ? 0 : $UsernameData['expiry'],
                'online_at' => $UsernameData['onlineAt'] > time() - 90 ? "online" : ($UsernameData['onlineAt'] ? gmdate('Y-m-d H:i:s', $UsernameData['onlineAt']) : null),
                'used_traffic' => $UsernameData['up'] + $UsernameData['down'],
                'links' => [],
                'subscription_url' => $invoice != false ? "https://$domainhosts/sub/" . $invoice['id_invoice'] : $UsernameData['subUrl'],
                'sub_updated_at' => $UsernameData['subFetchedAt'] ? date('c', $UsernameData['subFetchedAt']) : null,
                'sub_last_user_agent' => $UsernameData['subClient'] ?: null,
                'uuid' => null,
                'data_limit_reset' => $UsernameData['autoReset'] ? 'month' : 'no_reset'
            );
        }
        return $Output;
    }

    public function revokeSub(array $vars)
    {
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $Output = $vars['Output'];
        $revoke_sub = revoke_sub_nexora($username, $name_panel);
        if (!empty($revoke_sub['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $revoke_sub['error']
            );
        } elseif ($revoke_sub['status'] >= 400) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => json_decode($revoke_sub['body'], true)['error'] ?? ('error code : ' . $revoke_sub['status'])
            );
        } else {
            $Data_User = $this->DataUser($name_panel, $username);
            $Output = array(
                'status' => 'successful',
                'configs' => links_nexora(json_decode($revoke_sub['body'], true)['subUrl'] ?? ''),
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
        $UsernameData = removeuser_nexora($Get_Data_Panel['name_panel'], $username);
        if (!empty($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        } elseif ($UsernameData['status'] >= 400) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => json_decode($UsernameData['body'], true)['error'] ?? ('error code : ' . $UsernameData['status'])
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
        $modify = Modifyuser_nexora($name_panel, $username, $config);
        if (!empty($modify['error'])) {
            return array(
                'status' => false,
                'msg' => $modify['error']
            );
        } elseif ($modify['status'] >= 400) {
            return array(
                'status' => false,
                'msg' => json_decode($modify['body'], true)['error'] ?? ('error code : ' . $modify['status'])
            );
        }
        return array(
            'status' => true,
            'data' => json_decode($modify['body'], true)
        );
    }

    public function changeStatus(array $vars)
    {
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $DataUserOut = $vars['DataUserOut'];
        $this->Modifyuser($username, $name_panel, array("enable" => $DataUserOut['status'] != "active"));
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
        $reset = ResetUserDataUsage_nexora($username, $panel['name_panel']);
        if (!empty($reset['error'])) {
            return array(
                'status' => false,
                'msg' => 'error  : ' . $reset['error']
            );
        } elseif ($reset['status'] >= 400) {
            return array(
                'status' => false,
                'msg' => json_decode($reset['body'], true)['error'] ?? ('error code : ' . $reset['status'])
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
            'enable' => true,
            'volume' => $data_limit_new,
            'expiry' => $time_new,
            'duration' => 0,
        );
        return $this->applyModify($username, $panel['name_panel'], $data);
    }

    public function extraVolume(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $data = array(
            'volume' => $new_limit,
        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }

    public function extraTime(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $data = array(
            'expiry' => $new_limit,
            'duration' => 0,
        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }
}
