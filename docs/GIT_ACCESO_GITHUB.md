# Acceso a GitHub para `git push` y `git pull`

Si `git push` responde **403** o *Write access to repository not granted*, el token o la clave no tienen permiso de **escritura** en `Raysus/ris`.

Elija **una** de las dos opciones siguientes.

---

## Opción A — Token personal (HTTPS) — la más rápida

### 1. Crear el token en GitHub

1. Entre con una cuenta que tenga permiso de **escritura** en el repositorio `Raysus/ris`.
2. Abra: **Settings → Developer settings → Personal access tokens**.
3. Cree un token:
   - **Fine-grained:** repositorio `Raysus/ris` → permisos **Contents: Read and write**, **Metadata: Read**.
   - **Classic:** marque el scope **`repo`** (acceso completo a repos privados).
4. Copie el token (`github_pat_...` o `ghp_...`). **No lo comparta por chat ni lo suba al repo.**

### 2. Configurar esta estación (recomendado)

En su PC de desarrollo o en el servidor:

```bash
cd /ruta/a/RIS
export GITHUB_TOKEN='github_pat_XXXXX'   # token con Contents Read and write
bash scripts/preparar-git-estacion.sh
```

Eso configura identidad Git del repo, remoto HTTPS seguro y credenciales en `~/.git-credentials`.

### 3. Configurar el servidor con el mismo token

En el servidor del laboratorio, con su token **read and write**:

```bash
cd /opt/RIS
# Opción A: pegar token al ejecutar el script
./scripts/configurar-git-github.sh

# Opción B: sin mostrar el token en pantalla al pegar
export GITHUB_TOKEN='github_pat_XXXXX'   # su token
./scripts/configurar-git-github.sh
```

El script deja `origin` en `https://github.com/Raysus/ris.git` (sin token en la URL) y guarda el PAT en `~/.git-credentials`.

### 3. Probar

```bash
cd /opt/RIS
git pull origin laboratorios
git push origin laboratorios
```

**Alternativa manual** (si no usa el script): `git config --global credential.helper store`, luego en el primer `git pull` use como contraseña el token y como usuario `x-access-token` o su usuario de GitHub.

---

## Opción B — SSH (recomendado para servidores)

### 1. Generar clave en el servidor

```bash
ssh-keygen -t ed25519 -C "ris-$(hostname)" -f ~/.ssh/id_ed25519_github -N ""
cat ~/.ssh/id_ed25519_github.pub
```

Copie **toda** la línea que empieza por `ssh-ed25519`.

### 2. Registrar la clave en GitHub

1. **Settings → SSH and GPG keys → New SSH key**
2. Título: `RIS servidor <nombre del lab>`
3. Pegue la clave pública → **Add SSH key**

La cuenta debe ser **colaborador** del repo `Raysus/ris` con rol **Write** o **Maintain**.

### 3. Configurar SSH y el remoto

```bash
cat >> ~/.ssh/config << 'EOF'
Host github.com
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_ed25519_github
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

cd /opt/RIS
git remote set-url origin git@github.com:Raysus/ris.git
```

### 4. Probar

```bash
ssh -T git@github.com
# Debe decir: Hi <usuario>! You've successfully authenticated...

git pull origin laboratorios
git push origin laboratorios
```

---

## Si sigue fallando

| Error | Causa habitual | Qué hacer |
|-------|----------------|-----------|
| **403** en HTTPS | Token solo lectura o sin acceso al repo | Token nuevo con **Contents: write** o scope **repo** |
| **Permission denied (publickey)** | Clave no registrada en GitHub | Repetir opción B, paso 2 |
| **Repository not found** | Cuenta sin acceso al repo privado | Pedir a quien administra `Raysus/ris` que agregue su usuario como colaborador |
| Token en la URL del remoto | Riesgo de filtración | `git remote set-url origin https://github.com/Raysus/ris.git` |

---

## Comprobar el remoto actual

```bash
cd /opt/RIS
git remote -v
```

No debe aparecer `github_pat_` ni `ghp_` en la URL. Si aparece, ejecute:

```bash
git remote set-url origin https://github.com/Raysus/ris.git
```

---

**Rama de laboratorios:** `laboratorios`  
**No subir al repo:** `backend/.env`, tokens, claves.
