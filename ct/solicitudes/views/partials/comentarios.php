<?php
declare(strict_types=1);

if (!function_exists('ctSolicitudesViewDatetime')) {
    function ctSolicitudesViewDatetime(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '-';
        }
        $dt = new DateTimeImmutable($raw);
        return $dt->format('d-m-Y H:i');
    }
}

if (!function_exists('ctSolicitudesComentarioEstadoMeta')) {
    function ctSolicitudesComentarioEstadoMeta(?string $estado): array
    {
        $codigo = strtoupper(trim((string) $estado));
        if ($codigo === 'RESUELTO') {
            return [
                'codigo' => 'RESUELTO',
                'label' => 'Resuelto',
                'badge' => 'bg-success-subtle text-success-emphasis',
            ];
        }
        return [
            'codigo' => 'PENDIENTE',
            'label' => 'Pendiente revisión',
            'badge' => 'bg-warning-subtle text-warning-emphasis',
        ];
    }
}

if (!function_exists('ctSolicitudesRenderAreaCommentsThread')) {
    function ctSolicitudesRenderAreaCommentsThread(array $context): void
    {
        $idArea = (int) ($context['idArea'] ?? 0);
        $areaName = (string) ($context['areaName'] ?? ('Área #' . $idArea));
        $areaThreadAnchorId = (string) ($context['areaThreadAnchorId'] ?? ('ct-sol-area-thread-' . $idArea));
        $areaComentarios = is_array($context['areaComentarios'] ?? null) ? $context['areaComentarios'] : [];
        $canComment = !empty($context['canComment']) || !empty($context['canCommentByGerencia']);
        $currentUserId = (int) ($context['currentUserId'] ?? 0);
        $usuariosMap = is_array($context['usuariosMap'] ?? null) ? $context['usuariosMap'] : [];
        $idSolicitud = (int) ($context['idSolicitud'] ?? 0);
        $postUrl = (string) ($context['postUrl'] ?? '');
        $errorMessage = trim((string) ($context['errorMessage'] ?? ''));
        ?>
        <aside id="<?php echo ctEscape($areaThreadAnchorId); ?>" class="ct-sol-area-thread border rounded p-3">
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger py-2 px-2 small mb-2"><?php echo ctEscape($errorMessage); ?></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <div class="fw-semibold">Comentarios</div>
                <span class="badge rounded-pill text-bg-light"><?php echo ctEscape((string) count($areaComentarios)); ?></span>
            </div>
            <?php if ($canComment): ?>
                <form
                    method="post"
                    class="mb-3"
                    hx-post="<?php echo ctEscape($postUrl); ?>"
                    hx-target="#<?php echo ctEscape($areaThreadAnchorId); ?>"
                    hx-swap="outerHTML"
                >
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="agregar_comentario">
                    <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) $idSolicitud); ?>">
                    <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                    <input type="hidden" name="return_fragment" value="<?php echo ctEscape($areaThreadAnchorId); ?>">
                    <textarea name="comentario" class="form-control form-control-sm mb-2" rows="2" placeholder="Escribe una observación para <?php echo ctEscape($areaName); ?>..."></textarea>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Publicar comentario</button>
                </form>
            <?php else: ?>
                <div class="small text-muted mb-3">No tienes permisos para comentar en este bloque.</div>
            <?php endif; ?>
            <div class="ct-sol-area-thread-list d-grid gap-2">
                <?php if ($areaComentarios === []): ?>
                    <div class="small text-muted">Sin comentarios en este bloque todavía.</div>
                <?php else: ?>
                    <?php foreach ($areaComentarios as $comentarioArea): ?>
                        <?php
                        $idUsuarioComentario = (int) ($comentarioArea['id_usuario'] ?? 0);
                        $isOwnComment = $idUsuarioComentario === $currentUserId;
                        $estadoComentarioMeta = ctSolicitudesComentarioEstadoMeta((string) ($comentarioArea['estado_revision'] ?? 'PENDIENTE'));
                        $comentarioResuelto = $estadoComentarioMeta['codigo'] === 'RESUELTO';
                        $fechaResolucion = ctSolicitudesViewDatetime((string) ($comentarioArea['resuelto_en'] ?? ''));
                        $idUsuarioResolucion = (int) ($comentarioArea['id_usuario_resolucion'] ?? 0);
                        ?>
                        <article class="ct-sol-comment-item<?php echo $isOwnComment ? ' is-own' : ''; ?>">
                            <header class="ct-sol-comment-meta mb-2">
                                <strong><?php echo ctEscape($usuariosMap[$idUsuarioComentario] ?? ('Usuario #' . $idUsuarioComentario)); ?></strong>
                                <span><?php echo ctEscape(ctSolicitudesViewDatetime((string) ($comentarioArea['fecha_creacion'] ?? ''))); ?></span>
                            </header>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill <?php echo ctEscape((string) $estadoComentarioMeta['badge']); ?>"><?php echo ctEscape((string) $estadoComentarioMeta['label']); ?></span>
                                <?php if ($comentarioResuelto && trim($fechaResolucion) !== '-' && $fechaResolucion !== ''): ?>
                                    <span class="small text-muted">Resuelto: <?php echo ctEscape($fechaResolucion); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="small<?php echo $comentarioResuelto ? ' text-muted text-decoration-line-through' : ''; ?>"><?php echo nl2br(ctEscape((string) ($comentarioArea['comentario'] ?? ''))); ?></div>
                            <?php if ($comentarioResuelto && $idUsuarioResolucion > 0): ?>
                                <div class="small text-muted mt-2">Por: <?php echo ctEscape($usuariosMap[$idUsuarioResolucion] ?? ('Usuario #' . $idUsuarioResolucion)); ?></div>
                            <?php endif; ?>
                            <?php if (!$comentarioResuelto && $canComment): ?>
                                <form
                                    method="post"
                                    class="mt-2"
                                    hx-post="<?php echo ctEscape($postUrl); ?>"
                                    hx-target="#<?php echo ctEscape($areaThreadAnchorId); ?>"
                                    hx-swap="outerHTML"
                                >
                                    <?php ctCsrfField(); ?>
                                    <input type="hidden" name="accion" value="resolver_comentario">
                                    <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) $idSolicitud); ?>">
                                    <input type="hidden" name="id_solicitud_comentario" value="<?php echo ctEscape((string) ($comentarioArea['id_solicitud_comentario'] ?? 0)); ?>">
                                    <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                                    <input type="hidden" name="return_fragment" value="<?php echo ctEscape($areaThreadAnchorId); ?>">
                                    <button type="submit" class="btn btn-outline-success btn-sm">Marcar como resuelto</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>
        <?php
    }
}

