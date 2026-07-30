# Gestión de espacios — plan y modelo de datos

> Diseño del apartado **C** del volcado de requisitos del centro (30-07-2026): puntos 11, 12, 13 y 14.
> Sin código todavía. Este documento se cierra con decisiones abiertas que hay que resolver antes de
> tocar `src/`.

---

## 1. La tesis: es un solo mecanismo, no tres

El centro describe tres necesidades distintas:

| Caso | Cómo lo cuentan ellos |
|---|---|
| Cambio de aula puntual | Talleres externos, pruebas externas, exámenes ocupan aulas y hay que recolocar grupos |
| Semana de exámenes de 2º de Bachillerato | Los exámenes se hacen en las aulas de Inglés, así que Inglés se queda sin aula |
| Jornadas culturales / de igualdad | Horario alternativo con talleres, preasignando profesorado según su horario habitual |

Los tres son **la misma secuencia**:

```
enunciado (qué ocupa qué, en qué fechas y franjas, y a quién sustituye el horario ordinario)
   → el motor genera N alternativas con criterios explicables
   → el equipo directivo compara, elige una y la edita a mano
   → aprueba
   → se avisa a los afectados
   → se publica un documento imprimible y colgable
```

Lo único que cambia entre casos es **qué motor genera las alternativas** (reubicar clases desalojadas
vs. repartir talleres entre el profesorado). Todo lo demás —enunciado, alternativas, edición, estados,
aviso, documento— es común. El diseño lo trata como una entidad única (`SpacePlan`) con dos
generadores intercambiables.

