# Logística — contrato

Catálogo de recursos, asignaciones, devolución con merma y caja menor.
Caracterizado en la **Spec 0056**; reparado y ampliado en la **0057**.

> Manda sobre `INVENTORY_SYSTEM_EXPLAINED.md` y
> `CASH_MANAGEMENT_AND_NOTIFICATIONS.md` cuando discrepen: esos describen el
> diseño, este describe lo que el código hace, y cada afirmación tiene su prueba.
>
> Lo marcado ⚠️ sigue siendo comportamiento observado que conviene conocer; lo
> que la 0057 corrigió se señala como **reparado**.

Pruebas que lo sostienen:

| Archivo | Qué fija |
| --- | --- |
| `tests/Feature/Logistics/ResourceItemCharacterizationTest.php` (11) | catálogo, disponibilidad, stock bajo, aislamiento |
| `tests/Feature/Logistics/ResourceAllocationCharacterizationTest.php` (13) | ciclo de estados, stock, borrado, `by-meeting`/`by-leader` |
| `tests/Feature/Logistics/ResourceAllocationItemCharacterizationTest.php` (9) | **estado por ítem** |
| `tests/Feature/Logistics/CashAllocationCharacterizationTest.php` (7) | efectivo y los huecos de caja |
| `tests/Feature/Logistics/LogisticsTenantSecurityTest.php` (5) | permisos y aislamiento |
| `tests/Feature/Logistics/LogisticsBaseRepairTest.php` (14) | el ciclo reparado (0057) |
| `tests/Feature/Logistics/InventoryReturnTest.php` (15) | devolución parcial y merma (0057) |
| `tests/Feature/Logistics/PettyCashTest.php` (18) | caja menor (0057) |
| `tests/Unit/Support/LogisticsFactoriesTest.php` (3) | el harness |

---

## Las dos naturalezas

| | Retornable (inventario) | Fungible / efectivo |
| --- | --- | --- |
| `is_inventory_tracked` | `true` | `false` |
| `stock_quantity` / `reserved_quantity` | se llevan | no aplican |
| Al asignar | reserva | no hace nada |
| Al entregar | descuenta | no hace nada |
| Al devolver | reintegra | no vuelve |

`available_quantity = max(0, stock − reserved)`. Con `is_inventory_tracked=false`
devuelve `PHP_INT_MAX` ⚠️ (sale así en el JSON: `9223372036854775807`).

---

## Catálogo — `resource-items`

CRUD estándar bajo `permission:*_resources`, con `TenantScope`: un recurso de otro
tenant responde **404** en `show`/`update`/`destroy` y no aparece en `index`.
Borrado en blando.

- `POST` exige `name`, `category`, `unit` y `unit_cost` (⚠️ el costo es
  obligatorio aunque no se conozca). Por defecto `currency=COP`, `is_active=true`.
- `is_low_stock` es `stock <= min_stock`; **el mínimo cuenta como stock bajo**.
  Sin `min_stock` nunca hay alerta, aunque el stock esté en cero ⚠️.
- `GET /resource-items-low-stock` devuelve los activos en stock bajo, sin paginar.

**`is_inventory_tracked` se fija por la API y se deriva de la categoría**
(reparado en la 0057, era H7). Si no se manda, un recurso `cash` nace **sin**
rastreo y el resto con rastreo; mandarlo explícitamente manda sobre esa regla, y
se puede corregir con un `PUT`. Antes el campo no estaba en las reglas del
FormRequest, así que se descartaba y todo `cash` nuevo nacía como inventario —con
disponible 0— y quedaba inasignable.

⚠️ Borrar un recurso no comprueba si está reservado: se borra en blando dejando
la reserva viva y los ítems apuntando a un recurso borrado.

---

## Asignación de inventario — `resource-allocations`

### Crear (`POST`) — esto sí funciona

1. Valida el stock de **todos** los ítems antes de crear nada. Si a uno le falta →
   **422** con `{message, resource, requested, available, in_stock, reserved}` y
   no queda ni asignación ni reserva.
2. Crea la asignación en `pending` y **reserva** la cantidad de cada ítem. El
   stock no baja todavía.
3. Congela el `unit_cost` del catálogo en cada ítem y calcula `total_cost`.
4. Si hay `meeting_id` y el planner tiene teléfono, manda WhatsApp y consume un
   crédito de mensajería.

Un `resource_item_id` de otro tenant responde **404** — lo salva el `TenantScope`
del `find`, no la regla `exists:resource_items,id`, que no filtra por tenant.

### El ciclo de estados (reparado en la 0057)

