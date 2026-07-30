# Motor de propuesta del cuadrante de guardias y recreos

Encargo del centro: **dado el horario, que el programa PROPONGA el cuadrante** de guardias lectivas y de recreos; el equipo directivo valida o retoca. Hoy todo se pica a mano (`/guardias/horario` para las lectivas, `/guardias/recreo` para los recreos).

Documento de diseño. **No hay código escrito todavía.**

---

## 1. Lo que pide el centro

| # | Requisito | Origen |
| --- | --- | --- |
| R1 | **3 profesores de guardia + 2 de apoyo** por tramo. Los de apoyo solo entran si no hay personal suficiente para cubrir esa hora  | Paco, 30/07  |
| R2 | **Exentos**: orientadoras, PSC y equipo directivo. Y hay profes con menos guardias por tener otras complementarias  | Paco, 30/07  |
| R3 | El equipo directivo **pica a mano el nº de guardias de cada profesor**, con un desplegable  | Paco, 30/07  |
| R4 | Recreos: las 5 zonas, pero **ni el mismo día ni la misma zona** para una misma persona  | Paco, 30/07  |
| R5 | En los **recreos cortos no hay patio dirigido**; los patios dirigidos los organiza **por días** el equipo directivo  | Paco, 30/07  |
| R6 | **1 guardia de recreo = 1 recreo grande + 1 recreo corto**, y pueden ser de **días distintos**  | Paco, 30/07  |
| R7 | El equipo directivo asigna los **tipos** de guardia; el programa **solo distribuye**  | Paco, 30/07  |
| R8 | Añadir las **guardias de 7.ª hora**, que también **asigna a mano** el equipo directivo  | Paco, 30/07  |
| R9 | Reparto **equitativo**, ponderando la categoría de la zona en los recreos  | email del centro  |

---

## 2. Lo que dice el horario real (verificado, no supuesto)

Calculado sobre `catalogo/planificador.xml` + `catalogo/Horario (1).xml` (export real del curso 2025-2026).

**Marco horario:** uno solo ("A", `PlaHorEs`), **8 tramos**, de los cuales **6 lectivos y 2 recreos**.

| Tramo | 0 | 1 | 2 | **3** | 4 | 5 | **6** | 7 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Horas  | 8:25 | 9:20 | 10:15 | **11:10** | 11:35 | 12:30 | **13:25** | 13:35 |
| Fin  | 9:20 | 10:15 | 11:10 | **11:35** | 12:30 | 13:25 | **13:35** | 14:30 |
| Tipo  | lectivo | lectivo | lectivo | **recreo** | lectivo | lectivo | **recreo** | lectivo |

**El tramo 7 (13:35–14:30) es la 6.ª hora, no la 7.ª**: tiene 33–36 profesores dando clase todos los días. Confirma la respuesta de Paco: la 7.ª hora es una hora adicional que Peñalara **no exporta**.

**Plantilla y disponibilidad:** 68 profesores en el horario. Plazas a cubrir con R1: 6 tramos × 5 días × 5 = **150 a la semana**.

| Criterio de disponibilidad | Candidatos por tramo | Lectura |
| --- | --- | --- |
| Cualquier hueco sin clase  | **31–38**  | Sobra gente para 5 plazas  |
| Solo hueco **intermedio** entre clases  | 0–26  | Se hunde en los extremos  |

El criterio "hueco intermedio" da **0 candidatos a última hora** de martes a viernes y **1** el viernes a 1.ª, pero eso es un artefacto del criterio, no escasez real: quien está libre a última hora lo está justamente porque ya se ha ido. **Consecuencia de diseño:** la disponibilidad no se puede derivar solo del horario lectivo, porque el horario no dice la permanencia. Ver decisión D-1.

**Lo que sí es escaso es el cupo, no el hueco.** 150 plazas entre ~57 profesores no exentos = **2,6 guardias lectivas por cabeza**. Si el equipo directivo pica "2" a todo el mundo, la suma de cupos no llega y el cuadrante sale incompleto: la pantalla de cupos tiene que enseñar ese balance **mientras** lo teclean, no después.

---

## 3. Lo que hay que cambiar en el modelo

