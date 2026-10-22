<?php

class HiddifyPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $expire = $vars['expire'];
        $usernameC = $vars['usernameC'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $data_limit = $vars['data_limit'];
        $note = $vars['note'];
        $Output = $vars['Output'];
        $invoice = $vars['invoice'];
        $domainhosts = $vars['domainhosts'];
        if ($expire != 0) {
            $current_timestamp = time();
            $diff_seconds = $expire - $current_timestamp;
            $diff_days = ceil($diff_seconds / (60 * 60 * 24));
        } else {
            $diff_days = 111111;
        }
        $uuid = generateUUID();
        $data = array(
            "uuid" => $uuid,
            "name" => $usernameC,
            "added_by_uuid" => $Get_Data_Panel['secret_code'],
            "current_usage_GB" => "0",
            "usage_limit_GB" => $data_limit / pow(1024, 3),
            "package_days" => $diff_days,
            "comment" => $note,
        );
        $data_Output = adduserhi($Get_Data_Panel['name_panel'], $data);
        if (!empty($data_Output['error'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $data_Output['error']
            );
        } elseif (!empty($data_Output['status']) && $data_Output['status'] != 200) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $data_Output['status']
            );
        }
        $data_Output = json_decode($data_Output['body'], true);
        if (isset($data_Output['message']) && $data_Output['message']) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $data_Output['message'];
        } else {
            $Output['status'] = 'successful';
            $Output['username'] = $usernameC;
            $Output['subscription_url'] = "{$Get_Data_Panel['linksubx']}/{$data_Output['uuid']}/";
            $Output['configs'] = [];
            if ($invoice != false) {
                $Output['subscription_url'] = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
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
        $UsernameData = getdatauser($username, $Get_Data_Panel['name_panel']);
        if (empty($UsernameData)) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => "Not Connected TO paonel"
            );
        } elseif (isset($UsernameData['message'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['message']
            );
        } else {
            $startDate = $UsernameData['start_date'] ?? null;
            if ($startDate === null) {
                $date = 0;
            } else {
                $start_date = strtotime($startDate);
                $package_days = isset($UsernameData['package_days']) ? intval($UsernameData['package_days']) : 0;
                $end_date = $start_date + ($package_days * 86400);
                $date = strtotime(date("Y-m-d H:i:s", $end_date));
            }
            $usageLimit = isset($UsernameData['usage_limit_GB']) ? $UsernameData['usage_limit_GB'] * pow(1024, 3) : 0;
            $currentUsage = isset($UsernameData['current_usage_GB']) ? $UsernameData['current_usage_GB'] * pow(1024, 3) : 0;
            $uuid = $UsernameData['uuid'] ?? null;
            $linksuburl = $uuid ? "{$Get_Data_Panel['linksubx']}/{$uuid}/" : $Get_Data_Panel['linksubx'];
            $lastOnline = $UsernameData['last_online'] ?? null;
            if ($lastOnline == "1-01-01 00:00:00") {
                $lastOnline = null;
            }
            $remainingTraffic = $usageLimit - $currentUsage;
            if ($usageLimit > 0 && $remainingTraffic <= 0) {
                $status = "limited";
            } elseif ($date != 0 && ($date - time()) <= 0) {
                $status = "expired";
            } elseif ($startDate === null) {
                $status = "on_hold";
            } else {
                $status = "active";
            }
            if ($invoice != false) {
                $linksuburl = "https://$domainhosts/sub/" . $invoice['id_invoice'];
            }
            $Output = array(
                'status' => $status,
                'username' => $UsernameData['name'] ?? ($UsernameData['email'] ?? $username),
                'data_limit' => $usageLimit,
                'expire' => $date,
                'online_at' => $lastOnline,
                'used_traffic' => $currentUsage,
                'links' => [],
                'subscription_url' => $linksuburl,
                'sub_updated_at' => null,
                'sub_last_user_agent' => null,
            );
        }
        return $Output;
    }

    public function revokeSub(array $vars)
    {
        $Output = $vars['Output'];
        $Output = array(
            'status' => 'Unsuccessful',
            'msg' => 'panel not supported'
        );
        return $Output;
    }

    public function remove(array $vars)
    {
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $Output = $vars['Output'];
        $data_user = getdatauser($username, $name_panel);
        if (!is_array($data_user) || empty($data_user['uuid'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => 'user not found on panel'
            );
        } else {
            $removeResponse = removeuserhi($name_panel, $data_user['uuid']);
            if (!empty($removeResponse['error']) || $removeResponse['status'] < 200 || $removeResponse['status'] >= 300) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $removeResponse['error'] ?? $removeResponse['body']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'msg' => ""
                );
            }
        }
        return $Output;
    }

    public function modify(array $vars)
    {
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $config = $vars['config'];
        $modify = updateuserhi($username, $name_panel, $config);
        if (!empty($modify['error'])) {
            return array(
                'status' => false,
                'msg' => $modify['error']
            );
        } elseif (!empty($modify['status']) && $modify['status'] != 200) {
            return array(
                'status' => false,
                'msg' => 'error code : ' . $modify['status']
            );
        }
        $modify = json_decode($modify['body'], true);
        return array(
            'status' => true,
            'data' => $modify
        );
    }

    public function changeStatus(array $vars)
    {
        $Output = array(
            'status' => 'Unsuccessful',
            'msg' => null
        );
        return $Output;
    }

    public function resetUsage(array $vars)
    {
        return array(
            'status' => true
        );
    }

    public function extendService(array $vars)
    {
        $time_new = $vars['time_new'];
        $data_limit_new = $vars['data_limit_new'];
        $Method_extend = $vars['Method_extend'];
        $username = $vars['username'];
        $panel = $vars['panel'];
        $day = $time_new - time();
        $data = array(
            "package_days" => $time_new == 0 ? 111111 : $day / 86400,
            "usage_limit_GB" => $data_limit_new / pow(1024, 3),
            "start_date" => null
        );
        if (in_array($Method_extend, ["resetVolumeTime", "resetVolumeAddTime", "addTimeConvertVolume"], true)) {
            $data['current_usage_GB'] = "0";
        }
        return $this->applyModify($username, $panel['name_panel'], $data);
    }

    public function extraVolume(array $vars)
    {
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $new_limit = $vars['new_limit'];
        $datauser = getdatauser($username_account, $panel['name_panel']);
        $data = array(
            "current_usage_GB" => $datauser['current_usage_GB'],
            "usage_limit_GB" => $new_limit / pow(1024, 3),
        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }

    public function extraTime(array $vars)
    {
        $username_account = $vars['username_account'];
        $panel = $vars['panel'];
        $limit_time_new = $vars['limit_time_new'];
        $new_limit = $vars['new_limit'];
        $datauser = getdatauser($username_account, $panel['name_panel']);
        $data = array(
            "current_usage_GB" => $datauser['current_usage_GB'],
            "usage_limit_GB" => $datauser['usage_limit_GB'],
            "package_days" => $limit_time_new == 0 ? 0 : ($new_limit - time()) / 86400,
            "start_date" => null
        );
        return $this->applyModify($username_account, $panel['name_panel'], $data);
    }
}
