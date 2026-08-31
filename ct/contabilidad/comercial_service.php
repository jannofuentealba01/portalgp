<?php
declare(strict_types=1);

require_once __DIR__ . '/comercial_repository.php';

function ctComercialAllowedLines(): array
{
    return [10, 25, 50, 100];
}

function ctComercialAllowedSort(): array
{
    return [
        'id_terreno' => 't.id_terreno',
        'rol_asignado' => 't.rol_asignado',
        'estado_comercial' => 'ec.nombre',
        'ultima_tasacion' => 'lt.fecha_tasacion',
        'ultima_venta' => 'lv.fecha_venta',
    ];
}

function ctComercialParseQuery(array $query): array
{
    $lineasPermitidas = ctComercialAllowedLines();
    $sortPermitidos = ctComercialAllowedSort();

    $lineas = isset($query['lineas']) && is_numeric((string) $query['lineas']) ? (int) $query['lineas'] : 25;
    if (!in_array($lineas, $lineasPermitidas, true)) {
        $lineas = 25;
    }

    $pagina = isset($query['pagina']) && is_numeric((string) $query['pagina']) ? max(1, (int) $query['pagina']) : 1;
    $filtroTexto = ctNormalizeText((string) ($query['filtroTexto'] ?? ''));
    $filtroEstadoComercial = isset($query['filtroEstadoComercial']) && is_numeric((string) $query['filtroEstadoComercial'])
        ? max(0, (int) $query['filtroEstadoComercial'])
        : 0;

    $orden = trim((string) ($query['orden'] ?? 'id_terreno'));
    if (!isset($sortPermitidos[$orden])) {
        $orden = 'id_terreno';
    }

    $dir = strtolower(trim((string) ($query['dir'] ?? 'desc')));
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = 'desc';
    }

    $queryBase = [
        'filtroTexto' => $filtroTexto,
        'filtroEstadoComercial' => $filtroEstadoComercial > 0 ? (string) $filtroEstadoComercial : '',
        'lineas' => $lineas,
        'orden' => $orden,
        'dir' => $dir,
    ];

    return [
        'lineas' => $lineas,
        'lineasPermitidas' => $lineasPermitidas,
        'pagina' => $pagina,
        'filtroTexto' => $filtroTexto,
        'filtroEstadoComercial' => $filtroEstadoComercial,
        'orden' => $orden,
        'dir' => $dir,
        'sortPermitidos' => $sortPermitidos,
        'queryBase' => $queryBase,
    ];
}

function ctComercialBuildQuery(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }
    $qs = http_build_query($merged);
    return $qs === '' ? '' : ('?' . $qs);
}

function ctComercialSortLink(string $campo, array $base, string $ordenActual, string $dirActual): string
{
    $dir = ($ordenActual === $campo && $dirActual === 'asc') ? 'desc' : 'asc';
    return ctComercialBuildQuery($base, ['orden' => $campo, 'dir' => $dir, 'pagina' => 1]);
}

function ctComercialSortIcon(string $campo, string $ordenActual, string $dirActual): string
{
    if ($ordenActual !== $campo) {
        return 'bi-arrow-down-up';
    }
    return $dirActual === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
}

