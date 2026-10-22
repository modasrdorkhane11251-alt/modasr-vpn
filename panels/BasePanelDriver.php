<?php

abstract class BasePanelDriver
{
    const UNSUPPORTED = "\0panel.driver.unsupported\0";

    protected $manager;

    public function __construct(ManagePanel $manager)
    {
        $this->manager = $manager;
    }

    public function create(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function read(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function revokeSub(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function remove(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function modify(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function changeStatus(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function resetUsage(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function extendService(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function extraVolume(array $vars)
    {
        return self::UNSUPPORTED;
    }

    public function extraTime(array $vars)
    {
        return self::UNSUPPORTED;
    }

    protected function DataUser($name_panel, $username)
    {
        return $this->manager->DataUser($name_panel, $username);
    }

    protected function Modifyuser($username, $name_panel, $config = array())
    {
        return $this->manager->Modifyuser($username, $name_panel, $config);
    }

    protected function ResetUserDataUsage($username, $name_panel)
    {
        return $this->manager->ResetUserDataUsage($username, $name_panel);
    }

    protected function applyModify($username, $name_panel, $data)
    {
        $result = $this->manager->Modifyuser($username, $name_panel, $data);
        if ($result['status'] == false) {
            return array(
                'status' => false,
                'msg' => $result['msg']
            );
        }
        return $result;
    }
}
