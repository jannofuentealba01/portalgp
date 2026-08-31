<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const CT_TERCEROS_IMPORT_MAX_ROWS = 1000;

function ctTercerosImportDownloadTemplateXlsx(): never
{
    ctLoadSpreadsheetLibrary();

    $previousLevel = error_reporting();
    $previousDisplayErrors = (string) ini_get('display_errors');
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');

    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Terceros');

        $headers = ['tipo_persona', 'rut', 'nombre_razon_social'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        $sheet->setCellValue('A2', 'N');
        $sheet->setCellValue('B2', '12345678-5');
        $sheet->setCellValue('C2', 'Juan Perez');

        $sheet->setCellValue('A3', 'J');
        $sheet->setCellValue('B3', '76123456-7');
        $sheet->setCellValue('C3', 'Empresa Demo SpA');

        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ct_terceros_tpl_');
        if ($tmpFile === false) {
            throw new RuntimeException('No fue posible crear archivo temporal para la plantilla.');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $filename = 'plantilla_importacion_terceros_ct.xlsx';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Expires: 0');
        header('Content-Length: ' . (string) filesize($tmpFile));

        readfile($tmpFile);
        @unlink($tmpFile);
        exit();
    } finally {
        error_reporting($previousLevel);
        ini_set('display_errors', $previousDisplayErrors);
    }
}

function ctTercerosImportSessionKey(): string
{
    return 'ct_terceros_import_preview';
}

function ctTercerosImportOpenFlagKey(): string
{
    return 'ct_terceros_import_open_preview';
}

function ctTercerosImportGetPreview(): ?array
{
    $preview = $_SESSION[ctTercerosImportSessionKey()] ?? null;
    return is_array($preview) ? $preview : null;
}

function ctTercerosImportSavePreview(array $preview, bool $openOnLoad = false): void
{
    $_SESSION[ctTercerosImportSessionKey()] = $preview;
    if ($openOnLoad) {
        $_SESSION[ctTercerosImportOpenFlagKey()] = true;
    }
}

function ctTercerosImportClearPreview(): void
{
    unset($_SESSION[ctTercerosImportSessionKey()], $_SESSION[ctTercerosImportOpenFlagKey()]);
}

function ctTercerosImportMarkPreviewOpen(): void
{
    $_SESSION[ctTercerosImportOpenFlagKey()] = true;
}

function ctTercerosImportConsumePreviewOpenFlag(): bool
{
    $value = $_SESSION[ctTercerosImportOpenFlagKey()] ?? false;
    unset($_SESSION[ctTercerosImportOpenFlagKey()]);
    return $value === true;
}

function ctTercerosImportBuildPreviewFromUpload(PDO $conn, array $file, ?string $defaultTipoPersona = null): array
{
    $parsed = ctTercerosImportParseUpload($file);
    $defaultTipo = ctTercerosImportNormalizeDefaultTipo($defaultTipoPersona);
    $validated = ctTercerosImportApplyValidation($conn, $parsed['rows'], $defaultTipo);

    return [
        'id' => bin2hex(random_bytes(10)),
        'file_name' => $parsed['file_name'],
        'created_at' => date('Y-m-d H:i:s'),
        'default_tipo_persona' => $defaultTipo ?? '',
        'rows' => $validated['rows'],
        'summary' => $validated['summary'],
    ];
}

function ctTercerosImportRowsFromPostedPreview(array $postedRows): array
{
    $rows = [];
    foreach ($postedRows as $sourceIndex => $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'source_index' => is_numeric((string) $sourceIndex) ? max(0, (int) $sourceIndex) : 0,
            'line' => max(1, (int) ($row['line'] ?? 0)),
            'tipo_persona' => (string) ($row['tipo_persona'] ?? ''),
            'rut' => (string) ($row['rut'] ?? ''),
            'nombre_razon_social' => (string) ($row['nombre_razon_social'] ?? ''),
            'selected' => ctTercerosImportBoolFromMixed($row['selected'] ?? null),
        ];
    }

    if ($rows === []) {
        throw new RuntimeException('No hay filas para validar en la vista previa.');
    }
    if (count($rows) > CT_TERCEROS_IMPORT_MAX_ROWS) {
        throw new RuntimeException('La vista previa excede el máximo permitido de filas.');
    }

    return $rows;
}

