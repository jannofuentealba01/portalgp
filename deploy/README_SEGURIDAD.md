# Despliegue seguro de PortalGP

## Fuente y separación de entornos

- Desarrollo se integra en `dev`.
- AWS se despliega exclusivamente desde `codex/msp-aws-deploy`.
- El servidor debe tener el árbol Git limpio y el commit desplegado debe quedar
  registrado en `/var/lib/portalgp/deployed_commit`.
- Los secretos, certificados, adjuntos y configuración del sistema operativo
  nunca se guardan en Git.
- Los archivos de `deploy/nginx/` son plantillas versionadas. En una migración
  se adaptan al servidor web de destino sin copiar credenciales.
- Los archivos de `deploy/php-fpm/`, `deploy/ssh/` y `deploy/fail2ban/`
  separan PortalGP del resto del servidor sin guardar llaves ni direcciones IP.
- Ningún script modifica archivos de la aplicación `it-conecta`.

## Fases

1. Ejecutar el modo `bootstrap` para bloquear artefactos internos y habilitar
   el desafío ACME sin interrumpir HTTP.
2. Emitir y probar el certificado de IP con Certbot 5.4 o superior, perfil
   `shortlived` y renovación automática.
3. Ejecutar el modo `https` para crear el servidor TLS separado y convertir
   únicamente `/portalgp` en una redirección HTTPS.
4. Ejecutar `verify_portalgp_security.sh` y conservar el SHA informado.
5. Instalar las plantillas de host con `apply_host_security_stage4.sh`; comprobar
   una nueva conexión SSH antes de retirar cualquier llave administrativa.

## Uso

```bash
cd /var/www/portalgp
sudo bash deploy/scripts/deploy_portalgp_security.sh bootstrap
sudo bash deploy/scripts/deploy_portalgp_security.sh https 15.229.113.179
bash deploy/scripts/verify_portalgp_security.sh https://15.229.113.179
sudo bash deploy/scripts/apply_host_security_stage4.sh
```

La emisión inicial del certificado se realiza fuera del script porque requiere
aceptar el acuerdo de suscriptor de la autoridad certificadora y definir un
contacto operativo. Esa decisión no debe quedar implícita en un despliegue.

La restricción de SSH por dirección IP se administra en el firewall del
proveedor una vez definida una IP administrativa estable o una VPN. El script
no adivina esa dirección para evitar bloquear el acceso al servidor.
