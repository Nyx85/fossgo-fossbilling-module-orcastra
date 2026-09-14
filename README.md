# fossgo-fossbilling-module-orcastra

FOSSBilling service module for FOSSGO Private Cloud (Orcastra / LXD) — port of the WHMCS `orcastra` server module used on my.orca.id.

## Status

MVP in progress: create / suspend / unsuspend / terminate VM or container + client start/stop/restart + console link.

VDC (`orcastraproject`) is out of scope for MVP.

## Install (manage.fossgo.id)

Copy `modules/Serviceorcastra` into FOSSBilling `src/modules/` (or Docker volume overlays), enable the module in Admin, create products of type **Serviceorcastra**.

## Credentials

Product/server config needs Orcastra base URL + API keyId/keySecret (tenant org optional).