function ctTercerosImportOverlayPreviewMetadata(array $rows, array $previewRows): array
{
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $sourceIndex = max(0, (int) ($row['source_index'] ?? $index));
        $previewRow = $previewRows[$sourceIndex] ?? null;
        if (!is_array($previewRow)) {
            $rows[$index]['existing_id'] = 0;
            continue;
        }

        $rows[$index]['existing_id'] = max(0, (int) ($previewRow['existing_id'] ?? 0));
    }

    return $rows;
}

function ctTercerosImportApplyValidation(PDO $conn, array $rows, ?string $defaultTipoPersona = null): array
{
    $defaultTipo = ctTercerosImportNormalizeDefaultTipo($defaultTipoPersona);
    if ($defaultTipo !== null) {
        $rows = ctTercerosImportApplyDefaultTipo($rows, $defaultTipo);
    }

    $validatedRows = [];
    $rutOccurrences = [];
    $nombreOccurrences = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $line = max(1, (int) ($row['line'] ?? ($index + 2)));
        $selected = ctTercerosImportBoolFromMixed($row['selected'] ?? true);
        $tipo = ctTercerosImportNormalizeTipo((string) ($row['tipo_persona'] ?? ''));
        $rut = ctTercerosImportNormalizeRut((string) ($row['rut'] ?? ''));
        $nombre = ctNormalizeText(ctTercerosImportNormalizeString((string) ($row['nombre_razon_social'] ?? '')));
        $errors = [];

        if ($selected) {
            if ($tipo !== 'N' && $tipo !== 'J') {
                $errors[] = 'Tipo persona inválido. Debe ser N o J.';
            }

            if ($nombre === '') {
                $errors[] = 'Nombre / razón social es obligatorio.';
            } else {
                $nombreLen = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
                if ($nombreLen > 200) {
                    $errors[] = 'Nombre / razón social supera 200 caracteres.';
                } else {
                    $nombreKey = ctTercerosImportNormalizeNombreKey($nombre);
                    if ($nombreKey !== '') {
                        $nombreOccurrences[$nombreKey][] = $index;
                    }
                }
            }

            if ($rut !== '') {
                if (strlen($rut) > 20) {
                    $errors[] = 'RUT supera 20 caracteres.';
                } elseif (!ctTercerosImportRutDvIsValid($rut)) {
                    $errors[] = 'RUT inválido (dígito verificador).';
                } else {
                    $rutOccurrences[$rut][] = $index;
                }
            }
        }

        $validatedRows[$index] = [
            'source_index' => max(0, (int) ($row['source_index'] ?? $index)),
            'line' => $line,
            'tipo_persona' => $tipo,
            'rut' => $rut,
            'nombre_razon_social' => $nombre,
            'selected' => $selected,
            'errors' => $errors,
            'warnings' => [],
            'status' => 'pending',
            'operation' => 'create',
            'operation_source' => 'new',
            'existing_id' => max(0, (int) ($row['existing_id'] ?? 0)),
            'existing_tipo_persona' => '',
            'existing_rut' => '',
            'existing_nombre_razon_social' => '',
            'preserve_existing_rut' => false,
            'no_change' => false,
        ];
    }

    foreach ($rutOccurrences as $rut => $indexes) {
        if (count($indexes) <= 1) {
            continue;
        }
        foreach ($indexes as $rowIndex) {
            $validatedRows[$rowIndex]['errors'][] = 'RUT duplicado dentro del archivo.';
        }
    }

    foreach ($nombreOccurrences as $nombreKey => $indexes) {
        if (count($indexes) <= 1) {
            continue;
        }
        foreach ($indexes as $rowIndex) {
            $validatedRows[$rowIndex]['errors'][] = 'Nombre / razón social duplicado dentro del archivo.';
        }
    }

    $rutsToCheck = [];
    foreach ($validatedRows as $row) {
        if (!$row['selected']) {
            continue;
        }
        if ($row['rut'] === '') {
            continue;
        }
        $rutsToCheck[] = $row['rut'];
    }

    $existingByRut = ctTercerosRepoFindByRuts($conn, $rutsToCheck);
    $nameKeysToCheck = [];
    foreach ($validatedRows as $row) {
        if (!$row['selected']) {
            continue;
        }
        $nameKey = ctTercerosImportNormalizeNombreKey((string) ($row['nombre_razon_social'] ?? ''));
        if ($nameKey === '') {
            continue;
        }
        $nameKeysToCheck[] = $nameKey;
    }
    $existingByNameKey = ctTercerosRepoFindByNombreRazonSocialKeys($conn, $nameKeysToCheck);
    $anchorIdsToCheck = [];
    foreach ($validatedRows as $row) {
        if (!$row['selected']) {
            continue;
        }
        $anchorId = max(0, (int) ($row['existing_id'] ?? 0));
        if ($anchorId > 0) {
            $anchorIdsToCheck[] = $anchorId;
        }
    }
    $existingById = ctTercerosRepoFindByIds($conn, $anchorIdsToCheck);

    foreach ($validatedRows as $index => $row) {
        if (!$row['selected']) {
            continue;
        }

        $existingByRutRow = null;
        if ($row['rut'] !== '' && isset($existingByRut[$row['rut']])) {
            $existingByRutRow = $existingByRut[$row['rut']];
        }

        $existingByNameRow = null;
        $nameKey = ctTercerosImportNormalizeNombreKey((string) ($row['nombre_razon_social'] ?? ''));
        if ($nameKey !== '' && isset($existingByNameKey[$nameKey])) {
            $nameMatch = $existingByNameKey[$nameKey];
            $nameCount = max(0, (int) ($nameMatch['count'] ?? 0));
            if ($nameCount > 1) {
                $validatedRows[$index]['errors'][] = 'Nombre / razón social coincide con múltiples terceros existentes.';
            } else {
                $nameRow = $nameMatch['row'] ?? null;
                if (is_array($nameRow)) {
                    $existingByNameRow = $nameRow;
                }
            }
        }

        $existingByRutId = $existingByRutRow !== null ? max(0, (int) ($existingByRutRow['id_tercero'] ?? 0)) : 0;
        $existingByNameId = $existingByNameRow !== null ? max(0, (int) ($existingByNameRow['id_tercero'] ?? 0)) : 0;
        $anchorId = max(0, (int) ($row['existing_id'] ?? 0));
        $targetId = 0;
        $targetRow = null;
        $targetSource = 'new';

        if ($anchorId > 0) {
            $anchorRow = $existingById[$anchorId] ?? null;
            if (!is_array($anchorRow)) {
                $validatedRows[$index]['errors'][] = 'El registro objetivo para actualizar ya no existe.';
                continue;
            }

            if ($existingByRutId > 0 && $existingByRutId !== $anchorId) {
                $validatedRows[$index]['errors'][] = 'El RUT ingresado corresponde a otro tercero distinto.';
                continue;
            }
            if ($existingByNameId > 0 && $existingByNameId !== $anchorId) {
                $validatedRows[$index]['errors'][] = 'El nombre / razón social ingresado corresponde a otro tercero distinto.';
                continue;
            }

            $targetId = $anchorId;
            $targetRow = $anchorRow;
            $targetSource = 'anchor';
        } else {
            if ($existingByRutId > 0 && $existingByNameId > 0 && $existingByRutId !== $existingByNameId) {
                $validatedRows[$index]['errors'][] = 'RUT y nombre / razón social apuntan a terceros distintos.';
                continue;
            }

            $targetId = $existingByRutId > 0 ? $existingByRutId : $existingByNameId;
            if ($targetId > 0) {
                $targetRow = $existingByRutId > 0 ? $existingByRutRow : $existingByNameRow;
                $targetSource = $existingByRutId > 0 ? 'rut' : 'nombre';
            }
        }

        if ($targetId > 0) {
            $validatedRows[$index]['operation'] = 'update';
            $validatedRows[$index]['operation_source'] = $targetSource;
            $validatedRows[$index]['existing_id'] = $targetId;
            if (is_array($targetRow)) {
                $validatedRows[$index]['existing_tipo_persona'] = ctTercerosImportNormalizeTipo((string) ($targetRow['tipo_persona'] ?? ''));
                $validatedRows[$index]['existing_rut'] = ctTercerosImportNormalizeRut((string) ($targetRow['rut'] ?? ''));
                $validatedRows[$index]['existing_nombre_razon_social'] = ctNormalizeText(
                    ctTercerosImportNormalizeString((string) ($targetRow['nombre_razon_social'] ?? ''))
                );
            }
        }
    }

    foreach ($validatedRows as $index => $row) {
        if (!$row['selected']) {
            $validatedRows[$index]['status'] = 'omitido';
            $validatedRows[$index]['operation'] = 'omit';
            $validatedRows[$index]['operation_source'] = 'omit';
            $validatedRows[$index]['existing_id'] = null;
            continue;
        }

        if ($row['errors'] === []) {
            if (($row['operation'] ?? 'create') === 'create' && (string) ($row['rut'] ?? '') === '') {
                $validatedRows[$index]['warnings'][] = 'Se creará sin RUT.';
            }

            if (($row['operation'] ?? 'create') === 'update') {
                $existingRut = (string) ($row['existing_rut'] ?? '');
                $incomingRut = (string) ($row['rut'] ?? '');
                if ($incomingRut === '' && $existingRut !== '') {
                    $validatedRows[$index]['preserve_existing_rut'] = true;
                    $validatedRows[$index]['warnings'][] = 'RUT vacío en archivo: se mantendrá el RUT existente.';
                }

                $existingTipo = (string) ($row['existing_tipo_persona'] ?? '');
                $existingNombre = (string) ($row['existing_nombre_razon_social'] ?? '');
                $effectiveRut = $incomingRut !== '' ? $incomingRut : $existingRut;
                if (
                    $existingTipo !== ''
                    && $existingNombre !== ''
                    && (string) ($row['tipo_persona'] ?? '') === $existingTipo
                    && (string) ($row['nombre_razon_social'] ?? '') === $existingNombre
                    && $effectiveRut === $existingRut
                ) {
                    $validatedRows[$index]['no_change'] = true;
                    $validatedRows[$index]['warnings'][] = 'La fila no tiene cambios respecto al registro actual.';
                }
            }
        }

        if ($row['errors'] !== []) {
            $validatedRows[$index]['status'] = 'error';
        } elseif ($validatedRows[$index]['warnings'] !== []) {
            $validatedRows[$index]['status'] = 'warning';
        } else {
            $validatedRows[$index]['status'] = 'ok';
        }
    }

    return [
        'rows' => array_values($validatedRows),
        'summary' => ctTercerosImportBuildSummary(array_values($validatedRows)),
    ];
}

