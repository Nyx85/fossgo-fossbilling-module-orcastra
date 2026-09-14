# fossgo-fossbilling-module-orcastra

FOSSBilling **product type** module for FOSSGO Private Cloud (Orcastra / LXD) — port of the WHMCS `orcastra` server module used on my.orca.id.

Module id: `serviceorcastra` → product type **orcastra** (FOSSBilling auto-registers any enabled module whose id starts with `service`).

Author: **FOSSGO** · License: **Apache-2.0** · MVP version: **0.1.0**

---

## English

### What this MVP does

- Create / activate LXD **VM or container** via Orcastra **raw-proxy** `POST /1.0/instances`
- Suspend / unsuspend (stop / start), cancel / delete (stop + delete + best-effort revoke)
- Client & admin: **Mulai / Stop / Restart** and ticketed **Konsol / Terminal**
- Console access is **server-minted** via `POST /api/v1/integrations/console-access` — browser redirects to `redirect_url` with `ticket=` only (never bare `/terminal/...`)

### Out of scope (later)

- VDC / `orcastraproject` multi-VM projects
- Full catalog loaders (images/pools/profiles dropdowns from live API)
- Metrics panel

### Install (e.g. manage.fossgo.id)

1. Copy `modules/Serviceorcastra` into FOSSBilling `src/modules/` (or your Docker volume overlay).
2. Admin → Extensions → enable **Orcastra VM/Container** (`serviceorcastra`). Install creates `service_orcastra`.
3. Admin → Products → create a product with type **orcastra**.
4. On the product config tab, set:
   - `api_hostname` — default `orcastra.orca.id` (no scheme; client prepends `https://`)
   - `api_key_id` / `api_key_secret` — paste your Fossgo Mini key (`oak_…` / `oas_…`). Stored in product config; FOSSBilling encrypts extension/product secrets — **do not commit keys to git**.
   - `cluster_id` — e.g. `central-1` or `central-2`
   - `project`, `instance_type`, `image`, CPU/memory/disk, `storage_pool`, `dashboard_base` (`https://orcastra.orca.id`)
5. Place a test order and activate; confirm instance appears on the cluster.

### Live smoke test needs

- Reachable Orcastra URL (`https://orcastra.orca.id`)
- Valid Fossgo integration API key id + secret with cluster grants
- A writable LXD project + storage pool on `central-1` / `central-2`

### Reference

WHMCS sources under `reference/whmcs-orcastra/` (PHP only; cache JSON is gitignored).

---

## Bahasa Indonesia

### Ringkasan MVP

Modul tipe produk FOSSBilling untuk provision VM/container di Orcastra. Mendukung create/suspend/unsuspend/terminate, tombol **Mulai / Stop / Restart**, serta **Konsol / Terminal** ber-tiket (mint di server).

### Instalasi

1. Salin `modules/Serviceorcastra` ke `src/modules/` FOSSBilling.
2. Aktifkan ekstensi **Orcastra VM/Container**.
3. Buat produk tipe **orcastra**, isi hostname + `api_key_id` / `api_key_secret` (tempel di UI; jangan simpan secret di git).
4. Cluster contoh Mini: `central-1`, `central-2`. Dashboard default: `https://orcastra.orca.id`.

### Belum termasuk

VDC / project multi-VM akan dikerjakan terpisah.

### Uji live

Butuh URL Orcastra + kunci API Fossgo yang valid. Tanpa kredensial, hanya review kode / instalasi modul yang bisa diverifikasi.
