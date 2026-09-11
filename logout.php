<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
pgpApplySecurityHeaders();

pgpSecurityStartSession();
pgpSecurityDestroySession();

header('Location: login.php', true, 303);
exit;
