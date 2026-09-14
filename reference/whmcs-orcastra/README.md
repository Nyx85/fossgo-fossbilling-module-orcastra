# Orcastra WHMCS provisioning module

Installs as `modules/servers/orcastra` on WHMCS (`https://my.orca.id/`).

On payment (or Create in admin) it calls the Orcastra external API to create an LXD instance, then start / stop / delete it for suspend, unsuspend, and terminate.

## API key

In Orcastra (Integrations) create a key:

- type `automation` or `custom`
- capabilities: `inventory.read` + `lxd.proxy`
- cluster grant: **write** (create/start/stop) and **admin** if Terminate should delete the instance
- project grant: the LXD project the product uses, or `*`

Do not grant `instance.exec` or `instance.files`.

## WHMCS setup

1. Copy this folder to `modules/servers/orcastra`.
2. Setup → Products/Services → Servers → Add New Server
   - Type / Module: **Orcastra**
   - Hostname: API origin, e.g. `https://app.orcastra.io` or `https://orcastra-dev.caracal-bee.ts.net`
   - Username: API key id
   - Password (or Access Hash): API key secret
   - Test Connection (calls `GET /api/v1/integrations/whoami`)
3. Create a product, module **Orcastra**, assigned to that server. Fill Cluster ID, project, image, CPU, memory, disk.
4. Orders that use this product auto-provision on payment.

Instance names are `vm{serviceid}`. Live mapping is in `mod_orcastra_instances`.
