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
if (!tienePermiso($_SESSION['usuario']['id'], 'Administrar Usuarios')) {
    echo "<script>alert('No tienes permisos.'); window.location.href = '/portalgp/index.php';</script>";
    exit();
}

// Consulta principal con JOIN para obtener usuarios y sus roles
$usuarios = $conn->query("SELECT u.*, r.nombre_rol FROM cr_usuarios u INNER JOIN cr_roles r ON u.rol_id = r.id")->fetchAll(PDO::FETCH_ASSOC);

// Obtener roles y estados para los selects
$roles = $conn->query("SELECT id, nombre_rol FROM cr_roles")->fetchAll(PDO::FETCH_ASSOC);
$estados = $conn->query("SELECT id, estado FROM cr_estado_usuario")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Agregar Bootstrap CSS (si no está incluido en el header) -->
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

<style>
    /* Estilos personalizados (mismo diseño que editar_marca.php) */
    body {
        background-color: #f0f2f5;
    }

    .editar-usuario-container {
        max-width: 2000px!important;
        margin: 30px auto;
        padding: 20px;
        background-color: #ffffff;
        border-radius: 15px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }

    .editar-usuario-container h1 {
        text-align: center;
        margin-bottom: 30px;
        font-weight: bold;
        color: #343a40;
    }

    .btn-volver {
        background-color: #6c757d;
        color: #fff;
        border-radius: 4px; /* Ajusta el radio para que sea más rectangular */
        padding: 10px 20px; /* Ajusta el tamaño interno del botón */
        border: none; /* Remueve bordes predeterminados si es necesario */
        cursor: pointer; /* Muestra un cursor de mano al pasar sobre el botón */
        font-size: 16px; /* Ajusta el tamaño del texto */
        transition: all 0.3s ease;
    }

    .btn-volver:hover {
        background-color: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .modelo-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        margin-bottom: 15px;
        background-color: #f8f9fa;
        border-radius: 10px;
        transition: background-color 0.3s ease;
    }

    .modelo-item:hover {
        background-color: #e9ecef;
    }

    .modelo-nombre {
        font-size: 1.2rem;
        font-weight: 500;
        color: #343a40;
    }

    .marca-nombre {
        font-size: 1rem;
        color: #6c757d;
    }

    .actions button,
    .actions form {
        margin-left: 10px;
    }

    .btn-editar {
		border-radius: 4px !important; /* Ajusta el radio para que sea más rectangular */
        padding: 10px 20px !important; /* Ajusta el tamaño interno del botón */
        border: none !important; /* Remueve bordes predeterminados si es necesario */
        cursor: pointer !important; /* Muestra un cursor de mano al pasar sobre el botón */
        font-size: 16px !important; /* Ajusta el tamaño del texto */
	}
    .btn-eliminar {
        background-color: #dc3545;
        color: #fff;
		border-radius: 4px; /* Ajusta el radio para que sea más rectangular */
        padding: 10px 10px; /* Ajusta el tamaño interno del botón */
        border: none; /* Remueve bordes predeterminados si es necesario */
        cursor: pointer; /* Muestra un cursor de mano al pasar sobre el botón */
        font-size: 16px; /* Ajusta el tamaño del texto */
    }

    .btn-editar {
        background-color: #17a2b8;
        color: #fff;
    }

    .btn-editar:hover {
        background-color: #138496;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .btn-eliminar {
        background-color: #dc3545;
        color: #fff;
    }

    .btn-eliminar:hover {
        background-color: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .edit-field {
        display: flex;
        align-items: center; /* Alinea verticalmente al centro */
        justify-content: flex-start; /* Cambia a "center" si los botones deben centrarse horizontalmente */
        gap: 10px; /* Espacio entre los elementos */
	 }

   .edit-field input {
        flex: 1; /* Esto hace que el ancho sea flexible con respecto al contenedor */
        adding: 8px 10px; /* Ajusta el espacio interno */
        font-size: 14px; /* Ajusta el tamaño del texto */
        border: 1px solid #ced4da; /* Añade un borde visible */
        border-radius: 4px; /* Esquinas ligeramente redondeadas */
        text-transform: uppercase; /* Mantiene el texto en mayúsculas */
        width: 300px; /* Cambia el ancho aquí */
		height: 40px; /* Ajusta el alto */
}

    }

    .edit-buttons button {
        padding: 8px 16px;
        font-size: 1rem;
        font-weight: bold;
        border: none;
        border-radius: 50px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-guardar {
        background-color: #28a745;
        color: #fff;
		border-radius: 4px; /* Ajusta el radio para que sea más rectangular */
        padding: 10px 20px; /* Ajusta el tamaño interno del botón */
        border: none; /* Remueve bordes predeterminados si es necesario */
        cursor: pointer; /* Muestra un cursor de mano al pasar sobre el botón */
        font-size: 16px; /* Ajusta el tamaño del texto */
    }

    .btn-guardar:hover {
        background-color: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .btn-descartar {
        background-color: #6c757d;
        color: #fff;
		border-radius: 4px; /* Ajusta el radio para que sea más rectangular */
        padding: 10px 20px; /* Ajusta el tamaño interno del botón */
        border: none; /* Remueve bordes predeterminados si es necesario */
        cursor: pointer; /* Muestra un cursor de mano al pasar sobre el botón */
        font-size: 16px; /* Ajusta el tamaño del texto */
    }

    .btn-descartar:hover {
        background-color: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .error-message {
        color: red;
        font-size: 1rem;
        text-align: center;
        margin-top: 20px;
    }
	body {
        font-family: Arial, sans-serif;
        background-color: #f4f4f4;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        min-height: 100vh; /* Altura mínima igual a la altura de la ventana */
    }

   .editar-usuario-container {
        flex: 1; /* Esto asegura que el contenido principal ocupe el espacio restante */
    }

    .footer {
        background-color: #007bff;
        color: white;
        text-align: center;
        padding: 1000px;
        font-size: 14px;
        margin-top: auto; /* Empuja el footer hacia abajo */
}

	
</style>

<div class="editar-usuario-container" style="max-width: 1100px; margin: 50px auto; text-align: center;">
    <h2 style="text-align: center; color: #333; margin-bottom: 20px;">Gesti&oacute;n de Usuarios</h2>

    <table class="table" style="width: 100%; border-collapse: collapse; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
        <thead style="background-color: #20c997; color: white;">
            <tr>
                <th>ID</th>
                <th>Nombre de Usuario</th>
                <th>Nombre Completo</th>
                <th>Correo Electr&oacute;nico</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $usuario): ?>
            <tr>
                <td><?= $usuario['id'] ?></td>
                <td><?= htmlspecialchars($usuario['UserName'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($usuario['nombre_completo'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($usuario['correo_electronico'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($usuario['nombre_rol'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $usuario['estado_id'] == 1 ? 'Habilitado' : 'Inhabilitado' ?></td>
                <td>
                    <button class="btn btn-editar" onclick='abrirModal(<?= json_encode($usuario, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Editar</button>
                    <?php
                    $stmt_movements = $conn->prepare("SELECT COUNT(*) FROM cr_prestamos WHERE responsable_id = :id");
                    $stmt_movements->bindParam(':id', $usuario['responsable_id']);
                    $stmt_movements->execute();
                    $has_movements = $stmt_movements->fetchColumn() > 0;
                    ?>
                    <form action="procesar_eliminar_usuario.php" method="POST" onsubmit="return verificarEliminacion(<?= $has_movements ? 'true' : 'false' ?>)" style="display: inline;">
                        <input type="hidden" name="id" value="<?= $usuario['id'] ?>">
                        <button type="submit" class="btn btn-eliminar">Eliminar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal de edición -->
<div id="modalEdicion" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); justify-content: center; align-items: center;">
    <div style="background-color: white; padding: 20px; border-radius: 8px; width: 400px; position: relative;">
        <h3>Editar Usuario</h3>
        <form id="formEdicion" action="procesar_editar_usuario.php" method="POST">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Nombre de Usuario:</label>
                <input type="text" id="edit_username" readonly style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div class="form-group">
                <label>Nombre Completo:</label>
                <input type="text" name="nombre_completo" id="edit_nombre_completo" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div class="form-group">
                <label>Correo Electr&oacute;nico:</label>
                <input type="email" name="correo_electronico" id="edit_correo_electronico" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div class="form-group">
                <label>Contrase&ntilde;a (Dejar en blanco para no cambiar):</label>
                <input type="password" name="password_hash" id="edit_password" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div class="form-group">
                <label>Rol:</label>
                <select name="rol_id" id="edit_rol" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <?php foreach ($roles as $rol): ?>
                        <option value="<?= $rol['id'] ?>"><?= $rol['nombre_rol'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Estado:</label>
                <select name="estado_id" id="edit_estado" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <?php foreach ($estados as $estado): ?>
                        <option value="<?= $estado['id'] ?>"><?= $estado['estado'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top: 15px; display: flex; justify-content: space-between;">
			    <button type="submit" class="btn btn-guardar" style="padding: 10px 20px;">Guardar</button>
                <button type="button" onclick="cerrarModal()" class="btn btn-descartar" style="padding: 10px 20px;">Cancelar</button>
                
            </div>
        </form>
    </div>
</div>

<script>
function abrirModal(usuario) {
    document.getElementById("edit_id").value = usuario.id;
    document.getElementById("edit_username").value = usuario.UserName;
    document.getElementById("edit_nombre_completo").value = usuario.nombre_completo;
    document.getElementById("edit_correo_electronico").value = usuario.correo_electronico;
    document.getElementById("edit_password").value = "";
    document.getElementById("edit_rol").value = usuario.rol_id;
    document.getElementById("edit_estado").value = usuario.estado_id;
    document.getElementById("modalEdicion").style.display = "flex";
}

function cerrarModal() {
    document.getElementById("modalEdicion").style.display = "none";
}

function verificarEliminacion(hasMovements) {
    if (hasMovements) {
        alert('No se puede eliminar este usuario porque tiene movimientos asociados. Puede deshabilitarlo en su lugar.');
        return false;
    }
    return confirm('¿Seguro que deseas eliminar este usuario?');
}
</script>

<?php include 'Templates/footer.php'; ?>
