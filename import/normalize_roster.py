#!/usr/bin/env python3
"""Normaliza el listado de docentes del centro a un CSV estable para el importador.

Entrada: el texto de `pdftotext -layout docentes-email.pdf` (columnas alineadas por espacios).
Salida (stdout): CSV con cabecera `full_name,email,department,cargo`.

El parseo se ancla en dos señales fiables y NO en las posiciones de columna (que varían con
nombres/apellidos de longitud distinta):

  1. el email institucional (`...@educa.madrid.org`) cierra cada fila de dato;
  2. el "Puesto" (departamento) es uno de un conjunto cerrado conocido.

Con eso, el nombre completo es lo que va antes del departamento y el cargo lo que va entre el
departamento y el email. No se guardan datos personales en el repo: este script es código; su
salida (CSV con PII) va fuera del control de versiones.

Uso:
    pdftotext -layout docentes-email.pdf - | python3 import/normalize_roster.py > roster.csv
"""

import csv
import re
import sys

# Conjunto cerrado de departamentos/puestos tal como aparecen en el PDF. Ordenados por longitud
# descendente para que el emparejamiento tome siempre el más largo (evita que "Biología y Geología"
# se confunda con un prefijo). Añadir aquí si el centro incorpora un puesto nuevo.
DEPARTMENTS = [
    "Operaciones y Equipos de Producción Agraria",
    "Servicios a la Comunidad-Profesores",
    "Lengua Castellana y Literatura",
    "Geografía e Historia",
    "Biología y Geología",
    "Pedagogía Terapeutica",
    "Orientación Educativa",
    "Audición y Lenguaje",
    "Física y Química",
    "Educación Física",
    "Aula de Enlace",
    "Matemáticas",
    "Tecnología",
    "Economía",
    "Filosofía",
    "Religión",
    "Francés",
    "Música",
    "Dibujo",
    "Latín",
    "Ingles",
]

EMAIL_RE = re.compile(r"[\w.\-]+@educa\.madrid\.org", re.IGNORECASE)


def parse_line(line: str):
    """Devuelve (full_name, email, department, cargo) para una fila de dato, o None si no lo es."""
    email_match = EMAIL_RE.search(line)
    if not email_match:
        return None
    email = email_match.group(0).strip().lower()

    before_email = line[: email_match.start()]

    # Localiza el departamento más largo que aparezca en el texto previo al email.
    dept = None
    dept_at = -1
    for candidate in DEPARTMENTS:
        idx = before_email.find(candidate)
        if idx != -1:
            dept = candidate
            dept_at = idx
            break
    if dept is None:
        return None

    full_name = " ".join(before_email[:dept_at].split())
    cargo = before_email[dept_at + len(dept):]
    # El asterisco es una marca al margen del PDF, no un cargo; se descarta.
    cargo = " ".join(cargo.replace("*", " ").split())

    return full_name, email, dept, cargo


def main() -> int:
    writer = csv.writer(sys.stdout)
    writer.writerow(["full_name", "email", "department", "cargo"])
    count = 0
    for line in sys.stdin:
        parsed = parse_line(line)
        if parsed is not None:
            writer.writerow(parsed)
            count += 1
    print(f"# {count} docentes", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