`PUT /resource-allocations/{id}` acepta `status` y mueve el inventario. Hasta la
0057 no lo aceptaba: el campo no estaba en las reglas de
`UpdateResourceAllocationRequest`, `validated()` lo descartaba y toda la máquina
de estados del controlador era código inalcanzable — el `PUT` respondía 200 sin
cambiar nada (era H3, el hallazgo grande de la 0056).

La máquina vive ahora en `App\Services\Logistics\AllocationStatusService`:

| Transición | Qué hace |
| --- | --- |
| `pending → delivered` | libera la reserva y descuenta del stock |
| `delivered → returned` | reintegra el **100 %** (el cierre parcial es otro endpoint) |
| `pending → cancelled` | libera la reserva |
| repetir el estado actual | no hace nada (no descuenta dos veces) |
| cualquier otra | **422** con `allowed_transitions` |

Las guardas valen **también sin ítems**: antes la máquina solo se evaluaba
`if ($allocation->items()->exists())`, así que una entrega de dinero podía saltar
a cualquier estado sin validación (era H9).

Al cancelar, los ítems pasan a `cancelled`, un valor que el CHECK de la columna
no admitía: en SQLite pasaba desapercibido y en PostgreSQL habría respondido 500
la primera vez. La migración
`2026_08_09_100000_add_cancelled_to_allocation_item_statuses` lo amplía por
driver — mismo patrón que la 0038 usó en campañas.

### Borrar (`DELETE`)

- `pending` → libera las reservas. ✔
- `delivered` → **no** reintegra (correcto: salió del almacén) pero borra el único
  registro de quién tiene esas unidades ⚠️.

### Relaciones

- `GET /resource-allocations/by-meeting/{meeting}` → asignaciones de la reunión +
  `summary` con `total_cash|material|service` (del sistema viejo, sobre `amount`),
  `total_cost` (suma de `total_cost`) y `grand_total`, que **suma las dos cosas** ⚠️
  y puede contar dos veces si una asignación tiene `amount` e ítems.
- `GET /resource-allocations/by-leader/{user}` → igual, paginado. Un usuario de
  otro tenant da 404 (el binding del `User` también pasa por el scope).

---

## Devolución por ítem: cierre con merma (0057)

Hasta la 0057 el estado por ítem **marcaba pero no movía**: `returned` no
reintegraba stock, `lost`/`damaged` no descontaban ni valían nada, y el padre no
se enteraba. No existía la devolución parcial, existía una anotación parcial
(H4). El cierre la sustituye.

### `POST /resource-allocations/{id}/close`

Declara, por ítem, cuánto volvió y cuánto no:

```json
{ "items": [
    { "id": 12, "quantity_returned": 8, "quantity_lost": 2, "notes": "Se quedaron en la vereda" }
] }
```

- **Solo lo devuelto vuelve al stock.** Lo perdido y lo dañado no: son merma.
- Cada pérdida crea una fila en `inventory_losses` con `quantity`, `unit_cost`,
  `value` (= cantidad × costo), `reason` (`perdido`/`dañado`), la reunión y el
  responsable (`assigned_to_user_id`, o quien se pase en `responsible_user_id`).
- El `unit_cost` es una **fotografía** del momento del cierre: si el catálogo
  sube de precio, la pérdida de ayer sigue valiendo lo que valía.
- **Invariante:** `devuelto + perdido + dañado ≤ entregado`. Si un solo ítem se
  pasa, el cierre entero responde **422** y no se mueve nada.
- Un ítem ya cerrado no se puede volver a cerrar (era la vía fácil para inflar el
  inventario), y solo se cierra una asignación `delivered`.
- Declarar menos de lo entregado es legítimo: el resto queda sin declarar, el
  ítem es `parcial` y el stock sube solo por lo declarado.
- Cuando ya no queda ítem sin cerrar, la asignación pasa a `returned`.

El estado del ítem se **deriva** del desglose en `return_state`
(`devuelto|parcial|perdido|dañado`), así que no puede contradecir a las
cantidades. El `status` de siempre se mantiene por compatibilidad con lo que ya
consume el panel.

**La devolución total sigue funcionando igual**: declarar todo como devuelto da
el mismo resultado de antes, sin merma. Y `PUT /resource-allocations/{id}` con
`status=returned` sigue reintegrando el 100 %, para lo que no necesita desglose.

### `GET /inventory-losses`

Filtros: `resource_item_id`, `responsible_user_id`, `meeting_id`, `reason`,
`from`, `to`. El `summary` (`total_losses`, `total_quantity`, `total_value`) se
calcula sobre el filtro y no sobre la página visible.

### Los otros dos endpoints de ítem

`PATCH /resource-allocation-items/{id}/status` sigue existiendo y sigue siendo
solo una etiqueta: no mueve stock. Para mover algo, el cierre.

