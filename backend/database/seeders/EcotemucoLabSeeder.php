<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Persona;

/**
 * Instalación mínima para laboratorio ECOTEMUCO en LAN.
 *
 * - No crea Siresa ni sucursales demo.
 * - Casa matriz ECOTEMUCO con el mismo UUID que en la nube (sync catálogo/citas).
 * - Usuario admin (sis_admin) para primer acceso.
 *
 * Uso (primer arranque Docker):
 *   DB_AUTO_SEED=true
 *   DB_SEED_CLASS=EcotemucoLabSeeder
 *
 * Luego: DB_AUTO_SEED=false y Admin → Sync Nube → Catálogo (+ usuarios si aplica).
 */
class EcotemucoLabSeeder extends Seeder
{
    /** UUID de ECOTEMUCO en ris.healthticloud.cl (nube). */
    public const ECOTEMUCO_LAB_ID = '019ef4e0-a7f9-73d0-be80-db47957e1a6b';

    private function uuid(string $table, string|int $oldId): string
    {
        $hash = md5($table . '_' . $oldId);

        return substr($hash, 0, 8) . '-'
            . substr($hash, 8, 4) . '-'
            . substr($hash, 12, 4) . '-'
            . substr($hash, 16, 4) . '-'
            . substr($hash, 20, 12);
    }

    public function run(): void
    {
        \Schema::disableForeignKeyConstraints();

        $now = Carbon::now();

        $tipos = [
            [1, 'sis_admin', 'Sys. Admin'],
            [2, 'admin', 'Administrador(a)'],
            [3, 'recepcion', 'Recepcionista'],
            [4, 'tecnologo', 'Tecnólogo(a)'],
            [5, 'radiologo', 'Médico Radiólogo(a)'],
            [6, 'transcriptor', 'Transcriptor(a)'],
            [7, 'auxiliar', 'Auxiliar'],
            [8, 'secretario', 'Secretario(a)'],
            [9, 'secretaria', 'Secretaria'],
        ];
        foreach ($tipos as $t) {
            DB::table('tipo_usuarios')->insertOrIgnore([
                'id' => $this->uuid('tipo_usuarios', $t[0]),
                'name' => $t[1],
                'description' => $t[2],
                'permissions' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $labTypes = [
            [1, 'Clínico Humano', 'clinical'],
            [2, 'Veterinario', 'veterinary'],
            [3, 'Centro Dental', 'dental'],
        ];
        foreach ($labTypes as $lt) {
            DB::table('laboratory_types')->insertOrIgnore([
                'id' => $this->uuid('laboratory_types', $lt[0]),
                'name' => $lt[1],
                'code' => $lt[2],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $personaId = $this->uuid('personas', 1);
        if (!Persona::find($personaId)) {
            $persona = new Persona([
                'rut' => '11111111-1',
                'names' => 'Admin',
                'last_name_1' => 'Sistema',
                'has_sso_account' => false,
            ]);
            $persona->id = $personaId;
            $persona->created_at = $now;
            $persona->updated_at = $now;
            $persona->save();
        }

        $userId = $this->uuid('users', 1);
        DB::table('users')->insertOrIgnore([
            'id' => $userId,
            'persona_id' => $personaId,
            'tipo_usuario_id' => $this->uuid('tipo_usuarios', 1),
            'username' => 'admin',
            'password' => '$2y$12$b4tw76R98938HjrhIfMrH.Qjl2twQAODkYi9yTIIlpa517GCRN9FW',
            'settings' => '{"roles":["sis_admin","admin"]}',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('laboratories')->insertOrIgnore([
            'id' => self::ECOTEMUCO_LAB_ID,
            'laboratory_type_id' => $this->uuid('laboratory_types', 1),
            'parent_id' => null,
            'name' => 'ECOTEMUCO',
            'address' => null,
            'city' => 'Temuco',
            'phone' => null,
            'settings' => json_encode([
                'horaInicio' => '08:00:00',
                'horaFin' => '20:00:00',
                'intervalo' => '00:15:00',
                'colorInforme' => '#000000',
            ]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('laboratory_user')->insertOrIgnore([
            'id' => $this->uuid('laboratory_user', 'ecotemuco_admin'),
            'laboratory_id' => self::ECOTEMUCO_LAB_ID,
            'user_id' => $userId,
            'is_primary' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

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
            [13, '10', 'Convenios Directos'],
        ];
        foreach ($insurances as $ins) {
            DB::table('insurances')->insertOrIgnore([
                'id' => $this->uuid('insurances', $ins[0]),
                'code' => $ins[1],
                'name' => $ins[2],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        \Schema::enableForeignKeyConstraints();
    }
}
