<?php
declare(strict_types=1);

/** Complemento informativo: no agrega eventos financieros ni cambia la paginacion. */
function msp2GarantiasCajaAdminHistorial(PDO $conn, array $idsMovimiento): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsMovimiento), static fn(int $id): bool => $id > 0)));
    if ($ids === [] || !$conn->query("SELECT CASE WHEN OBJECT_ID(N'dbo.msp_vw_garantia_devolucion_caja_admin',N'V') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn()) {
        return [];
    }
    $statement = $conn->prepare('SELECT a.*,c.nombre_cuenta AS caja_registro
        FROM dbo.msp_vw_garantia_devolucion_caja_admin a
        JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=a.id_cuenta_tesoreria
        WHERE a.id_movimiento_garantia IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
        ORDER BY a.id_movimiento_garantia,a.orden_movimiento');
    $statement->execute($ids);
    $result = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int) $row['id_movimiento_garantia']][] = $row;
    }
    $statement->closeCursor();
    return $result;
}

function msp2GarantiasCajaAdminDetalle(array $rows): string
{
    if ($rows === []) {
        return '';
    }
    $html = '<details class="small mt-2"><summary>Registro administrativo en caja</summary>';
    foreach ($rows as $row) {
        $label = $row['naturaleza'] === 'E' ? 'Entrada administrativa' : 'Salida administrativa';
        $html .= '<div class="mt-1">' . msp2Escape($label) . ': <span class="text-nowrap">$ '
            . number_format((float) $row['monto'], 2, ',', '.') . '</span> · '
            . msp2Escape((string) $row['estado_movimiento']) . '</div>';
    }
    $html .= '<div class="text-muted">' . msp2Escape((string) $rows[0]['caja_registro'])
        . ' · Devolución #' . (int) $rows[0]['id_devolucion_garantia']
        . ' · Sin movimiento de efectivo. Efecto en caja: $ 0,00.</div></details>';
    return $html;
}
