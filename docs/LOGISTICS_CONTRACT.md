# Logística — contrato observado

Comportamiento **real** del catálogo de recursos, las asignaciones y el efectivo
(Spec 0056, caracterización).

> **Esto es caracterización.** Documenta lo que el código hace hoy, no lo que
> debería. Donde el doc viejo dice otra cosa se marca ⚠️. Lo que está mal se
> anota con severidad y destino; corregirlo es la Spec 0057.
>
> Manda sobre `INVENTORY_SYSTEM_EXPLAINED.md` y
> `CASH_MANAGEMENT_AND_NOTIFICATIONS.md` cuando discrepen.

Pruebas que lo sostienen:

| Archivo | Qué fija |
| --- | --- |
| `tests/Feature/Logistics/ResourceItemCharacterizationTest.php` (11) | catálogo, disponibilidad, stock bajo, aislamiento |
| `tests/Feature/Logistics/ResourceAllocationCharacterizationTest.php` (13) | ciclo de estados, stock, borrado, `by-meeting`/`by-leader` |
| `tests/Feature/Logistics/ResourceAllocationItemCharacterizationTest.php` (9) | **estado por ítem** |
| `tests/Feature/Logistics/CashAllocationCharacterizationTest.php` (7) | efectivo y los huecos de caja |
| `tests/Feature/Logistics/LogisticsTenantSecurityTest.php` (5) | permisos y aislamiento |
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

⚠️ **`is_inventory_tracked` no se puede fijar por la API.** No está en las reglas
de `StoreResourceItemRequest` ni de `UpdateResourceItemRequest`, así que
`validated()` lo descarta y el ítem nace con el default de la columna: `true`. La
migración apagó el flag para los `cash` que ya existían, pero **uno nuevo creado
por la API queda como inventario aunque sea dinero**, y no hay forma de
corregirlo sin tocar la base.

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

### 🔴 El ciclo de estados NO corre por la API

`UpdateResourceAllocationRequest` acepta solo `meeting_id`, `leader_user_id`,
`type`, `descripcion`, `amount` y `fecha_asignacion`. **`status` no está**, así
que `validated()` lo descarta y en el controlador `$newStatus` acaba siendo el
estado viejo. Consecuencia: `PUT /resource-allocations/{id}` con
`{"status":"delivered"}` responde **200 y no cambia nada**.

Toda la máquina de estados del controlador es, hoy, código inalcanzable:

| Transición | Lo que haría | Estado real |
| --- | --- | --- |
| `pending → delivered` | libera reserva y descuenta stock | inalcanzable |
| `delivered → returned` | reintegra el **100 %** al stock | inalcanzable |
| `pending → cancelled` | libera la reserva | inalcanzable |
| cualquier otra | 422 con `allowed_transitions` | inalcanzable |

Ejercido por dentro (saltándose el FormRequest) el ciclo sí hace lo documentado,
y el reintegro es **todo o nada**: no hay forma de devolver parte.

⚠️ Además, la máquina solo se evalúa `if ($allocation->items()->exists())`: una
asignación **sin ítems** —todo el efectivo— saltaría cualquier estado sin
validación alguna, si `status` llegara a aceptarse.

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

## Estado por ítem — `resource-allocation-items` (RC3)

**Marca, no mueve.** Es la corrección más importante frente al doc viejo.

`PATCH /resource-allocation-items/{id}/status` acepta `pending`, `delivered`,
`returned`, `damaged`, `lost` y escribe exactamente tres cosas: el estado, la
fecha (`delivered_at` o `returned_at`) y la persona (`delivered_by_user_id` o
`returned_to_user_id`). Y nada más.

| Lo que uno esperaría | Lo que pasa |
| --- | --- |
| `returned` reintegra al stock | **no**: el stock no se toca |
| `lost`/`damaged` descuentan o valoran la pérdida | **no**: son texto |
| el estado del padre se recalcula | **no**: sigue igual |
| hay transiciones válidas | **no**: se salta de `lost` a `delivered` y de vuelta |

Así que **no existe la devolución parcial: existe una anotación parcial**. El doc
viejo acierta al decir "todo o nada" en cuanto al stock —lo único que mueve
inventario es el ciclo de la asignación— pero no menciona que hay un segundo
camino que *parece* hacerlo por ítem y no hace nada. Es exactamente el terreno
sobre el que la 0057 tiene que construir la merma.

⚠️ Un ítem marcado `lost` queda con `returned_at` y `returned_to_user_id`
puestos, como si alguien lo hubiera recibido.