function ctTercerosImportRowsReadyToInsert(array $rows): array
{
    $ready = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['selected'] ?? false) !== true) {
            continue;
        }
        if (($row['status'] ?? '') === 'error') {
            continue;
        }

        $ready[] = [
            'operation' => (string) ($row['operation'] ?? 'create'),
            'operation_source' => (string) ($row['operation_source'] ?? ''),
            'existing_id' => (int) ($row['existing_id'] ?? 0),
            'existing_rut' => (string) ($row['existing_rut'] ?? ''),
            'preserve_existing_rut' => (($row['preserve_existing_rut'] ?? false) === true),
            'no_change' => (($row['no_change'] ?? false) === true),
            'tipo_persona' => (string) ($row['tipo_persona'] ?? ''),
            'rut' => (string) ($row['rut'] ?? ''),
            'nombre_razon_social' => (string) ($row['nombre_razon_social'] ?? ''),
        ];
    }

    return $ready;
}

function ctTercerosImportBuildSummary(array $rows): array
{
    $summary = [
        'total' => 0,
        'selected' => 0,
        'ready' => 0,
        'errors' => 0,
        'warnings' => 0,
        'omitted' => 0,
        'create' => 0,
        'update' => 0,
        'unchanged' => 0,
    ];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $summary['total']++;
        $selected = ($row['selected'] ?? false) === true;
        if (!$selected) {
            $summary['omitted']++;
            continue;
        }

        $summary['selected']++;
        if (($row['status'] ?? '') !== 'error') {
            $summary['ready']++;
            if (($row['status'] ?? '') === 'warning') {
                $summary['warnings']++;
            }
            if (($row['operation'] ?? 'create') === 'update') {
                if (($row['no_change'] ?? false) === true) {
                    $summary['unchanged']++;
                } else {
                    $summary['update']++;
                }
            } else {
                $summary['create']++;
            }
        } else {
            $summary['errors']++;
        }
    }

    return $summary;
}

