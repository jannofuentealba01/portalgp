<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <style>
        footer {
            background-color: #3C4C4E; /* Color sólido proporcionado */
            color: #fff; /* Blanco para contraste */
            text-align: center;
            padding: 15px 20px;
            margin-top: 20px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            border-top: 2px solid;
        }
        footer p {
            margin: 0;
            font-size: 0.9em;
        }
        /* Estilo responsive */
        @media (max-width: 768px) { /* Para tablets y pantallas medianas */
            footer {
                padding: 10px 15px;
            }
            footer p {
                font-size: 0.8em; /* Reduce el tamaño del texto */
            }
        }
        @media (max-width: 480px) { /* Para celulares */
            footer {
                padding: 8px 10px; /* Reduce el padding */
            }
            footer p {
                font-size: 0.75em; /* Aún más pequeño en pantallas pequeñas */
            }
        }
    </style>
</head>
<body>
    <footer>
        <p>&copy; Grupo Patagual 2025. Todos los derechos reservados.</p>
    </footer>
</body>
</html>
