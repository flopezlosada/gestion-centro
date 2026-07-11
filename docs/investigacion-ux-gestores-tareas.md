# Investigación UX: gestores de tareas (para decidir la dirección del producto)

Objetivo: que ~70 profes con poca apetencia tecnológica **entren, vean "qué tengo que hacer" y lo hagan**, sin fricción, rompiendo la resistencia al cambio. La usabilidad es el criterio nº 1: si no es claro y rápido, no lo usan.

Nota clave de contexto: en nuestro caso **las tareas las asigna la dirección** (por rol/persona), no las captura el profe. Eso nos acerca al modelo "**Assigned to me**" (Microsoft To Do/Planner, Asana My Tasks), no al de un to-do personal libre.

## 1. Paradigmas de organización

| Paradigma | Qué es | Encaje con nuestro caso |
| --- | --- | --- |
| **Hoy / Próximo (agenda por fecha)**  | Lista centrada en lo que vence hoy y en los próximos días  | **ALTO** — es el corazón del "qué me toca"  |
| **Assigned to me** | Lo que otros te han asignado, en un sitio  | **ALTO** — nuestras tareas nacen asignadas por dirección  |
| **Calendario / time-blocking**  | Ver/planificar en rejilla mensual/semanal  | **MEDIO** — buena vista global del curso (mes)  |
| **Listas por proyecto**  | Agrupar por área/proyecto  | MEDIO — como filtro secundario (por unidad)  |
| **Tableros Kanban**  | Columnas por estado, arrastrar tarjetas  | BAJO — potente pero "de gestor de proyecto", intimida a quien no lo usa  |
| **Tabla/base de datos**  | Rejilla tipo hoja de cálculo  | BAJO — para admin, no para el día a día del profe  |

**Conclusión:** para el profe, **agenda por fecha ("Hoy") + "asignado a mí"**. Kanban/tabla se reservan (si acaso) para dirección.

## 2. La vista de aterrizaje ("qué me toca hoy")

Lo que hacen bien Todoist ("Today"), Things ("Today") y MS To Do ("My Day"):

- **Una sola pantalla con lo de hoy**, no el catálogo entero → reduce carga cognitiva ("un menú manejable de lo comprometido, sin culpa por lo demás").
- **Agrupación por tiempo**: Vencidas · Hoy · Próximos 7 días · Más adelante.
- **Lo prioritario arriba** (Todoist marca P1 en rojo al principio).
- **Solo aparece lo que tiene fecha** (regla explícita en Todoist; sin fecha = no está en Hoy).
- **Trato de las vencidas: amable, no acusón.** Contraste clave: Todoist las pinta en rojo "mirándote fijamente"; **Things es suave y es de lo MÁS elogiado** — *"una lista que te hace sentir mal es una lista que dejas de abrir"*. Para profes reticentes, el tono suave es estratégico.
- MS To Do: **"My Day" empieza en blanco cada día** (arranque limpio, eliges qué afrontar) y sugiere lo pendiente de ayer con un toque.

## 3. Acciones de baja fricción (imprescindibles)

- **Marcar hecho en 1 clic desde la lista**, sin abrir la tarea. Es la acción nº 1; su fricción define si se usa.
- **Aplazar/reprogramar fácil** (en nuestro caso la fecha la pone dirección, así que esto se sustituye por "avisar/escalar", no por que el profe mueva la fecha).
- **Captura desde el correo** (en Outlook/Gmail marcas un email y se vuelve tarea) — "zero-copy". En nuestro caso las tareas ya nacen dadas, así que **evitamos el mayor problema de adopción de raíz** (ver §5, "doble entrada").
- **Móvil**: el profe mira el teléfono; tiene que verse perfecto en móvil.

## 4. Onboarding y romper la resistencia al cambio

De la investigación de adopción (fuentes abajo), lo que de verdad mueve la aguja:

