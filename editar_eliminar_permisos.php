<?php
include 'db.php';
include 'permisos.php';
include 'Templates/header.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario'])) {
    echo "<script>alert('Debes iniciar sesión.'); window.location.href = 'login.php';</script>";
    exit();
}

// Validar si el usuario tiene el permiso necesario
if (!tienePermiso($_SESSION['usuario']['id'], 'Administrar Permisos')) {
    echo "<script>alert('No tienes permisos.'); window.location.href = '/portalgp/index.php';</script>";
    exit();
}

// Obtener todos los permisos
$permisos = $conn->query("SELECT * FROM cr_permisos")->fetchAll(PDO::FETCH_ASSOC);

// Manejar la edición de permisos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_permiso'])) {
    $id = $_POST['id'];
    $nombre_permiso = $_POST['nombre_permiso'];
    $descripcion = $_POST['descripcion'];

    $stmt = $conn->prepare("UPDATE cr_permisos SET nombre_permiso = :nombre_permiso, descripcion = :descripcion WHERE id = :id");
    $stmt->bindParam(':nombre_permiso', $nombre_permiso);
    $stmt->bindParam(':descripcion', $descripcion);
    $stmt->bindParam(':id', $id);

    if ($stmt->execute()) {
        echo "<script>alert('Permiso editado exitosamente.'); window.location.href = 'editar_eliminar_permisos.php';</script>";
    } else {
        echo "<script>alert('Error al editar el permiso.');</script>";
    }
}
// Manejar la eliminación de permisos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_permiso'])) {
    $id = $_POST['id'];

    // Verificar si el permiso está relacionado con algún rol
    $stmt_check = $conn->prepare("SELECT r.nombre_rol FROM cr_rol_permisos rp JOIN cr_roles r ON rp.rol_id = r.id WHERE rp.permiso_id = :id");
    $stmt_check->bindParam(':id', $id);
    $stmt_check->execute();
    $roles_asociados = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

    if (count($roles_asociados) > 0) {
        // Mostrar un mensaje de error indicando los roles asociados
        $roles_list = array_map(function($rol) {
            return $rol['nombre_rol'];
        }, $roles_asociados);
        $roles_string = implode(', ', $roles_list);
        echo "<script>alert('No se puede eliminar este permiso porque está asociado a los siguientes roles: $roles_string');</script>";
    } else {
        // Intentar eliminar el permiso si no está asociado
        $stmt = $conn->prepare("DELETE FROM cr_permisos WHERE id = :id");
        $stmt->bindParam(':id', $id);

        if ($stmt->execute()) {
            echo "<script>alert('Permiso eliminado exitosamente.'); window.location.href = 'editar_eliminar_permisos.php';</script>";
        } else {
            echo "<script>alert('Error al eliminar el permiso.');</script>";
        }
    }
}

?>

<div class="container">
    <h2>Editar y Eliminar Permisos</h2>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre del Permiso</th>
                <th>Descripción</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($permisos as $permiso): ?>
            <tr>
                <td><?= htmlspecialchars($permiso['id']) ?></td>
                <td><?= htmlspecialchars($permiso['nombre_permiso']) ?></td>
                <td><?= htmlspecialchars($permiso['descripcion']) ?></td>
                <td>
                    <button class="btn btn-warning btn-sm" onclick="abrirModalEdicion(<?= htmlspecialchars(json_encode($permiso), ENT_QUOTES, 'UTF-8') ?>)">Editar</button>
                    <form method="POST" style="display: inline-block;">
                        <input type="hidden" name="id" value="<?= $permiso['id'] ?>">
                        <button type="submit" name="eliminar_permiso" class="btn btn-danger btn-sm" onclick="return confirm('¿Estás seguro de eliminar este permiso?');">Eliminar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal para editar permiso -->
<div id="modalEdicion" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); justify-content: center; align-items: center;">
    <div style="background-color: white; padding: 20px; border-radius: 8px; width: 400px;">
        <h3>Editar Permiso</h3>
        <form method="POST">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label for="edit_nombre_permiso">Nombre del Permiso:</label>
                <input type="text" id="edit_nombre_permiso" name="nombre_permiso" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="edit_descripcion">Descripción:</label>
                <textarea id="edit_descripcion" name="descripcion" class="form-control" required></textarea>
            </div>
            <button type="submit" name="editar_permiso" class="btn btn-primary">Guardar Cambios</button>
            <button type="button" class="btn btn-secondary" onclick="cerrarModalEdicion()">Cancelar</button>
        </form>
    </div>
</div>

<script>
function abrirModalEdicion(permiso) {
    document.getElementById('edit_id').value = permiso.id;
    document.getElementById('edit_nombre_permiso').value = permiso.nombre_permiso;
    document.getElementById('edit_descripcion').value = permiso.descripcion;
    document.getElementById('modalEdicion').style.display = 'flex';
}

function cerrarModalEdicion() {
    document.getElementById('modalEdicion').style.display = 'none';
}
</script>

<style>
.container {
    max-width: 800px;
    margin: 50px auto;
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: center;
}
.table th {
    background-color: #007bff;
    color: white;
}
.btn {
    padding: 5px 10px;
    border: none;
    border-radius: 4px;
    color: white;
    cursor: pointer;
}
.btn-warning {
    background-color: #ffc107;
}
.btn-danger {
    background-color: #dc3545;
}
.btn-primary {
    background-color: #007bff;
}
.btn-secondary {
    background-color: #6c757d;
}
.btn:hover {
    opacity: 0.9;
}
</style>

<?php include 'Templates/footer.php'; ?>
