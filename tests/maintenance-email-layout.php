<?php
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function csrf_field(){return '<input type="hidden" name="csrf_token" value="test">';}
function db(){return new class {
    function query($sql){return new class($sql) {
        function __construct(private string $sql){}
        function fetch(){return str_contains($this->sql,'SUM(')?['pending'=>3,'sent'=>12,'failed'=>0]:['automatic'=>1,'message_delay'=>600,'interaction_delay'=>300,'category_interval'=>3600];}
    };}
};}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="container py-4">
<?php require __DIR__.'/../includes/maintenance_email.php'; ?>
</main></body></html>
