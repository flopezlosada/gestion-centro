# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Personal de un centro educativo público de secundaria (IES). Perfiles primarios:

- **Docente**: entra a diario, normalmente entre clases o al final de la jornada, para ver "qué me toca hoy" y marcar tareas como hechas con el mínimo de fricción. A menudo desde el móvil.
- **Jefe/a de departamento y otros roles con rango**: además de sus propias tareas, crean y supervisan tareas de sus inferiores en la cadena de mando (validar / devolver).
- **Dirección**: coordina el plan del centro, la administración (usuarios, roles, organigrama de unidades) y la visión global del curso.

El alcance hoy es un único centro (IES La Cabrera), pero el producto debe diseñarse sin cerrar la puerta a generalizarse a otros centros más adelante.

## Product Purpose

Gestor del día a día de un centro educativo: planificación y seguimiento de las tareas del profesorado por rol y jerarquía, con avisos, recordatorios, escalado de vencidas y validación por el superior. Cada persona ve qué tiene que hacer hoy; la dirección coordina el plan del centro. El éxito es que el claustro sepa sin esfuerzo qué le toca y cuándo, y que nada importante se pierda.

## Positioning

A diferencia de un gestor de tareas genérico, el modelo está construido sobre la **organización real de un centro educativo**: todo es un rol, algunos roles tienen rango jerárquico, y la responsabilidad de una tarea cae en cascada rol → departamento → persona. La superioridad es contextual por (rol, departamento), no un simple árbol de mando. Esto permite asignar, escalar y validar tareas siguiendo la cadena de mando docente real, algo que una herramienta de propósito general no puede replicar sin recrear ese modelo.

## Operating Context

- **Fase 1 (sin datos de alumnado):** el **entregable de una tarea** es una **referencia/enlace** a la nube del centro; la app nunca guarda su contenido. Excepción acotada y deliberada: los **documentos de coordinación** que el centro pidió tener dentro (la tarea que el profesor ausente deja para su grupo y el **acta de una reunión**) se guardan como fichero en almacenamiento **privado** (fuera del web root, con nombre aleatorio) y solo se sirven a quien tiene por qué leerlos.
- **Uso diario y móvil**: consulta rápida "qué me toca hoy" (agenda personal en dos bloques: Con hora = tus citas de hoy, Por hacer = tareas del centro vencidas o de hoy + recordatorios sin hora, con casilla de hecho de 1 clic), más un anticipo de los próximos 7 días; cualquier otro día se consulta en el Calendario.
- **Ciclo de vida de tarea** por máquina de estados (Symfony Workflow): Pendiente → Entregada → Finalizada + Cancelada; Devolver → Pendiente.
- **Calendario del curso** mensual, con días no lectivos que condicionan las fechas límite.
- **Avisos in-app** + motor de recordatorios (15/7 días antes) y escalado de vencidas.
- **Trazabilidad**: cada cambio de una entidad se audita (actor + diff) y se ve en su ficha.
- **Módulo de guardias**: reparto y cobertura de guardias del profesorado, con import desde Peñalara.
- **Reuniones y proyectos**: convocatoria con día, hora, lugar, orden del día y convocados; su **acta** se sube a la ficha de la reunión. Los **proyectos** del centro (agrupación de profesorado con coordinación, distinta de un departamento) aportan por defecto sus profes al convocar.
- Datos del claustro con origen en el PDF público de docentes del centro; los datos reales (PII) nunca viven en git.

## Capabilities and Constraints

- **Stack**: Symfony 7.4 · PHP 8.3 · MySQL 8.0 · Twig sin build (CSS/JS vanilla, sistema de diseño propio). PWA con notificaciones push (VAPID). Entorno de desarrollo con DDEV; despliegue de staging en cdmon (MariaDB 10.11, apache-pack).
- **Autenticación passwordless**: magic-link por correo + SSO Google/Educamadrid. Sin contraseñas.
- **Modelo de organización (cerrado)**: departamento (no "unit"), todo es Role, algunos con rango jerárquico; superioridad contextual por (rol, departamento). Es la base de todo el dominio.
- **Acciones por rol**: el superior solo supervisa (validar/devolver), no ejecuta ni delega la tarea de otro; delegar es potestad del titular. Los roles asignables al crear una tarea se filtran por jerarquía.
- **Terminología**: "departamento", "rol", "guardia", "plan del curso", "entregable" (= enlace, no fichero), "reunión", "convocar", "acta" (= fichero), "proyecto" (≠ departamento).
- **Sin build de assets**: cualquier trabajo de diseño se sirve con CSS/JS vanilla desde `public/`, sin bundler.

## Brand Commitments

- Nombre **provisional** ("Gestión del centro"); no hay nombre, logo ni identidad institucional fijos. El diseño puede proponer identidad libremente.
- Existe un sistema de diseño propio incumbente en el código (identidad "cálida"), que es evidencia visual, no un compromiso de marca vinculante.
- Voz: cercana, clara y en español (con corrección ortográfica completa, tildes incluidas).

## Evidence on Hand

- `README.md` — descripción funcional de Fase 1, stack y flujos de datos (golden / demo / real).
- `docs/investigacion-ux-gestores-tareas.md` — investigación UX de gestores de tareas.
- `catalogo/` — catálogo real de tareas de centro usado por `app:seed-demo`.
- Claustro real: PDF público de docentes del IES (import idempotente vía `app:import-roster`).
- No existen testimonios, métricas de uso, casos de estudio ni clientes: el trabajo futuro no debe inventarlos.

## Product Principles

1. **"Qué me toca hoy" primero**: la fricción de consultar y marcar hecho debe ser mínima, pensada para el móvil entre clases.
2. **La jerarquía docente es el modelo, no un adorno**: asignación, escalado y validación siguen la cadena de mando real (rol/departamento contextual).
3. **La app no es el archivo del centro**: guarda coordinación y referencias, no el contenido de los entregables (Fase 1). Lo único que custodia son los documentos que la propia coordinación necesita a mano —el acta de una reunión, la tarea dejada para una guardia—, y siempre en almacenamiento privado y con el acceso acotado a su grupo.
4. **Nada importante se pierde**: recordatorios, escalado de vencidas y trazabilidad son parte del contrato, no extras.
5. **A medida hoy, sin cerrar la generalización**: decisiones de diseño válidas para La Cabrera pero que no impidan servir a otros centros.

## Accessibility & Inclusion

Requisito **vinculante**: al ser un centro público, aplica la normativa de accesibilidad de las administraciones (EN 301 549 → **WCAG 2.1 AA**). Todo el trabajo de diseño debe cumplir AA como suelo (contraste, foco visible, navegación por teclado, semántica, objetivos táctiles adecuados para uso móvil).