### 3.1 Cupo de guardias por profesor (entidad nueva)

R2 y R3 se resuelven con **una sola perilla**: el cupo. Exento = 0. Profe con complementarias = 1. No hace falta modelar "exención" como concepto aparte ni mantener una lista de roles exentos en paralelo al catálogo de `Role` — que además hoy no tiene rol de orientación ni de PSC (`RoleFixtures.php`), así que no se podría derivar aunque quisiéramos.

`GuardiaQuota`: `academicYear` + `teacher` + `lectiveDuties` + `breakDuties`, UNIQUE (curso, profesor). Es por curso porque el horario y las complementarias cambian cada septiembre.

**Dos números y no uno** (supuesto S-1): las guardias lectivas y las de recreo salen de bolsas distintas y con reglas de conteo distintas. Con un solo número el motor tendría que decidir él cómo repartir entre las dos, y el equipo directivo perdería justo el control que pide R3. "Ana: 2 guardias y 1 recreo" se pica igual de rápido y se lee mejor.

### 3.2 La plaza de recreo deja de ser la guardia (migración)

Esto es lo que más se mueve. Hoy `BreakDutyAssignment` tiene la regla vieja convertida en **estructura**:

- `UNIQUE (curso, profesor, weekday)` — `BreakDutyAssignment.php:38`
- `periods: BreakPeriodCoverage` con `BOTH` — `:70`
- `load()` = peso de la zona **contado una vez**, cubra uno o dos recreos — `:143`

El javadoc lo dice sin rodeos: *"a teacher cannot watch the patio at the first recreo and the biblioteca at the second on the same day"*. R4 y R6 lo tumban. La granularidad correcta pasa a ser **una fila = una plaza**:

| | Antes | Después |
| --- | --- | --- |
| Fila  | una guardia  | una plaza (un recreo, un día, una zona)  |
| Clave única  | curso+profe+día  | curso+profe+día+**posición de recreo**  |
| `periods`  | FIRST / SECOND / BOTH  | `breakPosition` 0 (grande) o 1 (corto)  |
| Guardia  | = la fila  | = **cálculo**: 1 grande + 1 corto  |

Migración sin pérdida: `BOTH` se parte en dos filas (posición 0 y 1, misma zona); `FIRST` → posición 0; `SECOND` → posición 1.

**Conteo (R6):** `guardias = min(nGrandes, nCortos)` y `sueltas = |nGrandes − nCortos|`. El motor minimiza las sueltas dando a cada profesor tantos grandes como cortos. La equidad ponderada por zona (R9) sigue siendo una lente aparte, la que ya calcula `GuardiaStatistics::equity()`.

⚠️ **Con las zonas actuales el emparejamiento no cuadra.** Semilla de `BreakZoneFixtures`: Patio (2 personas), Pistas, Pasillo, Biblioteca, Patio dirigido (1 cada una).

| | Zonas | Plazas/día | Plazas/semana |
| --- | --- | --- | --- |
| Recreo grande  | las 5  | 6  | **30**  |
| Recreo corto (sin patio dirigido, R5)  | 4  | 5  | **25**  |

Sobran **5 plazas de recreo grande a la semana**, es decir 5 profesores acabarán con medio recreo suelto. No es un fallo del motor: es aritmética de la demanda. Ver duda A-3.

### 3.3 La demanda de recreo pasa a ser por celda

R5 dice que el patio dirigido no existe en el corto y que se organiza **por días**. Hoy `BreakZone.requiredTeachers` es un número global por zona, que no puede expresar ninguna de las dos cosas.

Sustituirlo por una tabla de demanda `(zona, weekday, posición) → nº de personas`: como mucho 5 × 5 × 2 = 50 filas, editables en una rejilla. Es donde el equipo directivo ejerce R7 — decide **qué** hace falta y **cuándo**, y el motor solo reparte **quién**.

### 3.4 Tramos fuera del marco importado (7.ª hora)

R8: la 7.ª hora no viene en el export. Hace falta poder crear un `TimeSlot` a mano y que el import de Peñalara no se lo lleve por delante — exactamente el problema que `ScheduleEntrySource` (PENALARA / MANUAL) ya resolvió para las celdas de horario. Mismo patrón: `TimeSlot.source`, y `TimetableImporter` respeta los MANUAL.

