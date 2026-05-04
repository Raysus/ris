<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Diccionario en memoria para mapear los IDs antiguos (Integer) a los nuevos (UUID)
     */
    private $idMap = [];

    /**
     * Función mágica que genera un UUID para un ID antiguo, o recupera el UUID si ya fue generado.
     */
    private function getNewId($table, $oldId)
    {
        if ($oldId === null || $oldId === '\N') {
            return null;
        }
        if (!isset($this->idMap[$table][$oldId])) {
            $this->idMap[$table][$oldId] = (string) Str::uuid();
        }
        return $this->idMap[$table][$oldId];
    }

    public function run(): void
    {
        // 1. Desactivamos validaciones de llaves foráneas por seguridad durante la carga
        \Schema::disableForeignKeyConstraints();

        $now = Carbon::now();

        // ==========================================
        // 1. TIPO DE USUARIOS
        // ==========================================
        $tipos = [
            [1, 'sis_admin', 'Sys. Admin'], [7, 'auxiliar', 'Auxiliar'],
            [3, 'recepcion', 'Recepcionista'], [8, 'secretario', 'Secretario(a)'],
            [2, 'admin', 'Administrador(a)'], [4, 'tecnologo', 'Tecnólogo(a)'],
            [5, 'radiologo', 'Médico Radiólogo(a)'], [6, 'transcriptor', 'Transcriptor(a)']
        ];
        foreach ($tipos as $t) {
            DB::table('tipo_usuarios')->insert([
                'id' => $this->getNewId('tipo_usuarios', $t[0]),
                'name' => $t[1],
                'description' => $t[2],
                'permissions' => '{}',
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 2. TIPOS DE LABORATORIO
        // ==========================================
        $labTypes = [
            [1, 'Clínico Humano'], [2, 'Veterinario'], [3, 'Centro Dental']
        ];
        foreach ($labTypes as $lt) {
            DB::table('laboratory_types')->insert([
                'id' => $this->getNewId('laboratory_types', $lt[0]),
                'name' => $lt[1],
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 3. PERSONAS
        // ==========================================
        $personas = [
            [1, '11111111-1', 'Admin', 'Sistema', null, null, null, null, null, false],
            [3, '19.713.567-0', 'Fernanda', 'Riquelme', 'Vergara', 'F', '1997-09-18', '+56966952729', 'paola.riquelme.v@gmail.com', false],
            [2, '18.194.675-K', 'Raúl Antonio', 'Gutiérrez', 'Elgueta', 'M', '1992-02-29', '+56966952729', 'ra.guti.el@gmail.com', true],
            [5, '8.376.767-7', 'ENRIQUE VASQUEZ', 'GUTIERREZ', null, null, null, null, null, false]
        ];
        foreach ($personas as $p) {
            DB::table('personas')->insert([
                'id' => $this->getNewId('personas', $p[0]),
                'rut' => $p[1],
                'names' => $p[2],
                'last_name_1' => $p[3],
                'last_name_2' => $p[4],
                'gender' => $p[5],
                'birth_date' => $p[6],
                'phone' => $p[7],
                'email' => $p[8],
                'has_sso_account' => $p[9],
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 4. USERS
        // ==========================================
        $users = [
            [1, 1, 1, 'admin', '$2y$12$6Llt.ROd1gYP/soeOi.W1.sDCqnphi/QMh7FfyDOPBHKh/qZkCWGO', '{}', true, null],
            [2, 2, 2, 'rgutierrez', '$2y$12$yXYmAXSw4qjrXdUUnqxCIOSQkY8CBuX7Fs3GX04I8NIy0nndwUhGW', '{"roles": ["admin", "recepcion", "tecnologo", "radiologo", "transcriptor"]}', true, 'Sr.'],
            [3, 3, 4, 'friquelme', '$2y$12$ofsAN0.CxLVwTYeELZjLaefwEARnJxuB5fzRkQquSEjjQJzOwYAjO', '{"roles": ["tecnologo"]}', true, 'T.M.'],
            [4, 5, 1, 'SEVG', '$2y$12$NGJ2csIngANxoWxIaPU8EesJGIKPv96Bn2eX2Yi8KOrYifMFKDE3q', '{}', true, 'Sr.']
        ];
        foreach ($users as $u) {
            DB::table('users')->insert([
                'id' => $this->getNewId('users', $u[0]),
                'persona_id' => $this->getNewId('personas', $u[1]),
                'tipo_usuario_id' => $this->getNewId('tipo_usuarios', $u[2]),
                'username' => $u[3],
                'password' => $u[4],
                'settings' => $u[5],
                'is_active' => $u[6],
                'medical_title' => $u[7],
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 5. LABORATORIES (Manejo de parent_id recursivo)
        // ==========================================
        $labs = [
            [3, 1, null, 'IMEX Temuco', 'Dinamarca 621 Oficina 104', 'Temuco', '(45)2204250', '{}'],
            [1, 1, null, 'Centro de Diagnóstico RIS PRO', 'Av. Las Araucarias 1020', 'Temuco', null, '{"horaFin": "18:30", "intervalo": "00:15:00", "horaInicio": "08:30"}'],
            [4, 1, 1, 'Sucursal Sur', 'test', 'Temuco', '1233414', '[]'],
            [5, 1, null, 'Siresa', 'Manuel Montt 942', 'Temuco', '(45) 269 0000', '{"horaFin": "20:00:00", "intervalo": "00:15:00", "horaInicio": "08:00:00", "colorInforme": "#000000"}'],
            [6, 1, 5, 'Siresa Dinamarca', 'Dinamarca 661', 'Temuco', '(45) 288 8724', '[]'],
            [7, 1, 5, 'Siresa Lautaro', 'Av. O\'higgins 915', 'Lautaro', '(45) 273 8218', '[]'],
            [8, 1, 5, 'Siresa Victoria', 'Av. Arturo Prat 1130', 'Victoria', '(45) 288 8731', '[]']
        ];
        foreach ($labs as $l) {
            DB::table('laboratories')->insert([
                'id' => $this->getNewId('laboratories', $l[0]),
                'laboratory_type_id' => $this->getNewId('laboratory_types', $l[1]),
                'parent_id' => $this->getNewId('laboratories', $l[2]), // <- Magia UUID recursiva
                'name' => $l[3],
                'address' => $l[4],
                'city' => $l[5],
                'phone' => $l[6],
                'settings' => $l[7],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 6. LABORATORY_USER (Pivote)
        // ==========================================
        $labUsers = [
            [1, 3, true], [1, 2, true], [5, 2, false], [1, 4, true]
        ];
        foreach ($labUsers as $lu) {
            DB::table('laboratory_user')->insert([
                'id' => Str::uuid(), // Pivotes sin ID previo en BD pueden llevar UUID nuevo al azar
                'laboratory_id' => $this->getNewId('laboratories', $lu[0]),
                'user_id' => $this->getNewId('users', $lu[1]),
                'is_primary' => $lu[2],
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 7. PREVISIONES (Insurances)
        // ==========================================
        $insurances = [
            [3, '0', 'Particular'], [4, '1', 'Fonasa'], [5, '2', 'Isapre Banmédica'],
            [6, '3', 'Isapre Colmena'], [7, '4', 'Isapre Consalud'], [8, '5', 'Isapre Cruz Blanca'],
            [9, '6', 'Isapre Vida Tres'], [10, '7', 'Isapre Masvida'], [11, '8', 'DIPRECA'],
            [12, '9', 'CAPREDENA'], [13, '10', 'Convenios Directos']
        ];
        foreach ($insurances as $ins) {
            DB::table('insurances')->insert([
                'id' => $this->getNewId('insurances', $ins[0]),
                'code' => $ins[1],
                'name' => $ins[2],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 8. MACHINES
        // ==========================================
        $machines = [
            [1, 1, 'LUNAR', 'DEXA', '192.168.1.55', 'QrRisSCP', true],
            [3, 1, 'LORAD - M3', 'MAMO', '192.168.1.55', 'QrRisSCP', false],
            [4, 1, 'Philips IU22', 'ECO', '192.168.1.55', 'QrRisSCP', true],
            [5, 1, 'BENNET - MOVIPLAN800', 'RX', '192.168.1.55', 'QrRisSCP', true],
            [7, 1, 'Philips Affiniti 70', 'ECO', '192.168.1.55', 'QrRisSCP', true],
            [8, 1, 'Scanner GE', 'CT', '192.168.1.55', 'QrRisSCP', true],
            [9, 1, 'LORAD - M4', 'MAMO', '192.168.1.55', 'QrRisSCP', true],
            [10, 1, 'TOSHIBA - APLIO 500', 'ECO', '192.168.1.55', 'QrRisSCP', false],
            [236, 1, 'Siemens', 'RX', '192.168.1.55', 'QrRisSCP', true]
        ];
        foreach ($machines as $m) {
            DB::table('machines')->insert([
                'id' => $this->getNewId('machines', $m[0]),
                'laboratory_id' => $this->getNewId('laboratories', $m[1]),
                'name' => $m[2],
                'group' => $m[3],
                'ip_address' => $m[4],
                'ae_title' => $m[5],
                'is_active' => $m[6],
                'event_color' => '#3788d8',
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 9. EXÁMENES
        // ==========================================
        $exams = [
            [3, 1, 'RX', 'Teleradiograf de cavidades cardíacas F y L con bario.', '0401006', 0.00, 15],
            [8, 1, 'MAMO', 'Proyección complementaria de Mamas (axial)', '0401130', 0.00, 30],
            [9, 1, 'MAMO', 'Marcación preoperatoria de lesiones de la Mama', '0401011', 0.00, 30],
            [10, 1, 'MAMO', 'Radiografía de mamas, pieza operatoria', '0401012', 0.00, 30],
            [12, 1, 'RX', 'Proyección complementaria', '0401014', 0.00, 15],
            [13, 1, 'RX', 'Pielografía de eliminación', '0401027', 0.00, 90],
            [16, 1, 'RX', 'Agujeros ópticos', '0401030', 0.00, 15],
            [46, 1, 'ECO', 'Ecotomografía abdominal', '0404003', 48000.00, 20],
            [57, 1, 'ECO', 'Ecotomografía vascular periférica Bilateral', '0404118', 85000.00, 60],
            [5, 1, 'RX', 'Tórax simple (2 proyecciones)', '0401070', 28000.00, 15],
            [18, 1, 'RX', 'Cráneo F y L', '0401032', 28000.00, 15],
            [6, 1, 'MAMO', 'Mamografía bilateral (4 proyecciones)', '0401010', 45000.00, 30],
            [63, 1, 'CT', 'Cerebro (30 cortes, 8-10 mm)', '0403001', 115000.00, 30],
            [81, 1, 'CT', 'Abdomen y Pelvis', '0403020', 195000.00, 70],
            [77, 1, 'CT', 'Angiotac de cerebro', '0403101', 210000.00, 30],
            [78, 1, 'CT', 'Angiotac de torax', '0403102', 210000.00, 30],
            [79, 1, 'CT', 'Angiotac de ...', '0403103', 210000.00, 30],
            [80, 1, 'CT', 'Angiotac de cuello', '0403118', 210000.00, 30],
            [82, 1, 'CT', 'Columna Lumbar', '0403019', 115000.00, 70]
        ];
        foreach ($exams as $e) {
            DB::table('exams')->insert([
                'id' => $this->getNewId('exams', $e[0]),
                'laboratory_id' => $this->getNewId('laboratories', $e[1]),
                'group_code' => $e[2],
                'name' => $e[3],
                'fonasa_code' => $e[4],
                'price' => $e[5],
                'estimated_duration' => $e[6],
                'sub_exams' => '[]',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 10. SUB-EXÁMENES
        // ==========================================
        $subExams = [
            [2, 81, 'Sin Contraste'], [3, 81, 'Con Contraste'], [4, 81, 'Trifásico (Hígado)'],
            [5, 46, 'Adulto'], [6, 46, 'Niño'], [8, 5, 'Estándar'], [9, 82, 'Simple (F y L)'],
            [10, 82, 'Funcional (Dinámicas)'], [11, 77, 'Protocolo Angio c/c'], 
            [12, 78, 'Protocolo Angio c/c'], [13, 79, 'Protocolo Angio c/c'], [14, 80, 'Protocolo Angio c/c']
        ];
        foreach ($subExams as $se) {
            DB::table('sub_exams')->insert([
                'id' => $this->getNewId('sub_exams', $se[0]),
                'exam_id' => $this->getNewId('exams', $se[1]),
                'name' => $se[2],
                'additional_price' => 0,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 11. INSUMOS (Supplies)
        // ==========================================
        $supplies = [
            [1, 1, 'CONTRASTE', 'Medio de Contraste Iodado 100ml', 50, 200, 35000.00],
            [3, 1, 'CONTRASTE', 'Suero Fisiológico 250ml', 100, 500, 2500.00],
            [4, 1, 'FUNGIBLE', 'Jeringa 20cc', 500, 2000, 150.00],
            [5, 1, 'FUNGIBLE', 'Conector de doble cabezal', 80, 300, 4500.00],
            [6, 1, 'FUNGIBLE', 'Aguja Biopsia 14G', 15, 50, 28000.00],
            [7, 1, 'FUNGIBLE', 'Catéter Endovenoso 20G', 200, 1000, 800.00],
            [8, 1, 'PROTECCION', 'Bata Desechable Paciente', 300, 1000, 1200.00],
            [9, 1, 'FUNGIBLE', 'Placas / Film 14x17', 100, 1000, 3500.00],
            [2, 1, 'CONTRASTE', 'Gadolinio (Resonancia) 15ml', 29, 100, 42000.00]
        ];
        foreach ($supplies as $sup) {
            DB::table('supplies')->insert([
                'id' => $this->getNewId('supplies', $sup[0]),
                'laboratory_id' => $this->getNewId('laboratories', $sup[1]),
                'category' => $sup[2],
                'name' => $sup[3],
                'stock' => $sup[4],
                'max_stock' => $sup[5],
                'price' => $sup[6],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 12. SUPPLY PACKS (Paquetes de Insumos)
        // ==========================================
        $packs = [
            [1, 1, 'Pack Contraste TC Adulto'],
            [2, 1, 'Pack Contraste TC Pediátrico'],
            [3, 1, 'Pack Biopsia Core Mama'],
            [4, 1, 'Pack Inyección Artro-RM']
        ];
        foreach ($packs as $pk) {
            DB::table('supply_packs')->insert([
                'id' => $this->getNewId('supply_packs', $pk[0]),
                'laboratory_id' => $this->getNewId('laboratories', $pk[1]),
                'name' => $pk[2],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // Reactivamos las llaves foráneas
        \Schema::enableForeignKeyConstraints();
    }
}