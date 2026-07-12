# Gestión del centro

Gestor del **día a día de un centro educativo (IES)**: planificación de tareas del profesorado por
rol y jerarquía, con avisos, escalado y validación por el superior. Cada persona entra y ve **qué
tiene que hacer hoy**; la dirección coordina el plan del centro.

> Nombre provisional. Fase 1 (sin datos de alumnado): los entregables son **referencias/enlaces** a la
> nube del centro, la app nunca guarda su contenido.

## Qué hace (Fase 1)

- **Inicio**: agenda personal "qué me toca hoy", agrupada por Vencidas / Hoy / Próximos 7 días /
  Más adelante, con casilla de hecho de 1 clic.
- **Tareas**: plan del curso + ficha con el ciclo de vida (empezar → entregar → validar/devolver) y su
  histórico de actividad.
- **CRUD de tareas** por jerarquía: cada quien crea/asigna para sí y para sus inferiores en la cadena de
  mando (organigrama de unidades + responsable).
- **Calendario** mensual del plan.
- **Avisos** in-app + motor de recordatorios (15/7 días antes) y escalado de vencidas.
- **Administración**: usuarios, roles/permisos y organigrama de unidades.
- **Trazabilidad**: cada cambio de una entidad se registra (actor + diff) y se ve en su ficha.

## Stack

Symfony 7.4 · PHP 8.3 · MySQL 8.0 · Twig (sin build; CSS/JS vanilla, sistema de diseño propio "cálido").
Autenticación **passwordless** (magic-link + SSO Google/Educamadrid). Entorno de desarrollo con **DDEV**.

## Arrancar en local

```bash
ddev start
ddev composer install
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/console doctrine:fixtures:load --no-interaction   # golden + demo (todo)
ddev launch
```

### Datos: golden / demo / real

Las fixtures son sintéticas (sin datos personales, seguras en git) y se dividen en dos grupos, como en
el proyecto ISO:

```bash
ddev exec php bin/console doctrine:fixtures:load                  # golden + demo (todo)
ddev exec php bin/console doctrine:fixtures:load --group=golden   # solo el esqueleto de producción (roles + catálogo de plantillas)
ddev exec php bin/console doctrine:fixtures:load --group=demo     # golden + datos de ejemplo (personas, plan…)
```

Una instancia local **realista** (esqueleto + claustro real, sin duplicados) =
`--group=golden` + el import del claustro (idempotente, upsert de los roles golden por código):

```bash
ddev exec php bin/console doctrine:fixtures:load --group=golden --no-interaction
ddev exec php bin/console app:import-roster fixtures/real/roster.csv   # datos reales (PII), gitignored
```

Los datos reales del centro (PII) **nunca** se siembran en git: viven bajo `fixtures/real/` (ignorado)
y se cargan con `app:import-roster` (ver `import/README.md`).

Login (passwordless): en `/login` introduce un correo de la demo —`director@centro.test` (dirección) o
`profe@centro.test` (docente)— y abre el **enlace mágico** en Mailpit (`ddev launch -m`).

## Calidad

CI en cada push/PR: **PHPUnit 12**, **PHPStan nivel 6** y **gitleaks** (escaneo de secretos).

```bash
ddev exec vendor/bin/phpstan analyse
ddev exec php bin/console lint:twig templates
```

## Estructura

Symfony estándar: `src/Entity`, `src/Repository`, `src/Controller`, `src/Service`, `src/Form`,
`src/EventSubscriber`, `templates/`, `public/css` + `public/js`. La máquina de estados de las tareas se
define en `config/packages/workflow.yaml` (Symfony Workflow).
