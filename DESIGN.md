---
name: Gestión del centro
description: Sistema de diseño cálido para el día a día del docente — "qué me toca hoy" sobre papel cálido, verde bosque como voz, y una paleta de estado codificada.
colors:
  canvas: "#EDE7D8"
  bg: "#F5F1E6"
  surface: "#FBF9F1"
  surface-alt: "#FEFDF8"
  surface-sunken: "#F1EBDA"
  surface-sidebar: "#EFEADC"
  border: "#E4DDC9"
  border-strong: "#C9BFA3"
  separator: "#EDE6D3"
  text: "#1A1A17"
  text-muted: "#615C4E"
  text-faint: "#8A8471"
  text-tenue: "#B0A98F"
  on-accent: "#F5F1E6"
  accent: "#1E4A3D"
  accent-hover: "#17392F"
  accent-weak: "#DDF0E4"
  accent-weak-border: "#B9DBC6"
  accent-bright: "#7FB88C"
  success: "#2F6B55"
  warning: "#D9803B"
  warning-weak: "#FCF3E9"
  warning-text: "#9A4A1C"
  amber: "#E7B24A"
  amber-weak: "#F6E9CB"
  amber-text: "#6B4E12"
  danger: "#C0392B"
  danger-weak: "#F6DADA"
  danger-text: "#9A2A2A"
  personal: "#7A6CA8"
  personal-weak: "#E6E0F1"
  review: "#46707A"
  module-bg: "#EFEADB"
  module-band: "#E9E2CF"
  module-border: "#DED5BE"
  module-personal-bg: "#EDE9E2"
typography:
  display:
    fontFamily: "Newsreader, Georgia, 'Times New Roman', serif"
    fontSize: "27px"
    fontWeight: 500
    lineHeight: 1.15
    letterSpacing: "normal"
  title:
    fontFamily: "Newsreader, Georgia, serif"
    fontSize: "18px"
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: "normal"
  body:
    fontFamily: "'Instrument Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
  mono-figure:
    fontFamily: "'IBM Plex Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace"
    fontSize: "22px"
    fontWeight: 600
    lineHeight: 1
    letterSpacing: "normal"
  label:
    fontFamily: "'IBM Plex Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace"
    fontSize: "11px"
    fontWeight: 400
    lineHeight: 1.2
    letterSpacing: "0.16em"
rounded:
  sm: "10px"
  md: "12px"
  lg: "20px"
  pill: "999px"
spacing:
  xs: "6px"
  sm: "10px"
  md: "14px"
  lg: "18px"
  xl: "24px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.md}"
    padding: "10px 16px"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.md}"
    padding: "10px 16px"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.accent}"
    rounded: "{rounded.md}"
    padding: "10px 16px"
  guardia-hero:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.lg}"
    padding: "20px 22px"
  module:
    backgroundColor: "{colors.module-bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.lg}"
    padding: "0px"
  module-personal:
    backgroundColor: "{colors.module-personal-bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.lg}"
    padding: "0px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.lg}"
    padding: "22px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "10px 12px"
  badge:
    backgroundColor: "{colors.surface-sunken}"
    textColor: "{colors.text-muted}"
    rounded: "{rounded.sm}"
    padding: "4px 8px"
---

# Design System: Gestión del centro

## Overview

**Creative North Star: "El escritorio del docente"**

Esta es la mesa de trabajo ordenada del docente: cercana, cotidiana y personal, el sitio al que asomarse cada mañana para ver qué te toca hoy. La pantalla de Inicio lo dice literalmente — "Buenos días, {nombre}" — y encadena, en una sola columna legible, tu **próxima guardia**, tus **tareas que aprietan hoy** y tu **agenda privada**. No es el archivo institucional ni un dashboard corporativo, y tampoco una "app de colegio" con colorines: es una herramienta profesional de uso diario, muchas veces desde el móvil entre clases.

El sistema es **cálido y humano** (papel arena, tinta casi negra, verde bosque) y a la vez **sobrio**. La estructura es "lista abierta sobre el fondo con un único ancla oscura": casi todo respira en claro sobre el lienzo, y solo **una** pieza —la próxima guardia— es una tarjeta verde oscura y rellena que ancla la vista. Alrededor de esa ancla, la información "de gestión" (mi departamento, el centro, guardias de hoy) vive en **módulos con caja** claramente separados del flujo personal.

El color no es decorativo: es un **vocabulario codificado**. El verde es la voz de marca y de acción; el naranja avisa de urgencia (vencido, sin cubrir); el ámbar marca que una tarea lleva entregable; el rojo se reserva a lo destructivo o a una ausencia; el malva es siempre lo personal y privado. Todo es semántico y tematizado: un único juego de nombres de token cambia de valor entre claro y oscuro; jamás hay un hex suelto en el CSS. El tema oscuro no es un claro invertido, sino una tinta cálida sobria con su propio contraste AA.

