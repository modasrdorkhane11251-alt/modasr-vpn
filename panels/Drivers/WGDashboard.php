<?php

class WGDashboardPanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $data_limit = $vars['data_limit'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $usernameC = $vars['usernameC'];
        $expire = $vars['expire'];
        $Output = $vars['Output'];
        $data_limit = round($data_limit / (1024 * 1024 * 1024), 2);
        $data_Output = addpear($Get_Data_Panel['name_panel'], $usernameC);
        if (isset($data_Output['status']) && $data_Output['status'] === false) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $data_Output['msg']
            );
        }
        if (!empty($data_Output['status']) && $data_Output['status'] != 200) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $data_Output['status']
            );
        }
        if (!empty($data_Output['error'])) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => $data_Output['error']
            );
        }
        $data_Output = $data_Output['body'];
        $response = json_decode($data_Output['response'], true);
        if (empty($response['status'])) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = $response['message'] ?? '';
        } else {
            if ($data_limit != 0) {
                setjob($Get_Data_Panel['name_panel'], "total_data", $data_limit, $data_Output['public_key']);
            }
            if ($expire != 0) {
                setjob($Get_Data_Panel['name_panel'], "date", date('Y-m-d H:i:s', $expire), $data_Output['public_key']);
            }
            update("invoice", "user_info", json_encode($data_Output), "username", $usernameC);
            $download_config = downloadconfig($Get_Data_Panel['name_panel'], $data_Output['public_key']);
            if (!empty($download_config['status']) && $download_config['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $download_config['status']
                );
            }
            if (!empty($download_config['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $download_config['error']
                );
            }
            $download_config = json_decode($download_config['body'], true)['data'];
            $Output['status'] = 'successful';
            $Output['username'] = $usernameC;
            $Output['subscription_url'] = strval($download_config['file']);
            $Output['configs'] = [];
        }
        return $Output;
    }

    public function read(array $vars)
    {
        $username = $vars['username'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $Output = $vars['Output'];
        $UsernameData = get_userwg($username, $Get_Data_Panel['name_panel']);
        $invoiceinfo = select("invoice", "*", "username", $username, "select");
        if (!isset($UsernameData['id'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => isset($UsernameData['msg']) ? $UsernameData['msg'] : ''
            );
        } else {
            $jobtime = [];
            $jobvolume = [];
            foreach ($UsernameData['jobs'] as $job) {
                if ($job['Field'] == "total_data") {
                    $jobvolume = $job;
                } elseif ($job['Field'] == "date") {
                    $jobtime = $job;
                }
            }
            if (intval($invoiceinfo['Service_time']) == 0) {
                $expire = 0;
            } else {
                if (isset($jobtime['Value'])) {
                    $expire = strtotime($jobtime['Value']);
                } else {
                    $expire = 0;
                }
            }
            $status = "active";
            if (!$UsernameData['configuration']['Status'])
                $status = "disabled";
            if ($expire != 0 and $expire - time() < 0) {
                $status = "expired";
            }
            $data_useage = ($UsernameData['total_data'] * pow(1024, 3)) + ($UsernameData['cumu_data'] * pow(1024, 3));
            if (isset($jobvolume['Value']) && ($jobvolume['Value'] * pow(1024, 3)) < $data_useage) {
                $status = "limited";
            }
            $download_config = downloadconfig($Get_Data_Panel['name_panel'], $UsernameData['id']);
            if (!empty($download_config['status']) && $download_config['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $download_config['status']
                );
            }
            if (!empty($download_config['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $download_config['error']
                );
            }
            $download_config = json_decode($download_config['body'], true)['data'];
            $Output = array(
                'status' => $status,
                'username' => $UsernameData['name'],
                'data_limit' => $jobvolume['Value'] * pow(1024, 3),
                'expire' => $expire,
                'online_at' => null,
                'used_traffic' => $data_useage,
                'links' => [],
                'subscription_url' => strval($download_config['file']),
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
        $UsernameData = remove_userwg($Get_Data_Panel['name_panel'], $username);
        if (!empty($UsernameData['error']) || $UsernameData['status'] != 200 || empty(json_decode($UsernameData['body'], true)['status'])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $UsernameData['error'] ?? $UsernameData['body']
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
        $username = $vars['username'];
        $name_panel = $vars['name_panel'];
        $config = $vars['config'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $data_user = get_userwg($username, $name_panel);
        $configs = array(
            "DNS" => $data_user['DNS'],
            "allowed_ip" => $data_user['allowed_ip'],
            "endpoint_allowed_ip" => "0.0.0.0/0",
            "jobs" => $data_user['jobs'],
            "id" => $data_user['id'],
            "keepalive" => $data_user['keepalive'],
            "mtu" => $data_user['mtu'],
            "name" => $data_user['name'],
            "preshared_key" => $data_user['preshared_key'],
            "private_key" => $data_user['private_key']
        );
        $configs = array_merge($configs, $config);
        $modify = updatepear($Get_Data_Panel['name_panel'], $configs);
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

    public function resetUsage(array $vars)
    {
        $panel = $vars['panel'];
        $username = $vars['username'];
        allowAccessPeers($panel['name_panel'], $username);
        $datauser = get_userwg($username, $panel['name_panel']);
        $reset = ResetUserDataUsagewg($datauser['id'], $panel['name_panel']);
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
        return array(
            'status' => true,
            'data' => $reset
        );
    }

    public function extendService(array $vars)
    {
        $data_user = $vars['data_user'];
        $reset = $vars['reset'];
        $username = $vars['username'];
        $panel = $vars['panel'];
        $time_new = $vars['time_new'];
        $time_day = $vars['time_day'];
        $new_limit = $vars['new_limit'];
        $data_limit_new = $vars['data_limit_new'];
        if ($data_user['status'] == "limited" || $data_user['status'] == "expired") {
            $reset = $this->ResetUserDataUsage($username, $panel['name_panel']);
            if ($reset['status'] == false) {
                return array(
                    'status' => false,
                    'msg' => 'error reset : ' . $reset['msg']
                );
            }
        }
        allowAccessPeers($panel['name_panel'], $username);
        $datauser = get_userwg($username, $panel['name_panel']);
        $count = 0;
        foreach ($datauser['jobs'] as $jobsvolume) {
            if ($jobsvolume['Field'] == "date") {
                break;
            }
            $count += 1;
        }
        if (isset($datauser['jobs'][$count])) {
            $datam = array(
                "Job" => $datauser['jobs'][$count],
            );
            deletejob($panel['name_panel'], $datam);
        }
        $count = 0;
        foreach ($datauser['jobs'] as $jobsvolume) {
            if ($jobsvolume['Field'] == "total_data") {
                break;
            }
            $count += 1;
        }
        if (isset($datauser['jobs'][$count])) {
            $datam = array(
                "Job" => $datauser['jobs'][$count],
            );
            deletejob($panel['name_panel'], $datam);
        }
        $time_new = date("Y-m-d H:i:s", $time_new);
        if ($time_day != 0) {
            setjob($panel['name_panel'], "date", $time_new, $datauser['id']);
        }
        if ($new_limit != 0) {
            setjob($panel['name_panel'], "total_data", $data_limit_new / pow(1024, 3), $datauser['id']);
        }
        return array(
            'status' => true
        );
    }

    public function extraVolume(array $vars)
    {
        $panel = $vars['panel'];
        $username_account = $vars['username_account'];
        $new_limit = $vars['new_limit'];
        allowAccessPeers($panel['name_panel'], $username_account);
        $datauser = get_userwg($username_account, $panel['name_panel']);
        $count = 0;
        foreach ($datauser['jobs'] as $jobsvolume) {
            if ($jobsvolume['Field'] == "total_data") {
                break;
            }
            $count += 1;
        }
        if (isset($datauser['jobs'][$count])) {
            $datam = array(
                "Job" => $datauser['jobs'][$count],
            );
            deletejob($panel['name_panel'], $datam);
        } else {
            $this->ResetUserDataUsage($username_account, $panel['name_panel']);
        }
        $log = setjob($panel['name_panel'], "total_data", $new_limit / pow(1024, 3), $datauser['id']);
        return array(
            'status' => true,
            'data' => $log
        );
    }

    public function extraTime(array $vars)
    {
        $panel = $vars['panel'];
        $username_account = $vars['username_account'];
        $new_limit = $vars['new_limit'];
        allowAccessPeers($panel['name_panel'], $username_account);
        $datauser = get_userwg($username_account, $panel['name_panel']);
        $count = 0;
        foreach ($datauser['jobs'] as $jobsvolume) {
            if ($jobsvolume['Field'] == "date") {
                break;
            }
            $count += 1;
        }
        if (isset($datauser['jobs'][$count])) {
            $datam = array(
                "Job" => $datauser['jobs'][$count],
            );
            deletejob($panel['name_panel'], $datam);
        }
        $log = setjob($panel['name_panel'], "date", date('Y-m-d H:i:s', $new_limit), $datauser['id']);
        return array(
            'status' => true,
            'data' => $log
        );
    }
}
