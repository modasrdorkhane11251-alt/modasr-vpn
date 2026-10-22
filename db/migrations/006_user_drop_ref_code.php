<?php

return static function (PDO $pdo, Schema $schema): void {
    $schema->dropColumn('user', 'ref_code');
};
