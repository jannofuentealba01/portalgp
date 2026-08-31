<?php declare(strict_types=1); ?>
<section class="ct-crud-fade-in">
    <div class="ct-crud-toolbar d-flex justify-content-between align-items-center gap-2 mb-3">
        <div class="small text-muted">Mantenedor dedicado de tipos de usufructuario.</div>
        <button class="btn btn-primary ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-crear-usufructuario-tipo">
            <i class="bi bi-plus-square me-1"></i>Registrar tipo usufructuario
        </button>
    </div>

    <?php if ($error !== null): ?><div class="alert alert-warning mb-3"><?php echo ctEscape($error); ?></div><?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 ct-crud-table">
            <thead><tr><th style="width:90px;">ID</th><th>Tipo usufructuario</th><th style="width:120px;">Estado</th><th style="width:120px;">Usos</th><th class="text-center" style="width:170px;">Acciones</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Sin tipos de usufructuario registrados.</td></tr>
            <?php else: foreach ($rows as $row):
                $id=(int)($row['id_usufructuario_tipo']??0); $nombre=trim((string)($row['nombre']??'')); $count=(int)($row['usos_count']??0); $activo=((int)($row['activo']??0))===1;
                if($id<=0||$nombre===''){continue;}
            ?>
                <tr>
                    <td><?php echo $id; ?></td>
                    <td><?php echo ctEscape($nombre); ?></td>
                    <td><span class="ct-catalogo-pill <?php echo $activo ? 'is-active' : 'is-inactive'; ?>"><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span></td>
                    <td><?php echo number_format($count,0,',','.'); ?></td>
                    <td class="text-center">
                        <div class="ct-crud-actions justify-content-center">
                            <button type="button" class="btn btn-outline-secondary btn-sm ct-btn-editar" data-id="<?php echo $id; ?>" data-nombre="<?php echo ctEscape($nombre); ?>" data-bs-toggle="modal" data-bs-target="#ct-modal-editar-usufructuario-tipo"><i class="bi bi-pencil-square"></i></button>
                            <form method="post" class="d-inline" data-ct-disable-submit="1">
                                <?php ctCsrfField(); ?>
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn btn-outline-<?php echo $activo ? 'warning' : 'success'; ?> btn-sm" title="<?php echo $activo ? 'Inactivar' : 'Activar'; ?>"><i class="bi <?php echo $activo ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