function ctTercerosImportParseUpload(array $file): array
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $fileName = trim((string) ($file['name'] ?? ''));

    if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Debes seleccionar un archivo Excel válido.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('El archivo seleccionado está vacío.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('El archivo supera 5MB. Reduce su tamaño para continuar.');
    }

    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension !== 'xlsx' && $extension !== 'csv') {
        throw new RuntimeException('Formato no soportado. Usa .xlsx o .csv.');
    }

    $rawRows = $extension === 'xlsx'
        ? ctTercerosImportParseXlsx($tmpName)
        : ctTercerosImportParseCsv($tmpName);

    $rows = ctTercerosImportExtractMappedRows($rawRows);
    if (count($rows) > CT_TERCEROS_IMPORT_MAX_ROWS) {
        throw new RuntimeException('El archivo contiene demasiadas filas. Máximo permitido: ' . CT_TERCEROS_IMPORT_MAX_ROWS . '.');
    }

    return [
        'file_name' => $fileName !== '' ? $fileName : ('importacion.' . $extension),
        'rows' => $rows,
    ];
}

function ctTercerosImportParseCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('No fue posible leer el archivo CSV.');
    }

    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = ctTercerosImportDetectCsvDelimiter(is_string($firstLine) ? $firstLine : ',');
    $rows = [];
    $line = 0;

    while (($fields = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        $cells = [];
        foreach ($fields as $columnIndex => $value) {
            $normalized = ctTercerosImportNormalizeString((string) $value);
            $cells[$columnIndex] = ctNormalizeText($normalized);
        }
        $rows[] = [
            'line' => $line,
            'cells' => $cells,
        ];
    }

    fclose($handle);
    return $rows;
}