`PUT /resource-allocation-items/{id}` cambia cantidad/costo, recalcula el
`total_cost` del padre y **reajusta la reserva** validando disponible (reparado
en la 0057, era H8). Sobre una asignación ya entregada no toca reservas, porque
ahí ya no hay ninguna.

`DELETE` borra el ítem, recalcula el total y libera la reserva si la asignación
está `pending` (0056).

## Dinero: entrega suelta y caja menor

Hay dos formas de entregar dinero, y conviene no confundirlas.

### La entrega suelta (`resource_allocations` con `type='cash'`)

Sigue existiendo tal cual: monto, persona, reunión, fecha, notas y
`cash_purpose` — que **ahora sí entra por la API**, al crear y al editar
(reparado en la 0057, era H5: la columna y el `fillable` existían, pero ningún
FormRequest aceptaba el campo). Sirve para dejar constancia de que se entregó
algo; no tiene saldo, ni tope, ni cierre.

Un ítem de catálogo con `category='cash'` no reserva ni descuenta, porque nace
sin rastreo de inventario.

### La caja menor (0057)

Para controlar el dinero de verdad: fondo con saldo, anticipos y cierre.

**La regla del saldo:**

```
saldo = Σ reposiciones − Σ anticipos + Σ reintegros
```

**La legalización no entra en esa suma.** Es el punto que más se presta a
confusión: cuando alguien explica en qué gastó, no mueve nada — el dinero salió
del fondo el día del anticipo. Legalizar solo clasifica lo que ya salió. Lo único
que vuelve al fondo es el sobrante.

| Tabla | Qué guarda |
| --- | --- |
| `petty_cash_funds` | nombre, responsable, `balance`, moneda, activo |
| `petty_cash_movements` | cada entrada/salida con `balance_after` — el libro |
| `petty_cash_advances` | lo entregado a una persona, con su desenlace |
| `petty_cash_expense_lines` | el detalle de la legalización, con o sin recibo |

`balance` está fuera del `$fillable` a propósito: **no se puede fijar desde la
API**, ni al crear ni al editar. Lo escribe `PettyCashService` por asignación
directa, dentro de una transacción y con `lockForUpdate` sobre la fila del fondo,
para que dos anticipos simultáneos no gasten el mismo saldo dos veces. El saldo
inicial de un fondo nuevo se registra como reposición, no se escribe.

#### El ciclo del anticipo

1. **Reposición** — `POST /petty-cash-funds/{id}/replenish`. Entra dinero.
2. **Anticipo** — `POST /petty-cash-advances`. Sale hacia una persona (y opcionalmente
   una reunión). Valida `amount ≤ saldo` → si no, **422**. Nace
   `pendiente_legalizar`.
3. **Legalización** — `POST /petty-cash-advances/{id}/settle`. Líneas de gasto +
   `amount_returned` + `amount_charged_off`. Exige **cuadre exacto**:
   `gastado + devuelto + castigado = entregado`, o **422**. Solo el sobrante
   vuelve al fondo. Una sola vez por anticipo.
4. **Castigo** — `POST /petty-cash-advances/{id}/charge-off`. Atajo del caso real
   más frecuente: no se comprobó ni volvió. Se acepta como gasto de campaña pero
   queda registrado con quién, cuánto y en qué reunión.

Las líneas admiten `has_receipt = false` **sin bloquear**: exigir recibos que no
van a existir solo consigue que nadie legalice. Lo que no se puede es dejar
dinero sin explicar — para eso está el castigo, que es visible.

Un anticipo cierra como `legalizado` si algo se explicó o volvió, y como
`castigado` si no se explicó nada en absoluto.

#### Consultas

`GET /petty-cash-advances` filtra por `status`, `user_id`, `meeting_id` y
`petty_cash_fund_id`, con un `summary` de `total_advanced`, `total_spent`,
`total_returned`, `total_charged_off` y `total_pending` — el dato que el informe
de caja de la 0036 necesita por responsable y por reunión.

`GET /petty-cash-funds` lista los fondos con su saldo y el total.

Un fondo con anticipos sin legalizar no se puede borrar.

## Permisos y aislamiento (RC6)

