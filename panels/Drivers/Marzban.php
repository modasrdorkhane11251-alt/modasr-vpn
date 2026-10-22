<?php

class MarzbanPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $data_limit = $vars['data_limit'];
        $usernameC = $vars['usernameC'];
        $expire = $vars['expire'];
        $note = $vars['note'];
        $Get_Data_Product = $vars['Get_Data_Product'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        //create user
        $ConnectToPanel = adduser($Get_Data_Panel['name_panel'], $data_limit, $usernameC, $expire, $note, $Get_Data_Product['data_limit_reset'], $Get_Data_Product['name_product']);
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
        if (!empty($data_Output['detail']) && $data_Output['detail']) {
            $Output['status'] = 'Unsuccessful';
            if ($data_Output['detail']) {
                $Output['msg'] = $data_Output['detail'];
            } else {
                $Output['msg'] = '';
            }
        } else {
            $data_Output['subscription_url'] = absoluteSubscriptionUrl($data_Output['subscription_url'], $Get_Data_Panel['url_panel']);
            if ($Get_Data_Panel['version_panel'] == "1") {
                $out_put_link = outputlink($data_Output['subscription_url']."/links");

                $links = isBase64($out_put_link)
                    ? base64_decode($out_put_link)
                    : ($data_Output['links'] ?? ($out_put_link ?: ''));

                $data_Output['links'] = is_array($links)
                    ? $links
                    : explode("\n", (string) $links);
            }
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
        $username = $vars['username'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $Output = $vars['Output'];
        $name_panel = $vars['name_panel'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        $UsernameData = getuser($username, $Get_Data_Panel['name_panel']);
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
            $UsernameData = json_decode($UsernameData['body'] ?? '', true);
            if (!is_array($UsernameData) || !empty($UsernameData['detail']) || empty($UsernameData['username'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => is_array($UsernameData) ? ($UsernameData['detail'] ?? 'Unsuccessful') : 'Unsuccessful'
                );
            }
            $UsernameData['subscription_url'] = absoluteSubscriptionUrl($UsernameData['subscription_url'] ?? '', $Get_Data_Panel['url_panel']);
            if ($Get_Data_Panel['version_panel'] == "1") {
                $UsernameData['expire'] = strtotime($UsernameData['expire'] ?? '');
                $links = $UsernameData['links'] ?? outputlink($UsernameData['subscription_url']."/links");
                if(isBase64($links)) {
                    $links = base64_decode($links);
                }
                $UsernameData['links'] = is_array($links) ? $links : explode("\n", (string) $links);
                $sublist_update = get_list_update($name_panel, $username);
                if (!empty($sublist_update['error'])) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $sublist_update['error']
                    );
                } elseif (!empty($sublist_update['status']) && $sublist_update['status'] == 500) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $sublist_update['status']
                    );
                }
                $sublist_update_body = json_decode($sublist_update['body'] ?? '', true);
                if (!empty($sublist_update_body['updates']) && is_array($sublist_update_body['updates'])) {
                    $first_update = $sublist_update_body['updates'][0];
                    $UsernameData['sub_updated_at'] = isset($first_update['created_at']) ? $first_update['created_at'] : null;
                    $UsernameData['sub_last_user_agent'] = isset($first_update['user_agent']) ? $first_update['user_agent'] : null;
                } else {
                    $UsernameData['sub_updated_at'] = isset($UsernameData['sub_updated_at']) ? $UsernameData['sub_updated_at'] : null;
                    $UsernameData['sub_last_user_agent'] = isset($UsernameData['sub_last_user_agent']) ? $UsernameData['sub_last_user_agent'] : null;
                }
            } else {
                $UsernameData['expire'] = $UsernameData['expire'] ?? null;
            }
            if ($invoice != false) {
                $UsernameData['subscription_url'] = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
            if ($Get_Data_Panel['version_panel'] == "1") {
                $UsernameData['proxies'] = isset($UsernameData['proxy_settings']) ? $UsernameData['proxy_settings'] : null;
            }
            $Output = array(
                'status' => $UsernameData['status'] ?? 'Unknown',
                'username' => $UsernameData['username'] ?? $username,
                'data_limit' => $UsernameData['data_limit'] ?? null,
                'expire' => $UsernameData['expire'] ?? null,
                'online_at' => $UsernameData['online_at'] ?? null,
                'used_traffic' => $UsernameData['used_traffic'] ?? null,
                'links' => $UsernameData['links'] ?? [],
                'subscription_url' => $UsernameData['subscription_url'] ?? '',
                'sub_updated_at' => $UsernameData['sub_updated_at'] ?? null,
                'sub_last_user_agent' => $UsernameData['sub_last_user_agent'] ?? null,
                'uuid' => $UsernameData['proxies'] ?? null,
                'data_limit_reset' => $UsernameData['data_limit_reset_strategy'] ?? null
            );
        }
        return $Output;
    }

    public function revokeSub(array $vars)
    {
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $Output = $vars['Output'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $revoke_sub = revoke_sub($username, $name_panel);
        if (isset($revoke_sub['detail']) && $revoke_sub['detail']) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $revoke_sub['detail']
            );
        } else {
            $Data_User = $this->DataUser($name_panel, $username);
            $Data_User['subscription_url'] = absoluteSubscriptionUrl($Data_User['subscription_url'], $Get_Data_Panel['url_panel']);
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
        $UsernameData = removeuser($Get_Data_Panel['name_panel'], $username);
        if (!empty($UsernameData['status']) && $UsernameData['status'] != 200) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['status']
            );
        } elseif (!empty($UsernameData['error'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error']
            );
        }
        $UsernameData = json_decode($UsernameData['body'], true);
        if ($UsernameData['detail'] != "User successfully deleted") {
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
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $config = $vars['config'];
        if ($Get_Data_Panel['version_panel'] == "1") {
            $result = getuser($username, $name_panel);
            $result = json_decode($result['body'], true);
            $config['proxy_settings'] = $result['proxy_settings'];
        }
        $modify = Modifyuser($name_panel, $username, $config);
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
        $reset = ResetUserDataUsage($username, $panel['name_panel']);
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
        $data_limit_new = $vars['data_limit_new'];
        $time_new = $vars['time_new'];
        $inbounds = $vars['inbounds'];
        $invoice = $vars['invoice'];
        $username = $vars['username'];
        $panel = $vars['panel'];
        $data = array(
            'data_limit' => $data_limit_new,
            'expire' => $time_new,
            'inbounds' => $inbounds,
        );
        if ($invoice != false && $invoice['uuid'] != null) {
            $data['proxies'] = json_decode($invoice['uuid'], true);
        }
        return $this->applyModify($username, $panel['name_panel'], $data);
    }

    public function extraVolume(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $inbounds = $vars['inbounds'];
        $invoice = $vars['invoice'];
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $data = array(
            'data_limit' => $new_limit,
            'inbounds' => $inbounds,
        );
        if ($invoice != false && $invoice['uuid'] != null) {
            $data['proxies'] = json_decode($invoice['uuid'], true);
        }
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }

    public function extraTime(array $vars)
    {
        $new_limit = $vars['new_limit'];
        $inbounds = $vars['inbounds'];
        $invoice = $vars['invoice'];
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $data = array(
            'expire' => $new_limit,
            'inbounds' => $inbounds,
        );
        if ($invoice != false && $invoice['uuid'] != null) {
            $data['proxies'] = json_decode($invoice['uuid'], true);
        }
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }
}
