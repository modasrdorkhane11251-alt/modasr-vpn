<?php

class ManualsalePanelDriver extends BasePanelDriver
{
    public function create(array $vars)
    {
        $pdo = $vars['pdo'];
        $Get_Data_Panel = $vars['Get_Data_Panel'];
        $code_product = $vars['code_product'];
        $Output = $vars['Output'];
        $usernameC = $vars['usernameC'];
        $statement = $pdo->prepare("SELECT * FROM manualsell WHERE codepanel = :code_panel AND status = 'active' AND codeproduct = :code_product ORDER BY RAND() LIMIT 1");
        $statement->bindParam(":code_panel", $Get_Data_Panel['code_panel']);
        $statement->bindParam(":code_product", $code_product);
        $statement->execute();
        $configman = $statement->fetch(PDO::FETCH_ASSOC);
        $Output['status'] = 'successful';
        $Output['username'] = $usernameC;
        $Output['subscription_url'] = $configman['contentrecord'];
        $Output['configs'] = "";
        update("manualsell", "status", "selled", "id", $configman['id']);
        update("manualsell", "username", $usernameC, "id", $configman['id']);
        return $Output;
    }

    public function read(array $vars)
    {
        $pdo = $vars['pdo'];
        $username = $vars['username'];
        $Output = $vars['Output'];
        $stmt = $pdo->prepare("SELECT * FROM manualsell WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $configman = $stmt->fetch(PDO::FETCH_ASSOC);
        $service = select("invoice", "*", "username", $username, "select");
        $Output = array(
            'status' => $service['Status'],
            'username' => $service['username'],
            'data_limit' => null,
            'expire' => $service['time_sell'],
            'online_at' => null,
            'used_traffic' => null,
            'links' => [],
            'subscription_url' => $configman['contentrecord'],
            'sub_updated_at' => null,
            'sub_last_user_agent' => null,
            'uuid' => null
        );
        return $Output;
    }

    public function remove(array $vars)
    {
        $username = $vars['username'];
        $Output = $vars['Output'];
        update("manualsell", "status", "delete", "username", $username);
        $Output = array(
            'status' => 'successful',
            'username' => $username,
        );
        return $Output;
    }
}