`PUT /resource-allocation-items/{id}` cambia cantidad/costo y recalcula el
`total_cost` del padre, pero **no reajusta la reserva** ni comprueba que haya
stock para la cantidad nueva ⚠️.

`DELETE` borra el ítem y recalcula el total. Desde la 0056 **libera la reserva**
si la asignación está `pending` (antes la dejaba colgada).

---

## Efectivo (RC4)

Una entrega de dinero es una `ResourceAllocation` con `type='cash'` y `amount`, o
un ítem de catálogo con `category='cash'`.

Qué hay: monto, persona, reunión, fecha, notas. Qué **no** hay:

- ⚠️ **`cash_purpose` no entra por la API.** La columna existe y el modelo la
  declara fillable, pero no está en las reglas de crear ni de editar: el único
  campo que explicaba en qué se iba el dinero se descarta en silencio.
- **Ningún cierre.** Sin ítems la asignación se queda en `pending` para siempre:
  no hay «legalizado», ni «devuelto», ni «castigado».
- **Ningún fondo ni saldo.** El monto no se valida contra nada (cualquier cifra
  positiva entra; el 0 también). No existen `petty_cash_funds`, `cash_advances`,
  `cash_expenses` ni `resource_losses` — comprobado por ausencia en las pruebas,
  para que avisen el día que la 0057 las cree.
- ⚠️ Un ítem `cash` con `is_inventory_tracked=true` —que es el que produce hoy la
  API— tiene disponible 0 y **bloquea la entrega con un 422 de stock
  insuficiente**.

---

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
| `PATCH /resource-allocation-items/{id}/status`, `PUT .../{id}` | `edit_resources` |
| `DELETE /resource-allocation-items/{id}` | `delete_resources` |

Sin permiso → 403; sin sesión → 401. El super admin no tiene tenant, así que
`EnsureTenant` no fija filtro y ve el catálogo de todos.

`ResourceAllocationItem` **no usa `HasTenant` ni tiene `tenant_id`**: cuelga de la
asignación. Sus tres rutas resolvían el modelo por binding directo, así que
cualquiera con `edit_resources` podía tocar los ítems de otra campaña sabiendo el
id. Desde la 0056 las tres preguntan por la asignación padre a través del
`TenantScope` y responden 404.

---

## Hallazgos

Corregidos en la 0056 (🔴, cada uno con su prueba):

| # | Qué | Dónde |
| --- | --- | --- |
| H1 | Fuga entre tenants en las tres rutas de ítems | `ResourceAllocationItemController` |
| H2 | Borrar un ítem pendiente dejaba la reserva colgada (corrupción de stock) | `ResourceAllocationItemController@destroy` |

Para la 0057:

| # | Sev | Qué |
| --- | --- | --- |
| H3 | 🔴 | `status` no está en `UpdateResourceAllocationRequest`: el ciclo de inventario es inalcanzable por API |
| H4 | 🔴 | El estado por ítem no mueve stock ni valora pérdidas: no hay devolución parcial real |
| H5 | 🟠 | `cash_purpose` no entra por la API |
| H6 | 🟠 | El efectivo no tiene cierre (legalizado/devuelto/castigado) ni fondo con saldo |
| H7 | 🟠 | `is_inventory_tracked` no se puede fijar ni corregir por la API; un `cash` nuevo nace como inventario y queda inasignable |
| H8 | 🟠 | `PUT` de ítem no reajusta la reserva ni valida stock |
| H9 | 🟠 | Sin ítems, la máquina de estados ni se evalúa (todo el efectivo) |
| H10 | 🟠 | Borrar una asignación entregada borra el rastro de quién tiene las unidades |
| H11 | 🟡 | Un ítem `lost` queda con `returned_at`/`returned_to_user_id` |
| H12 | 🟡 | `available_quantity` = `PHP_INT_MAX` para lo no rastreable |
| H13 | 🟡 | Sin `min_stock` no hay alerta ni con stock en cero |
| H14 | 🟡 | Se borra en blando un recurso con reserva viva |
| H15 | 🟡 | `unit_cost` obligatorio al crear |
| H16 | 🟡 | `grand_total` de `by-meeting`/`by-leader` suma `amount` + `total_cost` y puede duplicar |
| H17 | 🟡 | Borrar el último ítem deja una asignación vacía con total 0 |
| H18 | 🟡 | `store`/`update`/`destroy` de asignaciones devuelven `$e->getMessage()` en el 500 |
