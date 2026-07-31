<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El cuadrante de recreo pasa de «una fila = una guardia» a «una fila = una PLAZA».
 *
 * La primera versión del modelo guardaba en cada fila qué recreos cubría (`periods`: first / second /
 * both), porque la regla que había dado el centro era «cubrir los dos tramos cuenta como una sola
 * guardia». La regla resultó ser otra: **una guardia es un recreo grande MÁS uno corto, y no tienen que
 * caer el mismo día**. Con eso, abarcar los dos deja de ser una propiedad de la fila:
 *
 * - `periods` → `period` (first / second). Cada fila es una plaza de UN recreo.
 * - el único (curso, profesor, día) → (curso, profesor, día, recreo). Lo único imposible es estar en dos
 *   zonas en el MISMO recreo; vigilar el patio en el grande y la biblioteca en el corto es justo lo que
 *   el centro pidió poder hacer, y el índice anterior lo prohibía.
 * - las guardias pasan a contarse (`min(grandes, cortos)`), y lo que queda sin pareja es media.
 *
 * **Backfill sin pérdida:** cada fila `both` se DUPLICA en dos plazas (grande y corto, misma zona y
 * mismo día), que es exactamente lo que esa fila significaba. Las `first` y `second` se quedan como
 * están, ya eran de un solo recreo.
 *
 * Y `break_zone_demand`: cuántas personas necesita una zona en una celda concreta (día × recreo). Guarda
 * solo EXCEPCIONES — el caso normal sigue siendo el número de la zona — porque las dos cosas que pidió el
 * centro son excepciones: «en los recreos cortos no hay patios dirigidos» y «los patios dirigidos los
 * organiza por días el equipo directivo». Nace vacía: sin filas, todo se comporta como hasta ahora.
 */
final class Version20260731200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cuadrante de recreo: una fila = una plaza (period), y demanda por celda (break_zone_demand).';
    }

    public function up(Schema $schema): void
    {
        // 1. La columna nueva, rellenada desde la vieja: 'both' se resuelve como la plaza del recreo
        //    grande, y la del corto se añade abajo como fila aparte.
        $this->addSql("ALTER TABLE break_duty_assignment ADD period VARCHAR(8) DEFAULT 'first' NOT NULL");
        $this->addSql("UPDATE break_duty_assignment SET period = CASE WHEN periods = 'second' THEN 'second' ELSE 'first' END");

        // 2. El índice único, ANTES de duplicar: el viejo (curso, profesor, día) impediría insertar la
        //    segunda plaza del mismo día.
        $this->addSql('DROP INDEX UNIQ_break_duty_teacher_weekday ON break_duty_assignment');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_break_duty_teacher_period ON break_duty_assignment (academic_year_id, teacher_id, weekday, period)');

        // 3. Cada 'both' era dos plazas: se crea la del recreo corto que le faltaba. La columna vieja se
        //    rellena también, porque todavía existe y es NOT NULL sin default: omitirla aborta el INSERT
        //    en modo estricto. Se borra justo después.
        $this->addSql(<<<'SQL'
            INSERT INTO break_duty_assignment (academic_year_id, teacher_id, zone_id, weekday, period, periods)
            SELECT academic_year_id, teacher_id, zone_id, weekday, 'second', 'second'
            FROM break_duty_assignment
            WHERE periods = 'both'
            SQL);

        $this->addSql('ALTER TABLE break_duty_assignment DROP periods');
        // El default solo servía para rellenar las filas que ya existían; a partir de aquí toda plaza
        // llega con su recreo puesto, y dejarlo haría que el esquema no cuadrase con la entidad.
        $this->addSql('ALTER TABLE break_duty_assignment CHANGE period period VARCHAR(8) NOT NULL');

        // Quién puso cada plaza. Todo lo que ya existe lo puso una persona a mano, que es justo lo que el
        // motor debe respetar: al proponer de nuevo solo se reemplaza lo que puso él. Aquí el default SÍ
        // se queda, porque la entidad también lo declara.
        $this->addSql("ALTER TABLE break_duty_assignment ADD source VARCHAR(8) DEFAULT 'manual' NOT NULL");

        $this->addSql('CREATE TABLE break_zone_demand (id INT AUTO_INCREMENT NOT NULL, zone_id INT NOT NULL, weekday SMALLINT NOT NULL, period VARCHAR(8) NOT NULL, required_teachers SMALLINT NOT NULL, UNIQUE INDEX UNIQ_break_demand_cell (zone_id, weekday, period), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE break_zone_demand ADD CONSTRAINT FK_break_demand_zone FOREIGN KEY (zone_id) REFERENCES break_zone (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE break_zone_demand');
        $this->addSql('ALTER TABLE break_duty_assignment DROP source');

        $this->addSql("ALTER TABLE break_duty_assignment ADD periods VARCHAR(8) DEFAULT 'both' NOT NULL");
        $this->addSql('UPDATE break_duty_assignment SET periods = period');

        // Deshacer el reparto. Donde la misma persona tenga las dos plazas del mismo día Y la misma zona,
        // vuelven a plegarse en una fila 'both' y sobra la del recreo corto.
        $this->addSql(<<<'SQL'
            UPDATE break_duty_assignment a
            JOIN break_duty_assignment b
              ON b.academic_year_id = a.academic_year_id AND b.teacher_id = a.teacher_id
             AND b.weekday = a.weekday AND b.zone_id = a.zone_id AND b.period = 'second'
            SET a.periods = 'both'
            WHERE a.period = 'first'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE b FROM break_duty_assignment b
            JOIN break_duty_assignment a
              ON a.academic_year_id = b.academic_year_id AND a.teacher_id = b.teacher_id
             AND a.weekday = b.weekday AND a.zone_id = b.zone_id AND a.period = 'first'
            WHERE b.period = 'second'
            SQL);

        // Lo que NO forma pareja del mismo día y zona se queda como una fila 'second' suelta, que el
        // modelo viejo admitía perfectamente. Borrarlas sería tirar datos: incluye tanto las que ya
        // existían antes de esta migración como las parejas en días o zonas distintas, que es
        // precisamente lo que el modelo nuevo permite crear y el viejo no sabía representar.
        //
        // Aviso honesto de lo que SÍ se pierde al bajar: una pareja repartida entre dos días o dos zonas
        // deja de contar como una guardia y pasa a ser dos filas sueltas. El dato sigue ahí; la relación
        // entre ellas, no — el modelo viejo no tenía dónde guardarla.
        $this->addSql("UPDATE break_duty_assignment SET periods = 'second' WHERE period = 'second'");

        // El único que sí estorba: el índice viejo prohíbe dos filas de la misma persona el mismo día, así
        // que si quedan sueltas de los dos recreos hay que dejar una. Se conserva la del recreo grande.
        $this->addSql(<<<'SQL'
            DELETE b FROM break_duty_assignment b
            JOIN break_duty_assignment a
              ON a.academic_year_id = b.academic_year_id AND a.teacher_id = b.teacher_id
             AND a.weekday = b.weekday AND a.id <> b.id
            WHERE b.period = 'second' AND a.period = 'first'
            SQL);

        $this->addSql('DROP INDEX UNIQ_break_duty_teacher_period ON break_duty_assignment');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_break_duty_teacher_weekday ON break_duty_assignment (academic_year_id, teacher_id, weekday)');
        $this->addSql('ALTER TABLE break_duty_assignment DROP period');
    }
}