- **Que la herramienta NO añada trabajo.** El fracaso nº 1 es el "**problema de la doble entrada**": si tienen que apuntar lo mismo en dos sitios, la abandonan. *Ventaja nuestra enorme:* las tareas ya vienen dadas por dirección; el profe **solo consume y marca hecho**, no captura → cero doble entrada.
- **"¿Qué cambia para mí el lunes?"** debe ser obvio: *entras y ves tus tareas de hoy*. Si no lo ven claro, vuelven a su método viejo.
- **Empezar pequeño**, un flujo de alto impacto (la agenda "Hoy"), no 20 funciones.
- **Ayuda en contexto dentro de la app** (tips/estados vacíos que guían) > manual denso. Nadie lee manuales.
- **Valores por defecto sensatos** y tono que no haga sentir torpe (miedo a "parecer incompetente" es un bloqueo real).
- **Familiaridad**: patrones que ya conocen (lista con casillas, calendario). Nada de jerga de gestor de proyectos.
- **No lanzar en pico** (inicio de curso/exámenes); la dirección debe **usarla también** (si jefatura no la usa, nadie la usa).

## 5. Errores de UX que matan la adopción

1. **Doble entrada / trabajo duplicado** (el mayor). — *Lo evitamos: tareas asignadas, no capturadas.*
2. **Sobrecarga de funciones**: "más features = mejor" es mentira; confunde. MVP mínimo.
3. **Fricción por clics de más / expectativas poco claras**.
4. **Tono que castiga** (rojos por todo, culpa) → dejan de abrirla.
5. **Cambiar demasiadas cosas a la vez** / migración que da miedo (perder datos).
6. **Que la comunicación viva en otro sitio** que el trabajo (context switching).

Estándar de éxito, textual: *"se adopta cuando trabajar EN la herramienta es más fácil que trabajar SORTEÁNDOLA"*.

## 6. Recomendación para nuestro caso

**Dirección elegida (prioridad 1): Inicio = agenda personal "Hoy" centrada en el profe.**
- Pantalla de aterrizaje = **Mis tareas** agrupadas: **Vencidas (suave) · Hoy · Próximos 7 días · Más adelante**, con lo obligatorio primero.
- **1 clic para "Hecho"** en cada fila (sin abrir la tarea).
- Tono **calmado** (identidad cálida ya elegida), vencidas sin dramatismo (estilo Things).
- **Estados vacíos que guían** ("no tienes nada hoy ✓ / cuando dirección te asigne algo, aparecerá aquí").

**Prioridad 2: Vista de calendario mensual** (el "plan del centro"/curso) para ver el mapa temporal — familiar y da visión global.

**Prioridad 3 (dirección):** vista de seguimiento (quién va al día / retrasado) — es de gestión, no del día a día del profe; mantenerla separada.

**Navegación mínima:** Hoy (inicio) · Calendario · Avisos · (Admin, solo dirección). Nada más de momento.

### Quick wins de alto impacto / bajo coste
1. Reconvertir el inicio en **agenda "Hoy/Semana/Más adelante"** (hoy es un dashboard genérico).
2. **Botón "Hecho" en 1 clic** en cada tarea de la lista.
3. **Vencidas con tono suave** (ámbar, no rojo agresivo; sin culpa).
4. **Estados vacíos que enseñan** qué es y qué esperar.
5. **Responsive de verdad** (móvil primero en la agenda).
6. Micro-ayuda en contexto (una línea por pantalla), no manual.

## Fuentes
- Todoist — Today view (planificar el día, vencidas, prioridad): https://www.todoist.com/help/articles/plan-your-day-with-the-todoist-today-view-UVUXaiSs
- Things vs Todoist (trato suave de vencidas, elogiado): https://alltech.medium.com/things-3-vs-todoist-picking-the-right-task-manager-for-how-you-actually-work-27e05239533a
- Microsoft To Do vs Planner / "My Day" + "Assigned to me": https://support.microsoft.com/en-us/todo/to-do-vs-planner y https://support.microsoft.com/en-us/planner/training/manage-your-tasks-with-my-tasks-and-my-day
- Por qué fracasa la adopción (doble entrada, hábitos, miedos): https://taskboard.dev/blog/project-management-tool-adoption/ y https://www.suebehaviouraldesign.com/en/blog/why-software-adoption-fails/
- Resistencia al cambio / adopción en educación (empezar pequeño, ayuda en contexto, no lanzar en pico): https://whatfix.com/blog/causes-of-resistance-to-change/ y https://www.raftr.com/overcoming-resistance-to-technology-in-higher-education/