function ctTercerosImportDetectCsvDelimiter(string $line): string
{
    $candidates = [',', ';', "\t", '|'];
    $best = ',';
    $bestScore = -1;

    foreach ($candidates as $candidate) {
        $score = substr_count($line, $candidate);
        if ($score > $bestScore) {
            $best = $candidate;
            $bestScore = $score;
        }
    }

    return $best;
}

function ctTercerosImportParseXlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene ZipArchive habilitado para leer archivos XLSX.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No fue posible abrir el archivo XLSX.');
    }

    try {
        $sheetPath = ctTercerosImportFirstSheetPath($zip);
        $sheetXmlRaw = $zip->getFromName($sheetPath);
        if (!is_string($sheetXmlRaw) || $sheetXmlRaw === '') {
            throw new RuntimeException('No fue posible leer la primera hoja del archivo XLSX.');
        }

        $sharedStrings = ctTercerosImportParseSharedStrings($zip);
        $sheetXml = ctTercerosImportLoadXml($sheetXmlRaw);
        $sheetXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $sheetXml->xpath('/x:worksheet/x:sheetData/x:row');
        if (!is_array($rowNodes)) {
            return [];
        }

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $line = max(1, (int) ($rowNode['r'] ?? 0));
            $cells = [];
            foreach ($rowNode->c as $cellNode) {
                $coordinate = (string) ($cellNode['r'] ?? '');
                $letters = strtoupper((string) preg_replace('/[^A-Z]/', '', $coordinate));
                if ($letters === '') {
                    continue;
                }

                $index = ctTercerosImportColumnIndexFromLetters($letters);
                $cells[$index] = ctTercerosImportCellValueFromXlsxCell($cellNode, $sharedStrings);
            }

            if ($cells !== []) {
                ksort($cells);
            }
            $rows[] = [
                'line' => $line > 0 ? $line : (count($rows) + 1),
                'cells' => $cells,
            ];
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

function ctTercerosImportFirstSheetPath(ZipArchive $zip): string
{
    $workbookRaw = $zip->getFromName('xl/workbook.xml');
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbookRaw) || !is_string($relsRaw)) {
        throw new RuntimeException('Estructura XLSX inválida.');
    }

    $workbookXml = ctTercerosImportLoadXml($workbookRaw);
    $workbookXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheetNodes = $workbookXml->xpath('/x:workbook/x:sheets/x:sheet');
    if (!is_array($sheetNodes) || $sheetNodes === []) {
        throw new RuntimeException('No se encontraron hojas en el archivo XLSX.');
    }

    $firstSheet = $sheetNodes[0];
    $attrs = $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relationshipId = isset($attrs['id']) ? (string) $attrs['id'] : '';
    if ($relationshipId === '') {
        throw new RuntimeException('No se encontró la relación de la primera hoja XLSX.');
    }

    $relsXml = ctTercerosImportLoadXml($relsRaw);
    $relsXml->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $targetNodes = $relsXml->xpath('/rel:Relationships/rel:Relationship[@Id="' . $relationshipId . '"]');
    if (!is_array($targetNodes) || $targetNodes === []) {
        throw new RuntimeException('No se encontró el archivo interno de la primera hoja XLSX.');
    }

    $target = (string) ($targetNodes[0]['Target'] ?? '');
    if ($target === '') {
        throw new RuntimeException('La primera hoja XLSX no tiene ruta de destino.');
    }

    $path = str_replace('\\', '/', $target);
    while (str_starts_with($path, '../')) {
        $path = substr($path, 3);
    }

    $resolved = str_starts_with($path, '/')
        ? ltrim($path, '/')
        : ('xl/' . ltrim($path, '/'));

    if ($resolved === '') {
        throw new RuntimeException('No se pudo resolver la ruta de la primera hoja XLSX.');
    }

    return $resolved;
}