if (!function_exists('ctSolicitudesRenderGeneralCommentsCard')) {
    function ctSolicitudesRenderGeneralCommentsCard(array $context): void
    {
        $generalComentarios = is_array($context['generalComentarios'] ?? null) ? $context['generalComentarios'] : [];
        $canComment = !empty($context['canComment']) || !empty($context['canCommentByGerencia']);
        $usuariosMap = is_array($context['usuariosMap'] ?? null) ? $context['usuariosMap'] : [];
        $idSolicitud = (int) ($context['idSolicitud'] ?? 0);
        $postUrl = (string) ($context['postUrl'] ?? '');
        $errorMessage = trim((string) ($context['errorMessage'] ?? ''));
        ?>
        <div class="card border-0 shadow-sm h-100" id="ct-sol-comentarios-generales">
            <div class="card-body">
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger py-2 px-2 small mb-2"><?php echo ctEscape($errorMessage); ?></div>
                <?php endif; ?>
                <h3 class="h6 mb-1">Comentarios generales</h3>
                <div class="small text-muted mb-3">Solo para notas transversales. Las observaciones operativas van en cada bloque de área.</div>
                <?php if ($canComment): ?>
                    <form
                        method="post"
                        class="mb-3"
                        hx-post="<?php echo ctEscape($postUrl); ?>"
                        hx-target="#ct-sol-comentarios-generales"
                        hx-swap="outerHTML"
                    >
                        <?php ctCsrfField(); ?>
                        <input type="hidden" name="accion" value="agregar_comentario">
                        <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) $idSolicitud); ?>">
                        <input type="hidden" name="id_area_solicitud" value="0">
                        <input type="hidden" name="return_fragment" value="ct-sol-comentarios-generales">
                        <textarea name="comentario" class="form-control mb-2" rows="2" placeholder="Comentario general de la solicitud..."></textarea>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Agregar comentario general</button>
                    </form>
                <?php else: ?>
                    <div class="small text-muted mb-3">No tienes permisos para agregar comentarios generales.</div>
                <?php endif; ?>
                <div class="d-grid gap-2">
                    <?php if ($generalComentarios === []): ?>
                        <div class="small text-muted">Sin comentarios generales.</div>
                    <?php else: ?>
                        <?php foreach ($generalComentarios as $comentario): ?>
                            <?php
                            $estadoComentarioGeneralMeta = ctSolicitudesComentarioEstadoMeta((string) ($comentario['estado_revision'] ?? 'PENDIENTE'));
                            $comentarioGeneralResuelto = $estadoComentarioGeneralMeta['codigo'] === 'RESUELTO';
                            $idUsuarioResolucionGeneral = (int) ($comentario['id_usuario_resolucion'] ?? 0);
                            ?>
                            <div class="border rounded p-2">
                                <div class="small text-muted mb-1 d-flex justify-content-between align-items-center gap-2">
                                    <span>
                                    <?php echo ctEscape($usuariosMap[(int) ($comentario['id_usuario'] ?? 0)] ?? ('Usuario #' . (int) ($comentario['id_usuario'] ?? 0))); ?>
                                    | <?php echo ctEscape(ctSolicitudesViewDatetime((string) ($comentario['fecha_creacion'] ?? ''))); ?>
                                    </span>
                                    <span class="badge rounded-pill <?php echo ctEscape((string) $estadoComentarioGeneralMeta['badge']); ?>"><?php echo ctEscape((string) $estadoComentarioGeneralMeta['label']); ?></span>
                                </div>
                                <div class="small<?php echo $comentarioGeneralResuelto ? ' text-muted text-decoration-line-through' : ''; ?>"><?php echo nl2br(ctEscape((string) ($comentario['comentario'] ?? ''))); ?></div>
                                <?php if ($comentarioGeneralResuelto && trim((string) ($comentario['resuelto_en'] ?? '')) !== ''): ?>
                                    <div class="small text-muted mt-1">
                                        Resuelto: <?php echo ctEscape(ctSolicitudesViewDatetime((string) ($comentario['resuelto_en'] ?? ''))); ?>
                                        <?php if ($idUsuarioResolucionGeneral > 0): ?>
                                            | Por: <?php echo ctEscape($usuariosMap[$idUsuarioResolucionGeneral] ?? ('Usuario #' . $idUsuarioResolucionGeneral)); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!$comentarioGeneralResuelto && $canComment): ?>
                                    <form
                                        method="post"
                                        class="mt-2"
                                        hx-post="<?php echo ctEscape($postUrl); ?>"
                                        hx-target="#ct-sol-comentarios-generales"
                                        hx-swap="outerHTML"
                                    >
                                        <?php ctCsrfField(); ?>
                                        <input type="hidden" name="accion" value="resolver_comentario">
                                        <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) $idSolicitud); ?>">
                                        <input type="hidden" name="id_solicitud_comentario" value="<?php echo ctEscape((string) ($comentario['id_solicitud_comentario'] ?? 0)); ?>">
                                        <input type="hidden" name="id_area_solicitud" value="0">
                                        <input type="hidden" name="return_fragment" value="ct-sol-comentarios-generales">
                                        <button type="submit" class="btn btn-outline-success btn-sm">Marcar como resuelto</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