function ctComercialRedirectAfterPost(array $queryBase): never
{
    $clean = array_filter($queryBase, static fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($clean);
    header('Location: ' . ($qs !== '' ? ('?' . $qs) : ''));
    exit();
}

function ctComercialCurrentUserId(): int
{
    $idUsuario = (int) ($_SESSION['usuario']['id'] ?? 0);
    if ($idUsuario <= 0) {
        throw new RuntimeException('No fue posible identificar al usuario actual.');
    }
    return $idUsuario;
}

function ctComercialNormalizeDate(string $raw, string $label): string
{
    $value = trim($raw);
    if ($value === '') {
        throw new RuntimeException('Debes ingresar ' . $label . '.');
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!($dt instanceof DateTimeImmutable) || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException('La ' . $label . ' es inválida. Usa formato YYYY-MM-DD.');
    }

    return $value;
}

function ctComercialNormalizeOptionalDate(string $raw, string $label): ?string
{
    $value = trim($raw);
    if ($value === '') {
        return null;
    }
    return ctComercialNormalizeDate($value, $label);
}

function ctComercialNormalizeMontoUf(string $raw, string $label, bool $allowNull = false): ?float
{
    $value = trim($raw);
    if ($value === '') {
        return $allowNull ? null : throw new RuntimeException('Debes ingresar ' . $label . '.');
    }

    $value = str_replace([' ', ','], ['', '.'], $value);
    if (!is_numeric($value)) {
        throw new RuntimeException('El campo ' . $label . ' es inválido.');
    }

    $number = round((float) $value, 4);
    if ($number <= 0) {
        throw new RuntimeException('El campo ' . $label . ' debe ser mayor a cero.');
    }

    return $number;
}

function ctComercialParseCompradoresFromPost(array $post): array
{
    $idsRaw = isset($post['venta_id_tercero']) && is_array($post['venta_id_tercero']) ? $post['venta_id_tercero'] : [];
    $porcentajesRaw = isset($post['venta_porcentaje']) && is_array($post['venta_porcentaje']) ? $post['venta_porcentaje'] : [];
    $rolesRaw = isset($post['venta_rol']) && is_array($post['venta_rol']) ? $post['venta_rol'] : [];

    $len = max(count($idsRaw), count($porcentajesRaw), count($rolesRaw));
    $compradores = [];

    for ($i = 0; $i < $len; $i++) {
        $idRaw = trim((string) ($idsRaw[$i] ?? ''));
        $pctRaw = trim((string) ($porcentajesRaw[$i] ?? ''));
        $rolRaw = ctNormalizeText((string) ($rolesRaw[$i] ?? ''));

        if ($idRaw === '' && $pctRaw === '' && $rolRaw === '') {
            continue;
        }

        if (!ctype_digit($idRaw) || (int) $idRaw <= 0) {
            throw new RuntimeException('Debes seleccionar un tercero válido en compradores.');
        }

        $pct = ctComercialNormalizeMontoUf($pctRaw, 'porcentaje de comprador');
        if ($pct === null || $pct > 100) {
            throw new RuntimeException('El porcentaje de comprador debe estar entre 0 y 100.');
        }

        $compradores[] = [
            'id_tercero' => (int) $idRaw,
            'porcentaje' => round($pct, 2),
            'rol_en_venta' => $rolRaw !== '' ? $rolRaw : null,
        ];
    }

    if ($compradores === []) {
        throw new RuntimeException('Debes registrar al menos un comprador.');
    }

    $total = 0.0;
    foreach ($compradores as $c) {
        $total += (float) $c['porcentaje'];
    }
    if ((int) round($total * 100) !== 10000) {
        throw new RuntimeException('La suma de porcentajes de compradores debe ser exactamente 100.00.');
    }

    return $compradores;
}

function ctComercialHandlePost(PDO $conn, array $post, array $queryBase): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'registrar_tasacion') {
            $idTerreno = isset($post['id_terreno']) && is_numeric((string) $post['id_terreno']) ? (int) $post['id_terreno'] : 0;
            $idTipoTasacion = isset($post['id_tipo_tasacion']) && is_numeric((string) $post['id_tipo_tasacion']) ? (int) $post['id_tipo_tasacion'] : 0;
            $idEntidadFinanciera = isset($post['id_entidad_financiera']) && is_numeric((string) $post['id_entidad_financiera'])
                ? (int) $post['id_entidad_financiera']
                : 0;
            if ($idEntidadFinanciera <= 0) {
                $idEntidadFinanciera = null;
            }
            $fechaTasacion = ctComercialNormalizeDate((string) ($post['fecha_tasacion'] ?? ''), 'la fecha de tasación');
            $valorTotalRaw = trim((string) ($post['valor_total_uf'] ?? ''));
            $valorUfM2Raw = trim((string) ($post['valor_uf_m2'] ?? ''));
            $vigenteDesde = ctComercialNormalizeOptionalDate((string) ($post['vigente_desde'] ?? ''), 'vigente desde');
            $vigenteHasta = ctComercialNormalizeOptionalDate((string) ($post['vigente_hasta'] ?? ''), 'vigente hasta');
            $esReferencial = isset($post['es_referencial']) ? 1 : 0;

            if ($idTerreno <= 0 || !ctComercialRepoTerrenoExists($conn, $idTerreno)) {
                throw new RuntimeException('El terreno seleccionado no existe.');
            }
            $idEstadoNoDisponible = ctComercialRepoFindEstadoPredialNoDisponible($conn);
            $idEstadoPredialTerreno = ctComercialRepoFindTerrenoEstadoPredialId($conn, $idTerreno);
            if ($idEstadoNoDisponible > 0 && $idEstadoPredialTerreno === $idEstadoNoDisponible) {
                throw new RuntimeException('No puedes registrar una tasación para un terreno en estado predial No disponible.');
            }
            $superficieTerreno = ctComercialRepoFindTerrenoSuperficie($conn, $idTerreno);
            if ($superficieTerreno <= 0) {
                throw new RuntimeException('El terreno seleccionado no tiene superficie válida para calcular la tasación.');
            }
            $valorTotalUf = $valorTotalRaw !== ''
                ? ctComercialNormalizeMontoUf($valorTotalRaw, 'valor total UF')
                : null;
            $valorUfM2 = $valorUfM2Raw !== ''
                ? ctComercialNormalizeMontoUf($valorUfM2Raw, 'valor UF por m²')
                : null;
            if ($valorTotalUf === null && $valorUfM2 === null) {
                throw new RuntimeException('Debes ingresar el valor total UF o el valor UF por m².');
            }
            if ($valorTotalUf === null && $valorUfM2 !== null) {
                $valorTotalUf = round($valorUfM2 * $superficieTerreno, 2);
            }
            if ($valorUfM2 === null && $valorTotalUf !== null) {
                $valorUfM2 = round($valorTotalUf / $superficieTerreno, 4);
            }
            if ($idTipoTasacion <= 0 || !ctComercialRepoTipoTasacionExists($conn, $idTipoTasacion)) {
                throw new RuntimeException('Debes seleccionar un tipo de tasación válido.');
            }
            if ($idEntidadFinanciera !== null && !ctComercialRepoEntidadFinancieraExists($conn, $idEntidadFinanciera)) {
                throw new RuntimeException('Debes seleccionar una entidad financiera válida.');
            }
            if ($vigenteDesde !== null && $vigenteHasta !== null && $vigenteHasta < $vigenteDesde) {
                throw new RuntimeException('Vigencia inválida: vigente hasta no puede ser menor que vigente desde.');
            }

            $idTasacion = ctComercialRepoInsertTasacion($conn, [
                'id_terreno' => $idTerreno,
                'id_tipo_tasacion' => $idTipoTasacion,
                'fecha_tasacion' => $fechaTasacion,
                'valor_total_uf' => (float) $valorTotalUf,
                'valor_uf_m2' => $valorUfM2 !== null ? (float) $valorUfM2 : null,
                'id_entidad_financiera' => $idEntidadFinanciera,
                'es_referencial' => $esReferencial,
                'vigente_desde' => $vigenteDesde,
                'vigente_hasta' => $vigenteHasta,
                'id_usuario' => ctComercialCurrentUserId(),
            ]);

            ctSetFlash('success', 'Tasación registrada correctamente (#' . $idTasacion . ').');
            ctComercialRedirectAfterPost($queryBase);
        }

        if ($accion === 'registrar_venta') {
            $idTerreno = isset($post['id_terreno']) && is_numeric((string) $post['id_terreno']) ? (int) $post['id_terreno'] : 0;
            $fechaVenta = ctComercialNormalizeDate((string) ($post['fecha_venta'] ?? ''), 'la fecha de venta');
            $valorTotalRaw = trim((string) ($post['valor_total_uf'] ?? ''));
            $valorUfM2Raw = trim((string) ($post['valor_venta_uf_m2'] ?? ''));
            $idTasacionReferencial = isset($post['id_tasacion_referencial']) && is_numeric((string) $post['id_tasacion_referencial'])
                ? (int) $post['id_tasacion_referencial']
                : 0;
            if ($idTasacionReferencial <= 0) {
                $idTasacionReferencial = null;
            }

            if ($idTerreno <= 0 || !ctComercialRepoTerrenoExists($conn, $idTerreno)) {
                throw new RuntimeException('El terreno seleccionado no existe.');
            }
            $superficieTerreno = ctComercialRepoFindTerrenoSuperficie($conn, $idTerreno);
            if ($superficieTerreno <= 0) {
                throw new RuntimeException('El terreno seleccionado no tiene superficie válida para calcular la venta.');
            }

            $valorTotalUf = $valorTotalRaw !== ''
                ? ctComercialNormalizeMontoUf($valorTotalRaw, 'valor total UF')
                : null;
            $valorUfM2 = $valorUfM2Raw !== ''
                ? ctComercialNormalizeMontoUf($valorUfM2Raw, 'valor venta UF por m²')
                : null;

            if ($valorTotalUf === null && $valorUfM2 === null) {
                throw new RuntimeException('Debes ingresar el valor total UF o el valor venta UF por m².');
            }
            if ($valorTotalUf === null && $valorUfM2 !== null) {
                $valorTotalUf = round($valorUfM2 * $superficieTerreno, 2);
            }
            if ($valorUfM2 === null && $valorTotalUf !== null) {
                $valorUfM2 = round($valorTotalUf / $superficieTerreno, 4);
            }

            if ($idTasacionReferencial !== null && !ctComercialRepoTasacionBelongsToTerreno($conn, $idTasacionReferencial, $idTerreno)) {
                throw new RuntimeException('La tasación referencial no corresponde al terreno seleccionado.');
            }

            $compradores = ctComercialParseCompradoresFromPost($post);
            foreach ($compradores as $comprador) {
                if (!ctComercialRepoTerceroExists($conn, (int) $comprador['id_tercero'])) {
                    throw new RuntimeException('Uno de los compradores no existe en terceros.');
                }
            }

            $idVenta = ctComercialRepoInsertVenta($conn, [
                'id_terreno' => $idTerreno,
                'fecha_venta' => $fechaVenta,
                'valor_total_uf' => (float) $valorTotalUf,
                'valor_venta_uf_m2' => $valorUfM2 !== null ? (float) $valorUfM2 : null,
                'id_tasacion_referencial' => $idTasacionReferencial,
                'compradores' => $compradores,
                'id_usuario' => ctComercialCurrentUserId(),
            ]);

            ctSetFlash('success', 'Venta registrada correctamente (#' . $idVenta . ').');
            ctComercialRedirectAfterPost($queryBase);
        }

        ctSetFlash('warning', 'Acción no reconocida.');
        ctComercialRedirectAfterPost($queryBase);
    } catch (Throwable $exception) {
        ctSetFlash('warning', $exception->getMessage());
        ctComercialRedirectAfterPost($queryBase);
    }
}