function ctTercerosImportParseSharedStrings(ZipArchive $zip): array
{
    $raw = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $xml = ctTercerosImportLoadXml($raw);
    $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $items = $xml->xpath('/x:sst/x:si');
    if (!is_array($items)) {
        return [];
    }

    $strings = [];
    foreach ($items as $item) {
        $parts = ctTercerosImportXPathTextNodes($item);
        if (!is_array($parts) || $parts === []) {
            $strings[] = '';
            continue;
        }

        $value = '';
        foreach ($parts as $part) {
            $value .= (string) $part;
        }
        $strings[] = ctNormalizeText(ctTercerosImportNormalizeString($value));
    }

    return $strings;
}

function ctTercerosImportCellValueFromXlsxCell(SimpleXMLElement $cell, array $sharedStrings): string
{
    $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $type = (string) ($cell['t'] ?? '');
    $value = '';

    if ($type === 's') {
        $index = (int) ($cell->v ?? -1);
        $value = $sharedStrings[$index] ?? '';
    } elseif ($type === 'inlineStr') {
        $parts = ctTercerosImportXPathTextNodes($cell);
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $value .= (string) $part;
            }
        }
    } else {
        $value = (string) ($cell->v ?? '');
    }

    return ctNormalizeText(ctTercerosImportNormalizeString($value));
}

function ctTercerosImportXPathTextNodes(SimpleXMLElement $node): array
{
    $node->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $parts = $node->xpath('.//x:t');
    if (is_array($parts) && $parts !== []) {
        return $parts;
    }

    $fallback = $node->xpath('.//*[local-name()="t"]');
    return is_array($fallback) ? $fallback : [];
}

