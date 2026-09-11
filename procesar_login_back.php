<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Este acceso antiguo fue retirado. Utiliza /portalgp/login.php.';
exit;