| Ruta | Permiso |
| --- | --- |
| `GET /resource-items`, `/{id}`, `/resource-items-low-stock` | `view_resources` |
| `POST /resource-items` | `create_resources` |
| `PUT /resource-items/{id}` | `edit_resources` |
| `DELETE /resource-items/{id}` | `delete_resources` |
| `GET /resource-allocations`, `/{id}`, `/by-meeting/*`, `/by-leader/*` | `view_resources` |
| `POST /resource-allocations` | `create_resources` |
| `PUT /resource-allocations/{id}` | `edit_resources` |
| `DELETE /resource-allocations/{id}` | `delete_resources` |
| `POST /resource-allocations/{id}/close` | `edit_resources` |
| `GET /inventory-losses` | `view_resources` |
| `PATCH /resource-allocation-items/{id}/status`, `PUT .../{id}` | `edit_resources` |
| `DELETE /resource-allocation-items/{id}` | `delete_resources` |
| `GET /petty-cash-funds`, `/{id}`, `/petty-cash-advances`, `/{id}` | `view_petty_cash` |
| `POST`/`PUT`/`DELETE` de fondos, `replenish`, y crear/`settle`/`charge-off` de anticipos | `manage_petty_cash` |

**La caja tiene permisos propios** (`view_petty_cash`, `manage_petty_cash`), no
reutiliza `*_resources`: quien administra las sillas no tiene por qué poder
entregar plata. Van en el catálogo (`Permissions::byModule()`), en el seeder y
asignados a `admin` y `coordinator`; `viewer` solo ve.

Sin permiso → 403; sin sesión → 401. El super admin no tiene tenant, así que
`EnsureTenant` no fija filtro y ve el catálogo de todos.

`ResourceAllocationItem` **no usa `HasTenant` ni tiene `tenant_id`**: cuelga de la
asignación. Sus tres rutas resolvían el modelo por binding directo, así que
cualquiera con `edit_resources` podía tocar los ítems de otra campaña sabiendo el
id. Desde la 0056 las tres preguntan por la asignación padre a través del
`TenantScope` y responden 404.

---

## Hallazgos: dónde quedó cada uno

De los 18 que dejó la caracterización (0056):

| # | Sev | Qué era | Estado |
| --- | --- | --- | --- |
| H1 | 🔴 | Fuga entre tenants en las rutas de ítems | **corregido en 0056** |
| H2 | 🔴 | Borrar un ítem dejaba la reserva colgada | **corregido en 0056** |
| H3 | 🔴 | `status` fuera del FormRequest: ciclo inalcanzable | **corregido en 0057** |
| H4 | 🔴 | El estado por ítem no movía stock ni valoraba | **sustituido en 0057** por el cierre con merma |
| H5 | 🟠 | `cash_purpose` no entraba por la API | **corregido en 0057** |
| H6 | 🟠 | El efectivo no tenía cierre ni fondo | **cubierto en 0057** por la caja menor |
| H7 | 🟠 | `is_inventory_tracked` no se podía fijar | **corregido en 0057** |
| H8 | 🟠 | `PUT` de ítem no reajustaba la reserva | **corregido en 0057** |
| H9 | 🟠 | Sin ítems la máquina de estados ni se evaluaba | **corregido en 0057** |
| H10 | 🟠 | Borrar lo entregado borra el rastro de quién lo tiene | abierto |
| H11 | 🟡 | Un ítem `lost` queda con `returned_at`/`returned_to_user_id` | abierto |
| H12 | 🟡 | `available_quantity` = `PHP_INT_MAX` para lo no rastreable | abierto |
| H13 | 🟡 | Sin `min_stock` no hay alerta ni con stock 0 | abierto |
| H14 | 🟡 | Se borra en blando un recurso con reserva viva | abierto |
| H15 | 🟡 | `unit_cost` obligatorio al crear | abierto |
| H16 | 🟡 | `grand_total` suma `amount` + `total_cost` y puede duplicar | abierto |
| H17 | 🟡 | Borrar el último ítem deja una asignación vacía | abierto |
| H18 | 🟡 | Los 500 de asignaciones filtraban `getMessage()` | **corregido en 0057** |

Y uno que la 0056 no podía ver porque el camino estaba muerto:

| # | Sev | Qué | Estado |
| --- | --- | --- | --- |
| H19 | 🔴 | `cancelled` no estaba en el CHECK de `resource_allocation_items.status`: en PostgreSQL habría dado 500 al cancelar | **corregido en 0057** |

Los ocho 🟡/🟠 abiertos no tocan caja ni merma; se priorizan aparte.

## Datos para los informes (0036)

Lo que esta spec deja listo para el informe de responsabilidad por persona:

- **Caja por responsable / reunión:** `GET /petty-cash-advances` con
  `filter[user_id]` o `filter[meeting_id]` → `summary.total_advanced`,
  `total_spent`, `total_returned`, `total_charged_off`, `total_pending`.
- **Mermas por ítem / responsable / reunión:** `GET /inventory-losses` con
  `filter[resource_item_id]`, `filter[responsible_user_id]` o
  `filter[meeting_id]` → `summary.total_value` y `total_quantity`.

Juntos responden la pregunta que motivó todo esto: **quién debe dinero o
enseres**.
