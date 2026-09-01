<?php
declare(strict_types=1);

// Compatibilidad con formularios o enlaces antiguos: eliminar ahora siempre
// conserva el registro y ejecuta el flujo seguro de desactivación histórica.
require __DIR__ . '/desactivar.php';
