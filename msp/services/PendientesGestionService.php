<?php
declare(strict_types=1);

/** Gestiona únicamente metadatos operacionales; nunca resuelve la causa de negocio. */
final class PendientesGestionService
{
    private PDO $conn;
    private ?PendientesService $motor;

    public function __construct(PDO $conn, ?PendientesService $motor = null)
    {
        $this->conn = $conn;
        $this->motor = $motor;
        if (!msp2TableExists($conn, 'msp_pendientes_meta') || !msp2TableExists($conn, 'msp_pendientes_bitacora')) {
            throw new RuntimeException('Falta instalar `msp/db/patch_bandeja_pendientes_gestion.sql`.');
        }
    }

    public function asignar(string $clave, int $idUsuarioAsignado, int $idUsuarioAccion, ?string $comentario = null): array
    {
        $this->validarUsuarioActivo($idUsuarioAsignado, 'asignado');
        return $this->modificar($clave, $idUsuarioAccion, 'ASIGNAR', [
            'id_usuario_asignado' => $idUsuarioAsignado,
            'comentario_interno' => $this->texto($comentario),
        ]);
    }

    public function tomarEnRevision(string $clave, int $idUsuarioAccion, ?string $comentario = null): array
    {
        return $this->modificar($clave, $idUsuarioAccion, 'TOMAR_REVISION', [
            'estado_revision' => 'EN_REVISION',
            'id_usuario_asignado' => $idUsuarioAccion,
            'id_usuario_toma' => $idUsuarioAccion,
            'pospuesto_hasta' => null,
            'comentario_interno' => $this->texto($comentario),
        ]);
    }

    public function posponer(string $clave, string $hasta, int $idUsuarioAccion, ?string $comentario = null): array
    {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
        if ($fecha === false || $fecha->format('Y-m-d') !== $hasta || $hasta < date('Y-m-d')) {
            throw new RuntimeException('La fecha de posposición debe ser válida y no puede estar en el pasado.');
        }
        return $this->modificar($clave, $idUsuarioAccion, 'POSPONER', [
            'estado_revision' => 'POSPUESTO',
            'pospuesto_hasta' => $hasta,
            'comentario_interno' => $this->texto($comentario),
        ]);
    }

    public function reabrir(string $clave, int $idUsuarioAccion, ?string $comentario = null): array
    {
        return $this->modificar($clave, $idUsuarioAccion, 'REABRIR', [
            'estado_revision' => 'ABIERTO',
            'id_usuario_toma' => null,
            'pospuesto_hasta' => null,
            'comentario_interno' => $this->texto($comentario),
        ]);
    }

    public function comentar(string $clave, string $comentario, int $idUsuarioAccion): array
    {
        $comentario = $this->texto($comentario) ?? '';
        if ($comentario === '') {
            throw new RuntimeException('Debes escribir un comentario interno.');
        }
        return $this->modificar($clave, $idUsuarioAccion, 'COMENTAR', [
            'comentario_interno' => $comentario,
        ]);
    }

    public function liberarAsignacion(string $clave, int $idUsuarioAccion, ?string $comentario = null): array
    {
        return $this->modificar($clave, $idUsuarioAccion, 'LIBERAR_ASIGNACION', [
            'estado_revision' => 'ABIERTO',
            'id_usuario_asignado' => null,
            'id_usuario_toma' => null,
            'pospuesto_hasta' => null,
            'comentario_interno' => $this->texto($comentario),
        ]);
    }

