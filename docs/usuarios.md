# Usuarios y contraseñas

## Contraseña asignada por el administrador

En **Administración → Usuarios**, el administrador define la contraseña:

- **Al crear un usuario:** escribe la contraseña y la confirma. Ya no se envía un enlace por correo. La persona entra con su correo y esa contraseña, y puede cambiarla después en su perfil.
- **Para restablecerla:** el botón de la llave en la fila del usuario abre "Asignar contraseña". La contraseña anterior deja de servir y se cierran las otras sesiones abiertas de esa persona, incluido "recordarme".

En producción la contraseña debe tener mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos, y no puede aparecer en filtraciones conocidas. En Auditoría queda "Contraseña asignada por el administrador", con quién la cambió y a quién. La contraseña nunca se guarda en la auditoría ni en los logs.

`php artisan app:create-admin` sigue igual: es para el primer administrador en producción.

## Un usuario por rol para revisar permisos

```
php artisan db:seed --class=RoleUsersSeeder
```

| Correo | Rol |
|---|---|
| `admin@example.com` | Administrador del sistema |
| `trazabilidad@example.com` | Administrador de trazabilidad |
| `supervisor.produccion@example.com` | Supervisor de producción |
| `calidad@example.com` | Calidad |
| `operario@example.com` | Operario de producción |
| `logistica@example.com` | Operario logístico |
| `consulta@example.com` | Consulta |

- La contraseña sale de `ROLE_USERS_PASSWORD`.
- Fuera de producción, si está vacía, es `password`. `php artisan migrate --seed` los crea solo en local.
- En producción `ROLE_USERS_PASSWORD` es obligatoria y debe cumplir la política. Si falta, el seeder no crea nada. Después de las pruebas, conviene desactivar estos usuarios o cambiarles la contraseña desde Usuarios.
- Si un correo ya existe, ese usuario no se toca: ni su contraseña ni su rol.