function ctTercerosImportColumnIndexFromLetters(string $letters): int
{
    $result = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $result = ($result * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $result - 1);
}

function ctTercerosImportLoadXml(string $xml): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    try {
        $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    } finally {
        libxml_use_internal_errors($previous);
    }

    if (!$parsed instanceof SimpleXMLElement) {
        throw new RuntimeException('No fue posible interpretar el XML interno del archivo.');
    }

    return $parsed;
}

function ctTercerosImportExtractMappedRows(array $rawRows): array
{
    $headerRowIndex = null;
    foreach ($rawRows as $index => $rawRow) {
        if (!is_array($rawRow)) {
            continue;
        }
        $cells = $rawRow['cells'] ?? [];
        if (!is_array($cells)) {
            continue;
        }

        $hasContent = false;
        foreach ($cells as $value) {
            if (trim((string) $value) !== '') {
                $hasContent = true;
                break;
            }
        }
        if ($hasContent) {
            $headerRowIndex = $index;
            break;
        }
    }

    if ($headerRowIndex === null || !isset($rawRows[$headerRowIndex]['cells'])) {
        throw new RuntimeException('El archivo no contiene encabezados.');
    }

    $headerMap = ctTercerosImportResolveHeaderMap((array) $rawRows[$headerRowIndex]['cells']);
    $required = ['nombre_razon_social'];
    $missing = [];
    foreach ($required as $field) {
        if (!isset($headerMap[$field])) {
            $missing[] = $field;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Faltan columnas requeridas en el archivo: ' . implode(', ', $missing) . '.'
        );
    }

    $rows = [];
    for ($i = $headerRowIndex + 1, $len = count($rawRows); $i < $len; $i++) {
        $rawRow = $rawRows[$i];
        if (!is_array($rawRow)) {
            continue;
        }
        $cells = $rawRow['cells'] ?? [];
        if (!is_array($cells)) {
            continue;
        }

        $line = max(1, (int) ($rawRow['line'] ?? ($i + 1)));
        $tipo = isset($headerMap['tipo_persona']) ? (string) ($cells[$headerMap['tipo_persona']] ?? '') : '';
        $rut = isset($headerMap['rut']) ? (string) ($cells[$headerMap['rut']] ?? '') : '';
        $nombre = (string) ($cells[$headerMap['nombre_razon_social']] ?? '');

        if (trim($tipo) === '' && trim($rut) === '' && trim($nombre) === '') {
            continue;
        }

        $rows[] = [
            'line' => $line,
            'tipo_persona' => $tipo,
            'rut' => $rut,
            'nombre_razon_social' => $nombre,
            'selected' => true,
        ];
    }

    if ($rows === []) {
        throw new RuntimeException('El archivo no contiene filas de datos.');
    }

    return $rows;
}

function ctTercerosImportResolveHeaderMap(array $headerCells): array
{
    $aliases = [
        'tipo_persona' => ['tipo_persona', 'tipo_de_persona', 'tipopersona', 'tipo', 'persona_tipo'],
        'rut' => ['rut', 'r_u_t'],
        'nombre_razon_social' => [
            'nombre_razon_social',
            'nombre_de_razon_social',
            'nombrerazonsocial',
            'nombre',
            'razonsocial',
            'nombre_razonsocial',
            'nombre_razon',
            'nombre_razon_o_social',
        ],
    ];

    $map = [];
    foreach ($headerCells as $columnIndex => $headerValue) {
        $normalized = ctTercerosImportNormalizeHeaderKey((string) $headerValue);
        if ($normalized === '') {
            continue;
        }

        foreach ($aliases as $field => $values) {
            if (isset($map[$field])) {
                continue;
            }
            if (in_array($normalized, $values, true)) {
                $map[$field] = (int) $columnIndex;
            }
        }
    }

    return $map;
}

