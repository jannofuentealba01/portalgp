<?php
declare(strict_types=1);

require_once __DIR__ . '/archivos_pdf_helper.php';

function msp2PagoContratoArchivosConfig(): array
{
    return msp2ArchivosPdfConfig();
}

function msp2PagoContratoArchivosRootDir(): string
{
    return msp2ArchivosPdfRootDir();
}

function msp2PagoContratoArchivosRequireDompdf(): void
{
    msp2ArchivosPdfRequireDompdf();
}

function msp2PagoContratoArchivosEnsureDir(string $dir): void
{
    msp2ArchivosPdfEnsureDir($dir);
}

function msp2PagoContratoArchivosTypeDbValue(string $type): string
{
    return msp2ArchivosPdfTypeDbValue($type);
}

function msp2PagoContratoArchivosTypeUiLabel(string $typeDb): string
{
    return msp2ArchivosPdfTypeUiLabel($typeDb);
}

function msp2PagoContratoArchivosPayloadJson(array $item): string
{
    return msp2ArchivosPdfPayloadJson($item);
}

function msp2PagoContratoArchivosDecodePayload(?string $payloadJson): array
{
    return msp2ArchivosPdfDecodePayload($payloadJson);
}

function msp2PagoContratoArchivosBuildPdf(string $type, array $pagoData, array $arrData, array $docData): array
{
    return msp2ArchivosPdfBuildPdf($GLOBALS['conn'], $type, [
        'type' => $type,
        'pago_data' => $pagoData,
        'arr_data' => $arrData,
        'doc_data' => $docData,
        'id_documento_cobro' => (int) ($docData['id_documento_cobro'] ?? 0),
    ]);
}

function msp2PagoContratoArchivosRelativePath(array $item, string $filename): string
{
    return msp2ArchivosPdfRelativePath($item, $filename);
}

function msp2PagoContratoArchivosAbsolutePath(string $relativePath): string
{
    return msp2ArchivosPdfAbsolutePath($relativePath);
}

function msp2PagoContratoArchivosWriteFile(string $absolutePath, string $bytes): void
{
    msp2ArchivosPdfWriteFile($absolutePath, $bytes);
}

function msp2PagoContratoArchivosArchiveOne(PDO $conn, array $item): array
{
    if (!isset($item['module'])) {
        $item['module'] = 'PAGO_CONTRATO';
    }

    return msp2ArchivosPdfArchiveOne($conn, $item);
}

function msp2PagoContratoArchivosArchiveMany(PDO $conn, array $items): array
{
    foreach ($items as $index => $item) {
        if (is_array($item) && !isset($item['module'])) {
            $items[$index]['module'] = 'PAGO_CONTRATO';
        }
    }

    return msp2ArchivosPdfArchiveMany($conn, $items);
}

function msp2PagoContratoArchivosFindById(PDO $conn, int $idArchivo): ?array
{
    return msp2ArchivosPdfFindById($conn, $idArchivo);
}

function msp2PagoContratoArchivosRefreshMaterialized(PDO $conn, array $row, bool $force = false): array
{
    return msp2ArchivosPdfRefreshMaterialized($conn, $row, $force);
}