Las guardias de esa hora **las pica dirección**: el motor no las propone, solo las respeta como ocupación.

---

## 4. El motor

### 4.1 Entradas

Horario lectivo del curso (`ScheduleEntry` LECTIVE) · marco horario (`TimeSlot`, incluidos los manuales) · cupos (§3.1) · demanda de recreo por celda (§3.3) · **plazas ya fijadas a mano**, que son intocables: las de 7.ª hora, los patios dirigidos y cualquier celda que el equipo directivo haya clavado en `/guardias/horario`.

### 4.2 Restricciones duras

| | |
| --- | --- |
| D1  | Nadie de guardia en un tramo en que da clase  |
| D2  | Como mucho una plaza por profesor y tramo  |
| D3  | Nunca por encima del cupo del profesor  |
| D4  | Cupo 0 = exento: no aparece jamás en la propuesta  |
| D5  | Las plazas fijadas a mano no se tocan  |
| D6  | La demanda de cada celda: 3 + 2 en cada tramo lectivo, y lo que diga la rejilla en cada celda de recreo  |

### 4.3 Preferencias (lo que el motor optimiza)

| | |
| --- | --- |
| P1  | Hueco **intermedio** antes que hueco en un extremo del día — no obligar a nadie a venir antes o quedarse después  |
| P2  | Repartir los días: no amontonar las guardias de un profesor en la misma jornada  |
| P3  | Recreos: mismos grandes que cortos por persona, para no dejar medias guardias sueltas (R6)  |
| P4  | Variar la zona y el día de recreo por persona (R4)  |
| P5  | Equidad ponderada por peso de zona (R9), medida con el Gini que ya existe  |
| P6  | El **apoyo** (R1) a quien ya esté en el edificio: es una reserva, no una guardia efectiva  |

### 4.4 Algoritmo: greedy por escasez + intercambios

1. **Ordenar las celdas por dificultad**, de menos candidatos elegibles a más. Es la heurística de siempre (atacar primero lo más restringido) y es lo que evita el clásico "me he quedado sin nadie para el viernes a primera".
2. **Elegir en cada celda** al candidato de menor coste según §4.3: más cupo pendiente, menos carga acumulada, hueco intermedio, día menos cargado. Desempate por nombre → **misma entrada, misma propuesta**: reproducible y testeable, sin azar.
3. **Pase de intercambios**: swaps que bajen el coste total, con tope de iteraciones. Arregla lo que el greedy dejó torcido sin rehacerlo todo.
4. **Pase de emparejado** en recreos: casar grandes con cortos por persona.

**Por qué no un solver exacto** (min-cost flow o ILP): resolvería 150 plazas sin despeinarse, pero (a) las preferencias no son lineales — "repartir los días", "variar la zona" —, (b) el resultado no sería **explicable**, y el equipo directivo tiene que retocarlo entendiendo por qué está cada cual donde está, y (c) mete una dependencia nueva. Con validación humana por delante, "óptimo" no es el requisito: "bueno, rápido y defendible" sí.

### 4.5 Salida: una propuesta, y sobre todo un informe

El motor **no escribe el cuadrante**. Devuelve un borrador donde cada plaza lleva su **motivo** ("hueco intermedio, lleva 0 de 2"), más un informe de lo que **no** ha podido hacer:

- celdas sin cubrir, con la razón (nadie libre / todos con el cupo lleno);
- profesores por debajo de su cupo;
- recreos sueltos sin pareja;
- el balance global: plazas necesarias contra suma de cupos.

Esa última parte es la que más importa. Sin ella, una propuesta incompleta se lee como un fallo del programa en vez de como lo que es: no hay cupo suficiente y hay que subirlo.

---

## 5. Flujo de pantallas

1. **`/guardias/cupos`** — rejilla profesor × (guardias, recreos) con desplegables (R3), buscador y "poner N a todos". Arriba, el balance en vivo: *"Cupos 148 · Plazas 150 · faltan 2"*.
2. **`/guardias/cuadrante`** — botón "Proponer". Rejilla día × tramo para las lectivas y rejilla día × zona para los recreos, **editables celda a celda**, con el informe al lado. Es un borrador: no publica nada.
3. **Aprobar** — escribe `ScheduleEntry` (GUARDIA / COLLABORATOR) y las plazas de recreo, y avisa a los afectados.

