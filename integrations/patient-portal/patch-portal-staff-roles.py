#!/usr/bin/env python3
"""Incluye secretaria/admin_siresa en vista staff del portal (listado pacientes/estudios)."""
from pathlib import Path

CTRL = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
AUTH = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/AuthController.php")

# Bloque listado index()
STUDY_OLD = (
    "$user->hasRole('super_admin') || $user->hasRole('admin') || "
    "$user->hasRole('tecnologo') || $user->hasRole('medico')"
)
STUDY_NEW = (
    "$user->hasRole('super_admin') || $user->hasRole('admin') || "
    "$user->hasRole('admin_siresa') || $user->hasRole('tecnologo') || "
    "$user->hasRole('medico') || $user->hasRole('secretaria')"
)

# Autorización por sede al ver estudio
AUTHZ_OLD = (
    "elseif ($user->hasRole('admin') || $user->hasRole('tecnologo') || $user->hasRole('medico')) {"
)
AUTHZ_NEW = (
    "elseif ($user->hasRole('admin') || $user->hasRole('admin_siresa') || "
    "$user->hasRole('tecnologo') || $user->hasRole('medico') || "
    "$user->hasRole('secretaria')) {"
)

AUTH_OLD = """            if (in_array('super_admin', $roles))
                $role = 'super_admin';
            elseif (in_array('admin', $roles))
                $role = 'admin';
            elseif (in_array('medico_solicitante', $roles))
                $role = 'medico_solicitante';"""

# Priorizar secretaria sobre admin genérico (KC suele asignar ambos)
AUTH_NEW = """            if (in_array('super_admin', $roles))
                $role = 'super_admin';
            elseif (in_array('secretaria', $roles) || in_array('secretario', $roles))
                $role = 'secretaria';
            elseif (in_array('admin', $roles) || in_array('admin_siresa', $roles))
                $role = 'admin';
            elseif (in_array('tecnologo', $roles))
                $role = 'tecnologo';
            elseif (in_array('medico', $roles))
                $role = 'medico';
            elseif (in_array('medico_solicitante', $roles))
                $role = 'medico_solicitante';"""


def replace_once(path: Path, old: str, new: str, label: str) -> None:
    text = path.read_text(encoding="utf-8")
    if new in text and old not in text:
        print(f"{label}: ya aplicado")
        return
    if old not in text:
        raise SystemExit(f"{label}: patrón no encontrado")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")
    print(f"{label}: OK")


def main() -> None:
    replace_once(CTRL, STUDY_OLD, STUDY_NEW, "StudyController.index staff")
    replace_once(CTRL, AUTHZ_OLD, AUTHZ_NEW, "StudyController.authz staff")
    replace_once(AUTH, AUTH_OLD, AUTH_NEW, "AuthController role map")


if __name__ == "__main__":
    main()
