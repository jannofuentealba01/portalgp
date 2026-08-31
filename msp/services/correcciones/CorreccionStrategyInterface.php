<?php
declare(strict_types=1);
interface CorreccionStrategyInterface{public function supports(string $tipo):bool;public function analizar(PDO $conn,array $solicitud):array;public function ejecutar(PDO $conn,array $correccion,int $usuarioId):array;}