**Un cuarto caso llega gratis**: el punto 2 del apartado A ("documento de aulas libres" para agrupar
guardias) y el punto 3 ("mover al docente que da clase en biblioteca y avisarle del cambio de aula por
motivos organizativos") son consultas y planes de este mismo módulo. No hay que programarlos aparte.

---

## 2. Qué hay hoy y qué falta (verificado, no supuesto)

**Lo que ya existe y sirve:**

- `ScheduleEntry` (`src/Entity/ScheduleEntry.php`) — la rejilla semanal por curso: docente × día × tramo,
  con `group_name`, `room_name`, `subject_name`. **Esta tabla ya contiene la ocupación completa de aulas**:
  toda celda lectiva trae aula.
- `ScheduleEntryRepository::distinctSlots()` — el marco horario (los tramos con sus horas).
- `NotificationDispatcher` — avisar a una persona (in-app + push + email) ya está resuelto, con
  `AccessGate` incluido.
- `Area` + `AreaVoter` + matriz de permisos por rol — el módulo nuevo encaja como un área más.
- `AcademicYear` — todo cuelga del curso, igual que el horario.

**Lo que falta y hay que crear:**

- **No existe catálogo de espacios.** El aula es hoy un `string` denormalizado (`room_name`) sin ficha:
  ni tipo, ni capacidad, ni edificio/planta.
- **Peñalara no aporta esos datos.** Verificado sobre `catalogo/planificador.xml` real: cada `<aula>`
  trae solo `nombre`, `abreviatura`, `descripcion`, `dedicada`, `claveDeExportacion`. **Ni capacidad ni
  tipo.** Tampoco hay matrícula por grupo. Ver §9, decisión abierta 1.
- **La escala es pequeña y eso es una buena noticia**: en el horario real del curso hay **40 aulas
  distintas en uso** (de 579 declaradas en el planificador, la mayoría basura) y **39 grupos**. Un
  catálogo de 40 fichas se rellena a mano en una tarde y el motor de propuestas es computacionalmente
  trivial: nada de resolutores de restricciones.
- **No hay capa de excepciones por fecha.** `ScheduleEntry` es *semanal*; un cambio de aula del martes 3
  de marzo no se puede escribir ahí sin corromper todas las semanas del curso. Esto obliga a la pieza
  central del diseño: la **rejilla efectiva** (§5).

---

## 3. Vocabulario

- **Espacio** (`Room`): aula, laboratorio, taller, gimnasio, pista, salón de actos, biblioteca.
- **Ocupación**: qué espacio está pillado en una (fecha, tramo) y por quién.
- **Horario ordinario**: lo que dice `ScheduleEntry` para el día de la semana de esa fecha.
- **Rejilla efectiva**: el horario ordinario **más** las excepciones de los planes aprobados. Es lo que
  hay que mirar para saber qué pasa de verdad un día concreto.
- **Plan** (`SpacePlan`): el expediente completo de una alteración (enunciado + alternativas + decisión).
- **Alternativa** (`SpacePlanOption`): una propuesta completa y autocontenida.
- **Línea** (`SpacePlanAssignment`): "el martes a 3.ª hora, E1B se va de 2IN5 a 0LC7".

---

## 4. Modelo de datos

Seis entidades. Cada una existe por una razón distinta; ninguna es un contenedor de conveniencia.

### 4.1 `Room` — catálogo de espacios (tabla `room`)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `code` | string(64) UNIQUE | La abreviatura de Peñalara: `2IN5`, `S ACTOS`, `LABQ`. Clave de cruce con el horario |
| `name` | string(128) | Nombre humano: "Aula de Inglés 5" |
| `kind` | enum `RoomKind` | `CLASSROOM`, `LAB`, `WORKSHOP`, `COMPUTER_ROOM`, `GYM`, `OUTDOOR`, `LIBRARY`, `ASSEMBLY_HALL`, `OTHER` |
| `capacity` | smallint NULL | A mano. Null = desconocida (no se inventa) |
| `building` | string(32) NULL | Para el criterio "no cruzar el centro" |
| `floor` | smallint NULL | Idem |
| `assignable` | bool, default true | ¿Puede recibir una clase reubicada? La biblioteca sí, las pistas no |
| `active` | bool, default true | Un aula que deja de usarse no se borra (rompería el histórico) |
| `notes` | text NULL | "Tiene proyector, no tiene enchufes" |

`Auditable`: **sí**. Es catálogo editado a mano y su capacidad decide reubicaciones.

**Cómo se cruza con el horario — decisión de diseño (recomendada):** añadir a `ScheduleEntry` una FK
`room_id` NULL (null legítimo: guardias y celdas sin aula), **manteniendo `room_name` como snapshot
textual**, y que el importador haga *upsert* de la ficha por `code` normalizado cuando encuentre un aula
nueva (`kind = OTHER`, `capacity = null`, marcada como "sin catalogar" en la UI).

- *Por qué*: si la ocupación se calcula comparando cadenas, un espacio de más o una mayúscula distinta
  hace que un aula ocupada aparezca libre **en silencio**, y el error se descubre con dos grupos en la
  misma aula. Es un footgun que se arregla a nivel de tipo, no con un aviso.
- *Coste*: una migración sobre `schedule_entry` y tocar `TimetableImporter`, que es código delicado
  (idempotencia, `source`, reconciliación). Se hace en la Fase 1, con el importador ya estable.
- *Alternativa descartada*: cruzar solo por texto. Más barata hoy, silenciosamente incorrecta mañana.

### 4.2 `SpacePlan` — el expediente (tabla `space_plan`)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `academic_year_id` | FK NOT NULL | Igual que el horario |
| `kind` | enum `SpacePlanKind` | `ROOM_CHANGE`, `EXAM_PERIOD`, `SPECIAL_DAY`. **Solo etiqueta y preset de formulario**, no ramas de lógica |
| `title` | string(160) | "Talleres de Cruz Roja, 3–5 de marzo" |
| `public_reason` | string(255) NULL | El texto que sale en el aviso y en el documento ("por motivos organizativos") |
| `internal_notes` | text NULL | Lo que no se publica |
| `date_from`, `date_to` | date NOT NULL | Un solo día = ambas iguales |
| `slot_from`, `slot_to` | smallint NULL | Franjas afectadas; null = jornada completa |
| `substitution_scope` | enum | `NONE` \| `GROUPS` \| `WHOLE_CENTRE` — **la clave que unifica los tres casos** |
| `scope_group_names` | json NULL | Los grupos cuyo horario ordinario queda sustituido (si `GROUPS`) |
| `status` | enum `SpacePlanStatus` | `DRAFT`, `PROPOSED`, `APPROVED`, `CANCELLED` |
| `chosen_option_id` | FK NULL | La alternativa elegida |
| `created_by_id` | FK NOT NULL | |
| `approved_by_id` / `approved_at` | FK NULL / datetime NULL | Sale impreso en el documento |
| `notified_at` | datetime NULL | Cuándo se avisó a los afectados |
| `published_at` / `public_token` | datetime NULL / string(64) NULL UNIQUE | El enlace público; revocable poniéndolos a null |

`Auditable`: **sí**.

Índices: `(academic_year_id, date_from, date_to)`, `(status)`, UNIQUE `(public_token)`.

**`substitution_scope` es la pieza que hace que un mecanismo cubra los tres casos:**

- Cambio de aula puntual → `NONE`. El horario ordinario sigue vigente; solo cambia dónde.
- Semana de exámenes de 2º Bach → `GROUPS` con los grupos de 2º. Su horario ordinario no aplica esos
  días; las clases de Inglés desalojadas se reubican como líneas del plan.
- Jornadas culturales → `WHOLE_CENTRE`. Ese día no hay horario ordinario: solo lo que diga el plan.

**Regla de oro, sin excepciones: solo un plan en estado `APPROVED` altera la rejilla efectiva.** Un
borrador no cambia nada de lo que ve el claustro.

**Por qué solo cuatro estados**: "aprobado" y "publicado" no son dos estados sino un estado y dos
acciones con marca de tiempo (`notified_at`, `published_at`). Separarlos en estados duplica pantallas
sin ganar nada; el centro nunca va a aprobar un plan y guardárselo.

### 4.3 `SpacePlanActivity` — el enunciado (tabla `space_plan_activity`)

Lo que el evento **mete** en el centro. Es entrada, no salida: no depende de qué alternativa se elija.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `plan_id` | FK NOT NULL, ON DELETE CASCADE | |
| `title` | string(160) | "Prueba EOI", "Examen de Matemáticas II", "Taller de primeros auxilios" |
| `room_id` | FK NULL | **Null = lo elige el motor.** No null = espacio impuesto por el evento |
| `fixed_date` / `fixed_slots` | date NULL / json NULL | Cuando la franja viene impuesta |
| `sessions` | smallint NULL | Cuando no: cuántas sesiones hay que colocar (talleres) |
| `required_capacity` | smallint NULL | Aforo mínimo |
| `required_kind` | enum `RoomKind` NULL | "Esto necesita sí o sí aula de informática" |
| `staff_per_session` | smallint, default 1 | Cuántos docentes lleva cada sesión |
| `target_group_names` | json NULL | A qué grupos va dirigido |

Una sola entidad cubre lo que parecían tres cosas distintas: **una ocupación externa es una actividad
con espacio y franja fijados; un taller es una actividad con ambos por asignar.** El motor solo mira si
`room_id`/`fixed_*` vienen rellenos.

### 4.4 `SpacePlanOption` — la alternativa (tabla `space_plan_option`)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `plan_id` | FK NOT NULL, ON DELETE CASCADE | |
| `label` | string(32) | "Opción A" |
| `strategy` | enum `ProposalStrategy` | `MIN_MOVEMENT`, `STABLE_ROOM`, `PRESERVE_SPECIALISED`, `MANUAL` |
| `rationale` | string(255) | Una frase que el humano entienda: "Mueve lo mínimo; usa el laboratorio de Física dos horas" |
| `metrics` | json | `movedClasses`, `affectedGroups`, `affectedTeachers`, `specialisedRoomsUsed`, `unresolved` |
| `generated_at` | datetime | |

Las **métricas no son adorno**: son lo único que hace comparables tres alternativas. "Tres opciones" sin
un número al lado es ruido, y el equipo directivo acabará eligiendo siempre la primera.

### 4.5 `SpacePlanAssignment` — la línea (tabla `space_plan_assignment`)

La unidad común a los tres casos. Es lo que se imprime, lo que se notifica y lo que altera la rejilla.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `option_id` | FK NOT NULL, ON DELETE CASCADE | La línea pertenece a una alternativa, no al plan |
| `date` | date NOT NULL | |
| `slot_index` | smallint NOT NULL | El mismo índice de tramo que el horario y el parte de guardias |
| `kind` | enum `AssignmentKind` | `RELOCATION` \| `ACTIVITY` |
| `room_id` | FK NULL | Destino. **Null es un estado legítimo**: "no se ha encontrado sitio", y es justo lo que el humano tiene que resolver |
| `origin_room_name` | string(64) NULL | De dónde sale (solo `RELOCATION`) |
| `group_names` | string(255) NULL | Mismo criterio de plegado que `GuardiaCover` para actividades multigrupo |
| `subject_name` | string(128) NULL | Solo `RELOCATION` |
| `activity_id` | FK NULL | La actividad que materializa (solo `ACTIVITY`) |
| `source_entry_id` | FK NULL, ON DELETE SET NULL | La celda de horario desalojada |
| `manually_edited` | bool, default false | Regenerar no pisa lo tocado a mano, y el documento puede marcarlo |
| `note` | string(255) NULL | |

Índices: `(option_id, date, slot_index)`, `(date, slot_index)` — el segundo lo usa la rejilla efectiva.

**Duplicación asumida**: las líneas fijas (una prueba externa que ocupa el salón de actos) se copian en
las tres alternativas. Son decenas de filas; a cambio, **una alternativa es autocontenida**: el
documento y la rejilla efectiva leen un solo sitio en vez de unir enunciado + variación.

### 4.6 `SpacePlanAssignmentStaff` — quién está en cada línea (tabla `space_plan_assignment_staff`)

| Columna | Tipo | Notas |
|---|---|---|
| `assignment_id` | FK, PK compuesta | |
| `user_id` | FK, PK compuesta | |
| `role` | enum | `RESPONSIBLE` \| `SUPPORT` |

*Por qué una tabla y no una FK en la línea*: el centro pide explícitamente **contar cuántas sesiones
cubre cada profe** ("dos sesiones y dos guardias, o cuatro guardias y una sesión") y avisar a cada uno.
Con una sola FK, un taller con dos docentes no se puede contar ni avisar bien. El coste es una tabla de
unión trivial; el beneficio es que el reparto equitativo es contable con un `GROUP BY`.

---

## 5. La pieza central: la rejilla efectiva

Sin esto el módulo no funciona, y es lo que más impacto tiene en el código existente.

**`RoomOccupancy`** — quién ocupa qué:

- `occupiedAt(date, slotIndex): array<roomId, Occupant>`
- `freeRoomsAt(date, slotIndex, filtros): Room[]`
- `weekGrid(from, to)` — la rejilla entera en **una consulta**, para que el motor no haga N+1.

Se calcula como: horario ordinario del día de la semana correspondiente **menos** lo sustituido por
planes aprobados (`substitution_scope`) **más** las líneas de esos planes aprobados.

**`EffectiveTimetable`** — qué le toca a alguien:

- `forGroup(group, date)`, `forTeacher(user, date)`, `forRoom(room, date)`

**Consumidores** (todos los que hoy leen `ScheduleEntry` a pelo acaban aquí):

1. El motor de propuestas — para saber qué está libre.
2. La pantalla "aulas libres" — resuelve el punto A.2 del volcado del centro.
3. La validación al aprobar — dos planes aprobados no pueden meter dos clases en la misma aula.
4. El documento publicable.
5. **El parte de guardias** — hoy `AbsenceRegistrar` fotografía el aula desde `ScheduleEntry`
   (`src/Guardia/AbsenceRegistrar.php:97`). Con un plan aprobado ese día, el aula del parte estaría mal.
6. La agenda del docente.

⚠️ **Deuda que hay que asumir conscientemente**: en cuanto exista un plan aprobado, el horario tiene dos
fuentes. Todo consumidor que no migre a `EffectiveTimetable` queda desincronizado en silencio. Por eso
la migración de consumidores va **por fases y explícita** (§8), no "cuando toque".

---

## 6. El motor de propuestas

Interfaz `PlanProposer` → `generate(SpacePlan): SpacePlanOption[]`. Puro y determinista (entrada:
enunciado + rejilla; salida: alternativas), testeable sin BD como ya lo es `GuardiaAssigner`.

### 6.1 `RelocationProposer` — cambios de aula y exámenes

Entrada: espacios bloqueados por las actividades, ventana de fechas y franjas, ámbito de sustitución.
Para cada clase desalojada busca destino entre los espacios libres, `assignable` y compatibles.

Tres estrategias, que es de donde salen las "varias opciones" **con criterio**, no por azar:

| Estrategia | Criterio | Para cuándo |
|---|---|---|
| `MIN_MOVEMENT` | Mueve lo imprescindible; prefiere el aula libre del mismo edificio/planta | El caso normal |
| `STABLE_ROOM` | Que el grupo caiga siempre en la misma aula toda la semana | Semana de exámenes: menos lío para el alumnado |
| `PRESERVE_SPECIALISED` | Penaliza ocupar laboratorios, talleres e informática con clases ordinarias | Cuando hay muchos desalojos |

Algoritmo: voraz con ordenación por dificultad (primero lo que menos destinos posibles tiene). Con 40
aulas y 6 tramos esto se resuelve en milisegundos.

**Honestidad de producto**: muchas veces solo habrá una salida razonable y las tres estrategias darán
lo mismo. La UI debe decir *"solo hay una opción viable"* o *"B y C son equivalentes"* en vez de
maquillar tres tarjetas iguales.

### 6.2 `WorkshopProposer` — jornadas culturales

Entrada: actividades con `sessions`, franjas del día, profesorado con docencia ese día, cupo por
persona (sesiones y guardias).

- **"Respetando su horario habitual"** (petición literal del centro) sale de `ScheduleEntry`: la
  participación de cada docente se limita a `[primera hora lectiva, última hora lectiva]` de su horario
  ese día de la semana. Si empieza a las 9:20, no se le pone nada a las 8:25.
- **Cupos**: el equipo directivo fija por persona cuántas sesiones y cuántas guardias cubre. El reparto
  reutiliza el criterio de equidad de `GuardiaAssigner` (ordenar por carga acumulada).
- Variantes: distinta prioridad de reparto (por departamento, por carga, por afinidad con la actividad).

**Guardias y talleres son el mismo cupo**: el ejemplo del centro ("dos sesiones y dos guardias") lo dice
sin ambigüedad. Las guardias de una jornada cultural son líneas `ACTIVITY` con `activity.title =
"Guardia"`, no una entidad nueva.

---

## 7. Aprobación, aviso y documento

### 7.1 Aprobar

Al aprobar: validar que no hay colisión con otros planes aprobados (misma aula, misma fecha, misma
franja) y que no queda ninguna línea con `room_id` nulo sin justificar. A partir de ahí el plan es
visible para todo el claustro y manda sobre el horario ordinario.

### 7.2 Avisar

Reutiliza `NotificationDispatcher` (in-app + push + email, con `AccessGate`).

- **Un aviso por persona con el resumen de SUS líneas**, no un aviso por línea. Un plan de semana de
  exámenes puede tener 60 líneas: 60 notificaciones a la misma persona son spam y se ignoran.
- Kind `space.plan.published`, enlace profundo a su vista del plan.
- **Cambios posteriores**: `space.plan.updated` **solo a los afectados por el delta**. Reavisar a los 58
  docentes porque se ha movido una línea entrena al claustro a ignorar los avisos.
- Esto cubre el punto A.3 del volcado: el docente al que se le quita la biblioteca recibe su aviso con
  el `public_reason` ("por motivos organizativos") que escribió el equipo directivo.

### 7.3 Documento publicable e imprimible

- Ruta interna `/espacios/planes/{id}/documento` (con sesión) y **pública `/e/{token}`** (sin login).
- **Tres vistas del mismo documento**, porque el tablón de cada sitio necesita una distinta:
  **por grupo** (para el alumnado), **por espacio** (para conserjería y las puertas), **por docente**
  (para la sala de profesores).
- Cabecera con centro, título, motivo, fechas y "aprobado por … el …"; pie con fecha de generación.
- **Formato**: HTML con `@media print` para A4. **Sin librería de PDF**: no hay ninguna instalada
  (verificado en `composer.json`) y el navegador ya imprime a PDF. Meter `dompdf` solo si el centro
  exige adjuntar el PDF por correo — y esa es una decisión aparte, no un detalle de implementación.
- **Riesgo a decidir (§9.2)**: el enlace público expone nombres de docentes y grupos sin autenticación.
  Mitigaciones: token largo aleatorio, cabecera `X-Robots-Tag: noindex`, y revocable en un clic.

### 7.4 Permisos

Nueva `Area::ESPACIOS` en el enum de áreas, con su fila en la matriz de roles:

- **Escritura**: equipo directivo (crear, generar, editar, aprobar, publicar).
- **Lectura**: todo el claustro ve los planes aprobados que le afectan.
- **Sin sesión**: solo el documento por token.

---

## 8. Plan de entrega por fases

Cada fase entrega valor por sí sola y se puede parar ahí.

| Fase | Qué entra | Qué desbloquea |
|---|---|---|
| **F1 — Catálogo y ocupación** | `Room` + FK en `ScheduleEntry` + comando de sincronización desde el horario + CRUD en admin + `RoomOccupancy` + pantalla **"Aulas libres"** | Valor inmediato **sin ningún motor**: resuelve el punto A.2 (agrupar guardias en un aula grande) y es la base de todo lo demás |
| **F2 — Plan de cambio de aula** | `SpacePlan`, `SpacePlanActivity`, `SpacePlanOption`, `SpacePlanAssignment` + `RelocationProposer` + pantallas de crear/comparar/editar/aprobar + `EffectiveTimetable` | El punto 11 completo |
| **F3 — Aviso y documento** | Notificación a afectados + documento imprimible + enlace público | El punto 12. A partir de aquí el módulo es usable de verdad |
| **F4 — Semana de exámenes** | Preset `EXAM_PERIOD` + `substitution_scope = GROUPS` + estrategia `STABLE_ROOM` | El punto 13. Casi todo es configuración de lo ya hecho |
| **F5 — Jornadas culturales** | `SpacePlanAssignmentStaff` + `WorkshopProposer` + cupos por docente | El punto 14 |
| **F6 — Integración con guardias** | El parte lee el aula efectiva; crear un plan desde el parte de guardias | Cierra los puntos A.2 y A.3 y elimina la desincronización del §5 |

**F1 antes que nada.** Es la única fase que no depende de decisiones abiertas y la que más se reutiliza.

---

## 9. Decisiones abiertas

### Para el centro

1. **Capacidad de las aulas y matrícula de los grupos.** Peñalara no exporta ninguna de las dos
   (verificado sobre el planificador real). Sin capacidad, el motor no puede validar aforo: solo puede
   ordenar por tamaño y dejar que el humano decida. ¿Rellenan la capacidad de las ~40 aulas en uso?
   ¿Y la matrícula de los 39 grupos? Con las dos, el sistema avisa de "no cabe"; con una, orienta; sin
   ninguna, solo propone.
2. **Enlace público sin login.** ¿Aceptan que el documento sea accesible por URL secreta (con nombres de
   docentes y grupos), o prefieren descargarlo y subirlo ellos a la web del centro?
3. **Jornadas culturales: ¿el alumnado va por grupo o se inscribe individualmente a los talleres?** Si
   hay inscripción individual, hace falta modelo de alumnado, que hoy **no existe** en la aplicación:
   eso es otro módulo, no un campo más.
4. **Semana de exámenes: ¿el sistema debe generar el calendario de exámenes** (qué materia, qué día, qué
   hora) o solo reubicar los espacios que los exámenes desalojan? Lo segundo es esta fase; lo primero es
   un módulo aparte con su propio conjunto de restricciones.
5. **¿Quién aprueba un plan?** ¿Solo dirección y jefatura de estudios, o también coordinación?
6. **¿Qué pasa con las clases ordinarias de un grupo que está de exámenes?** ¿Se dan igual o el nivel
   entero queda fuera del horario ordinario esa semana? De esto depende `substitution_scope`.

### Para Paco (técnicas)

7. **La FK `room_id` en `ScheduleEntry`** (§4.1): toca el importador, que es código delicado. ¿Se acepta
   el coste en F1 o se empieza cruzando por texto asumiendo el riesgo?
8. **¿`building`/`floor` en el catálogo?** El criterio "no cruzar el centro" es de los que más se
   agradecen en la práctica y son dos columnas. Yo las metería.
9. **Migración de consumidores a `EffectiveTimetable`** (§5): ¿se hace toda en F2 o se difiere el parte
   de guardias a F6 asumiendo que durante ese tiempo el parte puede mostrar el aula antigua?

---

## 10. Lo que este diseño NO hace (a propósito)

- **No es un resolutor de restricciones genérico.** Con 40 aulas y 6 tramos, un voraz con tres criterios
  explicables da mejores resultados de cara al usuario que un optimizador que nadie sabe por qué
  propuso lo que propuso.
- **No genera PDF nativo.** El navegador imprime a PDF. Añadir la dependencia cuando haya una razón real.
- **No hay reserva de espacios self-service** (que un docente pida el aula de informática para el
  jueves). Es lo primero que van a pedir cuando vean el módulo, y encaja como una `SpacePlanActivity`
  con flujo de aprobación ligero — pero no está pedido y no entra.
- **No modela alumnado.** Ni matrícula ni inscripciones (ver decisión 3).
- **No toca el horario ordinario.** `ScheduleEntry` sigue siendo el horario semanal importado de
  Peñalara y nada de este módulo lo modifica: las excepciones viven en la capa de planes.

---

## 11. Limitaciones conocidas (decirlo antes, no después)

1. **"Libre según el horario" no es "libre".** Peñalara no exporta usos no lectivos (reuniones, ensayos,
   una charla). La ocupación calculada es optimista. Mitigación: `Room.assignable` y poder bloquear un
   espacio a mano; y llamar a la pantalla exactamente **"Aulas libres según el horario"**, no "aulas
   libres".
2. **Sin capacidad ni matrícula no hay control de aforo** (decisión 1).
3. **Varias alternativas pueden ser artificiosas** cuando solo hay una salida; la UI tiene que admitirlo.
4. **Dos fuentes para el horario** hasta que todos los consumidores migren (§5).
5. **PII en el enlace público** (decisión 2).
