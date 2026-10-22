<?php

class MirzaAgentPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $data_limit = $vars['data_limit'];
        $expire = $vars['expire'];
        $usernameC = $vars['usernameC'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        //create user
        $ConnectToPanel = create_user_mirza($Get_Data_Panel, $data_limit / pow(1024, 3), $expire == 0 ? 0 : ($expire - time()) / 86400, $usernameC);
        if (!empty($ConnectToPanel['status']) && $ConnectToPanel['status'] != 200) {
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
        if (!$data_Output['status']) {
            $Output['status'] = 'Unsuccessful';
            if ($data_Output['msg']) {
                $Output['msg'] = $data_Output['msg'];
            } else {
                $Output['msg'] = '';
            }
        } else {
            $data_Output = $data_Output['obj'];
            if ($invoice != false) {
                $data_Output['subscription_url'] = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
            $Output['status'] = 'successful';
            $Output['username'] = $data_Output['username'];
            $Output['subscription_url'] = $data_Output['subscription_url'];
            $Output['configs'] = $data_Output['links'];
        }
        return $Output;
    }

    public function read(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        $UsernameData = get_user_data_mirza($Get_Data_Panel, $username);
        if (!empty($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        } elseif (!empty($UsernameData['status']) && $UsernameData['status'] != 200) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['status']
            );
        } else {
            $UsernameData = json_decode($UsernameData['body'], true);
            if (!$UsernameData['status']) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            }
            $UsernameData = $UsernameData['obj']['user'];
            if ($invoice != false) {
                $UsernameData['subscription_url'] = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
            $Output = array(
                'status' => $UsernameData['status'],
                'username' => $UsernameData['username'],
                'data_limit' => $UsernameData['data_limit'],
                'expire' => $UsernameData['expire'] ? $UsernameData['expire'] : 0,
                'online_at' => $UsernameData['online_at'],
                'used_traffic' => $UsernameData['used_traffic'],
                'links' => $UsernameData['links'],
                'subscription_url' => $UsernameData['subscription_url'],
                'sub_updated_at' => $UsernameData['sub_updated_at'],
                'sub_last_user_agent' => $UsernameData['sub_last_user_agent'],
                'uuid' => $UsernameData['uuid'],
                'data_limit_reset' => $UsernameData['data_limit_reset']
            );
        }
        return $Output;
    }

    public function revokeSub(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $Output = $vars['Output'];
        $name_panel = $vars['name_panel'];
        $revoke_sub = revoke_service_mirza($Get_Data_Panel, $username);
        if (!empty($revoke_sub['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $revoke_sub['error']
            );
        } elseif (!empty($revoke_sub['status']) && $revoke_sub['status'] != 200) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $revoke_sub['status']
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
        $UsernameData = remove_service_mirza($Get_Data_Panel, $username);
        if (isset($UsernameData['error'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['msg']
            );
        } elseif ($UsernameData['status'] != 200) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['status']
            );
        } else {
            $UsernameData = json_decode($UsernameData['body'], true);
            if (!$UsernameData['status']) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            }
            $Output = array(
                'status' => 'successful',
                'username' => $username,
            );
        }
        return $Output;
    }

    public function resetUsage(array $vars)
    {
        return array(
            'status' => true,
            'msg' => 'successful'
        );
    }

    public function extendService(array $vars)
    {
        $panel = $vars['panel'];
        $new_limit = $vars['new_limit'];
        $time_day = $vars['time_day'];
        $username = $vars['username'];
        $extend = extend_service_mirza($panel, $new_limit, $time_day, $username);
        if (!in_array($extend['status'], [200, 400])) {
            return array(
                'status' => false,
                'msg' => $extend['msg']
            );
        } elseif ($extend['error']) {
            return array(
                'status' => false,
                'msg' => $extend['error']
            );
        }
        $extend = json_decode($extend['body'], true);
        if (!$extend['status']) {
            return array(
                'status' => false,
                'msg' => $extend['msg']
            );
        }
        return array(
            'status' => true,
            'msg' => 'successful'
        );
    }

    public function extraVolume(array $vars)
    {
        $panel = $vars['panel'];
        $limit_volume_new = $vars['limit_volume_new'];
        $username_account = $vars['username_account'];
        $volume_add = add_volume_service_mirza($panel, $limit_volume_new, $username_account);
        if (!in_array($volume_add['status'], [200, 400])) {
            return array(
                'status' => false,
                'msg' => $volume_add['msg']
            );
        } elseif ($volume_add['error']) {
            return array(
                'status' => false,
                'msg' => $volume_add['error']
            );
        }
        $volume_add = json_decode($volume_add['body'], true);
        if (!$volume_add['status']) {
            return array(
                'status' => false,
                'msg' => $volume_add['msg']
            );
        }
        return array(
            'status' => true,
            'msg' => 'successful'
        );
    }

    public function extraTime(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $limit_time_new = $vars['limit_time_new'];
        $panel = $vars['panel'];
        $username_account = $vars['username_account'];
        $new_limit = $limit_time_new;
        $time_add = add_time_service_mirza($panel, $new_limit, $username_account);
        if (!in_array($time_add['status'], [200, 400])) {
            return array(
                'status' => false,
                'msg' => $time_add['msg']
            );
        } elseif ($time_add['error']) {
            return array(
                'status' => false,
                'msg' => $time_add['error']
            );
        }
        $time_add = json_decode($time_add['body'], true);
        if (!$time_add['status']) {
            return array(
                'status' => false,
                'msg' => $time_add['msg']
            );
        }
        return array(
            'status' => true,
            'msg' => 'successful'
        );
    }
}
