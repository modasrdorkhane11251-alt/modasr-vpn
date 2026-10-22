<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('admin')) {
        return;
    }
    $admins = $pdo->query("SELECT id_admin, username, password FROM admin")->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare("UPDATE admin SET username = ?, password = ? WHERE id_admin = ?");
    foreach ($admins as $admin) {
        $password = (string) $admin['password'];
        $isHashed = str_starts_with($password, '$2') || str_starts_with($password, '$argon2');
        if ($admin['username'] === 'root') {
            $statement->execute([$admin['id_admin'], password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $admin['id_admin']]);
        } elseif (!$isHashed) {
            $statement->execute([$admin['username'], password_hash($password, PASSWORD_BCRYPT), $admin['id_admin']]);
        }
    }
};
