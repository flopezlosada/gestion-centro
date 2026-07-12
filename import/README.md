# Importar el claustro (personas, departamentos, cargo)

Pipeline en dos etapas para cargar el listado de docentes del centro en la base de datos. El **código**
vive aquí; los **datos** (con datos personales y el nombre del centro) NO entran al repositorio — el CSV
y el PDF de origen son gitignored (van bajo `var/`, que está ignorado).

## Etapas

1. **Extraer** el PDF oficial a texto con columnas alineadas:

   ```bash
   pdftotext -layout docentes-email.pdf var/import/docentes.txt
   ```

2. **Normalizar** a un CSV estable (`full_name,email,department,cargo`):

   ```bash
   python3 import/normalize_roster.py < var/import/docentes.txt > var/import/roster.csv
   ```

   El parseo se ancla en el email institucional y en el conjunto cerrado de departamentos
   (`DEPARTMENTS` en el script), no en posiciones de columna. Si el centro incorpora un puesto nuevo,
   añádelo a esa lista.

3. **Importar** a la base de datos (idempotente; `--dry-run` para ver el resumen sin escribir):

   ```bash
   ddev exec php bin/console app:import-roster var/import/roster.csv --dry-run
   ddev exec php bin/console app:import-roster var/import/roster.csv
   ```

   Personas por email, departamentos y roles por código: re-ejecutar tras un cambio a mitad de curso
   solo actualiza lo que se movió, sin duplicar.

## Mapeo

- Cada fila → un `User` (nombre + email) en su `Unit` (departamento).
- `cargo` → rol: `DIRECTORA`→`direccion`, `JEFE/JEFA DE ESTUDIOS`→`jefatura_estudios`, `... ADJ.`→
  `jefatura_adjunta`, `SECRETARIA`→`secretaria`, `TUTOR/A ...`→`tutor`. Todos reciben además `docente`.

## Límites (verificar antes de producción)

- **Jefes de departamento no vienen en el origen** → las unidades quedan **sin responsable**
  (`manager` nulo). Hay que asignarlos a mano para cerrar la cadena de mando.
- **Permisos:** solo `direccion` recibe acceso al back-office (escritura en Administración). El resto de
  roles son marcadores; conceder más permisos es una decisión explícita del admin.
- **Datos personales:** aunque este listado es público en la web del centro, es dato personal del
  profesorado. Confirmar el visto bueno del DPD antes de la carga en producción.
- Verificar los emails/departamentos contra el origen tras cada extracción (un cambio de formato del
  PDF puede requerir ajustar el normalizador).