    public function obtener(string $clave): ?array
    {
        $clave = $this->clave($clave);
        $stmt = $this->conn->prepare(
            'SELECT m.*,ua.nombre_completo usuario_asignado,ut.nombre_completo usuario_toma,uu.nombre_completo usuario_actualiza
             FROM dbo.msp_pendientes_meta m
             LEFT JOIN dbo.cr_usuarios ua ON ua.id=m.id_usuario_asignado
             LEFT JOIN dbo.cr_usuarios ut ON ut.id=m.id_usuario_toma
             LEFT JOIN dbo.cr_usuarios uu ON uu.id=m.id_usuario_actualiza
             WHERE m.pendiente_clave=:clave'
        );
        $stmt->execute([':clave' => $clave]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function historial(string $clave, int $limite = 50): array
    {
        $clave = $this->clave($clave);
        $limite = max(1, min(200, $limite));
        $stmt = $this->conn->prepare(
            'SELECT TOP (' . $limite . ') b.*,u.nombre_completo usuario_accion
             FROM dbo.msp_pendientes_bitacora b
             INNER JOIN dbo.cr_usuarios u ON u.id=b.id_usuario_accion
             WHERE b.pendiente_clave=:clave
             ORDER BY b.fecha_registro DESC,b.id_pendiente_bitacora DESC'
        );
        $stmt->execute([':clave' => $clave]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function modificar(string $clave, int $idUsuarioAccion, string $accion, array $cambios): array
    {
        $clave = $this->clave($clave);
        $this->validarUsuarioActivo($idUsuarioAccion, 'que ejecuta la acción');
        $this->asegurarPendienteActivo($clave);

        $this->conn->beginTransaction();
        try {
            $actualStmt = $this->conn->prepare(
                'SELECT * FROM dbo.msp_pendientes_meta WITH (UPDLOCK,HOLDLOCK) WHERE pendiente_clave=:clave'
            );
            $actualStmt->execute([':clave' => $clave]);
            $actual = $actualStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'estado_revision' => 'ABIERTO',
                'id_usuario_asignado' => null,
                'id_usuario_toma' => null,
                'pospuesto_hasta' => null,
                'comentario_interno' => null,
            ];
            $nuevo = array_merge($actual, $cambios);
            if (($nuevo['estado_revision'] ?? '') !== 'POSPUESTO') {
                $nuevo['pospuesto_hasta'] = null;
            }

            $upsert = $this->conn->prepare(
                'UPDATE dbo.msp_pendientes_meta
                 SET estado_revision=:estado,id_usuario_asignado=:asignado,id_usuario_toma=:toma,
                     pospuesto_hasta=:pospuesto,comentario_interno=:comentario,
                     id_usuario_actualiza=:usuario,fecha_actualizacion=SYSDATETIME()
                 WHERE pendiente_clave=:clave;
                 IF @@ROWCOUNT=0
                    INSERT dbo.msp_pendientes_meta
                        (pendiente_clave,estado_revision,id_usuario_asignado,id_usuario_toma,pospuesto_hasta,comentario_interno,id_usuario_actualiza)
                    VALUES(:clave_insert,:estado_insert,:asignado_insert,:toma_insert,:pospuesto_insert,:comentario_insert,:usuario_insert);'
            );
            $params = [
                ':estado' => $nuevo['estado_revision'], ':asignado' => $nuevo['id_usuario_asignado'],
                ':toma' => $nuevo['id_usuario_toma'], ':pospuesto' => $nuevo['pospuesto_hasta'],
                ':comentario' => $nuevo['comentario_interno'], ':usuario' => $idUsuarioAccion, ':clave' => $clave,
                ':clave_insert' => $clave, ':estado_insert' => $nuevo['estado_revision'],
                ':asignado_insert' => $nuevo['id_usuario_asignado'], ':toma_insert' => $nuevo['id_usuario_toma'],
                ':pospuesto_insert' => $nuevo['pospuesto_hasta'], ':comentario_insert' => $nuevo['comentario_interno'],
                ':usuario_insert' => $idUsuarioAccion,
            ];
            $upsert->execute($params);

            $log = $this->conn->prepare(
                'INSERT dbo.msp_pendientes_bitacora
                    (pendiente_clave,accion,estado_anterior,estado_nuevo,id_usuario_asignado_anterior,
                     id_usuario_asignado_nuevo,pospuesto_hasta_anterior,pospuesto_hasta_nuevo,comentario,id_usuario_accion)
                 VALUES(:clave,:accion,:estado_anterior,:estado_nuevo,:asignado_anterior,
                        :asignado_nuevo,:pospuesto_anterior,:pospuesto_nuevo,:comentario,:usuario)'
            );
            $log->execute([
                ':clave' => $clave, ':accion' => $accion,
                ':estado_anterior' => $actual['estado_revision'] ?? null, ':estado_nuevo' => $nuevo['estado_revision'],
                ':asignado_anterior' => $actual['id_usuario_asignado'] ?? null, ':asignado_nuevo' => $nuevo['id_usuario_asignado'],
                ':pospuesto_anterior' => $actual['pospuesto_hasta'] ?? null, ':pospuesto_nuevo' => $nuevo['pospuesto_hasta'],
                ':comentario' => $nuevo['comentario_interno'], ':usuario' => $idUsuarioAccion,
            ]);
            $this->conn->commit();
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $exception;
        }
        return $this->obtener($clave) ?? [];
    }

    private function asegurarPendienteActivo(string $clave): void
    {
        if (!$this->motor instanceof PendientesService) {
            return;
        }
        foreach ($this->motor->buscar(['agrupar' => true, 'incluir_pospuestos' => true]) as $item) {
            if (hash_equals((string) ($item['id'] ?? ''), $clave)) {
                return;
            }
        }
        throw new RuntimeException('El pendiente ya no existe: su causa real fue resuelta o cambió.');
    }

    private function validarUsuarioActivo(int $idUsuario, string $contexto): void
    {
        if ($idUsuario <= 0) {
            throw new RuntimeException('El usuario ' . $contexto . ' no es válido.');
        }
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM dbo.cr_usuarios WHERE id=:id AND estado_id=1');
        $stmt->execute([':id' => $idUsuario]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('El usuario ' . $contexto . ' no existe o está deshabilitado.');
        }
    }

    private function clave(string $clave): string
    {
        $clave = trim($clave);
        if ($clave === '' || mb_strlen($clave) > 190 || preg_match('/^[A-Za-z0-9:_-]+$/', $clave) !== 1) {
            throw new RuntimeException('La clave del pendiente no es válida.');
        }
        return $clave;
    }

    private function texto(?string $texto): ?string
    {
        $texto = trim((string) $texto);
        if (mb_strlen($texto) > 1000) {
            throw new RuntimeException('El comentario no puede superar 1000 caracteres.');
        }
        return $texto !== '' ? $texto : null;
    }
}