function ctComercialFetchPage(PDO $conn, array $state): array
{
    $sortPermitidos = $state['sortPermitidos'];
    $orden = (string) $state['orden'];
    $dir = (string) $state['dir'];

    $orderSql = $sortPermitidos[$orden] . ' ' . strtoupper($dir);

    $filtros = [
        'filtroTexto' => $state['filtroTexto'],
        'filtroEstadoComercial' => $state['filtroEstadoComercial'],
    ];

    $lineas = (int) $state['lineas'];
    $pagina = (int) $state['pagina'];

    $totalRegistros = ctComercialRepoCountTerrenos($conn, $filtros);
    $totalPaginas = max(1, (int) ceil($totalRegistros / max(1, $lineas)));
    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * $lineas;
    $rows = ctComercialRepoListTerrenos($conn, $filtros, $orderSql, $offset, $lineas);

    return [
        'rows' => $rows,
        'totalRegistros' => $totalRegistros,
        'totalPaginas' => $totalPaginas,
        'paginaActual' => $pagina,
    ];
}

function ctComercialFormatUf($value): string
{
    $number = is_numeric((string) $value) ? (float) $value : 0.0;
    return number_format($number, 2, ',', '.');
}

function ctComercialFormatDate(?string $raw): string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return '-';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('d-m-Y', $ts);
}
