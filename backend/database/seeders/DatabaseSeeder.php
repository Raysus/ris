<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Persona;

class DatabaseSeeder extends Seeder
{
    /**
     * Diccionario en memoria para mapear los IDs antiguos (Integer) a los nuevos (UUID)
     */
    private $idMap = [];

    /**
     * Función mágica que genera un UUID para un ID antiguo, o recupera el UUID si ya fue generado.
     */
    /**
     * Función mágica determinista: Genera SIEMPRE el mismo UUID para un mismo registro,
     * garantizando que el entorno local y la nube sean espejos exactos.
     */
    private function getNewId($table, $oldId)
    {
        if ($oldId === null || $oldId === '\N') {
            return null;
        }

        // Generamos un hash MD5 único y predecible
        $hash = md5($table . '_' . $oldId);

        // Lo formateamos visualmente como un UUID válido (8-4-4-4-12)
        return substr($hash, 0, 8) . '-' .
            substr($hash, 8, 4) . '-' .
            substr($hash, 12, 4) . '-' .
            substr($hash, 16, 4) . '-' .
            substr($hash, 20, 12);
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
            [1, 'sis_admin', 'Sys. Admin'],
            [7, 'auxiliar', 'Auxiliar'],
            [3, 'recepcion', 'Recepcionista'],
            [8, 'secretario', 'Secretario(a)'],
            [9, 'secretaria', 'Secretaria'],
            [2, 'admin', 'Administrador(a)'],
            [4, 'tecnologo', 'Tecnólogo(a)'],
            [5, 'radiologo', 'Médico Radiólogo(a)'],
            [6, 'transcriptor', 'Transcriptor(a)'],
            [10, 'tens', 'TENS'],
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
            [1, 'Clínico Humano', 'clinical'],
            [2, 'Veterinario', 'veterinary'],
            [3, 'Centro Dental', 'dental'],
        ];
        foreach ($labTypes as $lt) {
            DB::table('laboratory_types')->insert([
                'id' => $this->getNewId('laboratory_types', $lt[0]),
                'name' => $lt[1],
                'code' => $lt[2],
                'created_at' => $now,
                'updated_at' => $now,
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
            $persona = new Persona([
                'rut' => $p[1],
                'names' => $p[2],
                'last_name_1' => $p[3],
                'last_name_2' => $p[4],
                'gender' => $p[5],
                'birth_date' => $p[6],
                'phone' => $p[7],
                'email' => $p[8],
                'has_sso_account' => $p[9],
            ]);
            $persona->id = $this->getNewId('personas', $p[0]);
            $persona->created_at = $now;
            $persona->updated_at = $now;
            $persona->save();
        }

        // ==========================================
        // 4. USERS
        // ==========================================
        $users = [
            [1, 1, 2, 'admin', '$2y$12$6Llt.ROd1gYP/soeOi.W1.sDCqnphi/QMh7FfyDOPBHKh/qZkCWGO', '{"roles":["admin"]}', true, null],
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
            [5, 1, null, 'Siresa', 'Manuel Montt 942', 'Temuco', '(45) 269 0000', '{"horaFin": "20:00:00", "intervalo": "00:15:00", "horaInicio": "08:00:00", "colorInforme": "#000000"}'],
            [6, 1, 5, 'Siresa Dinamarca', 'Dinamarca 661', 'Temuco', '(45) 288 8724', '[]'],
            [7, 1, 5, 'Siresa Lautaro', 'Av. O\'higgins 915', 'Lautaro', '(45) 273 8218', '[]'],
            [8, 1, 5, 'Siresa Victoria', 'Av. Arturo Prat 1130', 'Victoria', '(45) 288 8731', '[]'],
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
            [5, 1, true],
            [5, 2, true],
            [6, 2, false],
            [7, 2, false],
            [8, 2, false],
        ];

        foreach ($labUsers as $lu) {
            DB::table('laboratory_user')->insert([
                // ✅ Generamos UUID determinista para el pivote
                'id' => $this->getNewId('laboratory_user', $lu[0] . '_' . $lu[1]),

                // ✅ Relacionamos con el laboratorio
                'laboratory_id' => $this->getNewId('laboratories', $lu[0]),

                // 🔥 CORRECCIÓN: Faltaba esta línea o estaba mal referenciada
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
            [3, '0', 'Particular'],
            [4, '1', 'Fonasa'],
            [5, '2', 'Isapre Banmédica'],
            [6, '3', 'Isapre Colmena'],
            [7, '4', 'Isapre Consalud'],
            [8, '5', 'Isapre Cruz Blanca'],
            [9, '6', 'Isapre Vida Tres'],
            [10, '7', 'Isapre Masvida'],
            [11, '8', 'DIPRECA'],
            [12, '9', 'CAPREDENA'],
            [13, '10', 'Convenios Directos']
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
        // 7.5 PLANES DE SALUD (Insurance Plans)
        // ==========================================
        $plans = [
            // PARTICULAR
            [1, 3, 'Particular sin copago', 0],
            [2, 3, 'Particular 10% copago', 10],
            [3, 3, 'Particular 20% copago', 20],
            
            // FONASA
            [4, 4, 'FONASA Modalidad Institucional', 0],
            [5, 4, 'FONASA Modalidad Libre (10% copago)', 10],
            [6, 4, 'FONASA Modalidad Libre (15% copago)', 15],
            
            // ISAPRES
            [7, 5, 'Banmédica Plan Familia', 15],
            [8, 5, 'Banmédica Plan Individual', 20],
            
            [9, 6, 'Isapre Colmena Plan A', 12],
            [10, 6, 'Isapre Colmena Plan B', 18],
            
            [11, 7, 'Isapre Consalud Plan I', 10],
            [12, 7, 'Isapre Consalud Plan II', 15],
            
            [13, 8, 'Isapre Cruz Blanca Plan Básico', 8],
            [14, 8, 'Isapre Cruz Blanca Plan Plus', 12],
            
            [15, 9, 'Isapre Vida Tres Plan Estándar', 15],
            [16, 9, 'Isapre Vida Tres Plan Premium', 10],
            
            [17, 10, 'Isapre Masvida Plan Activo', 12],
            [18, 10, 'Isapre Masvida Plan Cuidado', 15],
            
            // INSTITUTOS MILITARES
            [19, 11, 'DIPRECA Plan Familia', 5],
            [20, 12, 'CAPREDENA Plan Familia', 5],
            
            // CONVENIOS
            [21, 13, 'Convenio Directo', 0],
        ];
        
        foreach ($plans as $p) {
            DB::table('insurance_plans')->insert([
                'id' => $this->getNewId('insurance_plans', $p[0]),
                'insurance_id' => $this->getNewId('insurances', $p[1]),
                'name' => $p[2],
                'percentage' => $p[3],
                'laboratory_id' => $this->getNewId('laboratories', 5),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // ==========================================
        // 8–12. Catálogo operativo (máquinas, exámenes, insumos): importar desde nube
        //   Admin → Sync Nube → Catálogo (+ usuarios)
        //   Para ECOTEMUCO en LAN sin Siresa: use EcotemucoLabSeeder (docs/DESPLIEGUE_ECOTEMUCO_LOCAL.md)
        // ==========================================

        // Reactivamos las llaves foráneas
        \Schema::enableForeignKeyConstraints();
    }
}