Es el mismo patrón de *propuesta → alternativas → validación → aviso* que ya se usó en `/espacios/planes`, y el que el centro pidió literalmente para los exámenes de 2.º de Bachillerato: *"el programa propone y el equipo directivo valida o retoca"*.

Al aprobar, esas celdas deben quedar a salvo del siguiente import de Peñalara. `ScheduleEntrySource` necesita un caso más (`ENGINE`), tratado como MANUAL a efectos de import pero distinguible para poder decir "esto lo puso el motor" y volver a proponer sin pisar lo que un humano tocó a mano.

---

## 6. Fases

| Fase | Qué | Entrega valor sola |
| --- | --- | --- |
| F1 ✅  | Cupos: entidad, pantalla y balance en vivo  | Sí — el equipo directivo ya puede fijar y ver el desajuste  |
| F2  | `TimeSlot` manual (7.ª hora) + respetar plazas fijadas  | Sí — desbloquea R8  |
| F3  | Motor de guardias lectivas + propuesta editable + informe  | Sí — el grueso del encargo  |
| F4  | Modelo de recreo: partir la plaza, demanda por celda, migración  | No — habilita F5  |
| F5  | Motor de recreos con emparejado grande + corto  | Sí  |
| F6  | Aprobar y publicar + avisos  | Sí — cierra el círculo  |

---

## 7. Supuestos y dudas abiertas

| # | Qué | Cómo va montado mientras no se responda |
| --- | --- | --- |
| S-1  | ¿El cupo es **un** número o **dos** (guardias / recreos)?  | Dos, por §3.1  |
| S-2  | ¿Una plaza de **apoyo** consume cupo igual que una de guardia?  | Sí, es una plaza; se distingue visualmente pero cuenta  |
| D-1  | ¿Puede el motor poner guardia a **1.ª o última hora** a quien no tiene clase pegada?  | Sí, pero penalizado (P1): solo cuando no hay alternativa  |
| A-3  | Sobran **5 plazas de recreo grande** a la semana (§3.2): 5 personas con media guardia suelta. ¿Se acepta, o se ajusta la demanda por celda hasta que cuadre?  | Se acepta y el informe lo dice con nombres  |

---

## 8. F1 — lo construido y lo que enseñó

`GuardiaQuota` (una fila por curso y docente, `Auditable`), `GuardiaQuotaBalance` (puro), `GuardiaQuotaController` en `/guardias/cupos` (gate **WRITE**, no READ), la plantilla, `quota-bulk.js` ("poner a todos") y la migración `Version20260730210000`. Verificado en runtime sobre el export real: 58 docentes, 6 tramos, **150 plazas**, y poner 2 a todo el mundo deja **34 sin cubrir**.

Tres cosas que solo aparecieron al ejecutarlo, y que valen para las fases siguientes:

- **"Sin decidir" no es "exento".** Leer un cero no tecleado como exención hacía que la pantalla recibiera al coordinador declarando exento a todo el claustro, y dejaba el reparto justo en 0 en vez de en 2,6 — el número más útil de la página. La fila lleva ahora `configured`, y al guardar se escribe fila **también para los ceros**: sin ella los dos estados son indistinguibles en base de datos.
- **Un POST parcial no debe borrar lo que no menciona.** El navegador envía siempre las 58 casillas, pero una petición truncada vaciaba el cupo de todos los ausentes — el mismo footgun que ya existe en `guardia_cover_update`. Ahora un docente que la petición no nombra se queda como estaba.
- **`select-menu.js` realza todos los `<select>` del documento y no tiene opt-out**, así que 58 filas × 2 desplegables habrían sido 116 listbox sintéticos. Los cupos se pican con `input type="number"`, como ya hace la pantalla de zonas.

Diferido a propósito: la carrera al crear cupos a la vez (el UNIQUE la corta, sin `try/catch` como sí hace `BreakDutyController`), y **3+2 son constantes de clase**, no configurables — parametrizarlas es trivial y no bloquea nada.
