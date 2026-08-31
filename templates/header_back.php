<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: portalgp/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        header {
            background-color: #3C4C4E; /* Color sólido proporcionado */
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        nav ul {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            justify-content: flex-end;
            flex-wrap: wrap; /* Permite que los elementos se ajusten en pantallas pequeñas */
        }
        nav ul li {
            margin-left: 20px;
        }
        nav ul li a {
            color: #FFFFFF; /* Blanco para contraste */
            text-decoration: none;
            font-weight: bold;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        nav ul li a:hover {
            background-color: #2B3738; /* Versión más oscura del color */
        }
        /* Estilo responsive */
        @media (max-width: 768px) { /* Para tablets y pantallas más pequeñas */
            nav ul {
                justify-content: center; /* Centra los elementos */
            }
            nav ul li {
                margin-left: 10px; /* Reduce el espacio entre los elementos */
            }
        }
        @media (max-width: 480px) { /* Para celulares */
            nav ul {
                flex-direction: column; /* Cambia la dirección a vertical */
                align-items: center; /* Centra los elementos verticalmente */
            }
            nav ul li {
                margin: 5px 0; /* Reduce el margen para cada enlace */
            }
        }
    </style>
</head>
<body>
    <header>
        <nav>
            <ul>
                <li><a href="/portalgp/index.php">Inicio</a></li>
                <li><a href="/portalgp/profile.php">Mi perfil</a></li>
                <li><a href="/portalgp/logout.php">Cerrar sesi&oacute;n</a></li>
            </ul>
        </nav>
    </header>
</body>
</html>