**Key Characteristics:**
- Papel cálido + tinta casi negra + verde bosque; nunca blanco puro ni gris corporativo.
- Una sola ancla oscura por pantalla (la próxima guardia); el resto respira en claro.
- Paleta de estado codificada: verde, naranja, ámbar, rojo, malva — cada color significa una cosa.
- Serif de lectura (Newsreader) en saludos y títulos; sans neutra (Instrument Sans) en el cuerpo; mono (IBM Plex Mono) en cifras, horas y etiquetas.
- Formas generosamente redondeadas (tarjetas 20px) y píldoras para avatares, casillas y puntos.
- Profundidad con sombras suaves, largas y teñidas (la del hero es verde).
- Tokens 100% semánticos con paridad claro/oscuro.

## Colors

Papel cálido y tinta casi negra como base, verde bosque como única voz de acción, y una escala de estado donde cada color tiene un significado fijo.

### Primary
- **Verde Bosque** (#1E4A3D): la voz de marca y de acción. Botones primarios, navegación activa, iconos de módulo, avatar del usuario, y el relleno del **hero de guardia**. Hover a #17392F. En tema oscuro el acento se aclara a un verde salvia (#7FB88C) para el contraste.
- **Verde Tenue** (#DDF0E4, borde #B9DBC6): fondo de acento suave — chips, hover y estado seleccionado.
- **Verde Salvia Brillante** (#7FB88C): color secundario vivo; el punto pulsante "tu próxima guardia" y señales de "disponible".

### Secondary — la paleta de estado codificada
- **Naranja Urgencia** (#D9803B, fondo #FCF3E9, texto #9A4A1C, borde #EBD3BC): **vencido / sin cubrir**. Es la señal de que algo apremia. La fila de tarea vencida se tiñe de naranja suave con una barra lateral naranja.
- **Ámbar Entregable** (#E7B24A, fondo #F6E9CB, texto #6B4E12): marca que una tarea **lleva un entregable / hay que adjuntar** o que algo está **pendiente de validación**. Es distinto del naranja: no es urgencia, es "esto conlleva un documento/acción".
- **Rojo Grave** (#C0392B, fondo #F6DADA, texto #9A2A2A): reservado a lo **destructivo, una ausencia, o algo no leído**. El color más escaso.
- **Malva Personal** (#7A6CA8, icono #6A5C99, fondo #E6E0F1): SIEMPRE lo **personal y privado** — el módulo "Mi agenda" y sus horas. Señala "solo tú lo ves".
- **Pizarra** (#46707A): estado "entregada / en revisión".

### Tertiary (categóricos del calendario)
- **Plan / Do / Check / Act** (#5C7A99, #5F8A5A, #4F8A86, #9A6A86): apagados y distintos, reservados a categorizar en el calendario. Nunca como acento de acción.

### Neutral
- **Lienzo** (#EDE7D8): papel exterior del shell.
- **Fondo** (#F5F1E6) y **Superficie** (#FBF9F1) / **Superficie Alta** (#FEFDF8): fondo de app, tarjetas, y tarjeta más clara.
- **Superficie Hundida** (#F1EBDA): cabeceras de tabla/grupo, inputs, hover; **Arena de barra lateral** (#EFEADC).
- **Superficies de módulo** — gestión: fondo #EFEADB, banda #E9E2CF, borde #DED5BE; personal: fondo #EDE9E2 (algo más neutro).
- **Filete** (#E4DDC9), **Borde Marcado** (#C9BFA3), **Separador** (#EDE6D3).
- **Tinta** (#1A1A17) / **Secundaria** (#615C4E) / **Terciaria** (#8A8471) / **Tenue** (#B0A98F, chevrons).

### Named Rules
**The Coded-Status Rule.** Cada color de estado significa una sola cosa y no se intercambia: **verde** = acción/hecho, **naranja** = urgencia (vencido/sin cubrir), **ámbar** = lleva entregable / por validar, **rojo** = destructivo/ausente/no leído, **malva** = personal y privado. Elegir el color equivocado miente sobre el estado.

**The One Voice Rule.** El verde bosque es la única voz de *acción y marca*. Los colores de estado informan, pero solo el verde invita a actuar (botones, enlaces de módulo, activo de nav).

**The Semantic-Only Rule.** Nunca escribas un hex en un componente. Todo color pasa por un token semántico para conservar la paridad claro/oscuro.

## Typography

**Display Font:** Newsreader (fallback: Georgia, serif)
**Body Font:** Instrument Sans (fallback: system-ui, Segoe UI, Roboto, sans-serif)
**Numeric / Label Font:** IBM Plex Mono (fallback: ui-monospace, SFMono-Regular, Menlo)

**Character:** Una serif de lectura, cálida y con voz humana, para el saludo y los títulos; una sans neutra y muy legible para el cuerpo; y una mono para todo lo que es *dato* — horas, cifras, contadores, etiquetas y metadatos. El contraste serif/mono es la firma: lo humano se lee, lo cuantitativo se tabula.

### Hierarchy
- **Display / Greeting** (Newsreader 500, 27px, line-height 1.15): el saludo "Buenos días, {nombre}" y los titulares de página.
- **Title** (Newsreader 500, 18–21px): títulos de módulo ("Mi agenda", "El centro") y el destino del hero de guardia.
- **Body** (Instrument Sans 400–500, 14px, line-height 1.4): títulos de tarea, texto corrido, controles.
- **Mono figure** (IBM Plex Mono 600, 22–32px): la hora del hero (32px), los contadores de módulo, los porcentajes. Los números son mono para que casen y "cuadren".
- **Label / Eyebrow** (IBM Plex Mono, 10–11px, mayúsculas, letter-spacing .12–.16em): fecha del día, eyebrows de sección, meta de tarea ("VENCIÓ 12/06 · Matemáticas"). La firma tipográfica del sistema.

### Named Rules
**The Serif-Greets, Mono-Counts Rule.** Newsreader para lo humano (saludo, títulos); IBM Plex Mono para todo dato (horas, cifras, contadores, eyebrows). El cuerpo y los controles son siempre Instrument Sans.

## Layout

Inicio es una **columna única legible** (`max-width: 600px`) en móvil: cabecera (fecha + saludo + avatar), luego el ancla de guardia, y debajo el resto apilado. En escritorio (`≥900px`) el `max-width` sube a 1000px y **todo lo que va bajo el hero se reparte en dos columnas** (rejilla `1fr 1fr`); el saludo gana un botón "+ Nueva tarea" a la derecha (oculto en móvil, donde se crea desde Tareas). El shell es barra lateral de 230px (arena) + contenido.

El ritmo es holgado: `gap` de 24px entre bloques del Inicio, padding de 20–22px en hero y tarjetas, 16–18px dentro de los módulos. La densidad es baja y respirable a propósito — es un vistazo, no una hoja de cálculo. Breakpoints observados (`min-width: 900px` para la rejilla de dos columnas; el resto del sistema colapsa la barra lateral en `max-width: 760px`).

## Elevation & Depth

El rediseño **usa sombras**, pero suaves, largas y teñidas — nunca duras. La profundidad distingue tres planos: el fondo de papel, los **módulos** que flotan levemente sobre él (`shadow-card`), y la **única ancla** —el hero de guardia— que flota más y con una **sombra verde** propia (`shadow-hero`), reforzando que es la pieza protagonista. Las hojas inferiores (bottom sheets) usan una sombra hacia arriba (`shadow-sheet`). En tema oscuro las sombras casi desaparecen y la separación recae en los bordes.

### Shadow Vocabulary
- **Sombra de módulo** (`0 14px 30px -24px rgba(60,50,20,.5)`): elevación leve de módulos y tarjetas sobre el papel.
- **Sombra de hero** (`0 20px 38px -24px rgba(30,74,61,.7)`): teñida de verde, exclusiva del ancla de guardia. Su color refuerza la jerarquía.
- **Sombra de hoja** (`0 -20px 50px -20px rgba(20,40,30,.5)`): bottom sheets (filtros, popovers móviles).

### Named Rules
**The Single Dark Anchor Rule.** Exactamente **una** pieza rellena y oscura por pantalla de Inicio: la próxima guardia, con su sombra verde. Todo lo demás respira en claro. Dos anclas competirían y romperían la jerarquía.

## Shapes

Lenguaje **generosamente redondeado**: 10px en badges e inputs internos, 12px en botones, inputs y items de nav, **20px en tarjetas, módulos y el hero**. Las **píldoras** (999px) son parte del vocabulario ahora: el avatar del usuario (42px), la casilla circular de tarea (`.tick`, 22px), los puntos pulsantes y de agenda, las barras de progreso y los `pill-validate`. Los iconos de módulo son cuadrados de esquina suave (9px). La forma comunica: redondez amable, coherente con "escritorio del docente", nada de esquinas duras de panel administrativo.

## Components

### Guardia hero (componente firma)
La pieza protagonista y la única ancla oscura. Tarjeta enlace **verde bosque rellena**, radio 20px, con sombra verde (`shadow-hero`). Eyebrow mono con **punto pulsante** verde salvia ("Tu próxima guardia"), la hora en mono 32px, el destino en Newsreader 21px, y un subtítulo con a quién cubres y si la ausencia "tiene tarea" (ámbar) o "sin tarea". Cuando no hay guardia hoy, se sustituye por la **tira tranquila** `.no-guardia`: fondo hundido, icono de reloj en verde tenue, "Hoy no tienes guardia" + próxima fecha. El contraste entre el hero oscuro y la tira clara ES la señal.

### Módulos con caja
Zona "de gestión" separada del flujo personal. Caja radio 20px con **banda de cabecera** (icono + título Newsreader + subtítulo + acción), `shadow-card`, y filas internas separadas por filete. Dos sabores: **gestión** (fondo oliva `module-bg`, icono verde) y **personal** (`module--personal`, malva). Contenido según rol: tiles de cifras (`module-tile`, número mono), filas con avatar/stat + chevron (`module-row`), barra de progreso, y un pie "Ver todo…" en verde.

### Tasklist (Mis tareas)
Lista abierta sobre el fondo, sin caja. Cada item: **casilla circular** (`.tick`, 22px, borde 2px, se rellena de verde al marcar con un check SVG), título en Instrument Sans 14.5px, y meta en mono ("HOY", "VENCIÓ 12/06 · Depto · adjuntar documento"). Una tarea **vencida** se convierte en **fila de alerta**: fondo naranja suave, radio 14px y barra lateral naranja de 4px. Las no accionables llevan un tick estático.

### Buttons
- **Shape:** radio 12px (`--radius`), Instrument Sans 500/600.
- **Primary (`.btn`):** relleno verde sobre `--on-accent`, hover a verde oscuro. Variantes: **+ Nueva tarea** (píldora verde radio 11, solo escritorio), **btn-mini** (verde radio 10, en módulos), **btn-sm**.
- **Secondary:** contorno, texto verde, borde `--border-strong`, hover rellena hundido.
- **Danger:** relleno rojo; exclusivo de acciones destructivas.

### Badges & pills
- **Badge:** mono 10.5px mayúsculas, radio 10px, borde teñido por estado (draft/review/success/warning/danger/accent…). Reservado a estados accionables.
- **pill-validate:** píldora ámbar mono ("VALIDAR") en filas pendientes de validación.

### Inputs / Fields
- Ancho completo, padding 10px 12px, borde `--border-strong`, **radio 12px**, fondo superficie, Instrument Sans 14px. Focus: borde a verde (`--accent`); búsquedas/fechas añaden halo `0 0 0 3px var(--accent-weak)`. El foco de teclado global es `outline: 2px solid var(--focus)`. Error: `aria-invalid` tiñe el borde de rojo.

### Navigation
- Barra lateral arena; items radio 12px, 2px de separación. Reposo en tinta; **hover** rellena verde tenue; **activo** relleno verde sólido con `--on-accent`. Grupos plegables con borde-izquierdo. En móvil colapsa a panel deslizante.

## Do's and Don'ts

### Do:
- **Do** pasar todo color por un token semántico; nunca escribas un hex en un componente.
- **Do** respetar el código de estado: verde=acción/hecho, naranja=urgencia, ámbar=entregable/por validar, rojo=destructivo/ausente, malva=personal.
- **Do** mantener **una sola ancla oscura** (el hero de guardia) por pantalla de Inicio; el resto en claro.
- **Do** usar Newsreader para saludos/títulos, IBM Plex Mono para toda cifra/hora/etiqueta, Instrument Sans para el cuerpo.
- **Do** separar lo personal (malva, "solo tú lo ves") de lo de gestión (módulos oliva).
- **Do** usar sombras suaves y largas; teñir de verde solo la del hero.
- **Do** cuidar el contraste AA y el foco visible en ambos temas (WCAG 2.1 AA, requisito de centro público).

### Don't:
- **Don't** usar rojo para un vencimiento rutinario (eso es naranja); el rojo es destructivo/ausente.
- **Don't** meter dos anclas oscuras compitiendo en la misma pantalla.
- **Don't** dar a lo personal (agenda) el verde de gestión, ni al revés: el malva marca privacidad.
- **Don't** derivar hacia una estética infantil de "app de colegio" (colorines, iconografía juvenil, tono lúdico).
- **Don't** poner cifras en la serif ni títulos en la mono: los números se tabulan, el texto se lee.
- **Don't** romper la paridad claro/oscuro definiendo un color solo para un tema.
