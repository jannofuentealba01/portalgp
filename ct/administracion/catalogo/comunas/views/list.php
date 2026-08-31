<?php declare(strict_types=1); ?>
<section class="ct-crud-fade-in">
    <div class="ct-crud-toolbar d-flex justify-content-between align-items-center gap-2 mb-3">
        <div class="small text-muted">Mantenedor dedicado de comunas.</div>
        <button class="btn btn-primary ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-crear-comuna">
            <i class="bi bi-plus-square me-1"></i>Registrar comuna
        </button>
    </div>

    <?php if ($error !== null): ?><div class="alert alert-warning mb-3"><?php echo ctEscape($error); ?></div><?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 ct-crud-table">
            <thead><tr><th style="width:90px;">ID</th><th>Comuna</th><th style="width:140px;">Terrenos</th><th class="text-center" style="width:140px;">Acciones</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Sin comunas registradas.</td></tr>
            <?php else: foreach ($rows as $row):
                $id=(int)($row['id_comuna']??0); $nombre=trim((string)($row['nombre']??'')); $count=(int)($row['terrenos_count']??0);
                if($id<=0||$nombre===''){continue;}
            ?>
                <tr>
                    <td><?php echo $id; ?></td>
                    <td><?php echo ctEscape($nombre); ?></td>
                    <td><?php echo number_format($count,0,',','.'); ?></td>
                    <td class="text-center">
                        <div class="ct-crud-actions justify-content-center">
                            <button type="button" class="btn btn-outline-secondary btn-sm ct-btn-editar" data-id="<?php echo $id; ?>" data-nombre="<?php echo ctEscape($nombre); ?>" data-bs-toggle="modal" data-bs-target="#ct-modal-editar-comuna"><i class="bi bi-pencil-square"></i></button>
                            <button type="button" class="btn btn-outline-danger btn-sm ct-btn-eliminar" data-id="<?php echo $id; ?>" data-nombre="<?php echo ctEscape($nombre); ?>" data-count="<?php echo $count; ?>" data-bs-toggle="modal" data-bs-target="#ct-modal-eliminar-comuna"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