function ctTercerosImportNormalizeHeaderKey(string $value): string
{
    $value = ctTercerosImportNormalizeString(trim($value));
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
    }
    $value = strtolower($value);
    $value = str_replace(['.', '-', '/', '\\', '(', ')'], ' ', $value);
    $value = preg_replace('/\s+/', '_', $value);
    $value = preg_replace('/[^a-z0-9_]/', '', (string) $value);
    return trim((string) $value, '_');
}

function ctTercerosImportNormalizeTipo(string $tipo): string
{
    $normalized = strtoupper(ctNormalizeText(ctTercerosImportNormalizeString($tipo)));
    if ($normalized === '') {
        return '';
    }

    $compact = preg_replace('/[^A-Z]/', '', $normalized);
    $compact = is_string($compact) ? $compact : '';
    $aliases = [
        'N' => 'N',
        'NATURAL' => 'N',
        'PERSONANATURAL' => 'N',
        'J' => 'J',
        'JURIDICA' => 'J',
        'PERSONAJURIDICA' => 'J',
    ];

    return $aliases[$compact] ?? $normalized;
}

function ctTercerosImportNormalizeDefaultTipo(?string $tipo): ?string
{
    $normalized = ctTercerosImportNormalizeTipo((string) $tipo);
    return ($normalized === 'N' || $normalized === 'J') ? $normalized : null;
}

function ctTercerosImportNormalizeNombreKey(string $nombre): string
{
    return strtoupper(ctNormalizeText(ctTercerosImportNormalizeString($nombre)));
}

function ctTercerosImportApplyDefaultTipo(array $rows, string $defaultTipo): array
{
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $tipoActual = ctTercerosImportNormalizeTipo((string) ($row['tipo_persona'] ?? ''));
        if ($tipoActual === '') {
            $rows[$index]['tipo_persona'] = $defaultTipo;
        }
    }

    return $rows;
}

function ctTercerosImportNormalizeRut(string $rut): string
{
    $rut = strtoupper(ctNormalizeText(ctTercerosImportNormalizeString($rut)));
    if ($rut === '') {
        return '';
    }

    $clean = preg_replace('/[^0-9K]/', '', $rut);
    $clean = is_string($clean) ? $clean : '';
    if ($clean === '') {
        return '';
    }
    if (strlen($clean) === 1) {
        return $clean;
    }

    return ctTercerosImportFormatRutFromClean($clean);
}

function ctTercerosImportFormatRutFromClean(string $clean): string
{
    $dv = substr($clean, -1);
    $body = substr($clean, 0, -1);
    if ($body === '') {
        $body = '0';
    }

    return $body . '-' . $dv;
}

function ctTercerosImportRutDvIsValid(string $rut): bool
{
    $clean = preg_replace('/[^0-9K]/', '', strtoupper($rut));
    $clean = is_string($clean) ? $clean : '';
    if (strlen($clean) < 2) {
        return false;
    }

    $dvGiven = substr($clean, -1);
    $number = substr($clean, 0, -1);
    if ($number === '' || !ctype_digit($number)) {
        return false;
    }

    $sum = 0;
    $factor = 2;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $sum += ((int) $number[$i]) * $factor;
        $factor = $factor === 7 ? 2 : ($factor + 1);
    }

    $remainder = 11 - ($sum % 11);
    if ($remainder === 11) {
        $dvExpected = '0';
    } elseif ($remainder === 10) {
        $dvExpected = 'K';
    } else {
        $dvExpected = (string) $remainder;
    }

    return $dvGiven === $dvExpected;
}

function ctTercerosImportNormalizeString(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $encoding = mb_detect_encoding($trimmed, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if (is_string($encoding) && $encoding !== '' && strtoupper($encoding) !== 'UTF-8') {
            $converted = mb_convert_encoding($trimmed, 'UTF-8', $encoding);
            if (is_string($converted) && $converted !== '') {
                $trimmed = $converted;
            }
        }
    }

    return $trimmed;
}

function ctTercerosImportBoolFromMixed(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    if (!is_string($value)) {
        return false;
    }

    $normalized = strtolower(trim($value));
    return $normalized === '1' || $normalized === 'true' || $normalized === 'on' || $normalized === 'yes';
}
