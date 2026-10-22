<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('departman')) {
        return;
    }
    $removed = $pdo->exec(
        'DELETE dup_row FROM departman dup_row
         JOIN (
             SELECT MIN(id) AS keep_id, idsupport, name_departman
             FROM departman
             GROUP BY idsupport, name_departman
             HAVING COUNT(*) > 1
         ) keep_row
         ON dup_row.idsupport = keep_row.idsupport
         AND dup_row.name_departman = keep_row.name_departman
         AND dup_row.id > keep_row.keep_id'
    );
    if ($removed) {
        error_log("[db:migration:departman_dedupe] removed $removed duplicate departman rows");
    }
};
